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

use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Domain\Payment\PaymentStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Order\OrderStatusTransition;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Util;
use Dou\Front\Model\Order\Order;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台收银台业务层（收银台 / 支付页 / 线下凭证 / 货到付款）。
 *
 * 控制器仅负责参数收集与响应格式化，支付台账与状态推进在此类完成。
 */
class CashierService extends BaseService
{
    /** @var OrderCore */
    private $order;

    /** @var PaymentService */
    private $paymentService;

    /** @var OrderStatusTransition */
    private $orderStatusTransition;

    /**
         * @param OrderCore $order
         * @param PaymentService $paymentService 支付台账服务（dou_order_payment）
         * @param OrderStatusTransition $orderStatusTransition 订单状态机
         */
    public function __construct(
        OrderCore $order,
        PaymentService $paymentService,
        OrderStatusTransition $orderStatusTransition
    ) {
        $this->order = $order;
        $this->paymentService = $paymentService;
        $this->orderStatusTransition = $orderStatusTransition;
    }

    /**
     * 获取收银台订单
     *
     * @param int $userId
     * @param string $orderSn
     * @return array|null
     */
    public function getCashierOrder($userId, $orderSn)
    {
        $orderModel = Order::where('order_sn', $orderSn)
            ->where('status', OrderStatus::PENDING)
            ->where('user_id', $userId)
            ->first();
        $order = $orderModel ? $orderModel->getAttributes() : null;
        if (!$order) {
            return null;
        }

        $order['order_id'] = (int) $order['id'];
        $order = $this->decorateCurrency($order);
        $order['pay_time_limit'] = $this->order->payTimeLimit($order['created_at'], true);

        return $order;
    }

    /**
     * 获取支付页订单
     *
     * @param int $userId
     * @param string $orderSn
     * @return array|null
     */
    public function getPayOrder($userId, $orderSn)
    {
        $orderModel = Order::where('order_sn', $orderSn)
            ->where('status', OrderStatus::PENDING)
            ->where('user_id', $userId)
            ->first();
        $order = $orderModel ? $orderModel->getAttributes() : null;
        if (!$order) {
            return null;
        }

        $order['order_id'] = (int) $order['id'];
        $order = $this->decorateCurrency($order);
        $order['pay_time_limit'] = $this->order->payTimeLimit($order['created_at'], true);

        return $order;
    }

    /**
     * 按订单币快照装饰订单：格式化应付并附带币种上下文。
     *
     * @param array $order
     * @return array
     */
    private function decorateCurrency(array $order)
    {
        $ctx = currency()->contextForOrder($order);
        $order['currency_code'] = $ctx['code'];
        $order['currency_rate'] = $ctx['rate'];
        $order['currency_decimal'] = $ctx['decimals'];
        $order['currency_symbol'] = $ctx['symbol'];
        $order['order_amount_format'] = Util::formatCurrency(currency()->orderAmount($order), $ctx);

        return $order;
    }

    /** 根据订单与成功支付台账计算剩余应付，购物订单不抵扣储值余额。 */
    public function remainingAmount(array $order)
    {
        $orderId = isset($order['order_id']) ? (int) $order['order_id'] : (int) $order['id'];
        $paid = (float) DB::table('order_payment')->where('order_id', $orderId)
            ->where('status', PaymentStatus::SUCCEEDED)->sum('amount');
        return max(0, round(currency()->orderAmount($order) - $paid, 2));
    }

    /** 上传付款凭证前校验订单和线下支付记录，不执行任何余额扣款。 */
    public function assertPaymentReady($userId, $orderSn, $paymentSn = '')
    {
        $order = DB::table('order')->where('order_sn', (string) $orderSn)
            ->where('user_id', (int) $userId)->where('status', OrderStatus::PENDING)->find();
        $payment = $this->paymentService->findBySn((string) $paymentSn);
        $ok = $order && $payment && (int) $payment['order_id'] === (int) $order['id']
            && (string) $payment['gateway'] === 'offlinepay'
            && (string) $payment['status'] === PaymentStatus::PENDING;
        return array('ok' => (bool) $ok, 'msg' => $ok ? '' : lang('order_payment_invalid', lang('illegal')));
    }

    /**
     * 保存线下付款凭证
     *
     * 把上传后的凭证路径挂到对应 dou_order_payment 行（payment_sn 来自 offlinepay
     * 渲染的上传表单），并把订单状态推进到 awaiting_confirmation 等待管理员确认。
     * payment 自身仍保持 pending，由后台 payCheck / payReject 推进到终态。
     *
     * @param int $userId
     * @param string $orderSn
     * @param string $paymentSn 上传表单内嵌的 payment_sn
     * @param string $payEvidencePath attachment()->store 返回的文件路径
     * @return bool
     */
    public function savePayEvidence($userId, $orderSn, $paymentSn, $payEvidencePath)
    {
        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->find();
        if (!$order || (string) $order['status'] !== OrderStatus::PENDING) {
            return false;
        }

        $paymentSn = (string) $paymentSn;
        $payment = $paymentSn !== '' ? $this->paymentService->findBySn($paymentSn) : null;
        if (!$payment
            || (int) $payment['order_id'] !== (int) $order['id']
            || (string) $payment['gateway'] !== 'offlinepay'
            || (string) $payment['status'] !== PaymentStatus::PENDING
        ) {
            Log::error('savePayEvidence invalid payment binding', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $orderSn,
                    'user_id' => (int) $userId,
                    'payment_sn' => $paymentSn,
                ),
            ));
            return false;
        }

        if (!(string) $payEvidencePath) {
            return false;
        }

        $offlineAmount = round((float) $payment['amount'], 2);
        $paid = round((float) DB::table('order_payment')->where('order_id', (int) $order['id'])
            ->where('status', PaymentStatus::SUCCEEDED)->sum('amount'), 2);
        $remaining = max(0, round(currency()->orderAmount($order) - $paid, 2));

        if ($offlineAmount + 0.005 < $remaining) {
            Log::error('savePayEvidence offline amount not covering remaining', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $orderSn,
                    'user_id' => (int) $userId,
                    'offline_amount' => $offlineAmount,
                    'remaining' => $remaining,
                ),
            ));
            return false;
        }

        $this->paymentService->attachPayEvidence($paymentSn, $payEvidencePath);
        $this->orderStatusTransition->changeStatus($orderSn, OrderStatus::AWAITING_CONFIRMATION);

        $currentStatus = (string) DB::table('order')->where('id', (int) $order['id'])->value('status');
        if ($currentStatus !== OrderStatus::AWAITING_CONFIRMATION) {
            Log::error('savePayEvidence order not advanced', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $orderSn,
                    'status' => $currentStatus,
                ),
            ));
            return false;
        }

        DB::table('order')
            ->where('id', (int) $order['id'])
            ->data(array('pay_id' => 'offlinepay'))
            ->update();

        return true;
    }

    /**
     * 货到付款
     *
     * 货到付款语义为「锁定订单等待发货后线下结算」，因此 payment 立即记成功；
     * 订单状态由 {@see PaymentService::markSucceeded()} 内部 changeStatus(PAID)
     * 推进（无物流插件 / 非 product 模块时会顺势推到 COMPLETED）。
     *
     * @param int $userId
     * @param string $orderSn
     * @param string $paymentSn 收银表单内嵌的 payment_sn
     * @return bool
     */
    public function submitCod($userId, $orderSn, $paymentSn)
    {
        $paymentSn = (string) $paymentSn;
        if ($paymentSn === '') {
            Log::error('submitCod missing payment_sn', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $orderSn,
                    'user_id' => (int) $userId,
                ),
            ));
            return false;
        }

        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->find();
        if (!$order || (string) $order['status'] !== OrderStatus::PENDING
            || !OrderPolicy::canUseCod($order['module'])) {
            return false;
        }

        $payment = $this->paymentService->findBySn($paymentSn);
        if (!$payment
            || (int) $payment['order_id'] !== (int) $order['id']
            || (string) $payment['gateway'] !== 'cod'
            || (string) $payment['status'] !== PaymentStatus::PENDING
        ) {
            Log::error('submitCod invalid payment binding', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $orderSn,
                    'user_id' => (int) $userId,
                    'payment_sn' => $paymentSn,
                ),
            ));
            return false;
        }

        if (!$this->paymentService->markSucceeded($paymentSn, '')) {
            return false;
        }

        DB::table('order')
            ->where('id', (int) $order['id'])
            ->data(array('pay_id' => 'cod'))
            ->update();

        return true;
    }
}
