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

namespace Dou\Front\Service\Aftersale;

use Dou\Core\Domain\Aftersale\AftersaleStatus;
use Dou\Core\Domain\Aftersale\AftersaleType;
use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Aftersale\AftersaleItemQuery;
use Dou\Core\Service\Aftersale\AftersaleRefundDispatcher;
use Dou\Core\Service\Aftersale\AftersaleService as AftersaleCore;
use Dou\Core\Service\Aftersale\AftersaleStatusTransition;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;
use Dou\Front\Model\Aftersale\Aftersale;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台售后服务业务层（买家申请 + 工作台处理）
 */
class AftersaleService extends BaseService
{
    /** @var AftersaleCore */
    private $lib;

    /** @var AftersaleStatusTransition */
    private $transition;

    /** @var AftersaleItemQuery */
    private $itemQuery;

    /** @var AftersaleRefundDispatcher */
    private $refundDispatcher;

    /**
     * @param AftersaleCore $lib
     * @param AftersaleStatusTransition $transition 售后状态机
     * @param AftersaleItemQuery $itemQuery 按行可申请余量查询
     * @param AftersaleRefundDispatcher $refundDispatcher 统一退款派发
     */
    public function __construct(AftersaleCore $lib, AftersaleStatusTransition $transition, AftersaleItemQuery $itemQuery, AftersaleRefundDispatcher $refundDispatcher)
    {
        $this->lib = $lib;
        $this->transition = $transition;
        $this->itemQuery = $itemQuery;
        $this->refundDispatcher = $refundDispatcher;
    }

    /**
     * 会员售后列表（已格式化，含订单快照）
     *
     * @param int $userId
     * @param int $page
     * @param string $pageUrl 分页基础 URL
     * @return array ['list' => array, 'pager' => string]
     */
    public function buildAftersaleUserListData($userId, $page, $pageUrl)
    {
        $pagerUrl = $pageUrl ?: null;
        $result = Aftersale::filterByUserId($userId)
            ->applyDefaultOrder()
            ->paginate(15, $page, $pagerUrl);

        $aftersaleList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $order = DB::table('order')
                ->field('order_sn, order_amount, shipped_at')
                ->where('id', intval($row['order_id']))
                ->find();

            $order['url'] = route('order.user.show', ['order_sn' => $order['order_sn']]);
            $order['shipped_at'] = date('Y-m-d', strtotime($order['shipped_at']));

            $aftersaleId = (int) $row['id'];
            $aftersaleCtx = currency()->contextForAftersale($row);
            $aftersaleList[] = array(
                'id' => $row['id'],
                'aftersale_sn' => $row['aftersale_sn'],
                'detail_url' => route('aftersale.user.show', array('id' => $aftersaleId)),
                'order' => $order,
                'aftersale_money' => Util::formatCurrency($row['refunded_amount'], $aftersaleCtx),
                'item_list' => $this->lib->getAftersaleItemList(
                    $row['order_id'],
                    $this->lib->getItemIdsByAftersaleId($aftersaleId)
                ),
                'img_list' => attachment()->gallery('aftersale', $row['id'], 'gallery', true),
                'reason' => $row['reason'],
                'handle_record' => $this->lib->getHandleRecordByAftersaleId($aftersaleId),
                'status' => language()->dataLangFormat('aftersale_status_', $row['status']),
                'is_handle' => ((string) $row['status'] !== AftersaleStatus::PENDING),
                'created_at' => date('Y-m-d H:i', strtotime($row['created_at'])),
            );
        }

        return array('list' => $aftersaleList, 'pager' => $result['pager']);
    }

    /**
     * 获取申请售后所需数据（含订单信息与商品清单）
     *
     * @param string $orderSn
     * @param int $userId
     * @return array ['order' => array|null, 'item_list' => array, 'img_list' => mixed, 'can_apply' => bool]
     */
    public function buildAftersaleApplyData($orderSn, $userId)
    {
        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', intval($userId))
            ->find();

        if (!$order) {
            return array('order' => null, 'item_list' => array(), 'img_list' => array(), 'can_apply' => false);
        }
        $order['order_id'] = (int) $order['id'];

        $canApply = (bool) $this->lib->ifCanAftersale($order['order_id'], $userId);

        // 申请页只展示仍有可退余量且有可退金额的行，避免已退完商品被重复勾选后静默失效。
        $refundable = $this->itemQuery->refundableItems($order['order_id']);
        $orderItemIds = array();
        $quantityMap = array();
        foreach ($refundable as $orderItemId => $line) {
            if ((int) $line['quantity_left'] > 0 && (float) $line['amount_left'] > 0) {
                $orderItemIds[] = (int) $orderItemId;
                $quantityMap[(int) $orderItemId] = (int) $line['quantity_left'];
            }
        }
        $itemList = empty($orderItemIds)
            ? array()
            : $this->lib->getAftersaleItemList($order['order_id'], array(), false, $orderItemIds, $quantityMap);

        return array(
            'order' => $order,
            'item_list' => $itemList,
            'img_list' => array(),
            'can_apply' => $canApply && !empty($itemList),
        );
    }

    /**
     * 提交售后申请（写库）
     *
     * @param string $orderSn
     * @param int $userId
     * @param string $reason
     * @param array $submittedItems order_item.id 列表（至少一项）
     * @param string $type 售后类型（refund_only / refund_with_return），由买家在申请页选择
     * @param string $draftToken 上传草稿令牌，提交后把草稿附件 claim 到真实 aftersale_id
     * @return array ['success' => bool, 'error' => string] error 为空表示成功
     */
    public function submitAftersaleApply($orderSn, $userId, $reason, array $submittedItems, $type = AftersaleType::REFUND_ONLY, $draftToken = '')
    {
        $type = AftersaleType::isValid($type) ? (string) $type : AftersaleType::REFUND_ONLY;
        $order = DB::table('order')
            ->field('id, module, mode, currency_code, currency_rate, currency_decimal')
            ->where('order_sn', $orderSn)
            ->where('user_id', intval($userId))
            ->find();

        if (!$order) {
            return array('success' => false, 'error' => 'order_wrong');
        }

        // 固定时限 + 一单只处理一次 + 模块策略统一由核心服务判定
        if (!$this->lib->ifCanAftersale((int) $order['id'], (int) $userId)) {
            return array('success' => false, 'error' => 'order_wrong');
        }

        $submittedItems = array_map('intval', $submittedItems);
        if (empty($submittedItems)) {
            return array('success' => false, 'error' => 'items_required');
        }

        // 行选择按 order_item.id，避免同商品多规格时整行全退
        $orderItems = DB::table('order_item')
            ->where('order_id', intval($order['id']))
            ->field('id')
            ->select();
        $validItemIds = array_map('intval', array_column($orderItems, 'id'));
        $invalid = array_diff($submittedItems, $validItemIds);
        if (!empty($invalid)) {
            return array('success' => false, 'error' => 'invalid_items');
        }

        $aftersaleSn = $this->lib->createAftersaleSn();

        // 申请明细行 + 申请金额：按所选 order_item.id 取订单行余量折算
        $applyLines = array();
        if (!empty($submittedItems)) {
            $refundable = $this->itemQuery->refundableItems((int) $order['id']);
            foreach ($refundable as $orderItemId => $line) {
                if (in_array((int) $orderItemId, $submittedItems, true) && $line['quantity_left'] > 0) {
                    $applyLines[] = array('order_item_id' => (int) $orderItemId, 'quantity' => $line['quantity_left']);
                }
            }
        }
        $applyAmount = 0.0;
        $resolved = $this->itemQuery->validateApplyLines((int) $order['id'], $applyLines);
        if ($resolved['valid']) {
            $applyAmount = $resolved['apply_amount'];
        }
        // 所选商品已无可退余量时不生成 0 元售后单，避免白占订单的再次申请资格
        if ($applyAmount <= 0) {
            return array('success' => false, 'error' => 'invalid_items');
        }

        DB::beginTransaction();
        try {
            // 申请只占售后资格，不改变 PAID，商家在审核期间仍可发货。
            $lockedOrder = DB::table('order')->where('id', (int) $order['id'])->lock('FOR UPDATE')->find();
            if (!$lockedOrder || !$this->lib->ifCanAftersale((int) $order['id'], (int) $userId)) {
                DB::rollback();
                return array('success' => false, 'error' => 'order_wrong');
            }
            // 一单只处理一次：并发/脏数据下再次确认该订单尚无实质处理的售后单
            $processed = DB::table('aftersale')
                ->where('order_id', intval($order['id']))
                ->where('status', 'NOT IN', array(AftersaleStatus::REJECTED, AftersaleStatus::CANCELLED))
                ->exists();
            if ($processed) {
                DB::rollback();
                return array('success' => false, 'error' => 'order_wrong');
            }

            // 原子占位：只有 allow_aftersale=1 的订单才能被本次申请占用，防并发重复申请。
            $claimed = DB::table('order')
                ->where('id', intval($order['id']))
                ->where(function ($q) {
                    $q->where('allow_aftersale', '1')->whereOr('status', OrderStatus::PAID);
                })
                ->data(array(
                    'allow_aftersale' => 0,
                    'aftersale_status' => 1,
                ))
                ->update();
            if (!$claimed) {
                DB::rollback();
                return array('success' => false, 'error' => 'order_wrong');
            }

            $aftersale = Aftersale::create(array(
                'aftersale_sn' => $aftersaleSn,
                'order_id' => $order['id'],
                'user_id' => $userId,
                'type' => $type,
                'currency_code' => isset($order['currency_code']) ? (string) $order['currency_code'] : '',
                'currency_rate' => isset($order['currency_rate']) ? (float) $order['currency_rate'] : 1,
                'currency_decimal' => isset($order['currency_decimal']) ? (int) $order['currency_decimal'] : 2,
                'apply_amount' => $applyAmount,
                'reason' => $reason,
                'status' => AftersaleStatus::PENDING,
                'created_at' => date('Y-m-d H:i:s'),
                'expired_at' => date('Y-m-d H:i:s', time() + 432000),
            ));
            if (!$aftersale) {
                DB::rollback();
                return array('success' => false, 'error' => 'save_failed');
            }
            $aftersaleId = (int) $aftersale['id'];

            // 写明细行
            if ($resolved['valid']) {
                foreach ($resolved['items'] as $line) {
                    DB::table('aftersale_item')->data(array(
                        'aftersale_id' => $aftersaleId,
                        'order_id' => (int) $order['id'],
                        'order_item_id' => (int) $line['order_item_id'],
                        'item_id' => (int) $line['item_id'],
                        'quantity' => (int) $line['quantity'],
                        'amount' => $line['amount'],
                        'reason' => (string) $reason,
                    ))->insert();
                }
            }

            // 申请时间线
            $this->transition->writeLog($aftersaleId, 'apply', 'user', $userId, (string) $reason);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return array('success' => false, 'error' => 'save_failed');
        }

        // 草稿附件提交后绑定到真实 aftersale_id，draft → owned；附件失败不回滚已创建的售后单。
        if ($draftToken !== '') {
            try {
                attachment()->claimByToken('aftersale', $draftToken, $aftersaleId, 'user', $userId);
            } catch (\Exception $e) {
                // 附件绑定失败不影响已创建的售后单，仅记录日志
                Log::warning('aftersale attachment claim failed: ' . $e->getMessage(), array('channel' => 'aftersale'));
            }
        }

        return array('success' => true, 'error' => '');
    }

    /**
     * 买家售后详情（含协商时间线、寄回物流、可执行动作）。
     *
     * @param int $aftersaleId
     * @param int $userId 当前登录会员（用于归属校验）
     * @return array|null 非本人或不存在返回 null
     */
    public function buildAftersaleShowData($aftersaleId, $userId)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersaleModel = Aftersale::find($aftersaleId);
        $aftersale = $aftersaleModel ? $aftersaleModel->getAttributes() : null;
        if (!$aftersale || (int) $aftersale['user_id'] !== (int) $userId) {
            return null;
        }

        $aftersale['id'] = (int) $aftersale['id'];
        $statusRaw = (string) $aftersale['status'];
        $typeRaw = (string) $aftersale['type'];
        $aftersale['status_raw'] = $statusRaw;
        $aftersale['type_format'] = AftersaleType::isValid($typeRaw)
            ? lang('aftersale_type_' . $typeRaw)
            : lang('aftersale_type_refund_only');
        $aftersale['status'] = language()->dataLangFormat('aftersale_status_', $statusRaw);
        $aftersaleCtx = currency()->contextForAftersale($aftersale);
        $aftersale['currency_code'] = $aftersaleCtx['code'];
        $aftersale['apply_amount_format'] = Util::formatCurrency((float) $aftersale['apply_amount'], $aftersaleCtx);
        $aftersale['refunded_amount_format'] = (float) $aftersale['refunded_amount'] > 0
            ? Util::formatCurrency((float) $aftersale['refunded_amount'], $aftersaleCtx)
            : lang('none');
        $aftersale['created_at'] = date('Y-m-d H:i:s', strtotime($aftersale['created_at']));

        $order = DB::table('order')
            ->field('order_sn, order_amount, shipped_at')
            ->where('id', intval($aftersale['order_id']))
            ->find();
        if ($order) {
            $order['order_id'] = (int) $aftersale['order_id'];
            $order['url'] = route('order.user.show', array('order_sn' => $order['order_sn']));
            $order['shipped_at'] = $order['shipped_at'] ? date('Y-m-d', strtotime($order['shipped_at'])) : '';
        }
        $aftersale['order'] = $order;

        $aftersale['item_list'] = $this->lib->getAftersaleItemList(
            $aftersale['order_id'],
            $this->lib->getItemIdsByAftersaleId($aftersaleId),
            true
        );
        $aftersale['img_list'] = attachment()->gallery('aftersale', $aftersaleId, 'gallery', true);
        $aftersale['timeline'] = $this->buildTimeline($aftersaleId);

        $returnInfo = DB::table('aftersale_return')->where('aftersale_id', $aftersaleId)->order('id DESC')->find();
        if ($returnInfo) {
            $shippedTs = Util::toTimestamp($returnInfo['shipped_at']);
            $returnInfo['shipped_at_format'] = $shippedTs !== null ? date('Y-m-d H:i', $shippedTs) : '';
        }
        $aftersale['return_info'] = $returnInfo;

        $aftersale['can_return_ship'] = ($statusRaw === AftersaleStatus::WAITING_RETURN);
        $aftersale['can_cancel'] = in_array($statusRaw, array(
            AftersaleStatus::PENDING,
            AftersaleStatus::WAITING_RETURN,
        ), true);

        return $aftersale;
    }

    /**
     * 组装售后协商时间线（dou_aftersale_log，倒序）。
     *
     * @param int $aftersaleId
     * @return array
     */
    private function buildTimeline($aftersaleId)
    {
        $logs = DB::table('aftersale_log')
            ->where('aftersale_id', (int) $aftersaleId)
            ->order('id DESC')
            ->select();

        $operatorLabels = array(
            'user' => lang('aftersale_operator_user'),
            'admin' => lang('aftersale_operator_admin'),
            'work' => lang('aftersale_operator_work'),
            'system' => lang('aftersale_operator_system'),
            'webhook' => lang('aftersale_operator_webhook'),
        );

        $timeline = array();
        foreach ((array) $logs as $log) {
            $fromFmt = language()->dataLangFormat('aftersale_status_', (string) $log['from_status']);
            $toFmt = language()->dataLangFormat('aftersale_status_', (string) $log['to_status']);
            $operatorType = (string) $log['operator_type'];
            $timeline[] = array(
                'from' => $fromFmt['format'] !== '' ? $fromFmt['format'] : (string) $log['from_status'],
                'to' => $toFmt['format'] !== '' ? $toFmt['format'] : (string) $log['to_status'],
                'operator' => isset($operatorLabels[$operatorType]) ? $operatorLabels[$operatorType] : $operatorType,
                'note' => (string) $log['note'],
                'created_at' => ($logTs = Util::toTimestamp($log['created_at'])) !== null ? date('Y-m-d H:i:s', $logTs) : '',
            );
        }
        return $timeline;
    }

    /**
     * 买家填写寄回运单（WAITING_RETURN → RETURN_SHIPPED）。
     *
     * @param int $aftersaleId
     * @param int $userId
     * @param string $shippingCompany
     * @param string $trackingNo
     * @param string $ip
     * @return array ['success' => bool, 'error' => string]
     */
    public function submitReturnShipping($aftersaleId, $userId, $shippingCompany, $trackingNo, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale || (int) $aftersale['user_id'] !== (int) $userId) {
            return array('success' => false, 'error' => lang('page_wrong'));
        }
        if ((string) $aftersale['status'] !== AftersaleStatus::WAITING_RETURN) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }
        if (trim((string) $shippingCompany) === '' || trim((string) $trackingNo) === '') {
            return array('success' => false, 'error' => lang('aftersale_return_required'));
        }

        // 写寄回物流（aftersale_id 唯一键，存在则更新）
        $exists = DB::table('aftersale_return')->where('aftersale_id', $aftersaleId)->find();
        $payload = array(
            'shipping_company' => trim((string) $shippingCompany),
            'tracking_no' => trim((string) $trackingNo),
            'shipped_at' => date('Y-m-d H:i:s'),
        );
        if ($exists) {
            DB::table('aftersale_return')->where('aftersale_id', $aftersaleId)->data($payload)->update();
        } else {
            $payload['aftersale_id'] = $aftersaleId;
            DB::table('aftersale_return')->data($payload)->insert();
        }

        $note = lang('aftersale_return_company') . '：' . $payload['shipping_company'] . '，' . lang('aftersale_return_sn') . '：' . $payload['tracking_no'];
        if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::RETURN_SHIPPED, 'ship', 'user', (int) $userId, (string) $ip, $note)) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        return array('success' => true, 'error' => '');
    }

    /**
     * 买家撤销售后（PENDING / WAITING_RETURN → CANCELLED）。
     *
     * @param int $aftersaleId
     * @param int $userId
     * @param string $ip
     * @return array ['success' => bool, 'error' => string]
     */
    public function cancelAftersale($aftersaleId, $userId, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale || (int) $aftersale['user_id'] !== (int) $userId) {
            return array('success' => false, 'error' => lang('page_wrong'));
        }
        $allowed = array(AftersaleStatus::PENDING, AftersaleStatus::WAITING_RETURN);
        if (!in_array((string) $aftersale['status'], $allowed, true)) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::CANCELLED, 'cancel', 'user', (int) $userId, (string) $ip)) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        // 撤销后仅清空当前售后标记；是否恢复申请入口由固定时限与「一单只处理一次」规则统一判定
        DB::table('order')->where('id', (int) $aftersale['order_id'])->data(array(
            'aftersale_status' => 0,
        ))->update();
        $this->lib->restoreAftersaleEligibility((int) $aftersale['order_id']);

        return array('success' => true, 'error' => '');
    }

    /**
     * 工作人员售后列表（分页，可按状态筛选）
     *
     * @param string $status 'all' 或具体状态字符串
     * @param int $page
     * @param int $pageSize
     * @return array ['list' => array, 'pager' => mixed, 'status_list' => array]
     */
    public function buildAftersaleWorkListData($status, $page, $pageSize = 10)
    {
        $query = DB::table('aftersale');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $result = $query->order('id DESC')->paginate($pageSize, $page);

        $aftersaleList = array();
        foreach ((array) $result['list'] as $row) {
            $order = DB::table('order')
                ->field('order_sn, order_amount, shipped_at')
                ->where('id', intval($row['order_id']))
                ->find();

            $aftersaleId = (int) $row['id'];
            $aftersaleCtx = currency()->contextForAftersale($row);
            $aftersaleList[] = array(
                'id' => $row['id'],
                'aftersale_sn' => $row['aftersale_sn'],
                'order' => $order,
                'aftersale_money' => Util::formatCurrency($row['refunded_amount'], $aftersaleCtx),
                'item_list' => $this->lib->getAftersaleItemList(
                    $row['order_id'],
                    $this->lib->getItemIdsByAftersaleId($aftersaleId)
                ),
                'reason' => $row['reason'],
                'handle_record' => $this->lib->getHandleRecordByAftersaleId($aftersaleId),
                'status' => language()->dataLangFormat('aftersale_status_', $row['status']),
                'is_handle' => ((string) $row['status'] !== AftersaleStatus::PENDING),
                'created_at' => date('Y-m-d H:i', strtotime($row['created_at'])),
            );
        }

        return array(
            'list' => $aftersaleList,
            'pager' => $result['pager'],
            'status_list' => language()->dataListLangFormat('aftersale_status_', 'all,pending,approved,refunding,refunded,rejected,cancelled', $status),
        );
    }

    /**
     * 工作人员售后单详情
     *
     * @param int $aftersaleId
     * @return array|null
     */
    public function buildAftersaleWorkShowData($aftersaleId)
    {
        $aftersaleModel = Aftersale::find(intval($aftersaleId));
        $aftersale = $aftersaleModel ? $aftersaleModel->getAttributes() : null;
        if (!$aftersale) {
            return null;
        }

        $aftersaleId = (int) $aftersale['id'];
        $aftersale['id'] = $aftersaleId;
        $refundedAmount = (float) $aftersale['refunded_amount'];
        $approvedAmount = (float) $aftersale['approved_amount'];
        $aftersaleCtx = currency()->contextForAftersale($aftersale);
        $aftersale['currency_code'] = $aftersaleCtx['code'];
        $aftersale['aftersale_money'] = $refundedAmount > 0
            ? $refundedAmount
            : ($approvedAmount > 0 ? $approvedAmount : (float) $aftersale['apply_amount']);
        $aftersale['aftersale_money_format'] = $refundedAmount > 0
            ? Util::formatCurrency($refundedAmount, $aftersaleCtx)
            : lang('none');
        $aftersale['handle_record'] = $this->lib->getHandleRecordByAftersaleId($aftersaleId);
        $aftersale['is_handle'] = ((string) $aftersale['status'] !== AftersaleStatus::PENDING);
        $aftersale['status'] = language()->dataLangFormat('aftersale_status_', $aftersale['status']);
        $aftersale['created_at'] = date('Y-m-d H:i:s', strtotime($aftersale['created_at']));

        $order = DB::table('order')
            ->field('order_sn, order_amount, shipped_at')
            ->where('id', intval($aftersale['order_id']))
            ->find();
        if ($order) {
            $order['order_id'] = (int) $aftersale['order_id'];
            $order['shipped_at'] = date('Y-m-d', strtotime($order['shipped_at']));
        }
        $aftersale['order'] = $order;

        $user = DB::table('user')
            ->field('user_sn, email')
            ->where('id', intval($aftersale['user_id']))
            ->find();
        if ($user) {
            $user['user_id'] = (int) $aftersale['user_id'];
        }
        $aftersale['user'] = $user;

        $aftersale['item_list'] = $this->lib->getAftersaleItemList(
            $aftersale['order_id'],
            $this->lib->getItemIdsByAftersaleId($aftersaleId),
            true
        );
        $aftersale['img_list'] = attachment()->gallery(
            'aftersale',
            $aftersaleId,
            'gallery',
            true
        );

        return $aftersale;
    }

    /**
     * 工作人员处理售后单
     *
     * @param int $aftersaleId
     * @param string $aftersaleMoney 退款金额
     * @param string $handleRecord 处理备注
     * @param array $itemList order_item.id 列表
     * @param int $operatorId 工作人员（会员）ID
     * @param string $ip 操作 IP（控制器从 Request 显式传入）
     * @return array ['success' => bool, 'error' => string]
     */
    public function handleAftersaleWorkOrder($aftersaleId, $aftersaleMoney, $handleRecord, array $itemList, $operatorId = 0, $ip = '')
    {
        if (empty($handleRecord)) {
            return array('success' => false, 'error' => lang('aftersale_handle_record') . lang('is_empty'));
        }

        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            return array('success' => false, 'error' => lang('page_wrong'));
        }
        if ((string) $aftersale['status'] !== AftersaleStatus::PENDING) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        $order = DB::table('order')
            ->field('id, order_sn, module, mode, item_link_status, order_amount')
            ->where('id', intval($aftersale['order_id']))
            ->where('status', 'IN', array(OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED))
            ->find();
        if (!$order) {
            return array('success' => false, 'error' => lang('order_wrong'));
        }
        if (!OrderPolicy::isRefundable($order['module'])) {
            return array('success' => false, 'error' => lang('order_aftersale_not_supported'));
        }
        // 积分兑换订单禁止售后退款：工作台审核入口显式拦截，避免误报「金额超限」
        if (isset($order['mode']) && (string) $order['mode'] === 'point') {
            return array('success' => false, 'error' => lang('order_aftersale_not_supported'));
        }
        $order['order_id'] = (int) $order['id'];

        $money = round((float) $aftersaleMoney, 2);
        if ($money <= 0) {
            return array('success' => false, 'error' => lang('aftersale_money_invalid'));
        }

        if (!empty($itemList)) {
            $itemList = array_map('intval', $itemList);
            $orderItems = DB::table('order_item')
                ->where('order_id', intval($order['order_id']))
                ->field('id')
                ->select();
            $validItemIds = array_map('intval', array_column($orderItems, 'id'));
            $invalid = array_diff($itemList, $validItemIds);
            if (!empty($invalid)) {
                return array('success' => false, 'error' => lang('aftersale_invalid_items'));
            }
        }

        // 存在待确认网关退款时不可重复派发，先由管理员确认或标记失败
        $hasPendingRefund = DB::table('order_refund')
            ->where('aftersale_id', $aftersaleId)
            ->where('status', 'pending')
            ->exists();
        if ($hasPendingRefund) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        DB::beginTransaction();
        try {
            // 锁售后单并复核状态，防并发重复处理
            $lockedAftersale = DB::table('aftersale')->where('id', $aftersaleId)->lock('FOR UPDATE')->find();
            if (!$lockedAftersale || (string) $lockedAftersale['status'] !== AftersaleStatus::PENDING) {
                DB::rollback();
                return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
            }

            // 锁订单行，串行化同一订单的可退金额校验与审核
            $lockedOrder = DB::table('order')
                ->field('id, status, module')
                ->where('id', (int) $lockedAftersale['order_id'])
                ->lock('FOR UPDATE')
                ->find();
            if (!$lockedOrder || !in_array((string) $lockedOrder['status'], array(OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED), true)) {
                DB::rollback();
                return array('success' => false, 'error' => lang('order_wrong'));
            }
            if (!OrderPolicy::isRefundable((string) $lockedOrder['module'])) {
                DB::rollback();
                return array('success' => false, 'error' => lang('order_aftersale_not_supported'));
            }

            $available = $this->refundDispatcher->availableRefundAmount((int) $lockedOrder['id']);
            if ($money > $available + 0.005) {
                DB::rollback();
                return array('success' => false, 'error' => lang('aftersale_money_over'));
            }

            // 先按最终商品集合重写明细，保证退款落定副作用使用正确行
            if (!empty($itemList) && !$this->rewriteAftersaleItems($aftersaleId, (int) $lockedOrder['id'], $itemList)) {
                DB::rollback();
                return array('success' => false, 'error' => lang('aftersale_invalid_items'));
            }

            DB::table('aftersale')->where('id', $aftersaleId)->data(array(
                'approved_amount' => $money,
                'handled_at' => date('Y-m-d H:i:s'),
            ))->update();
            if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::APPROVED, 'approve', 'work', (int) $operatorId, (string) $ip, $handleRecord)) {
                DB::rollback();
                return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        // 统一走退款派发：钱包腿同步退回、网关腿登记 pending；落定副作用由 settle 统一处理
        if (!$this->refundDispatcher->dispatch($aftersaleId, 'work', (int) $operatorId, (string) $ip)) {
            return array('success' => false, 'error' => lang('aftersale_handle_wrong'));
        }

        return array('success' => true, 'error' => '');
    }

    /**
     * 重写售后单的商品明细行（drop & reinsert）。
     *
     * 工作台 handle 时若 item_list 被修改，按新集合重写 dou_aftersale_item；
     * 数量取订单行余量、金额按 AftersaleItemQuery 摊分（与申请时一致）。
     *
     * @param int $aftersaleId
     * @param int $orderId
     * @param array $itemIds 模板 POST 上来的 order_item.id 列表
     * @return bool true=重写成功；false=商品集无效（调用方应回滚）
     */
    private function rewriteAftersaleItems($aftersaleId, $orderId, array $itemIds)
    {
        // 先算后删：重写自身明细时排除当前售后单，避免把本单数量算成“已占用”
        $refundable = $this->itemQuery->refundableItems($orderId, $aftersaleId);
        $applyLines = array();
        $itemIdsInt = array_map('intval', $itemIds);
        foreach ($refundable as $orderItemId => $line) {
            if (in_array((int) $orderItemId, $itemIdsInt, true) && $line['quantity_left'] > 0) {
                $applyLines[] = array('order_item_id' => $orderItemId, 'quantity' => $line['quantity_left']);
            }
        }
        if (empty($applyLines)) {
            return false;
        }
        $resolved = $this->itemQuery->validateApplyLines($orderId, $applyLines, $aftersaleId);
        if (!$resolved['valid']) {
            return false;
        }

        DB::table('aftersale_item')->where('aftersale_id', $aftersaleId)->delete();
        foreach ($resolved['items'] as $line) {
            DB::table('aftersale_item')->data(array(
                'aftersale_id' => $aftersaleId,
                'order_id' => $orderId,
                'order_item_id' => (int) $line['order_item_id'],
                'item_id' => (int) $line['item_id'],
                'quantity' => (int) $line['quantity'],
                'amount' => $line['amount'],
                'reason' => '',
            ))->insert();
        }

        return true;
    }
}
