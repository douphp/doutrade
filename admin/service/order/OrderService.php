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

namespace Dou\Admin\Service\Order;

use Dou\Admin\Model\Order\Order;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Domain\Payment\PaymentStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderPayNameResolver;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;
use Dou\Vendor\Excel\Excel;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订单业务（`index.php?route=order/...`）。
 *
 * 职责：
 * - 列表筛选、详情组装、发货与线下付款审核、批量删除与取消。
 * - 销售额统计与订单自动化任务触发。
 */
class OrderService extends BaseService
{
    /** @var OrderCore */
    private $order;

    /** @var PaymentService */
    private $paymentService;

    /** @var OrderPayNameResolver */
    private $payNameResolver;

    /**
     * @param OrderCore $order 订单模块（状态、明细、物流、自动化）
     * @param PaymentService $paymentService 支付台账服务（dou_order_payment）
     * @param OrderPayNameResolver $payNameResolver 支付方式展示名解析
     */
    public function __construct(
        OrderCore $order,
        PaymentService $paymentService,
        OrderPayNameResolver $payNameResolver
    ) {
        $this->order = $order;
        $this->paymentService = $paymentService;
        $this->payNameResolver = $payNameResolver;
    }

    /**
     * 从列表入口解析 order_id（支持 order_sn 或 order_id）
     *
     * @param string $orderSn
     * @param string $orderId
     * @return string
     */
    public function resolveViewOrderId($orderSn, $orderId)
    {
        if (Check::number((string) $orderSn)) {
            $oid = Order::getOrderIdByOrderSn($orderSn);
            return $oid ? (string) $oid : '';
        }
        if (Check::number((string) $orderId)) {
            return (string) $orderId;
        }
        return '';
    }

    /**
     * 后台订单列表页数据
     *
     * @param array $input username（会员关键字：手机/邮箱/user_sn/联系人，Core\UserService::findUserId）、status、order_sn、order_consignee、time_start、time_end、page、reject_filter
     * @return array order_list, pager, status_filter, status_options
     */
    public function buildOrderListData(array $input)
    {
        $this->runAutoTasks();

        $username = Arr::get($input, 'username', '');
        $status = Arr::get($input, 'status', '');
        $orderSn = Arr::get($input, 'order_sn', '');
        $order_consignee = Arr::get($input, 'order_consignee', '');
        $time_start = Arr::get($input, 'time_start', '');
        $time_end = Arr::get($input, 'time_end', '');
        $page = isset($input['page']) ? (int) $input['page'] : 1;

        $page_url = route('admin.order');
        if ($username !== '') {
            $page_url .= '&username=' . rawurlencode($username);
        }
        if ($status !== '') {
            $page_url .= '&status=' . rawurlencode($status);
        }
        if ($orderSn !== '') {
            $page_url .= '&order_sn=' . rawurlencode($orderSn);
        }
        if ($order_consignee !== '') {
            $page_url .= '&order_consignee=' . rawurlencode($order_consignee);
        }
        if ($time_start !== '') {
            $page_url .= '&time_start=' . rawurlencode($time_start);
        }
        if ($time_end !== '') {
            $page_url .= '&time_end=' . rawurlencode($time_end);
        }

        $result = $this->buildFilteredOrderQuery($input)
            ->paginate(15, $page, Util::normalizeQueryString($page_url));

        // 列表收件人字段统一从 order_address 取，dou_order 平拍 contact/phone 已下线
        $orderIds = array();
        foreach ($result['list'] as $model) {
            $orderIds[] = (int) $model->getRawAttribute('id');
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

        $order_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $user = $model->user;
            $email = ($user instanceof \Dou\Core\Orm\Model) ? (string) $user->getRawAttribute('email') : '';
            $status_format = lang('order_status_' . $row['status']);
            $oid = (int) $row['id'];
            $addr = isset($addressMap[$oid]) ? $addressMap[$oid] : array();
            $createdAtTs = Util::toTimestamp($model->getRawAttribute('created_at'));
            $order_list[] = array(
                'order_id' => $row['id'],
                'order_sn' => $row['order_sn'],
                'user' => (user() !== null ? user()->format($row['user_id']) : false),
                'email' => $email,
                'mode' => $row['mode'],
                'phone' => isset($addr['phone']) ? $addr['phone'] : '',
                'contact' => isset($addr['contact_name']) ? $addr['contact_name'] : '',
                'order_amount' => Util::formatPrice($model->getRawAttribute('order_amount')),
                'order_point' => $row['order_point'] . lang('order_point_unit'),
                'status' => $row['status'],
                'status_format' => ($row['aftersale_status'] == 2 && (string) $row['status'] === OrderStatus::REFUNDED)
                    ? lang('order_aftersale_status_2')
                    : $status_format,
                'created_at' => $createdAtTs !== null ? date('Y-m-d H:i:s', $createdAtTs) : '',
            );
        }

        return array(
            'order_list' => $order_list,
            'pager' => $result['pager'],
            'status_filter' => $status,
            'status_options' => $this->statusOptions(),
        );
    }

    /**
     * 按列表筛选条件构建订单查询（列表页与导出共用，避免两套筛选逻辑漂移）。
     *
     * @param array $input username / status / order_sn / order_consignee / time_start / time_end / reject_filter
     * @return \Dou\Core\Orm\Builder
     */
    private function buildFilteredOrderQuery(array $input)
    {
        $username = Arr::get($input, 'username', '');
        $status = Arr::get($input, 'status', '');
        $orderSn = Arr::get($input, 'order_sn', '');
        $order_consignee = Arr::get($input, 'order_consignee', '');
        $time_start = Arr::get($input, 'time_start', '');
        $time_end = Arr::get($input, 'time_end', '');
        $rejectFilter = (array) Arr::get($input, 'reject_filter', array());

        $forceEmpty = false;
        foreach ($rejectFilter as $rejected) {
            if ($rejected) {
                $forceEmpty = true;
                break;
            }
        }

        $userIdFilter = 0;
        if ($username !== '' && !$forceEmpty) {
            $uid = user() !== null ? user()->findUserId($username) : null;
            if ($uid) {
                $userIdFilter = (int) $uid;
            } else {
                $forceEmpty = true;
            }
        }

        $consigneeGroup = null;
        if ($order_consignee !== '') {
            $consigneeGroup = $this->buildConsigneeCondition($order_consignee);
        }

        if ($forceEmpty) {
            // 任一筛选项校验未通过或会员关键字未命中：强制空结果集，避免与未筛选等价
            $userIdFilter = -1;
        }

        return Order::withUser()
            ->filterByUserId($userIdFilter)
            ->filterByStatus($status)
            ->filterByOrderSn($orderSn)
            ->applyConsigneeGroup($consigneeGroup)
            ->filterByTimeStart($time_start)
            ->filterByTimeEnd($time_end)
            ->applyDefaultOrder();
    }

    /**
     * 组装订单导出数据（head + list）。
     *
     * 收货地址、商品明细、插件名称均批量预取，避免逐单查询。
     * 金额输出原始数值（不附加货币符号），便于二次对账。
     *
     * @param array $input 列表筛选条件（username/status/order_sn/...）。
     * @param array $checkbox 勾选的订单 ID；空数组表示按筛选条件导出全部。
     * @return array head, list
     */
    public function buildExcelOrderList(array $input = array(), array $checkbox = array())
    {
        $excelList = array('head' => OrderExcelDefinition::head(), 'list' => array());

        $query = $this->buildFilteredOrderQuery($input);
        if (!empty($checkbox)) {
            $ids = Check::intIds($checkbox);
            if (empty($ids)) {
                return $excelList;
            }
            $query->whereIn('id', $ids);
        }

        $orders = array();
        $orderIds = array();
        foreach ($query->get() as $model) {
            $raw = $model->getAttributes();
            $orders[] = $raw;
            $orderIds[] = isset($raw['id']) ? (int) $raw['id'] : 0;
        }
        if (empty($orders)) {
            return $excelList;
        }

        $addressMap = array();
        $addressRows = DB::table('order_address')
            ->field('order_id, contact_name, phone, country, province, city, district, address')
            ->whereIn('order_id', $orderIds)
            ->select();
        foreach ((array) $addressRows as $row) {
            $addressMap[(int) $row['order_id']] = $row;
        }

        $itemMap = array();
        $itemRows = DB::table('order_item')
            ->field('order_id, name, item_number')
            ->whereIn('order_id', $orderIds)
            ->order('id ASC')
            ->select();
        foreach ((array) $itemRows as $row) {
            $oid = (int) $row['order_id'];
            if (!isset($itemMap[$oid])) {
                $itemMap[$oid] = array('list' => array(), 'number' => 0);
            }
            $qty = isset($row['item_number']) ? (int) $row['item_number'] : 0;
            $itemMap[$oid]['list'][] = (string) $row['name'] . ' * ' . $qty;
            $itemMap[$oid]['number'] += $qty;
        }

        $pluginNames = $this->pluginNameMap();
        $paymentNames = $this->paymentNameMap($orderIds, $pluginNames);

        foreach ($orders as $order) {
            $oid = isset($order['id']) ? (int) $order['id'] : 0;
            $address = isset($addressMap[$oid]) ? $addressMap[$oid] : array();
            $items = isset($itemMap[$oid]) ? $itemMap[$oid] : array('list' => array(), 'number' => 0);

            $regionParts = array();
            foreach (array('country', 'province', 'city', 'district') as $regionField) {
                if (!empty($address[$regionField])) {
                    $regionParts[] = (string) $address[$regionField];
                }
            }

            $isPointMode = isset($order['mode']) && (string) $order['mode'] === 'point';
            $status = isset($order['status']) ? (string) $order['status'] : '';
            $statusFormat = lang('order_status_' . $status);
            if (isset($order['aftersale_status']) && (int) $order['aftersale_status'] === 2 && $status === OrderStatus::REFUNDED) {
                $statusFormat = lang('order_aftersale_status_2');
            }

            $payName = '';
            if (isset($paymentNames[$oid]) && $paymentNames[$oid] !== '') {
                $payName = $paymentNames[$oid];
            } elseif (isset($order['pay_id']) && (string) $order['pay_id'] !== '') {
                $payId = (string) $order['pay_id'];
                $payName = isset($pluginNames[$payId]) ? $pluginNames[$payId] : $payId;
            }

            $excelList['list'][] = OrderExcelDefinition::row(array(
                'order_sn' => isset($order['order_sn']) ? $order['order_sn'] : '',
                'user_email' => isset($order['email']) ? $order['email'] : '',
                'contact_name' => isset($address['contact_name']) ? $address['contact_name'] : '',
                'phone' => isset($address['phone']) ? $address['phone'] : '',
                'region' => implode(' ', $regionParts),
                'address' => isset($address['address']) ? $address['address'] : '',
                'item_list' => implode('; ', $items['list']),
                'item_number' => (int) $items['number'],
                'item_amount' => isset($order['item_amount']) ? $order['item_amount'] : '',
                'shipping_fee' => isset($order['shipping_fee']) ? $order['shipping_fee'] : '',
                'order_amount' => $isPointMode ? '' : (isset($order['order_amount']) ? $order['order_amount'] : ''),
                'order_point' => $isPointMode ? (isset($order['order_point']) ? $order['order_point'] : '') : '',
                'currency_code' => isset($order['currency_code']) ? $order['currency_code'] : '',
                'currency_amount' => isset($order['currency_order_amount']) ? $order['currency_order_amount'] : '',
                'pay_name' => $payName,
                'status_format' => $statusFormat,
                'tracking_no' => isset($order['tracking_no']) ? $order['tracking_no'] : '',
                'created_at' => isset($order['created_at']) ? $order['created_at'] : '',
                'paid_at' => isset($order['paid_at']) ? $order['paid_at'] : '',
            ));
        }

        return $excelList;
    }

    /**
     * 插件 slug → 名称映射（支付 / 配送名称共用），进程内缓存。
     *
     * @return array
     */
    private function pluginNameMap()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = array();
        if (DB::tableExist('plugin')) {
            $rows = DB::table('plugin')->field('slug, name')->select();
            foreach ((array) $rows as $row) {
                $cache[(string) $row['slug']] = (string) $row['name'];
            }
        }

        return $cache;
    }

    /**
     * 批量解析订单支付方式：成功支付腿组合成「余额支付 + 网关名」。
     *
     * 等价于 OrderPayNameResolver::resolveForOrder() 的批量版，避免逐单查询。
     *
     * @param array $orderIds
     * @param array $pluginNames slug => name
     * @return array order_id => 支付方式文案
     */
    private function paymentNameMap(array $orderIds, array $pluginNames)
    {
        $map = array();
        if (empty($orderIds) || !DB::tableExist('order_payment')) {
            return $map;
        }

        $rows = DB::table('order_payment')
            ->field('order_id, gateway')
            ->whereIn('order_id', $orderIds)
            ->where('status', PaymentStatus::SUCCEEDED)
            ->order('id ASC')
            ->select();

        $legs = array();
        foreach ((array) $rows as $row) {
            $oid = (int) $row['order_id'];
            $gateway = isset($row['gateway']) ? (string) $row['gateway'] : '';
            if ($gateway === '') {
                continue;
            }
            if ($gateway === 'wallet') {
                $name = lang('order_pay_wallet');
            } else {
                $name = isset($pluginNames[$gateway]) ? $pluginNames[$gateway] : $gateway;
            }
            if (!isset($legs[$oid])) {
                $legs[$oid] = array();
            }
            if (!in_array($name, $legs[$oid], true)) {
                $legs[$oid][] = $name;
            }
        }

        foreach ($legs as $oid => $names) {
            $map[$oid] = implode(' + ', $names);
        }

        return $map;
    }

    /**
     * 订单状态筛选下拉选项（值 + 文案），按 OrderStatus::all() 顺序输出。
     *
     * @return array {value, format}[]
     */
    public function statusOptions()
    {
        $options = array();
        foreach (OrderStatus::all() as $status) {
            $options[] = array(
                'value' => $status,
                'format' => lang('order_status_' . $status),
            );
        }

        return $options;
    }

    /**
     * 订单详情页数据（模板用 $order）
     *
     * @param string $orderId
     * @return array|null
     */
    public function buildOrderViewData($orderId)
    {
        if ($orderId === '' || $orderId === null) {
            return null;
        }

        $order = Order::findByOrderId($orderId);
        if (!$order) {
            return null;
        }

        // 币种快照：后续会覆写 $order 的展示字段，金额换算统一用原始行数据
        $orderRow = $order->getAttributes();

        $order['order_id'] = (int) $order['id'];
        $order['user'] = (user() !== null ? user()->format($order['user_id']) : false);
        $order['pay_name'] = $this->payNameResolver->resolveForOrder($order['pay_id'], (int) $order['order_id']);
        $order['shipping_name'] = DB::table('plugin')->where('slug', $order['shipping_id'])->value('name');
        $order['item_amount_format'] = Util::formatPrice($order['item_amount']);
        $order['shipping_fee_format'] = Util::formatPrice($order['shipping_fee']);
        $order['order_amount_format'] = Util::formatPrice($order['order_amount']);

        // 订单币信息：摘要金额仍为基准币，客户实付按订单币快照展示
        $orderCtx = currency()->contextForOrder($orderRow);
        $order['currency_code'] = $orderCtx['code'];
        $order['currency_symbol'] = $orderCtx['symbol'];
        $order['currency_rate'] = $orderCtx['rate'];
        $order['currency_rate_format'] = rtrim(rtrim(number_format($orderCtx['rate'], 8, '.', ''), '0'), '.');
        $order['currency_order_amount_format'] = Util::formatCurrency(currency()->orderAmount($orderRow), $orderCtx);

        // 收货地址字段统一从 order_address 取（dou_order 平拍 contact/phone/country/province/address/postcode 已下线）
        $addrRow = DB::table('order_address')->where('order_id', (int) $order['order_id'])->find();
        $order['contact'] = isset($addrRow['contact_name']) ? $addrRow['contact_name'] : '';
        $order['phone'] = isset($addrRow['phone']) ? $addrRow['phone'] : '';
        $order['country'] = isset($addrRow['country']) ? $addrRow['country'] : '';
        $order['province'] = isset($addrRow['province']) ? $addrRow['province'] : '';
        $order['address'] = isset($addrRow['address']) ? $addrRow['address'] : '';
        $order['postcode'] = isset($addrRow['postcode']) ? $addrRow['postcode'] : '';

        $paymentHistory = $this->paymentService->listByOrder((int) $order['order_id']);
        $order['payment_list'] = $this->formatPaymentList($paymentHistory, $orderCtx, $orderCtx['code']);
        $order['pay_evidence'] = $this->resolvePayEvidence($paymentHistory);
        $pendingPayment = $this->findActiveOfflinePayment($paymentHistory);
        $order['pending_payment'] = $pendingPayment;

        // 注意：$order 为 Model 实例，offsetGet 返回属性副本，不能对 $order['x'][] 做追加
        // （会触发 Indirect modification of overloaded element），此处整体赋值。
        $order['coupon'] = false;
        $order['coupons'] = array();
        $order['coupon_amount'] = 0.0;
        $order['coupon_amount_format'] = Util::formatPrice(0);
        $couponSvc = Module::make('coupon');
        if ($couponSvc) {
            foreach ($couponSvc->getOrderCouponView((int) $order['order_id']) as $key => $value) {
                $order[$key] = $value;
            }
        }
        $couponAmount = (float) $order['coupon_amount'];
        $order['item_amount_gross'] = Num::round((float) $order['item_amount'] + $couponAmount, 2);
        $order['item_amount_gross_format'] = Util::formatPrice($order['item_amount_gross']);

        // 待确认退款（售后退款 / 迟到支付 / 超额支付）：在订单详情页直接收尾
        // 同样先收集到局部数组，避免对 Model 的数组属性做间接追加。
        $pendingRefunds = array();
        $refundRows = DB::table('order_refund')
            ->where('order_id', (int) $order['order_id'])
            ->where('status', 'pending')
            ->order('id ASC')
            ->select();
        foreach ((array) $refundRows as $refundRow) {
            $refundTs = Util::toTimestamp(isset($refundRow['created_at']) ? $refundRow['created_at'] : null);
            $pendingRefunds[] = array(
                'refund_sn' => (string) $refundRow['refund_sn'],
                'channel' => (string) $refundRow['channel'],
                'currency_code' => isset($refundRow['currency_code']) && $refundRow['currency_code'] !== ''
                    ? (string) $refundRow['currency_code']
                    : $orderCtx['code'],
                'amount_format' => Util::formatCurrency((float) $refundRow['amount'], $orderCtx),
                'created_at_format' => $refundTs !== null ? date('Y-m-d H:i', $refundTs) : '',
                'aftersale_id' => (int) $refundRow['aftersale_id'],
            );
        }
        $order['pending_refunds'] = $pendingRefunds;

        $order['need_pay_check'] = ($order['pay_id'] === 'offlinepay'
            && $order['status'] === OrderStatus::AWAITING_CONFIRMATION
            && $pendingPayment !== null);
        $order['status_format'] = ($order['aftersale_status'] == 2 && (string) $order['status'] === OrderStatus::REFUNDED)
            ? lang('order_aftersale_status_2')
            : lang('order_status_' . $order['status']);
        $order['item_list'] = $this->order->getOrderItem($order['order_id']);
        $order['allow_retry_paid'] = in_array(
            (string) $order['status'],
            array(OrderStatus::PAID, OrderStatus::COMPLETED),
            true
        );
        $order['can_ship'] = in_array(
            (string) $order['status'],
            array(OrderStatus::PAID, OrderStatus::COMPLETED),
            true
        ) && in_array((string) $order['module'], array('product', 'item', 'equipment'), true);

        return $order;
    }

    /**
     * 把 PaymentService 返回的原始 payment 历史装饰为视图友好的结构。
     *
     * 支付腿金额为订单币口径；$ctx 为空时按基准币展示。
     *
     * @param array $rows
     * @param array|null $ctx 订单币上下文
     * @param string $currencyCode 订单币种代码（行内缺省时兜底展示）
     * @return array
     */
    private function formatPaymentList(array $rows, $ctx = null, $currencyCode = '')
    {
        $list = array();
        foreach ($rows as $row) {
            $payEvidence = isset($row['pay_evidence']) ? (string) $row['pay_evidence'] : '';
            $paidAt = Util::toTimestamp(isset($row['paid_at']) ? $row['paid_at'] : null);
            $gateway = isset($row['gateway']) ? (string) $row['gateway'] : '';
            $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
            $rowCurrency = isset($row['currency_code']) && (string) $row['currency_code'] !== ''
                ? (string) $row['currency_code']
                : $currencyCode;
            $list[] = array(
                'id' => (int) $row['id'],
                'payment_sn' => isset($row['payment_sn']) ? (string) $row['payment_sn'] : '',
                'gateway' => $gateway,
                'gateway_name' => $this->payNameResolver->resolveGateway($gateway),
                'currency_code' => $rowCurrency,
                'amount' => $ctx !== null ? Util::formatCurrency($amount, $ctx) : Util::formatBasePrice($amount),
                'status' => isset($row['status']) ? (string) $row['status'] : '',
                'status_format' => $this->formatPaymentStatusLabel(isset($row['status']) ? (string) $row['status'] : ''),
                'transaction_id' => isset($row['transaction_id']) ? (string) $row['transaction_id'] : '',
                'pay_evidence_url' => $payEvidence !== '' ? attachment()->url($payEvidence) : '',
                'paid_at_format' => $paidAt !== null ? date('Y-m-d H:i:s', $paidAt) : '',
                'add_time_format' => isset($row['created_at']) && ($createdAtTs = Util::toTimestamp($row['created_at'])) !== null ? date('Y-m-d H:i:s', $createdAtTs) : '',
            );
        }
        return $list;
    }

    /**
     * payment.status 字符串 → 后台展示用的中文短句。
     *
     * @param string $status
     * @return string
     */
    private function formatPaymentStatusLabel($status)
    {
        switch ($status) {
            case PaymentStatus::PENDING:   return '待处理';
            case PaymentStatus::SUCCEEDED: return '已成功';
            case PaymentStatus::FAILED:    return '已失败';
            case PaymentStatus::CLOSED:    return '已关闭';
            default:                        return (string) $status;
        }
    }

    /**
     * 从 payment 历史中取出离线付款时用户上传的最新凭证（用于详情页头部摘要展示）。
     *
     * @param array $rows
     * @return string
     */
    private function resolvePayEvidence(array $rows)
    {
        foreach ($rows as $row) {
            if (!empty($row['pay_evidence'])) {
                return attachment()->url($row['pay_evidence']);
            }
        }
        return '';
    }

    /**
     * 取当前需要管理员处理的 pending 离线付款 payment 行（如有）。
     *
     * @param array $rows
     * @return array|null
     */
    private function findActiveOfflinePayment(array $rows)
    {
        foreach ($rows as $row) {
            if (!isset($row['status'])) {
                continue;
            }
            if ((string) $row['status'] !== PaymentStatus::PENDING) {
                continue;
            }
            if (!empty($row['pay_evidence']) || (isset($row['gateway']) && (string) $row['gateway'] === 'offlinepay')) {
                return $row;
            }
        }
        return null;
    }

    /**
     * 线下付款审核通过：找该订单当前 pending 的 payment，标记为 SUCCEEDED；
     * payment 状态机会联动把订单推进到 PAID。
     *
     * markSucceeded 返回 false 表示订单未推进到 PAID/COMPLETED（状态机拒绝或
     * changeStatus 内部联动回滚），此时 payment 仍保持 pending、订单状态未变，
     * 必须抛出 DomainException 给管理员明确反馈，避免静默「点了没反应」。
     *
     * @param string $orderId
     * @return string 详情页 URL
     */
    public function payCheck($orderId)
    {
        if (!Check::number((string) $orderId)) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }
        $orderModel = Order::find($orderId);
        if (!$orderModel) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }

        $payment = $this->paymentService->findActivePendingWithEvidence((int) $orderId);
        if (!$payment) {
            throw new DomainException('未找到待审核的支付记录', route('admin.order.show', ['order_id' => $orderId]));
        }
        if (!isset($payment['gateway']) || (string) $payment['gateway'] !== 'offlinepay') {
            throw new DomainException('当前待审核支付不是线下付款，无法人工确认', route('admin.order.show', ['order_id' => $orderId]));
        }

        if (!$this->paymentService->markSucceeded((string) $payment['payment_sn'], '')) {
            throw new DomainException('确认收款失败，订单状态未推进，请查看日志后重试', route('admin.order.show', ['order_id' => $orderId]));
        }

        return route('admin.order.show', ['order_id' => $orderId]);
    }

    /**
     * 线下付款驳回：把当前 pending 的 payment 标记为 FAILED，并把订单回退到 PENDING，
     * 允许用户重新上传凭证。
     *
     * 顺序：先 changeStatus 回退 order 到 PENDING 并回查确认生效（AWAITING_CONFIRMATION
     * → PENDING 不触发任何业务联动，rollback 概率极低），再 markFailed 标记 payment。
     * 反转后即便 changeStatus 失败，payment 仍保持 pending，管理员可重试，不形成死局。
     *
     * @param string $orderId
     * @param string $reason 驳回原因（写入 payment.raw_callback 留底）
     * @return string 详情页 URL
     */
    public function payReject($orderId, $reason = '')
    {
        if (!Check::number((string) $orderId)) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }
        $orderModel = Order::find($orderId);
        $orderRow = $orderModel ? $orderModel->getAttributes() : null;
        if (!$orderRow) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }

        $payment = $this->paymentService->findActivePendingWithEvidence((int) $orderId);
        if (!$payment) {
            throw new DomainException('未找到待审核的支付记录', route('admin.order.show', ['order_id' => $orderId]));
        }
        if (!isset($payment['gateway']) || (string) $payment['gateway'] !== 'offlinepay') {
            throw new DomainException('当前待审核支付不是线下付款，无法驳回', route('admin.order.show', ['order_id' => $orderId]));
        }

        $this->order->changeStatus((string) $orderRow['order_sn'], OrderStatus::PENDING);

        $currentStatus = (string) Order::whereKey($orderId)
            ->value('status');
        if ($currentStatus !== OrderStatus::PENDING) {
            throw new DomainException('订单状态回退失败，请重试', route('admin.order.show', ['order_id' => $orderId]));
        }

        // 线下凭证被驳回：清除 paid_at / pay_id，避免未付款订单继续计入营收
        DB::table('order')->where('id', (int) $orderId)->data(array(
            'paid_at' => null,
            'pay_id' => '',
        ))->update();

        $reason = (string) $reason;
        $this->paymentService->markFailed((string) $payment['payment_sn'], $reason !== '' ? $reason : '管理员驳回', $reason);

        return route('admin.order.show', ['order_id' => $orderId]);
    }

    /**
     * 标记订单级待处理退款失败（route=order/fail_refund）。
     *
     * 仅用于非售后退款（aftersale_id=0，如超额支付 / 迟到支付）；售后退款请在售后详情处理。
     * 标记失败后释放该笔占用的可退额度。
     *
     * @param string $refundSn 退款单号
     * @return string 详情页 URL
     * @throws DomainException
     */
    public function failOrderRefund($refundSn)
    {
        $refundSn = (string) $refundSn;
        $refund = DB::table('order_refund')->where('refund_sn', $refundSn)->find();
        if (!$refund) {
            throw new DomainException('未找到退款记录', route('admin.order'));
        }
        if ((int) $refund['aftersale_id'] > 0) {
            throw new DomainException('售后退款请在售后详情页处理', route('admin.aftersale.show', array('id' => (int) $refund['aftersale_id'])));
        }
        if ((string) $refund['status'] !== 'pending') {
            throw new DomainException('该退款不在待处理状态，无法标记失败', route('admin.order.show', array('order_id' => (int) $refund['order_id'])));
        }
        if (!$this->paymentService->markRefundFailed($refundSn, 'manual by admin')) {
            throw new DomainException('标记退款失败未生效，请刷新后重试', route('admin.order.show', array('order_id' => (int) $refund['order_id'])));
        }

        return route('admin.order.show', array('order_id' => (int) $refund['order_id']));
    }

    /**
     * 重新执行付款后联动（积分 / 分销 / 会员升级）。
     *
     * 仅对已进入 PAID / COMPLETED 的订单生效；三项均为幂等操作，
     * 部分成功后重跑不会重复发放（积分按 from=order_sn 守卫、分销自带去重、会员升级不会重复升级）。
     *
     * @param string $orderId
     * @return array message、back_url
     */
    public function retryPaidEffects($orderId)
    {
        if (!Check::number((string) $orderId)) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }

        $order = Order::findByOrderId($orderId);
        if (!$order) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }

        $showUrl = route('admin.order.show', ['order_id' => $orderId]);
        if (!in_array((string) $order['status'], array(OrderStatus::PAID, OrderStatus::COMPLETED), true)) {
            throw new DomainException(lang('order_retry_paid_invalid'), $showUrl);
        }

        $this->order->retryPaidEffects((string) $order['order_sn']);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::REPLAY, 1, 'ORDER:' . $order['order_sn']);

        return array(
            'message' => lang('order_retry_paid_success'),
            'back_url' => $showUrl,
        );
    }

    /**
     * 保存物流公司与运单号；首次填写时推进订单为已发货并解锁库存等。
     *
     * 字段白名单与校验规则定义在 OrderTrackingFormRequest::rules()。
     * @see \Dou\Admin\Request\Order\OrderTrackingFormRequest::rules()
     *
     * @param array $data validated 数据（order_id、shipping_id、tracking_no）
     * @return string 跳转回详情页的 URL
     */
    public function tracking(array $data)
    {
        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        if ($orderId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.order'));
        }

        $shippingId = Arr::get($data, 'shipping_id', '');
        $trackingNo = Arr::get($data, 'tracking_no', '');
        $showUrl = route('admin.order.show', ['order_id' => $orderId]);

        if (!$this->order->fulfill(
            $orderId,
            (string) $shippingId,
            (string) $trackingNo,
            'admin',
            (int) auth('admin')->id(),
            ''
        )) {
            throw new DomainException(lang('order_tracking_wrong', lang('illegal')), $showUrl);
        }

        return $showUrl;
    }

    /**
     * 列表批量：del_all 删订单与明细及关联模块行；cancel_all 批量取消；excel / excel_all 导出。
     *
     * 字段白名单与校验规则定义在 OrderActionFormRequest::rules()。
     * @see \Dou\Admin\Request\Order\OrderActionFormRequest::rules()
     *
     * @param array $data validated 数据（action、checkbox、筛选字段）
     * @return array|null array 表示 happy-path 消息；excel/excel_all 分支由 Excel::export 直出响应后返回 null
     */
    public function action(array $data)
    {
        $action = Arr::get($data, 'action', '');
        $checkbox = Arr::get($data, 'checkbox', array());
        if (!is_array($checkbox)) {
            $checkbox = array();
        }

        if ($action === 'excel') {
            if (empty($checkbox)) {
                throw new DomainException(lang('order_select_empty'), route('admin.order'));
            }
            Excel::export('order', $this->buildExcelOrderList(array(), $checkbox));

            return null;
        }

        if ($action === 'excel_all') {
            // 导出与列表页完全一致的筛选结果
            Excel::export('order', $this->buildExcelOrderList($data));

            return null;
        }

        if (empty($checkbox)) {
            throw new DomainException(lang('order_select_empty'), route('admin.order'));
        }

        if ($action === 'del_all') {
            $deleted = 0;
            foreach ((array) $checkbox as $oid) {
                if (!Check::number((string) $oid)) {
                    continue;
                }
                $order = Order::findByOrderId($oid);
                if (!$order) {
                    continue;
                }

                // 只允许删除已取消且无任何支付/退款记录的订单，保留资金审计轨迹，
                // 避免物理删除后迟到支付回调找不到订单、无法登记退款
                if ((string) $order['status'] !== OrderStatus::CANCELLED) {
                    continue;
                }
                $paymentRecords = (int) DB::table('order_payment')
                    ->where('order_id', (int) $order['id'])
                    ->count();
                $refundRecords = DB::tableExist('order_refund')
                    ? (int) DB::table('order_refund')->where('order_id', (int) $order['id'])->count()
                    : 0;
                if ($paymentRecords > 0 || $refundRecords > 0) {
                    continue;
                }

                DB::beginTransaction();
                try {
                    DB::table('order_item')->where('order_id', (int) $order['id'])->delete();
                    DB::table('order_address')->where('order_id', (int) $order['id'])->delete();
                    DB::table('order_invoice')->where('order_id', (int) $order['id'])->delete();
                    DB::table('order_coupon')->where('order_id', (int) $order['id'])->delete();
                    DB::table('order_status_log')->where('order_id', (int) $order['id'])->delete();
                    DB::table('order')->where('id', (int) $order['id'])->delete();
                    DB::commit();
                    $deleted++;
                    audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'ORDER:' . $order['order_sn']);
                } catch (\Exception $e) {
                    DB::rollback();
                    Log::error('Admin order del_all failed', array(
                        'channel' => 'order',
                        'payload' => array(
                            'order_sn' => (string) $order['order_sn'],
                            'message' => $e->getMessage(),
                        ),
                    ));
                }
            }

            if ($deleted <= 0) {
                throw new DomainException(lang('order_del_failed', lang('illegal')), route('admin.order'));
            }

            $page = Session::get('page', 1);
            return array(
                'message' => lang('order_del_success'),
                'back_url' => Util::normalizeQueryString(route('admin.order', array(), array('page' => $page))),
            );
        }

        if ($action === 'cancel_all') {
            $result = $this->cancelAll($checkbox);
            if ($result['total'] <= 0) {
                throw new DomainException(lang('select_empty'), route('admin.order'));
            }
            $message = $result['failed'] > 0
                ? sprintf(lang('order_cancel_partial'), $result['success'], $result['failed'])
                : lang('order_cancel_success');

            return array(
                'message' => $message,
                'back_url' => route('admin.order'),
            );
        }

        throw new DomainException(lang('select_empty'), route('admin.order'));
    }

    /**
     * 批量取消未付款订单（后台专用）。
     *
     * @param array $checkbox
     * @return array total/success/failed
     */
    private function cancelAll($checkbox)
    {
        $result = array('total' => 0, 'success' => 0, 'failed' => 0);
        if (!is_array($checkbox) || empty($checkbox)) {
            Log::error('Admin order cancel_all invalid checkbox', array(
                'channel' => 'order',
            ));
            return $result;
        }

        $orderIds = array_values(array_unique(array_filter(array_map('intval', $checkbox))));
        if (empty($orderIds)) {
            return $result;
        }

        $rows = DB::table('order')
            ->whereIn('id', $orderIds)
            ->where('status', 'IN', array(OrderStatus::PENDING, OrderStatus::AWAITING_CONFIRMATION))
            ->select();

        $result['total'] = count($orderIds);
        // 状态不符或取消失败都计入失败数，避免部分未处理却提示全部成功
        $result['failed'] = count($orderIds) - count((array) $rows);
        foreach ((array) $rows as $row) {
            $ok = $this->order->cancelOrder(
                (string) $row['order_sn'],
                'admin cancelled',
                'admin',
                (int) auth('admin')->id(),
                '',
                array(OrderStatus::PENDING, OrderStatus::AWAITING_CONFIRMATION)
            );
            if ($ok) {
                $result['success']++;
                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CANCEL, 1, 'ORDER:' . $row['order_sn']);
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 初始化订单相关 parameter 并跳转参数组
     *
     * @return void
     */
    public function set()
    {
        $set_list = array('order_pay_timeout', 'order_allow_aftersale_time', 'order_allow_comment_time', 'order_quick_buy');
        foreach ($set_list as $name) {
            if (!DB::table('parameter')->where('name', $name)->value('name')) {
                DB::table('parameter')->data(array(
                    'name' => $name,
                    'lang' => lang('order_' . $name . '_lang'),
                    'value' => lang('order_' . $name . '_value'),
                    'cue' => lang('order_' . $name . '_cue'),
                    'group' => 'order',
                ))->insert();
            }
        }
        return route('admin.parameter.set', ['group' => 'order']);
    }

    /**
     * 执行自动化任务（列表等入口调用）
     *
     * @return void
     */
    public function runAutoTasks()
    {
        if ($this->order) {
            $this->order->runScheduledTasksIfDue();
        }
    }

    /**
     * 订单详情简明数据（含 order items），与后台视图版字段粒度不同。
     *
     * @param int|string $id order_id
     * @return array|null
     */
    public function getOrderDetail($id)
    {
        $order = Order::findByOrderId($id);
        if (!$order) {
            return null;
        }
        $order['order_id'] = (int) $order['id'];
        $order['goods'] = Order::getOrderItems($id);
        return $order;
    }

    /**
     * 构造收件人多维模糊条件（手机/联系人/地址 EXISTS-IN-order_address）。
     *
     * dou_order 平拍 contact/phone/address 已下线，此处改走 order_address 子查询。
     *
     * @param string $consignee 搜索关键字
     * @return callable 供查询构造器调用
     */
    public function buildConsigneeCondition($consignee)
    {
        $consignee = (string) $consignee;
        return function ($q) use ($consignee) {
            $ids = DB::table('order_address')
                ->where('contact_name', $consignee)
                ->whereOr('phone', $consignee)
                ->whereOr('address', $consignee)
                ->column('order_id');
            if (!empty($ids)) {
                $q->whereIn('id', $ids);
            } else {
                // 没命中也要强制空集，避免回退到全表扫描
                $q->where('id', -1);
            }
        };
    }

    /**
     * 后台发起退款，委托 Order 模块处理。
     *
     * @param int|string $orderId
     * @param float $amount
     * @param string $remark
     * @return bool
     */
    public function processRefund($orderId, $amount, $remark = '')
    {
        return $this->order->adminRefund($orderId, $amount, $remark);
    }

    /**
     * 订单详情页可用的物流公司下拉数据。
     *
     * @return array
     */
    public function getShippingListForAdmin()
    {
        return $this->order->getShippingList();
    }
}
