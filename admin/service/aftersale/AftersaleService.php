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

namespace Dou\Admin\Service\Aftersale;

use Dou\Admin\Model\Aftersale\Aftersale;
use Dou\Core\Domain\Aftersale\AftersaleStatus;
use Dou\Core\Domain\Aftersale\AftersaleType;
use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Service\Aftersale\AftersaleRefundDispatcher;
use Dou\Core\Service\Aftersale\AftersaleService as AftersaleCore;
use Dou\Core\Service\Aftersale\AftersaleStatusTransition;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台售后业务（route=aftersale/...）
 */
class AftersaleService extends BaseService
{
    /** @var AftersaleCore */
    private $lib;

    /** @var AftersaleStatusTransition */
    private $transition;

    /** @var AftersaleRefundDispatcher */
    private $dispatcher;

    /**
     * @param AftersaleCore $lib
     * @param AftersaleStatusTransition $transition 售后状态机
     * @param AftersaleRefundDispatcher $dispatcher 售后退款派发 / 网关退款收尾
     */
    public function __construct(AftersaleCore $lib, AftersaleStatusTransition $transition, AftersaleRefundDispatcher $dispatcher)
    {
        $this->lib = $lib;
        $this->transition = $transition;
        $this->dispatcher = $dispatcher;
    }

    /**
     * 同步 SESSION 筛选条件到当前请求合并结果。
     *
     * @param array $merged 调用方从 Request::all() 取好后显式传入
     * @return array
     */
    private function mergeAdminListSessionInput(array $merged)
    {
        if (!empty($merged['key'])) {
            $_SESSION['req'] = $merged;
        } else {
            if (isset($merged['menu'])) {
                unset($_SESSION['req']);
            }
            if (!empty($_SESSION['req']) && is_array($_SESSION['req'])) {
                foreach ($_SESSION['req'] as $key => $value) {
                    $merged[$key] = $value;
                }
            }
        }
        return $merged;
    }

    /**
     * 后台售后列表页数据
     *
     * @param array $rawInput 调用方从 Request::all() 取好后显式传入（合并 SESSION 缓存的筛选条件）
     * @return array aftersale_list, pager, req
     */
    public function buildAftersaleListData(array $rawInput)
    {
        $req = $this->mergeAdminListSessionInput($rawInput);

        $rawSn = Arr::get($req, 'aftersale_sn', '');
        $aftersale_sn = Check::number($rawSn) ? $rawSn : '';
        $time_start = isset($req['time_start']) ? trim($req['time_start']) : '';
        $time_end = isset($req['time_end']) ? trim($req['time_end']) : '';

        $page_url = route('admin.aftersale');
        if ($aftersale_sn !== '') {
            $page_url .= '&aftersale_sn=' . $aftersale_sn;
        }
        if ($time_start !== '') {
            $page_url .= '&time_start=' . $time_start;
        }
        if ($time_end !== '') {
            $page_url .= '&time_end=' . $time_end;
        }

        $page = Check::number(Arr::get($req, 'page', ''))
            ? (int) $req['page'] : 1;
        $result = Aftersale::filterByAftersaleSn($aftersale_sn)
            ->filterByTimeStart($time_start)
            ->filterByTimeEnd($time_end)
            ->applyDefaultOrder()
            ->paginate(25, $page, Util::normalizeQueryString($page_url));

        $aftersale_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $order = DB::table('order')
                ->field('order_sn, order_amount, shipped_at')
                ->where('id', intval($row['order_id']))
                ->find();

            if (!is_array($order)) {
                $order = array(
                    'order_id' => (int) $row['order_id'],
                    'order_sn' => '',
                    'order_amount' => '',
                    'shipped_at' => '',
                    'product' => array(
                        'name' => '',
                    ),
                );
            } else {
                $order['order_id'] = (int) $row['order_id'];
                $shippingTs = !empty($order['shipped_at']) ? strtotime($order['shipped_at']) : 0;
                $order['shipped_at'] = $shippingTs ? date('Y-m-d', $shippingTs) : '';
                $itemName = DB::table('order_item')
                    ->where('order_id', intval($row['order_id']))
                    ->order('id ASC')
                    ->value('name');
                $order['product'] = array(
                    'name' => $itemName ? $itemName : '',
                );
            }

            $user = DB::table('user')
                ->field('id, user_sn, email')
                ->where('id', intval($row['user_id']))
                ->find();
            if (is_array($user)) {
                $user['user_id'] = (int) $row['user_id'];
            }

            $statusRaw = $model->getRawAttribute('status');
            $statusFmt = language()->dataLangFormat('aftersale_status_', $statusRaw);
            $aftersaleId = (int) $row['id'];
            $refundedAmount = (float) $row['refunded_amount'];
            $aftersaleCtx = currency()->contextForAftersale($row);
            $aftersale_list[] = array(
                'id' => $row['id'],
                'aftersale_sn' => $row['aftersale_sn'],
                'order' => $order,
                'user' => $user,
                'currency_code' => $aftersaleCtx['code'],
                'aftersale_money' => $refundedAmount > 0 ? Util::formatCurrency($refundedAmount, $aftersaleCtx) : '无退款',
                'item_list' => $this->lib->getAftersaleItemList(
                    $row['order_id'],
                    $this->lib->getItemIdsByAftersaleId($aftersaleId)
                ),
                'reason' => $row['reason'],
                'handle_record' => $this->lib->getHandleRecordByAftersaleId($aftersaleId),
                'status' => array(
                    'value' => isset($statusFmt['value']) ? $statusFmt['value'] : $statusRaw,
                    'format' => Arr::get($statusFmt, 'format', ''),
                    'class' => '',
                ),
                'is_handle' => ((string) $statusRaw !== AftersaleStatus::PENDING),
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'aftersale_list' => $aftersale_list,
            'pager' => $result['pager'],
            'req' => array(
                'aftersale_sn' => Arr::get($req, 'aftersale_sn', ''),
                'time_start' => $time_start,
                'time_end' => $time_end,
            ),
        );
    }

    /**
     * 详情页数据（含解锁标记）
     *
     * @param string $aftersale_id
     * @param string $unlock
     * @return array|null
     */
    public function buildAftersaleViewData($aftersale_id, $unlock)
    {
        if ($aftersale_id === '' || $aftersale_id === null) {
            return null;
        }

        $aftersaleModel = Aftersale::find(intval($aftersale_id));
        $aftersale = $aftersaleModel ? $aftersaleModel->getAttributes() : null;
        if (!$aftersale) {
            return null;
        }

        $aftersaleId = (int) $aftersale['id'];
        $aftersale['id'] = $aftersaleId;
        $statusRaw = (string) $aftersale['status'];
        $typeRaw = (string) $aftersale['type'];
        $refundedAmount = (float) $aftersale['refunded_amount'];
        $approvedAmount = (float) $aftersale['approved_amount'];
        $applyAmount = (float) $aftersale['apply_amount'];

        $aftersale['status_raw'] = $statusRaw;
        $aftersale['type_raw'] = $typeRaw;
        $aftersale['type_format'] = AftersaleType::isValid($typeRaw)
            ? lang('aftersale_type_' . $typeRaw)
            : lang('aftersale_type_refund_only');
        $aftersale['requires_return'] = AftersaleType::requiresReturn($typeRaw);

        // 表单回填默认值：审核额优先，否则申请额（金额口径为订单币）
        $aftersaleCtx = currency()->contextForAftersale($aftersale);
        $aftersale['currency_code'] = $aftersaleCtx['code'];
        $aftersale['approve_default'] = $approvedAmount > 0 ? $approvedAmount : $applyAmount;
        $aftersale['apply_amount_format'] = Util::formatCurrency($applyAmount, $aftersaleCtx);
        $aftersale['approved_amount_format'] = $approvedAmount > 0 ? Util::formatCurrency($approvedAmount, $aftersaleCtx) : lang('none');
        $aftersale['refunded_amount_format'] = $refundedAmount > 0 ? Util::formatCurrency($refundedAmount, $aftersaleCtx) : lang('none');

        $statusFmt = language()->dataLangFormat('aftersale_status_', $statusRaw);
        $aftersale['status'] = array(
            'value' => isset($statusFmt['value']) ? $statusFmt['value'] : $statusRaw,
            'format' => Arr::get($statusFmt, 'format', ''),
        );
        $aftersale['created_at'] = date('Y-m-d H:i:s', strtotime($aftersale['created_at']));

        $aftersale['order'] = DB::table('order')
            ->field('order_sn, order_amount, shipped_at')
            ->where('id', intval($aftersale['order_id']))
            ->find();
        if (!$aftersale['order']) {
            return null;
        }
        $aftersale['order']['order_id'] = (int) $aftersale['order_id'];
        $aftersale['order']['order_amount_format'] = Util::formatBasePrice($aftersale['order']['order_amount']);
        if (!empty($aftersale['order']['shipped_at'])) {
            $aftersale['order']['shipped_at'] = date('Y-m-d', strtotime($aftersale['order']['shipped_at']));
        }

        $aftersale['user'] = DB::table('user')
            ->field('id, user_sn, email')
            ->where('id', intval($aftersale['user_id']))
            ->find();
        if (is_array($aftersale['user'])) {
            $aftersale['user']['user_id'] = (int) $aftersale['user_id'];
        }

        $aftersale['item_list'] = $this->lib->getAftersaleItemList(
            $aftersale['order_id'],
            $this->lib->getItemIdsByAftersaleId($aftersaleId),
            true
        );
        $aftersale['img_list'] = attachment()->gallery(
            'aftersale',
            $aftersale['id'],
            'gallery',
            true
        );

        // 协商时间线 + 寄回物流 + 待确认网关退款
        $aftersale['timeline'] = $this->buildTimeline($aftersaleId);
        $aftersale['return_info'] = $this->buildReturnInfo($aftersaleId);
        $aftersale['pending_refunds'] = $this->buildPendingRefunds((int) $aftersale['order']['order_id']);

        // 状态机可用动作（按当前态 + 类型）
        $aftersale['can_approve'] = ($statusRaw === AftersaleStatus::PENDING);
        $aftersale['can_reject'] = in_array($statusRaw, array(
            AftersaleStatus::PENDING,
            AftersaleStatus::RETURN_SHIPPED,
            AftersaleStatus::RECEIVED,
            AftersaleStatus::REFUND_FAILED,
        ), true);
        $aftersale['can_receive'] = ($statusRaw === AftersaleStatus::RETURN_SHIPPED);
        $aftersale['can_dispatch'] = in_array($statusRaw, array(
            AftersaleStatus::APPROVED,
            AftersaleStatus::RECEIVED,
            AftersaleStatus::REFUND_FAILED,
        ), true);
        $aftersale['has_pending_refunds'] = !empty($aftersale['pending_refunds']);

        return array(
            'aftersale' => $aftersale,
            'unlock' => $unlock,
        );
    }

    /**
     * 组装售后协商时间线（dou_aftersale_log，倒序）。
     *
     * @param int $aftersaleId
     * @return array 每项 from/to 状态文案、动作、操作者、备注、时间
     */
    private function buildTimeline($aftersaleId)
    {
        $logs = DB::table('aftersale_log')
            ->where('aftersale_id', (int) $aftersaleId)
            ->order('id DESC')
            ->select();

        $operatorLabels = array(
            'user' => '买家',
            'admin' => '管理员',
            'work' => '工作人员',
            'system' => '系统',
            'webhook' => '网关回调',
        );

        $timeline = array();
        foreach ((array) $logs as $log) {
            $fromFmt = language()->dataLangFormat('aftersale_status_', (string) $log['from_status']);
            $toFmt = language()->dataLangFormat('aftersale_status_', (string) $log['to_status']);
            $operatorType = (string) $log['operator_type'];
            $timeline[] = array(
                'from' => Arr::get($fromFmt, 'format', (string) $log['from_status']),
                'to' => Arr::get($toFmt, 'format', (string) $log['to_status']),
                'action' => (string) $log['action'],
                'operator' => isset($operatorLabels[$operatorType]) ? $operatorLabels[$operatorType] : $operatorType,
                'note' => (string) $log['note'],
                'created_at' => ($logTs = Util::toTimestamp($log['created_at'])) !== null ? date('Y-m-d H:i:s', $logTs) : '',
            );
        }
        return $timeline;
    }

    /**
     * 取最新一条买家寄回物流（dou_aftersale_return）。
     *
     * @param int $aftersaleId
     * @return array|null
     */
    private function buildReturnInfo($aftersaleId)
    {
        $return = DB::table('aftersale_return')
            ->where('aftersale_id', (int) $aftersaleId)
            ->order('id DESC')
            ->find();
        if (!$return) {
            return null;
        }
        $shippedTs = Util::toTimestamp($return['shipped_at']);
        $return['shipped_at_format'] = $shippedTs !== null ? date('Y-m-d H:i', $shippedTs) : '';
        return $return;
    }

    /**
     * 取该订单待确认的网关退款腿（dou_order_refund status=pending）。
     *
     * @param int $orderId
     * @return array
     */
    private function buildPendingRefunds($orderId)
    {
        $rows = DB::table('order_refund')
            ->where('order_id', (int) $orderId)
            ->where('status', 'pending')
            ->order('id DESC')
            ->select();

        $list = array();
        foreach ((array) $rows as $row) {
            $list[] = array(
                'refund_sn' => (string) $row['refund_sn'],
                'channel' => (string) $row['channel'],
                'amount' => Util::formatPrice((float) $row['amount']),
                'created_at' => ($createdAtTs = Util::toTimestamp($row['created_at'])) !== null ? date('Y-m-d H:i', $createdAtTs) : '',
            );
        }
        return $list;
    }

    /**
     * 详情页 URL。
     *
     * @param int $aftersaleId
     * @return string
     */
    private function showUrl($aftersaleId)
    {
        return route('admin.aftersale.show', array('id' => (int) $aftersaleId));
    }

    /**
     * 审核同意（route=aftersale/approve）：PENDING → APPROVED，登记审核退款金额。
     *
     * 退货退款类型同意后进入 WAITING_RETURN（等买家寄回）；仅退款类型留在 APPROVED，
     * 由管理员点「派发退款」进入退款流程。
     *
     * @param int $aftersaleId
     * @param float $approvedAmount 审核通过的退款金额
     * @param string $note 审核备注（写入协商时间线）
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function approve($aftersaleId, $approvedAmount, $note, $adminId, $ip)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        if ((string) $aftersale['status'] !== AftersaleStatus::PENDING) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        $order = DB::table('order')->where('id', (int) $aftersale['order_id'])->find();
        if (!$order || !OrderPolicy::isRefundable($order['module'])) {
            throw new DomainException(lang('order_aftersale_not_supported'), '');
        }
        // 积分兑换订单不允许退款
        if (isset($order['mode']) && (string) $order['mode'] === 'point') {
            throw new DomainException(lang('order_aftersale_not_supported'), '');
        }

        $money = round((float) $approvedAmount, 2);
        if ($money <= 0) {
            throw new DomainException(lang('aftersale_money_invalid'), '');
        }

        DB::beginTransaction();
        try {
            // 锁售后单并复核状态，防止并发审核在「已同意」后再次改写金额
            $lockedAftersale = DB::table('aftersale')->where('id', $aftersaleId)->lock('FOR UPDATE')->find();
            if (!$lockedAftersale || (string) $lockedAftersale['status'] !== AftersaleStatus::PENDING) {
                throw new DomainException(lang('aftersale_handle_wrong'), '');
            }

            // 锁订单行，串行化同一订单的金额校验
            if (!DB::table('order')->where('id', (int) $lockedAftersale['order_id'])->lock('FOR UPDATE')->value('id')) {
                throw new DomainException(lang('item_not_exist'), '');
            }
            $available = $this->dispatcher->availableRefundAmount((int) $lockedAftersale['order_id']);
            if ($money > $available + 0.005) {
                throw new DomainException(lang('aftersale_money_over_available'), '');
            }

            DB::table('aftersale')->where('id', $aftersaleId)->data(array('approved_amount' => $money))->update();
            if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::APPROVED, 'approve', 'admin', (int) $adminId, (string) $ip, (string) $note)) {
                throw new DomainException(lang('aftersale_handle_wrong'), '');
            }

            // 退货退款 → 待买家寄回；仅退款 → 停在 APPROVED 等待派发退款
            if (AftersaleType::requiresReturn($lockedAftersale['type'])) {
                if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::WAITING_RETURN, 'approve', 'admin', (int) $adminId, (string) $ip)) {
                    throw new DomainException(lang('aftersale_handle_wrong'), '');
                }
            }

            Aftersale::whereKey($aftersaleId)->update(array('admin_id' => (int) $adminId, 'handled_at' => date('Y-m-d H:i:s')));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        throw new RedirectException($this->showUrl($aftersaleId));
    }

    /**
     * 驳回售后（route=aftersale/reject）：PENDING/RETURN_SHIPPED/RECEIVED/REFUND_FAILED → REJECTED。
     *
     * 退款失败（REFUND_FAILED）时驳回表示「关闭售后且不再退款」：
     * 先释放待确认网关退款占用，再把订单从退款中恢复到退款前状态。
     *
     * @param int $aftersaleId
     * @param string $note 驳回理由
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function reject($aftersaleId, $note, $adminId, $ip)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        $allowed = array(
            AftersaleStatus::PENDING,
            AftersaleStatus::RETURN_SHIPPED,
            AftersaleStatus::RECEIVED,
            AftersaleStatus::REFUND_FAILED,
        );
        $fromStatus = (string) $aftersale['status'];
        if (!in_array($fromStatus, $allowed, true)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        if ($fromStatus === AftersaleStatus::REFUND_FAILED) {
            // 关闭前释放 pending 网关退款，避免关单后网关晚到账造成账实不符
            $this->dispatcher->failPendingRefunds($aftersaleId, 'aftersale closed by admin');
            if (!$this->dispatcher->restoreOrderAfterRefundClosed($aftersaleId, 'admin', (int) $adminId, (string) $ip)) {
                throw new DomainException(lang('aftersale_handle_wrong'), $this->showUrl($aftersaleId));
            }
        }

        if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::REJECTED, 'reject', 'admin', (int) $adminId, (string) $ip, (string) $note)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        Aftersale::whereKey($aftersaleId)->update(array('admin_id' => (int) $adminId, 'handled_at' => date('Y-m-d H:i:s')));
        // 售后被驳回，订单恢复可再申请；仍有其他未结售后时保留售后占位
        $hasOtherOpen = DB::table('aftersale')
            ->where('order_id', (int) $aftersale['order_id'])
            ->where('status', 'NOT IN', AftersaleStatus::terminal())
            ->exists();
        if (!$hasOtherOpen) {
            // 此前因同单其他售后未结而保持退款中的订单，在此一并收尾恢复
            if (!$this->dispatcher->restoreOrderAfterRefundClosed($aftersaleId, 'admin', (int) $adminId, (string) $ip)) {
                throw new DomainException(lang('aftersale_handle_wrong'), $this->showUrl($aftersaleId));
            }
            DB::table('order')->where('id', (int) $aftersale['order_id'])->data(array(
                'aftersale_status' => 0,
            ))->update();
            $this->lib->restoreAftersaleEligibility((int) $aftersale['order_id']);
        }

        throw new RedirectException($this->showUrl($aftersaleId));
    }

    /**
     * 确认收货（route=aftersale/receive）：RETURN_SHIPPED → RECEIVED。
     *
     * @param int $aftersaleId
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function receive($aftersaleId, $adminId, $ip)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        if ((string) $aftersale['status'] !== AftersaleStatus::RETURN_SHIPPED) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::RECEIVED, 'receive', 'admin', (int) $adminId, (string) $ip)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        throw new RedirectException($this->showUrl($aftersaleId));
    }

    /**
     * 派发退款（route=aftersale/dispatch_refund）：APPROVED/RECEIVED/REFUND_FAILED → REFUNDING → 自动退钱包腿。
     *
     * 钱包腿同步到账；网关腿落 pending，待管理员在网关后台完成后点「确认网关退款」收尾。
     *
     * @param int $aftersaleId
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function dispatchRefund($aftersaleId, $adminId, $ip)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        $allowed = array(AftersaleStatus::APPROVED, AftersaleStatus::RECEIVED, AftersaleStatus::REFUND_FAILED);
        if (!in_array((string) $aftersale['status'], $allowed, true)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }
        $order = DB::table('order')->where('id', (int) $aftersale['order_id'])->find();
        if (!$order || !OrderPolicy::isRefundable($order['module'])) {
            throw new DomainException(lang('order_aftersale_not_supported'), '');
        }
        // 积分兑换订单不允许退款
        if (isset($order['mode']) && (string) $order['mode'] === 'point') {
            throw new DomainException(lang('order_aftersale_not_supported'), '');
        }

        if (!$this->dispatcher->dispatch($aftersaleId, 'admin', (int) $adminId, (string) $ip)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        throw new RedirectException($this->showUrl($aftersaleId));
    }

    /**
     * 确认网关退款已完成（route=aftersale/confirm_gateway_refund）。
     *
     * @param string $refundSn 待确认的退款单号
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function confirmGatewayRefund($refundSn, $adminId, $ip)
    {
        $refundSn = (string) $refundSn;
        $refund = DB::table('order_refund')->where('refund_sn', $refundSn)->find();
        if (!$refund) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        $aftersaleId = (int) $refund['aftersale_id'];

        if (!$this->dispatcher->confirmGatewayRefund($refundSn, 'admin', (int) $adminId, (string) $ip)) {
            throw new DomainException(lang('aftersale_handle_wrong'), $aftersaleId > 0 ? $this->showUrl($aftersaleId) : route('admin.aftersale'));
        }

        if ($aftersaleId > 0) {
            throw new RedirectException($this->showUrl($aftersaleId));
        }
        throw new RedirectException(route('admin.aftersale'));
    }

    /**
     * 标记网关退款失败（route=aftersale/fail_gateway_refund）。
     *
     * 超时转 REFUND_FAILED 后，管理员在网关后台确认未退款时使用；
     * 释放 pending 占用并把 REFUNDING 收敛为 REFUND_FAILED，之后可重新派发退款。
     *
     * @param int $aftersaleId
     * @param int $adminId
     * @param string $ip
     * @return void
     * @throws DomainException
     */
    public function failGatewayRefund($aftersaleId, $adminId, $ip)
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            throw new DomainException(lang('item_not_exist'), route('admin.aftersale'));
        }
        $allowed = array(AftersaleStatus::REFUNDING, AftersaleStatus::REFUND_FAILED);
        if (!in_array((string) $aftersale['status'], $allowed, true)) {
            throw new DomainException(lang('aftersale_handle_wrong'), '');
        }

        $this->dispatcher->failPendingRefunds($aftersaleId, 'manual by admin');

        if ((string) $aftersale['status'] === AftersaleStatus::REFUNDING) {
            if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::REFUND_FAILED, 'refund', 'admin', (int) $adminId, (string) $ip, '管理员标记网关退款失败')) {
                throw new DomainException(lang('aftersale_handle_wrong'), $this->showUrl($aftersaleId));
            }
        } else {
            $this->transition->writeLog($aftersaleId, 'refund_fail_mark', 'admin', (int) $adminId, '管理员标记网关退款失败', (string) $ip);
        }

        throw new RedirectException($this->showUrl($aftersaleId));
    }
}
