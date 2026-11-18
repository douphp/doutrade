<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Core\Service\Aftersale;

use Dou\Core\Domain\Aftersale\AftersaleStatus;
use Dou\Core\Domain\Aftersale\AftersaleType;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后超时自动流转（scheduled 入口）。
 *
 * 扫描 expired_at 已到期的售后单，按当前态执行自动动作：
 * - PENDING        → APPROVED（卖家逾期未审核，自动同意，approved_amount = apply_amount）
 * - WAITING_RETURN → CANCELLED（买家逾期未寄回，自动撤销）
 * - RETURN_SHIPPED → RECEIVED（卖家逾期未确认收货，视为已收货，随后派发退款）
 * - REFUNDING      → REFUND_FAILED（网关退款长时间无回调，转人工）
 */
class AftersaleAutoFlow extends BaseService
{
    /** @var AftersaleStatusTransition */
    private $transition;

    /** @var AftersaleRefundDispatcher */
    private $dispatcher;

    /** @var AftersaleService */
    private $lib;

    /**
     * @param AftersaleStatusTransition $transition
     * @param AftersaleRefundDispatcher $dispatcher
     * @param AftersaleService $lib 售后核心服务（资格恢复判定）
     */
    public function __construct(AftersaleStatusTransition $transition, AftersaleRefundDispatcher $dispatcher, AftersaleService $lib)
    {
        $this->transition = $transition;
        $this->dispatcher = $dispatcher;
        $this->lib = $lib;
    }

    /**
     * 执行一轮超时扫描。
     *
     * @param int $limit 单轮最多处理条数
     * @return int 实际流转条数
     */
    public function run($limit = 200)
    {
        if (!DB::tableExist('aftersale')) {
            return 0;
        }
        $now = date('Y-m-d H:i:s');
        $rows = (array) DB::table('aftersale')
            ->where('expired_at', 'IS NOT NULL')
            ->where('expired_at', '<=', $now)
            ->where('status', 'IN', array(
                AftersaleStatus::PENDING,
                AftersaleStatus::WAITING_RETURN,
                AftersaleStatus::RETURN_SHIPPED,
                AftersaleStatus::REFUNDING,
            ))
            ->order('id ASC')
            ->limit($limit)
            ->select();

        $handled = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $status = (string) $row['status'];
            switch ($status) {
                case AftersaleStatus::PENDING:
                    // 自动同意：审核金额取「申请金额」与「订单当前可退金额」的较小值；
                    // 无可退金额时留待人工处理，避免 0 元自动退款。
                    $available = $this->dispatcher->availableRefundAmount((int) $row['order_id']);
                    $approved = round(min((float) $row['apply_amount'], $available), 2);
                    if ($approved <= 0) {
                        break;
                    }
                    // 带状态 + 版本条件更新，避免与人工审核并发时覆盖已审核金额
                    $claimed = DB::table('aftersale')
                        ->where('id', $id)
                        ->where('status', AftersaleStatus::PENDING)
                        ->where('version', (int) $row['version'])
                        ->data(array('approved_amount' => $approved))
                        ->update();
                    if (!$claimed) {
                        break;
                    }
                    if ($this->transition->changeStatus($id, AftersaleStatus::APPROVED, 'approve', 'system', 0, '', '系统超时自动同意', (int) $row['version'])) {
                        // 仅退款类型自动进入退款；退货退款需买家寄回，停在 APPROVED→WAITING_RETURN
                        if (!AftersaleType::requiresReturn((string) $row['type'])) {
                            $this->dispatcher->dispatch($id, 'system', 0, '');
                        } else {
                            $this->transition->changeStatus($id, AftersaleStatus::WAITING_RETURN, 'approve', 'system', 0, '', '等待买家寄回');
                        }
                        $handled++;
                    }
                    break;
                case AftersaleStatus::WAITING_RETURN:
                    if ($this->transition->changeStatus($id, AftersaleStatus::CANCELLED, 'cancel', 'system', 0, '', '买家逾期未寄回，系统自动撤销')) {
                        // 与买家撤销一致：清空当前售后标记，是否恢复入口由固定时限与「一单只处理一次」规则判定
                        DB::table('order')->where('id', (int) $row['order_id'])->data(array(
                            'aftersale_status' => 0,
                        ))->update();
                        $this->lib->restoreAftersaleEligibility((int) $row['order_id']);
                        $handled++;
                    }
                    break;
                case AftersaleStatus::RETURN_SHIPPED:
                    if ($this->transition->changeStatus($id, AftersaleStatus::RECEIVED, 'receive', 'system', 0, '', '卖家逾期未确认，系统视为已收货')) {
                        $this->dispatcher->dispatch($id, 'system', 0, '');
                        $handled++;
                    }
                    break;
                case AftersaleStatus::REFUNDING:
                    if ($this->transition->changeStatus($id, AftersaleStatus::REFUND_FAILED, 'refund', 'system', 0, '', '退款长时间无回调，转人工处理')) {
                        // 保留 pending 网关退款，由管理员确认已退或标记失败，避免重复退款
                        $handled++;
                    }
                    break;
            }
        }

        return $handled;
    }
}
