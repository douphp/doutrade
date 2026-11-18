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
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Aftersale\AftersaleService;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderPayNameResolver;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;
use Dou\Front\Model\Order\Order;
use Dou\Front\Service\View\OrderItemDecorator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台工作台订单业务层（API order/work：列表 / 详情 / 取消 / 支付审核 / 物流）。
 *
 * 控制器仅负责参数收集与响应格式化，工作台订单的读写在此类完成。
 */
class WorkOrderService extends BaseService
{
    /** @var OrderCore */
    private $order;

    /** @var OrderItemDecorator */
    private $itemDecorator;

    /** @var PaymentService */
    private $paymentService;

    /** @var OrderPayNameResolver */
    private $payNameResolver;

    /**
     * @param OrderCore $order
     * @param OrderItemDecorator $itemDecorator 订单项视图装配器（叠 comment_url）
     * @param PaymentService $paymentService 支付台账服务（dou_order_payment）
     * @param OrderPayNameResolver $payNameResolver 支付方式展示名解析
     */
    public function __construct(
        OrderCore $order,
        OrderItemDecorator $itemDecorator,
        PaymentService $paymentService,
        OrderPayNameResolver $payNameResolver
    ) {
        $this->order = $order;
        $this->itemDecorator = $itemDecorator;
        $this->paymentService = $paymentService;
        $this->payNameResolver = $payNameResolver;
    }

    /**
     * 工作台订单列表
     *
     * @param mixed $status
     * @param int $page
     * @param int $operatorUserId
     * @param AftersaleService|null $aftersale
     * @return array
     */
    public function getWorkOrderList($status, $page, $operatorUserId, $aftersale = null)
    {
        $this->order->runScheduledTasksIfDue();

        // AR：Order casts 接管 created_at/数字字段；金额/支付/明细按行 enrich
        $result = Order::filterByStatus($status)
            ->applyDefaultOrder()
            ->paginate(10, (int) $page);

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
        $ifPayment = plugin()->hasGroup('payment');
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $rawStatus = $model->getRawAttribute('status');
            $rawAddTime = Util::toTimestamp($model->getRawAttribute('created_at'));
            $statusFormat = lang('order_status_' . $rawStatus);
            $ifCanAftersale = (($aftersale instanceof AftersaleService) && Config::get('features.aftersale', false))
                ? $aftersale->ifCanAftersale($row['id'], $operatorUserId)
                : false;
            $orderPointView = $this->order->pointForView($row['order_point']);
            $oid = (int) $row['id'];
            $addr = isset($addressMap[$oid]) ? $addressMap[$oid] : array();
            $orderCtx = currency()->contextForOrder($row);
            $currencyOrderAmount = currency()->orderAmount($row);

            $list[] = array(
                'order_id' => $row['id'],
                'order_sn' => $row['order_sn'],
                'email' => isset($emailMap[(int) $row['user_id']]) ? $emailMap[(int) $row['user_id']] : '',
                'mode' => $row['mode'],
                'contact' => isset($addr['contact_name']) ? $addr['contact_name'] : '',
                'phone' => isset($addr['phone']) ? $addr['phone'] : '',
                'order_amount' => $currencyOrderAmount,
                'order_amount_format' => Util::formatCurrency($currencyOrderAmount, $orderCtx),
                'order_point' => $orderPointView['point'],
                'order_point_format' => $orderPointView['point_format'],
                'status' => $rawStatus,
                'status_format' => $statusFormat,
                'if_can_aftersale' => $ifCanAftersale,
                'if_payment' => $ifPayment,
                'pay_time_limit' => $this->order->payTimeLimit($rawAddTime, true, true),
                'created_at' => $row['created_at'],
                'item_list' => $this->itemDecorator->decorate(
                    $this->order->getOrderItem($row['id'], $row['user_id']),
                    (int) $row['user_id']
                ),
            );
        }

        return array(
            'order_list' => $list,
            'status_list' => language()->dataListLangFormat(
                'order_status_',
                'all,' . OrderStatus::PENDING . ',' . OrderStatus::AWAITING_CONFIRMATION . ',' . OrderStatus::PAID . ',' . OrderStatus::COMPLETED . ',' . OrderStatus::CANCELLED
                . ',' . OrderStatus::REFUNDING . ',' . OrderStatus::REFUNDED . ',' . OrderStatus::PARTIAL_REFUNDED,
                $status
            ),
            'payment' => plugin()->defaultPaymentSlug(),
        );
    }

    /**
     * 工作台订单详情
     *
     * @param string $orderSn
     * @param int $operatorUserId
     * @param AftersaleService|null $aftersale
     * @return array|null
     */
    public function getWorkOrderDetail($orderSn, $operatorUserId, $aftersale = null)
    {
        $this->order->runScheduledTasksIfDue();

        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return null;
        }
        $order['order_id'] = (int) $order['id'];

        $order['user'] = (user() !== null ? user()->format($order['user_id']) : false);
        $order['pay_name'] = $this->payNameResolver->resolveForOrder(
            isset($order['pay_id']) ? $order['pay_id'] : '',
            (int) $order['order_id']
        );
        $shippingName = $order['shipping_id'] ? plugin()->valueBySlug($order['shipping_id'], 'name') : false;
        $order['shipping_name'] = $shippingName !== false ? $shippingName : '';
        // 工作台按下单币种快照展示金额，避免操作员切换币种后历史订单金额失真
        $orderCtx = currency()->contextForOrder($order);
        $hasOrderCurrency = trim((string) $order['currency_code']) !== '';
        $itemAmount = $hasOrderCurrency ? (float) $order['currency_item_amount'] : (float) $order['item_amount'];
        $shippingFee = $hasOrderCurrency ? (float) $order['currency_shipping_fee'] : (float) $order['shipping_fee'];
        $order['item_amount_format'] = Util::formatCurrency($itemAmount, $orderCtx);
        $order['shipping_fee_format'] = Util::formatCurrency($shippingFee, $orderCtx);
        $order['order_amount_format'] = Util::formatCurrency(currency()->orderAmount($order), $orderCtx);
        $orderPointView = $this->order->pointForView($order['order_point']);
        $order['order_point'] = $orderPointView['point'];
        $order['order_point_format'] = $orderPointView['point_format'];
        $order['email'] = DB::table('user')->where('id', (int) $order['user_id'])->value('email');
        $order['pay_time_limit'] = $this->order->payTimeLimit($order['created_at'], true, true);
        $order['created_at'] = date('Y-m-d H:i:s', strtotime($order['created_at']));
        $order['status_format'] = lang('order_status_' . $order['status']);

        // 收货地址字段统一从 order_address 取（dou_order 平拍 contact/phone/country/province/address/postcode 已下线）
        $addrRow = DB::table('order_address')->where('order_id', (int) $order['order_id'])->find();
        $order['contact'] = isset($addrRow['contact_name']) ? $addrRow['contact_name'] : '';
        $order['phone'] = isset($addrRow['phone']) ? $addrRow['phone'] : '';
        $order['country'] = isset($addrRow['country']) ? $addrRow['country'] : '';
        $order['province'] = isset($addrRow['province']) ? $addrRow['province'] : '';
        $order['address'] = isset($addrRow['address']) ? $addrRow['address'] : '';
        $order['postcode'] = isset($addrRow['postcode']) ? $addrRow['postcode'] : '';
        $order['item_list'] = $this->itemDecorator->decorate(
            $this->order->getOrderItem($order['order_id']),
            (int) $operatorUserId
        );
        $payEvidence = $this->paymentService->findLatestPayEvidence((int) $order['order_id']);
        $order['pay_evidence'] = $payEvidence !== '' ? attachment()->url($payEvidence) : '';
        $order['if_payment'] = plugin()->hasGroup('payment');
        if (($aftersale instanceof AftersaleService) && Config::get('features.aftersale', false)) {
            $order['if_can_aftersale'] = $aftersale->ifCanAftersale($order['order_id'], $operatorUserId);
        }

        $order['coupon'] = false;
        $order['coupons'] = array();
        $order['coupon_amount'] = 0.0;
        $order['coupon_amount_format'] = Util::formatPrice(0);
        $couponSvc = Module::make('coupon');
        if ($couponSvc) {
            $order = array_merge($order, $couponSvc->getOrderCouponView((int) $order['order_id']));
        }
        $couponAmount = isset($order['coupon_amount']) ? (float) $order['coupon_amount'] : 0.0;
        $order['item_amount_gross'] = Num::round((float) $order['item_amount'] + $couponAmount, 2);
        $order['item_amount_gross_format'] = Util::formatPrice($order['item_amount_gross']);

        return $order;
    }

    /**
     * 工作台取消订单
     *
     * @param string $orderSn
     * @return bool
     */
    public function cancelWorkOrder($orderSn)
    {
        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order || $order['status'] !== OrderStatus::PENDING) {
            return false;
        }

        $operatorId = auth('api') ? (int) auth('api')->id() : 0;

        return $this->order->cancelOrder(
            (string) $orderSn,
            'work cancelled',
            'work',
            $operatorId,
            '',
            array(OrderStatus::PENDING)
        );
    }

    /**
     * 是否存在可审核的线下付款支付腿（工作台「确认收款」入口的前置判定）。
     *
     * 与 {@see payCheckWorkOrder()} 的校验口径一致：必须存在 gateway=offlinepay 的 pending 支付腿，
     * 避免无支付腿的待确认订单显示入口、点击后失败。
     *
     * @param int $orderId
     * @return bool
     */
    public function hasPendingOfflinePaymentLeg($orderId)
    {
        $payment = $this->paymentService->findActivePendingWithEvidence((int) $orderId);
        if (!$payment || !isset($payment['gateway'])) {
            return false;
        }

        return (string) $payment['gateway'] === 'offlinepay';
    }

    /**
     * 工作台支付审核
     *
     * @param int $orderId
     * @return bool
     */
    public function payCheckWorkOrder($orderId)
    {
        $order = DB::table('order')->where('id', intval($orderId))->find();
        if (!$order
            || (string) $order['status'] !== OrderStatus::AWAITING_CONFIRMATION
            || (string) $order['pay_id'] !== 'offlinepay'
        ) {
            return false;
        }

        $payment = $this->paymentService->findActivePendingWithEvidence((int) $order['id']);
        if (!$payment
            || !isset($payment['gateway'])
            || (string) $payment['gateway'] !== 'offlinepay'
        ) {
            return false;
        }

        return $this->paymentService->markSucceeded((string) $payment['payment_sn'], '');
    }

    /**
     * 工作台更新物流
     *
     * @param int $orderId
     * @param string $shippingId
     * @param string $trackingNo
     * @return bool
     */
    public function updateWorkTracking($orderId, $shippingId, $trackingNo)
    {
        $operatorId = auth('api') ? (int) auth('api')->id() : 0;

        return $this->order->fulfill(
            (int) $orderId,
            (string) $shippingId,
            (string) $trackingNo,
            'work',
            $operatorId,
            ''
        );
    }
}
