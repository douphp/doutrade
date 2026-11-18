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
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后状态机（与 {@see \Dou\Core\Service\Order\OrderStatusTransition} 同构）。
 *
 * 关键设计：
 * - 状态字符串枚举 + 表驱动迁移校验（{@see AftersaleStatus::canTransit}）
 * - 乐观锁：UPDATE ... WHERE version = $expected，影响行数为 0 视为并发冲突，拒绝本次迁移
 * - 每次迁移写一行 dou_aftersale_log（协商时间线）
 * - 进入「待办态」时按超时表写 expired_at，供 {@see AftersaleAutoFlow} 扫描自动流转
 */
class AftersaleStatusTransition extends BaseService
{
    /** 各状态的超时阈值（秒）；不在表内的状态 expired_at 置 NULL（不超时）。 */
    private static $timeoutSeconds = array(
        AftersaleStatus::PENDING => 432000,        // 5 天卖家不审核 → 自动 APPROVED
        AftersaleStatus::WAITING_RETURN => 864000, // 10 天买家不寄出 → 自动 CANCELLED
        AftersaleStatus::RETURN_SHIPPED => 864000, // 10 天卖家不确认收货 → 自动 RECEIVED
        AftersaleStatus::REFUNDING => 259200,      // 3 天网关无回调 → REFUND_FAILED
    );

    /**
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * 变更售后状态（乐观锁 + 写 log + 计算超时点）。
     *
     * @param int $aftersaleId
     * @param string $newStatus {@see AftersaleStatus} 字符串枚举
     * @param string $action 操作动作：apply/approve/reject/cancel/ship/receive/refund/...
     * @param string $operatorType 操作者类型：user/admin/system/webhook
     * @param int $operatorId 操作者 ID
     * @param string $ip 操作 IP
     * @param string $note 操作备注 / 协商留言
     * @param int|null $expectedVersion 乐观锁期望版本号（null 表示不校验版本，读当前值）
     * @return bool true=迁移成功；false=非法迁移 / 并发冲突 / 记录不存在
     */
    public function changeStatus($aftersaleId, $newStatus, $action = '', $operatorType = 'system', $operatorId = 0, $ip = '', $note = '', $expectedVersion = null)
    {
        $aftersaleId = (int) $aftersaleId;
        $newStatus = (string) $newStatus;
        if (!AftersaleStatus::isValid($newStatus)) {
            $this->logError('invalid aftersale status: ' . $newStatus, array('aftersale_id' => $aftersaleId));
            return false;
        }

        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            return false;
        }

        $fromStatus = (string) $aftersale['status'];
        if ($fromStatus === $newStatus) {
            return true;
        }
        if (!AftersaleStatus::canTransit($fromStatus, $newStatus)) {
            $this->logError('illegal aftersale transition: ' . $fromStatus . ' -> ' . $newStatus, array(
                'aftersale_id' => $aftersaleId,
            ));
            return false;
        }

        $currentVersion = (int) $aftersale['version'];
        $expected = ($expectedVersion === null) ? $currentVersion : (int) $expectedVersion;

        // 列类型为 datetime：超时态写格式化时间，非超时态写 NULL（避免零日期）。
        $expiredAt = isset(self::$timeoutSeconds[$newStatus])
            ? date('Y-m-d H:i:s', time() + self::$timeoutSeconds[$newStatus])
            : null;
        $data = array(
            'status' => $newStatus,
            'version' => $currentVersion + 1,
            'expired_at' => $expiredAt,
        );
        if (in_array($newStatus, AftersaleStatus::terminal(), true)) {
            // closed_at 为 datetime 列，必须写格式化时间（写整数时间戳会被库强制转换或报错）
            $data['closed_at'] = date('Y-m-d H:i:s');
        }

        // 乐观锁：仅当 version 仍为期望值时才迁移
        $affected = DB::table('aftersale')
            ->where('id', $aftersaleId)
            ->where('version', $expected)
            ->data($data)
            ->update();
        if (!$affected) {
            $this->logError('aftersale optimistic lock conflict', array(
                'aftersale_id' => $aftersaleId,
                'expected_version' => $expected,
                'current_version' => $currentVersion,
            ));
            return false;
        }

        DB::table('aftersale_log')->data(array(
            'aftersale_id' => $aftersaleId,
            'from_status' => $fromStatus,
            'to_status' => $newStatus,
            'action' => (string) $action,
            'operator_type' => (string) $operatorType,
            'operator_id' => (int) $operatorId,
            'note' => (string) $note,
            'attachment_token' => '',
            'ip' => (string) $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ))->insert();

        return true;
    }

    /**
     * 仅写一行售后日志（不迁移状态），用于「协商留言 / 备注」类动作。
     *
     * @param int $aftersaleId
     * @param string $action
     * @param string $operatorType
     * @param int $operatorId
     * @param string $note
     * @param string $ip
     * @return void
     */
    public function writeLog($aftersaleId, $action, $operatorType, $operatorId, $note, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $status = (string) DB::table('aftersale')->where('id', $aftersaleId)->value('status');
        DB::table('aftersale_log')->data(array(
            'aftersale_id' => $aftersaleId,
            'from_status' => $status,
            'to_status' => $status,
            'action' => (string) $action,
            'operator_type' => (string) $operatorType,
            'operator_id' => (int) $operatorId,
            'note' => (string) $note,
            'attachment_token' => '',
            'ip' => (string) $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ))->insert();
    }

    /**
     * @param string $message
     * @param array $payload
     * @return void
     */
    private function logError($message, array $payload = array())
    {
        Log::error($message, array(
            'channel' => 'aftersale',
            'module' => 'aftersale',
            'payload' => $payload,
        ));
    }
}
