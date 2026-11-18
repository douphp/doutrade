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

namespace Dou\Front\Service\Order;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Domain\Payment\PaymentStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderPayNameResolver;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;
use Dou\Core\Support\ViewVars;
use Dou\Front\Model\Order\Order;
use Dou\Front\Service\View\OrderItemDecorator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员视角的订单视图服务（front/api 共用）。
 *
 * 仅承担「会员可见」的订单读模型与「-1 状态自取消」动作；任何深度订单业务都委托给
 * `Module::make('order')` / `Module::make('aftersale')` / `Module::make('coupon')`，
 * order 模块未装时返回空集合或 null，调用方据此降级视图。
 */
class UserService extends BaseService
{
    /** @var OrderItemDecorator */
    private $itemDecorator;

    /** @var PaymentService */
    private $paymentService;

    /** @var OrderPayNameResolver */
    private $payNameResolver;

    /**
     * @param OrderItemDecorator $itemDecorator 订单项视图装配器（叠 comment_url）
     * @param PaymentService $paymentService 支付台账服务（解析 pay_evidence 等）
     * @param OrderPayNameResolver $payNameResolver 支付方式展示名解析
     */
    public function __construct(
        OrderItemDecorator $itemDecorator,
        PaymentService $paymentService,
        OrderPayNameResolver $payNameResolver
    ) {
        $this->itemDecorator = $itemDecorator;
        $this->paymentService = $paymentService;
        $this->payNameResolver = $payNameResolver;
    }

    /**
     * 取消订单（仅允许取消状态为 0 的未付款订单）。
     *
     * 取消前先统计已成功的钱包腿金额：取消成功时由 PaymentService 原额退回余额，
     * 返回值供前端提示「已退回余额」。
     *
     * @param int $userId
     * @param string $orderSn
     * @return array ok, refund_amount, refund_amount_format
     */
    public function cancelOrder($userId, $orderSn)
    {
        if (!Module::has('order')) {
            return array('ok' => false, 'refund_amount' => 0.0, 'refund_amount_format' => '');
        }

        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->find();

        if (!$order || $order['status'] !== OrderStatus::PENDING) {
            return array('ok' => false, 'refund_amount' => 0.0, 'refund_amount_format' => '');
        }

        // 已扣款的余额腿取消后原额退回，统计时点必须在取消前
        $walletRefund = round((float) DB::table('order_payment')
            ->where('order_id', (int) $order['id'])
            ->where('gateway', 'wallet')
            ->where('status', PaymentStatus::SUCCEEDED)
            ->sum('amount'), 2);
        if ($walletRefund < 0) {
            $walletRefund = 0.0;
        }

        $orderSvc = Module::make('order');
        if (!$orderSvc) {
            return array('ok' => false, 'refund_amount' => 0.0, 'refund_amount_format' => '');
        }

        $ok = $orderSvc->cancelOrder(
            (string) $orderSn,
            'user cancelled',
            'user',
            (int) $userId,
            '',
            array(OrderStatus::PENDING)
        );

        if (!$ok) {
            return array('ok' => false, 'refund_amount' => 0.0, 'refund_amount_format' => '');
        }

        return array(
            'ok' => true,
            'refund_amount' => $walletRefund,
            'refund_amount_format' => $walletRefund > 0 ? Util::formatBasePrice($walletRefund) : '',
        );
    }

    /**
     * 构建会员订单列表数据（含分页）。
     *
     * @param int $userId
     * @param int $page
     * @param string $pagerUrl 翻页 URL 基础
     * @param string $status 筛选状态：all / 0 / 1 / 10 / -2
     * @return array list, pager, status, status_list
     */
    public function buildOrderListData($userId, $page, $pagerUrl, $status = 'all')
    {
        if (!Module::has('order')) {
            return array(
                'list' => array(),
                'pager' => '',
                'status' => $status,
                'status_list' => $this->buildOrderStatusList($status),
            );
        }

        $orderSvc = Module::make('order');
        if ($orderSvc) {
            $orderSvc->runScheduledTasksIfDue();
        }

        // 翻页保留筛选：状态走 paginate 的 $get 参数（rewrite 下 Connection 会把首个 & 转 ? 并前置 /），
        // 不能直接拼到 $pagerUrl，否则伪静态分页段会被破坏。
        $pagerGet = $status != 'all' ? '&status=' . rawurlencode((string) $status) : '';

        // AR：Order casts 接管 created_at（datetime:Y-m-d H:i:s）；金额/明细/支付/售后保留 per-row enrich
        $result = Order::filterByUserId($userId)
            ->filterByStatus($status)
            ->applyDefaultOrder()
            ->paginate(15, (int) $page, $pagerUrl, $pagerGet);

        // 收件人字段统一批量从 order_address 取（dou_order 平拍 contact/phone 已下线）
        $orderIds = array();
        $userIds = array();
        foreach ($result['list'] as $model) {
            $orderIds[] = (int) $model->getRawAttribute('id');
            $userIds[] = (int) $model->getRawAttribute('user_id');
        }
        $addressMap = array();
        if (!empty($orderIds)) {
            $rows = DB::table('order_address')
                ->field('order_id, contact_name, phone')
                ->whereIn('order_id', $orderIds)
                ->select();
            foreach ((array) $rows as $r) {
                $addressMap[(int) $r['order_id']] = $r;
            }
        }
        $emailMap = array();
        if (!empty($userIds)) {
            $rows = DB::table('user')
                ->field('id, email')
                ->whereIn('id', array_values(array_unique($userIds)))
                ->select();
            foreach ((array) $rows as $r) {
                $emailMap[(int) $r['id']] = (string) $r['email'];
            }
        }

        $list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $cashierUrl = '';
            if (plugin()->hasGroup('payment')) {
                $cashierUrl = route('order.cashier', array(), array('query' => array('order_sn' => $row['order_sn'])));
            }

            $ifCanAftersale = false;
            $aftersaleSvc = Module::make('aftersale');
            if ($aftersaleSvc) {
                $ifCanAftersale = $aftersaleSvc->ifCanAftersale($row['id'], $userId);
            }

            $rawStatus = $model->getRawAttribute('status');
            $statusKey = 'order_status_' . $rawStatus;
            $statusFormat = lang($statusKey);
            $isFullRefund = ((string) $rawStatus === OrderStatus::REFUNDED);
            $orderPointView = $orderSvc->pointForView($row['order_point']);
            $rawAddTime = Util::toTimestamp($model->getRawAttribute('created_at'));
            $oid = (int) $row['id'];
            $addr = isset($addressMap[$oid]) ? $addressMap[$oid] : array();
            $orderCtx = currency()->contextForOrder($row);
            $currencyOrderAmount = currency()->orderAmount($row);

            $list[] = array(
                'order_id' => $row['id'],
                'order_sn' => $row['order_sn'],
                'order_link' => route('order.user.show', array('order_sn' => $row['order_sn'])),
                'email' => isset($emailMap[(int) $row['user_id']]) ? $emailMap[(int) $row['user_id']] : '',
                'mode' => $row['mode'],
                'contact' => isset($addr['contact_name']) ? $addr['contact_name'] : '',
                'phone' => isset($addr['phone']) ? $addr['phone'] : '',
                'order_amount' => $currencyOrderAmount,
                'order_amount_format' => Util::formatCurrency($currencyOrderAmount, $orderCtx),
                'order_point' => $orderPointView['point'],
                'order_point_format' => $orderPointView['point_format'],
                'status' => $rawStatus,
                'status_format' => ($row['aftersale_status'] == 2 && $isFullRefund)
                    ? lang('order_aftersale_status_2')
                    : $statusFormat,
                'status_class' => ($row['aftersale_status'] == 2 && $isFullRefund)
                    ? 'info'
                    : OrderStatus::badgeClass($rawStatus),
                'cashier_url' => $cashierUrl,
                'if_can_aftersale' => $ifCanAftersale,
                'aftersale_url' => route('aftersale.user.create', array(), array('query' => array('order_sn' => $row['order_sn']))),
                'pay_time_limit' => $orderSvc ? $orderSvc->payTimeLimit($rawAddTime, true) : '',
                'created_at' => $row['created_at'],
                'item_list' => $orderSvc
                    ? $this->itemDecorator->decorate($orderSvc->getOrderItem($row['id'], '', $orderCtx), (int) $userId)
                    : array(),
            );
        }

        return array(
            'list' => $list,
            'pager' => $result['pager'],
            'status' => $status,
            'status_list' => $this->buildOrderStatusList($status),
        );
    }

    /**
     * 构建订单状态筛选 tab 列表，每项含可点链接（route 具名 + query，伪静态/普通模式都正确）。
     *
     * @param string $status 当前选中状态
     * @return array 每项 value / format / cur / url
     */
    private function buildOrderStatusList($status)
    {
        $rows = language()->dataListLangFormat(
            'order_status_',
            'all,' . OrderStatus::PENDING . ',' . OrderStatus::AWAITING_CONFIRMATION . ',' . OrderStatus::PAID . ',' . OrderStatus::COMPLETED . ',' . OrderStatus::CANCELLED
            . ',' . OrderStatus::REFUNDING . ',' . OrderStatus::REFUNDED . ',' . OrderStatus::PARTIAL_REFUNDED,
            $status
        );
        foreach ($rows as &$row) {
            $value = isset($row['value']) ? (string) $row['value'] : '';
            $row['url'] = ($value === '' || $value === 'all')
                ? route('order.user')
                : route('order.user', array(), array('query' => array('status' => $value)));
        }
        unset($row);

        return $rows;
    }

    /**
     * 构建订单详情数据。
     *
     * @param int $userId
     * @param string $orderSn
     * @return array|null 找不到或不属于该会员时返回 null
     */
    public function buildOrderShowData($userId, $orderSn)
    {
        if (!Module::has('order')) {
            return null;
        }

        $orderSvc = Module::make('order');
        if ($orderSvc) {
            // 详情读取前先跑一次节流自动任务，保证超时取消/售后过期状态是最新的
            $orderSvc->runScheduledTasksIfDue();
        }

        $orderRow = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->find();
        if (!$orderRow) {
            return null;
        }
        $orderRow['order_id'] = (int) $orderRow['id'];

        $order = $orderRow;
        $order['pay_name'] = $this->payNameResolver->resolveForOrder(
            isset($order['pay_id']) ? $order['pay_id'] : '',
            (int) $order['order_id']
        );
        $shippingName = $order['shipping_id'] ? plugin()->valueBySlug($order['shipping_id'], 'name') : false;
        $order['shipping_name'] = $shippingName !== false ? $shippingName : '';
        $orderCtx = currency()->contextForOrder($order);
        $useOrderCurrency = !currency()->isBase($orderCtx);
        $order['currency_code'] = $orderCtx['code'];
        $order['currency_symbol'] = $orderCtx['symbol'];
        $order['item_amount_format'] = Util::formatCurrency(
            $useOrderCurrency && isset($order['currency_item_amount']) ? $order['currency_item_amount'] : $order['item_amount'],
            $orderCtx
        );
        $order['shipping_fee_format'] = Util::formatCurrency(
            $useOrderCurrency && isset($order['currency_shipping_fee']) ? $order['currency_shipping_fee'] : $order['shipping_fee'],
            $orderCtx
        );
        $order['order_amount_format'] = Util::formatCurrency(currency()->orderAmount($order), $orderCtx);

        // 混合支付进度：余额已抵扣（SUCCEEDED 钱包腿快照）与待付金额（订单金额 - 已成功支付腿）
        $walletPaidAmount = isset($order['wallet_paid']) ? round((float) $order['wallet_paid'], 2) : 0.0;
        if ($walletPaidAmount < 0) {
            $walletPaidAmount = 0.0;
        }
        $paidTotal = round((float) DB::table('order_payment')
            ->where('order_id', (int) $order['order_id'])
            ->where('status', PaymentStatus::SUCCEEDED)
            ->sum('amount'), 2);
        $payableAmount = round(currency()->orderAmount($order) - $paidTotal, 2);
        if ($payableAmount < 0) {
            $payableAmount = 0.0;
        }
        $order['wallet_paid_amount'] = $walletPaidAmount;
        $order['wallet_paid_format'] = $walletPaidAmount > 0 ? Util::formatCurrency($walletPaidAmount, $orderCtx) : '';
        $order['payable_amount'] = $payableAmount;
        $order['payable_amount_format'] = Util::formatCurrency($payableAmount, $orderCtx);

        // 收货地址字段统一从 order_address 取（dou_order 平拍 contact/phone/country/province/address/postcode 已下线）
        $addrRow = DB::table('order_address')->where('order_id', (int) $order['order_id'])->find();
        $order['contact'] = isset($addrRow['contact_name']) ? $addrRow['contact_name'] : '';
        $order['phone'] = isset($addrRow['phone']) ? $addrRow['phone'] : '';
        $order['country'] = isset($addrRow['country']) ? $addrRow['country'] : '';
        $order['province'] = isset($addrRow['province']) ? $addrRow['province'] : '';
        $order['address'] = isset($addrRow['address']) ? $addrRow['address'] : '';
        $order['postcode'] = isset($addrRow['postcode']) ? $addrRow['postcode'] : '';
        $orderPointView = $orderSvc->pointForView($order['order_point']);
        $order['order_point'] = $orderPointView['point'];
        $order['order_point_format'] = $orderPointView['point_format'];
        $order['email'] = DB::table('user')->where('id', $order['user_id'])->value('email');
        $order['pay_time_limit'] = $orderSvc ? $orderSvc->payTimeLimit($order['created_at'], true) : '';
        $order['created_at'] = date('Y-m-d H:i:s', strtotime($order['created_at']));

        $statusKey = 'order_status_' . $order['status'];
        $isFullRefund = ((string) $order['status'] === OrderStatus::REFUNDED);
        $order['status_format'] = ($order['aftersale_status'] == 2 && $isFullRefund)
            ? lang('order_aftersale_status_2')
            : (lang($statusKey));
        $order['status_class'] = ($order['aftersale_status'] == 2 && $isFullRefund)
            ? 'info'
            : OrderStatus::badgeClass($order['status']);

        // 状态时间线：order_status_log 按时间升序，逐条「时间 + 落定状态 + 操作人 + 原因」
        $order['status_logs'] = array();
        $logRows = DB::table('order_status_log')
            ->where('order_id', (int) $order['order_id'])
            ->order('created_at ASC, id ASC')
            ->select();
        foreach ((array) $logRows as $log) {
            $toStatus = (string) $log['to_status'];
            $statusText = lang('order_status_' . $toStatus);
            if ($statusText === '' || $statusText === 'order_status_' . $toStatus) {
                $statusText = $toStatus;
            }
            $operatorType = (string) $log['operator_type'];
            $operatorText = lang('order_log_operator_' . $operatorType);
            if ($operatorText === '' || $operatorText === 'order_log_operator_' . $operatorType) {
                $operatorText = $operatorType;
            }
            $logTs = Util::toTimestamp($log['created_at']);
            $order['status_logs'][] = array(
                'time' => $logTs !== null ? date('Y-m-d H:i:s', $logTs) : '',
                'status_text' => $statusText,
                'operator_text' => $operatorText,
                'reason' => (string) $log['reason'],
            );
        }

        $order['item_list'] = $orderSvc
            ? $this->itemDecorator->decorate($orderSvc->getOrderItem($order['order_id'], '', $orderCtx), (int) $userId)
            : array();
        $payEvidence = $this->paymentService->findLatestPayEvidence((int) $order['order_id']);
        $order['pay_evidence'] = $payEvidence !== '' ? attachment()->url($payEvidence) : '';
        $order['aftersale_url'] = route('aftersale.user.create', array(), array('query' => array('order_sn' => $order['order_sn'])));

        $cashierUrl = '';
        if (plugin()->hasGroup('payment')) {
            $cashierUrl = route('order.cashier', array(), array('query' => array('order_sn' => $order['order_sn'])));
        }
        $order['cashier_url'] = $cashierUrl;

        $ifCanAftersale = false;
        $aftersaleSvc = Module::make('aftersale');
        if ($aftersaleSvc) {
            $ifCanAftersale = $aftersaleSvc->ifCanAftersale($order['order_id'], $userId);
        }
        $order['if_can_aftersale'] = $ifCanAftersale;

        $order['coupon'] = false;
        $order['coupons'] = array();
        $order['coupon_amount'] = 0.0;
        $order['coupon_amount_format'] = Util::formatCurrency(0, $orderCtx);
        $couponSvc = Module::make('coupon');
        if ($couponSvc) {
            $order = array_merge($order, $couponSvc->getOrderCouponView((int) $order['order_id'], $orderCtx));
        }
        $couponAmount = isset($order['coupon_amount']) ? (float) $order['coupon_amount'] : 0.0;
        $grossItemAmount = $useOrderCurrency && isset($order['currency_item_amount'])
            ? (float) $order['currency_item_amount']
            : (float) $order['item_amount'];
        $order['item_amount_gross'] = Num::round($grossItemAmount + $couponAmount, 2);
        $order['item_amount_gross_format'] = Util::formatCurrency($order['item_amount_gross'], $orderCtx);

        return ViewVars::orderForTemplate($order);
    }
}
