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

namespace Dou\Core\Service\Payment;

use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Domain\Payment\PaymentStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderStatusTransition;
use Dou\Core\Service\User\UserStatsService;
use Dou\Core\Service\Wallet\WalletService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单笔支付尝试的台账服务。
 *
 * 一个订单可能有多次支付尝试（用户付款失败重试 / 切换网关 / 离线付款多次提交）；
 * 每次 `createForOrder()` 都会落一行 dou_order_payment，作为第三方网关侧的 out_trade_no。
 *
 * 状态机：参见 {@see PaymentStatus}。
 *
 * Webhook 幂等：
 * - DB 层 UNIQUE KEY `gateway_txn` 保证相同 (gateway, transaction_id) 不会落多行
 * - {@see markSucceeded()} 内置幂等：已成功的 payment 直接返回 true，不再触发订单联动
 *
 * 联动订单：payment 成功后由本服务调用 {@see OrderStatusTransition::changeStatus}
 * 把对应订单推进到 PAID；订单端的状态机保证只有合法迁移会生效。
 */
class PaymentService extends BaseService
{
    /** @var PaymentSnGenerator */
    private $snGenerator;

    /** @var OrderStatusTransition */
    private $orderStatusTransition;

    /** @var WalletService */
    private $wallet;

    /** @var UserStatsService */
    private $userStats;

    /** 金额比较容差：避免 decimal(10,2) 浮点误差导致「已付满」判定漏判。 */
    const AMOUNT_EPSILON = 0.005;

    /**
     * @param PaymentSnGenerator $snGenerator
     * @param OrderStatusTransition $orderStatusTransition
     * @param WalletService $wallet 余额台账服务（混合支付的钱包腿）
     * @param UserStatsService $userStats 会员统计（待结算钱包腿结算时读取可用余额）
     */
    public function __construct(
        PaymentSnGenerator $snGenerator,
        OrderStatusTransition $orderStatusTransition,
        WalletService $wallet,
        UserStatsService $userStats
    ) {
        $this->snGenerator = $snGenerator;
        $this->orderStatusTransition = $orderStatusTransition;
        $this->wallet = $wallet;
        $this->userStats = $userStats;
    }

    /**
     * 为订单创建一行 pending 状态的 payment。
     *
     * @param int $orderId
     * @param string $orderSn
     * @param string $gateway 支付插件 slug（pay_id）
     * @param mixed $amount 本次支付金额（订单币）
     * @param int $expiredAt 0 表示不主动过期（仍可被 reconcile 兜底）
     * @param string $currencyCode 订单币种；空表示基准币历史行为
     * @param mixed $baseAmount 基准币等值金额；null 表示与 amount 相同（基准币订单）
     * @return array{id:int, payment_sn:string}
     */
    public function createForOrder($orderId, $orderSn, $gateway, $amount, $expiredAt = 0, $currencyCode = '', $baseAmount = null)
    {
        $paymentSn = $this->snGenerator->generate();
        DB::table('order_payment')->data(array(
            'payment_sn' => $paymentSn,
            'order_id' => (int) $orderId,
            'order_sn' => (string) $orderSn,
            'gateway' => (string) $gateway,
            'currency_code' => strtoupper(trim((string) $currencyCode)),
            'amount' => round((float) $amount, 2),
            'base_amount' => $baseAmount === null ? round((float) $amount, 2) : round((float) $baseAmount, 2),
            'status' => PaymentStatus::PENDING,
            'expired_at' => ((int) $expiredAt > 0) ? date('Y-m-d H:i:s', (int) $expiredAt) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ))->insert();

        return array(
            'id' => (int) DB::insertId(),
            'payment_sn' => $paymentSn,
        );
    }

    /**
     * 按 payment_sn 查找。
     *
     * @param string $paymentSn
     * @return array|null
     */
    public function findBySn($paymentSn)
    {
        if ((string) $paymentSn === '') {
            return null;
        }
        $row = DB::table('order_payment')->where('payment_sn', $paymentSn)->find();
        return $row ? $row : null;
    }

    /**
     * 按 gateway + transaction_id 查找（webhook 二次确认幂等用）。
     *
     * @param string $gateway
     * @param string $transactionId
     * @return array|null
     */
    public function findByGatewayTxn($gateway, $transactionId)
    {
        if ((string) $gateway === '' || (string) $transactionId === '') {
            return null;
        }
        $row = DB::table('order_payment')
            ->where('gateway', $gateway)
            ->where('transaction_id', $transactionId)
            ->find();
        return $row ? $row : null;
    }

    /**
     * 取订单当前最新的活跃 payment（status=pending）。
     *
     * @param int $orderId
     * @return array|null
     */
    public function findActivePending($orderId)
    {
        // 待结算钱包腿只是混合支付意向，不是可复用的网关支付尝试，必须排除
        $row = DB::table('order_payment')
            ->where('order_id', (int) $orderId)
            ->where('gateway', '<>', 'wallet')
            ->where('status', PaymentStatus::PENDING)
            ->order('id DESC')
            ->find();
        return $row ? $row : null;
    }

    /**
     * 取订单当前最新一条「带付款凭证」的 pending payment；无凭证时回退到最新 pending。
     *
     * 线下付款多次提交凭证可能留下多条 pending，管理员审核应以实际带凭证的那条为准。
     *
     * @param int $orderId
     * @return array|null
     */
    public function findActivePendingWithEvidence($orderId)
    {
        $row = DB::table('order_payment')
            ->where('order_id', (int) $orderId)
            ->where('gateway', '<>', 'wallet')
            ->where('status', PaymentStatus::PENDING)
            ->where('pay_evidence', '<>', '')
            ->order('id DESC')
            ->find();

        return $row ? $row : $this->findActivePending($orderId);
    }

    /**
     * 把 payment 推进到 SUCCEEDED，并把可收款订单推进到 PAID。
     *
     * 并发安全：payment / order 均使用 FOR UPDATE 串行化；已成功直接幂等返回。
     * 金额校验：传入 $paidAmount 时与 payment.amount 比较，防止网关侧金额异常。
     * 迟到支付：订单已 PAID/COMPLETED/CANCELLED/REFUNDING 等状态时，仍把资金记入台账，
     * 但不修改订单状态，同时生成 pending 退款单并写 critical 日志。
     *
     * @param string $paymentSn
     * @param string $transactionId 第三方网关流水号
     * @param string $rawCallback 第三方回调原文，留底用
     * @param mixed $paidAmount 网关实际支付金额；null 表示调用方不提供
     * @param string $currency 网关币种（留痕）
     * @return bool true=成功推进/已成功/已登记迟到支付；false=参数不合法 / 金额不符 / 状态机拒绝
     */
    public function markSucceeded($paymentSn, $transactionId, $rawCallback = '', $paidAmount = null, $currency = '')
    {
        $payment = $this->findBySn($paymentSn);
        if (!$payment) {
            $this->logError('payment not found for markSucceeded', array('payment_sn' => $paymentSn));
            return false;
        }

        $paymentId = (int) $payment['id'];
        $orderId = (int) $payment['order_id'];
        $orderSn = (string) $payment['order_sn'];

        // 币种一致性：回调币种与支付腿快照不一致时拒绝，避免错币入账
        $paymentCurrency = isset($payment['currency_code']) ? strtoupper(trim((string) $payment['currency_code'])) : '';
        $gatewayCurrency = strtoupper(trim((string) $currency));
        if ($paymentCurrency !== '' && $gatewayCurrency !== '' && $paymentCurrency !== $gatewayCurrency) {
            $this->logCritical('payment currency mismatch', array(
                'payment_sn' => (string) $paymentSn,
                'order_sn' => $orderSn,
                'expected_currency' => $paymentCurrency,
                'paid_currency' => $gatewayCurrency,
            ));
            return false;
        }

        // 已成功：另一支付腿可能后到，仅尝试收尾（自愈），不重复落库 / 不重复累计 gateway_paid
        if ((string) $payment['status'] === PaymentStatus::SUCCEEDED) {
            $this->settleWalletThenFinalize($orderSn);
            return true;
        }
        if (!PaymentStatus::canTransit($payment['status'], PaymentStatus::SUCCEEDED)) {
            $this->logError('illegal payment transition: ' . $payment['status'] . ' -> succeeded', array(
                'payment_sn' => (string) $paymentSn,
            ));
            return false;
        }

        if ($paidAmount !== null) {
            $expected = round((float) $payment['amount'], 2);
            $actual = round((float) $paidAmount, 2);
            if (abs($expected - $actual) > self::AMOUNT_EPSILON) {
                $this->logCritical('payment amount mismatch', array(
                    'payment_sn' => (string) $paymentSn,
                    'order_sn' => $orderSn,
                    'expected' => $expected,
                    'paid' => $actual,
                    'currency' => (string) $currency,
                ));
                return false;
            }
        }

        $normal = false;
        $orderStatus = '';
        try {
            DB::beginTransaction();

            $lockedPayment = DB::table('order_payment')->where('id', $paymentId)->lock('FOR UPDATE')->find();
            if (!$lockedPayment) {
                DB::rollback();
                return false;
            }
            if ((string) $lockedPayment['status'] === PaymentStatus::SUCCEEDED) {
                DB::commit();
                $this->settleWalletThenFinalize($orderSn);
                return true;
            }
            if (!PaymentStatus::canTransit($lockedPayment['status'], PaymentStatus::SUCCEEDED)) {
                DB::rollback();
                $this->logError('illegal payment transition: ' . $lockedPayment['status'] . ' -> succeeded', array(
                    'payment_sn' => (string) $paymentSn,
                ));
                return false;
            }

            $order = DB::table('order')->where('id', $orderId)->lock('FOR UPDATE')->find();
            if (!$order) {
                DB::rollback();
                return false;
            }
            $orderStatus = (string) $order['status'];
            if ((string) $lockedPayment['gateway'] === 'cod' && !OrderPolicy::canUseCod($order['module'])) {
                DB::rollback();
                return false;
            }
            $normal = ($orderStatus === OrderStatus::PENDING || $orderStatus === OrderStatus::AWAITING_CONFIRMATION);

            $data = array(
                'status' => PaymentStatus::SUCCEEDED,
                'transaction_id' => ((string) $transactionId === '') ? null : (string) $transactionId,
                'paid_at' => date('Y-m-d H:i:s'),
            );
            if ((string) $rawCallback !== '') {
                $data['raw_callback'] = (string) $rawCallback;
            }
            $paymentUpdated = DB::table('order_payment')
                ->where('id', $paymentId)
                ->data($data)
                ->update();
            if ($paymentUpdated === false) {
                throw new \RuntimeException('payment confirmation write failed');
            }

            // 累计网关已付金额（混合支付 / 多次网关重试的汇总，退款拆分按此分账）
            // gateway_paid 为基准币快照：外币支付腿按落库时的 base_amount 累计，缺省按订单快照换算
            $gatewayPaidBase = isset($lockedPayment['base_amount']) && (float) $lockedPayment['base_amount'] > 0
                ? (float) $lockedPayment['base_amount']
                : currency()->toBase((float) $lockedPayment['amount'], currency()->contextForOrder($order));
            $orderUpdated = DB::table('order')
                ->where('id', $orderId)
                ->exp('gateway_paid', '+', $gatewayPaidBase);
            if ($orderUpdated === false) {
                throw new \RuntimeException('payment order total write failed');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $this->logError('markSucceeded exception: ' . $e->getMessage(), array(
                'payment_sn' => (string) $paymentSn,
                'order_sn' => $orderSn,
            ));
            return false;
        } catch (\Throwable $e) {
            DB::rollback();
            $this->logError('markSucceeded error: ' . $e->getMessage(), array('payment_sn' => (string) $paymentSn));
            return false;
        }

        if ($normal) {
            // 网关腿已成功：先结算待结算钱包腿（混合支付的余额意向），再判断是否付满
            return $this->settleWalletThenFinalize($orderSn);
        }

        // 订单已付款 / 已取消 / 退款中等：资金已到账，保留成功腿并登记待退款
        $this->createRefundRequest($orderId, $orderSn, $paymentId, (string) $lockedPayment['gateway'], (float) $lockedPayment['amount'], 'late_payment_' . $orderStatus);
        $this->logCritical('late payment captured for non-payable order', array(
            'payment_sn' => (string) $paymentSn,
            'order_sn' => $orderSn,
            'order_status' => $orderStatus,
            'amount' => (float) $lockedPayment['amount'],
            'currency' => (string) $currency,
        ));

        return true;
    }

    /**
     * 尝试把订单推进到 PAID：当订单全部成功支付腿（网关 + 钱包）金额累计覆盖
     * order_amount 时触发 changeStatus(PAID)。混合支付两腿可任意先后到达，每腿
     * 成功后都调用本方法，凑满才收尾，未满保持 PENDING（部分支付）。
     *
     * 幂等：订单已 PAID/COMPLETED 直接返回 true；非可收款状态返回 false。
     *
     * @param string $orderSn
     * @return bool true=已收尾或已是付款终态；false=未付满 / 订单不可收款 / 推进失败
     */
    public function tryFinalizeOrder($orderSn)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return false;
        }

        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return false;
        }

        $status = (string) $order['status'];
        if ($status === OrderStatus::PAID || $status === OrderStatus::COMPLETED) {
            return true;
        }
        if ($status !== OrderStatus::PENDING && $status !== OrderStatus::AWAITING_CONFIRMATION) {
            return false;
        }

        $orderId = $this->resolveOrderId($order);
        $paid = (float) DB::table('order_payment')
            ->where('order_id', $orderId)
            ->where('status', PaymentStatus::SUCCEEDED)
            ->sum('amount');
        // 应付按订单币口径：新订单取 currency_order_amount，旧订单回退 order_amount
        $orderAmount = currency()->orderAmount($order);
        if ($paid + self::AMOUNT_EPSILON < $orderAmount) {
            return false;
        }

        // 多次支付尝试 / 并行回调导致的超额收款：登记一笔待退款，避免静默多收
        if ($paid > $orderAmount + self::AMOUNT_EPSILON) {
            $this->createRefundRequest(
                $orderId,
                $orderSn,
                0,
                'overpay',
                round($paid - $orderAmount, 2),
                'overpaid'
            );
            $this->logCritical('order overpaid', array(
                'order_sn' => $orderSn,
                'paid' => round($paid, 2),
                'order_amount' => $orderAmount,
            ));
        }

        if ((string) $order['pay_id'] === '' && $orderId > 0) {
            $legs = (array) DB::table('order_payment')
                ->where('order_id', $orderId)
                ->where('status', PaymentStatus::SUCCEEDED)
                ->select();
            if (!empty($legs)) {
                $allWallet = true;
                foreach ($legs as $leg) {
                    if ((string) $leg['gateway'] !== 'wallet') {
                        $allWallet = false;
                        break;
                    }
                }
                if ($allWallet) {
                    DB::table('order')->where('id', $orderId)->data(array('pay_id' => 'wallet'))->update();
                }
            }
        }

        $this->orderStatusTransition->changeStatus($orderSn, OrderStatus::PAID);

        $currentStatus = (string) DB::table('order')->where('order_sn', $orderSn)->value('status');
        if ($currentStatus !== OrderStatus::PAID && $currentStatus !== OrderStatus::COMPLETED) {
            $this->logError('order not advanced after changeStatus', array(
                'order_sn' => $orderSn,
                'order_status' => $currentStatus,
            ));
            return false;
        }

        // 订单已收尾：关闭其它仍 pending 的支付尝试，避免持续挂起；迟到成功会走
        // markSucceeded 的迟到分支登记退款（PaymentStatus 已允许 closed/failed -> succeeded）。
        $this->closePendingForOrder($orderSn, 'order finalized');

        return true;
    }

    /**
     * 关闭订单下仍 pending 的支付尝试（订单已付 / 已取消时调用）。
     *
     * @param string $orderSn
     * @param string $reason
     * @param int $exceptPaymentId 保留的 payment id（通常为刚成功的腿）
     * @return int 关闭数量
     */
    public function closePendingForOrder($orderSn, $reason = '', $exceptPaymentId = 0)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return 0;
        }

        $rows = (array) DB::table('order_payment')
            ->where('order_sn', $orderSn)
            ->where('status', PaymentStatus::PENDING)
            ->order('id ASC')
            ->select();

        $closed = 0;
        foreach ($rows as $row) {
            if ((int) $row['id'] === (int) $exceptPaymentId) {
                continue;
            }
            try {
                if ($this->markClosed((string) $row['payment_sn'], $reason)) {
                    $closed++;
                }
            } catch (\Exception $e) {
                $this->logError('close pending payment failed: ' . $e->getMessage(), array(
                    'payment_sn' => (string) $row['payment_sn'],
                    'order_sn' => $orderSn,
                ));
            }
        }

        return $closed;
    }

    /**
     * 关闭订单下仍 pending 的钱包意向腿（不扣款）。
     *
     * 用于线下凭证已按全额上传、此前遗留的钱包意向不再参与的清理场景。
     *
     * @param string $orderSn
     * @param string $reason
     * @return int 关闭数量
     */
    public function closePendingWalletIntents($orderSn, $reason = '')
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return 0;
        }

        $rows = (array) DB::table('order_payment')
            ->where('order_sn', $orderSn)
            ->where('gateway', 'wallet')
            ->where('status', PaymentStatus::PENDING)
            ->order('id ASC')
            ->select();

        $closed = 0;
        foreach ($rows as $row) {
            try {
                if ($this->markClosed((string) $row['payment_sn'], $reason !== '' ? $reason : 'wallet intent closed')) {
                    $closed++;
                }
            } catch (\Exception $e) {
                $this->logError('close pending wallet intent failed: ' . $e->getMessage(), array(
                    'payment_sn' => (string) $row['payment_sn'],
                    'order_sn' => $orderSn,
                ));
            }
        }

        return $closed;
    }

    /**
     * 取消订单时为已成功的网关腿登记待退款（钱包腿由 refundWalletPaymentOnCancel 处理）。
     *
     * @param string $orderSn
     * @param string $reason
     * @return int 登记数量
     */
    public function refundCapturedLegsOnCancel($orderSn, $reason = '')
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return 0;
        }
        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return 0;
        }
        $orderId = $this->resolveOrderId($order);
        if ($orderId <= 0) {
            return 0;
        }

        $count = 0;
        $legs = DB::table('order_payment')
            ->where('order_id', $orderId)
            ->where('gateway', '<>', 'wallet')
            ->where('status', PaymentStatus::SUCCEEDED)
            ->select();
        foreach ((array) $legs as $leg) {
            $refundSn = $this->createRefundRequest(
                $orderId,
                $orderSn,
                (int) $leg['id'],
                (string) $leg['gateway'],
                (float) $leg['amount'],
                'cancel_' . (string) $reason
            );
            if ($refundSn !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 登记一笔待退款（迟到支付 / 超额支付），按支付腿或订单+渠道去重。
     *
     * @param int $orderId
     * @param string $orderSn
     * @param int $paymentId 0 表示订单级退款（如超额支付）
     * @param string $channel
     * @param mixed $amount
     * @param string $reason
     * @return string refund_sn；未创建返回空串
     */
    private function createRefundRequest($orderId, $orderSn, $paymentId, $channel, $amount, $reason)
    {
        $amount = round((float) $amount, 2);
        if ($amount <= self::AMOUNT_EPSILON) {
            return '';
        }

        try {
            $query = DB::table('order_refund')
                ->where('order_id', (int) $orderId)
                ->where('status', 'IN', array('pending', 'succeeded'));
            if ((int) $paymentId > 0) {
                $query->where('order_payment_id', (int) $paymentId);
            } else {
                $query->where('channel', (string) $channel);
            }
            if ($query->exists()) {
                return '';
            }

            $refundSn = $this->snGenerator->generate();
            $attempts = 0;
            while (DB::table('order_refund')->where('refund_sn', $refundSn)->exists() && $attempts < 5) {
                $refundSn = $this->snGenerator->generate();
                $attempts++;
            }

            $order = DB::table('order')->where('id', (int) $orderId)->find();
            $ctx = currency()->contextForOrder($order);
            DB::table('order_refund')->data(array(
                'refund_sn' => $refundSn,
                'order_id' => (int) $orderId,
                'aftersale_id' => 0,
                'order_payment_id' => (int) $paymentId,
                'channel' => (string) $channel,
                'currency_code' => $ctx['code'],
                'currency_rate' => $ctx['rate'],
                'currency_decimal' => $ctx['decimals'],
                'amount' => $amount,
                'base_amount' => currency()->toBase($amount, $ctx),
                'status' => 'pending',
                'raw_callback' => (string) $reason,
                'created_at' => date('Y-m-d H:i:s'),
            ))->insert();

            return $refundSn;
        } catch (\Exception $e) {
            $this->logCritical('create refund request failed: ' . $e->getMessage(), array(
                'order_id' => (int) $orderId,
                'order_sn' => (string) $orderSn,
                'payment_id' => (int) $paymentId,
                'amount' => $amount,
            ));
            return '';
        }
    }

    /** 购物余额支付已移除；所有调用均拒绝，不扣款也不生成支付记录。 */
    public function markSucceededByWallet($orderSn, $userId, $amount)
    {
        // 储值卡仅支持线下消费，服务层禁止新余额支付。
        return false;
    }

    /** 购物余额抵扣已移除；不再登记待结算意向。 */
    public function applyPendingWalletLeg($orderSn, $userId, $amount)
    {
        return 0.0;
    }

    /** 关闭未结算余额意向，不扣款；返回金额恒为零。 */
    public function settlePendingWalletLegs($orderSn)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return 0.0;
        }

        $legs = (array) DB::table('order_payment')
            ->where('order_sn', $orderSn)
            ->where('gateway', 'wallet')
            ->where('status', PaymentStatus::PENDING)
            ->order('id ASC')
            ->select();
        if (empty($legs)) {
            return 0.0;
        }

        $settled = 0.0;
        // 正常只有一条意向腿（重复提交会复用），上限兜底异常数据
        foreach (array_slice($legs, 0, 5) as $leg) {
            $settled = round($settled + $this->settleWalletLeg($leg), 2);
        }

        return $settled;
    }

    /** 关闭余额意向，禁止将其结算为新的购物扣款。 */
    private function settleWalletLeg(array $leg)
    {
        // 旧意向尚未扣款，关闭后重新支付剩余应付。
        $this->markClosed((string) $leg['payment_sn'], 'wallet payment disabled');
        return 0.0;
    }

    /**
     * 结算待结算钱包腿并尝试收尾订单（网关腿成功后的统一收尾入口）。
     *
     * @param string $orderSn
     * @return bool 同 {@see tryFinalizeOrder()}
     */
    private function settleWalletThenFinalize($orderSn)
    {
        $this->settlePendingWalletLegs($orderSn);

        return $this->tryFinalizeOrder($orderSn);
    }

    /**
     * 取消 / 超时订单时退回已扣的钱包余额腿。
     *
     * 仅处理 gateway='wallet' 且 status=SUCCEEDED 的腿：逐腿原额退回会员余额
     * （createMoney 正向），成功后置该腿为 REFUNDED 防重复退款。网关腿在订单 PENDING
     * 阶段仍是 pending（未成功），由对账关闭、无需在此退款；已 PAID 订单的整单退款属
     * 售后流程（P1.3 的网关先退、余额后退），不走本方法。
     *
     * @param string $orderSn
     * @return int 实际退回的钱包腿数量
     */
    public function refundWalletPaymentOnCancel($orderSn)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return 0;
        }
        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return 0;
        }
        $userId = (int) $order['user_id'];

        $legs = DB::table('order_payment')
            ->where('order_sn', $orderSn)
            ->where('gateway', 'wallet')
            ->where('status', PaymentStatus::SUCCEEDED)
            ->select();

        $refunded = 0;
        foreach ((array) $legs as $leg) {
            $amount = round((float) $leg['amount'], 2);
            if ($amount <= 0) {
                continue;
            }
            try {
                DB::beginTransaction();

                $lockedStatus = (string) DB::table('order_payment')
                    ->where('id', (int) $leg['id'])
                    ->lock('FOR UPDATE')
                    ->value('status');
                if ($lockedStatus !== PaymentStatus::SUCCEEDED) {
                    DB::rollback();
                    continue;
                }

                $ok = $this->wallet->createMoney($userId, 'user', $userId, 'order_refund', $amount, $orderSn, 0, 'order_refund', 0, '', '');
                if (!$ok) {
                    DB::rollback();
                    $this->logError('wallet refund failed', array(
                        'order_sn' => $orderSn,
                        'payment_sn' => (string) $leg['payment_sn'],
                    ));
                    continue;
                }

                if (DB::table('order_payment')
                    ->where('id', (int) $leg['id'])
                    ->data(array('status' => PaymentStatus::REFUNDED))
                    ->update() === false
                ) {
                    throw new \Exception('wallet refund leg update failed');
                }

                DB::commit();
                $refunded++;
            } catch (\Exception $e) {
                DB::rollback();
                $this->logError('wallet refund exception: ' . $e->getMessage(), array(
                    'order_sn' => $orderSn,
                ));
            }
        }

        if ($refunded > 0) {
            // 以仍成功的钱包腿重新校准快照，避免部分退款后 wallet_paid 虚高
            $stillPaid = round((float) DB::table('order_payment')
                ->where('order_sn', $orderSn)
                ->where('gateway', 'wallet')
                ->where('status', PaymentStatus::SUCCEEDED)
                ->sum('amount'), 2);
            if (DB::table('order')->where('order_sn', $orderSn)->data(array('wallet_paid' => $stillPaid))->update() === false) {
                $this->logError('wallet_paid cancel recalibration failed', array('order_sn' => $orderSn));
            }
        }
        return $refunded;
    }

    /**
     * 订单当前可退金额：成功支付腿合计 − pending/succeeded 退款合计。
     *
     * 售后审核、工作台处理与自动流转统一使用该口径，避免“审批金额大于实际可退额”导致退款卡住。
     *
     * @param int $orderId
     * @return float
     */
    public function availableRefundAmount($orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return 0.0;
        }
        $totalPaid = (float) DB::table('order_payment')
            ->where('order_id', $orderId)
            ->where('status', 'IN', array(PaymentStatus::SUCCEEDED, PaymentStatus::PARTIAL_REFUNDED, PaymentStatus::REFUNDED))
            ->sum('amount');
        $reserved = (float) DB::table('order_refund')
            ->where('order_id', $orderId)
            ->where('status', 'IN', array('pending', 'succeeded'))
            ->sum('amount');
        $available = round($totalPaid - $reserved, 2);

        return $available > 0 ? $available : 0.0;
    }

    /**
     * 售后退款分账：按 dou_order_payment 的成功支付腿，gateway 优先、wallet 在后，
     * 把 $refundAmount 拆到各腿并落 dou_order_refund。
     *
     * 渠道处理（小微商城现实约束：网关插件无统一退款 API）：
     * - wallet 腿：同步退回会员余额（createMoney 正向），dou_order_refund.status=succeeded，
     *   并把该支付腿标 REFUNDED / PARTIAL_REFUNDED。
     * - gateway 腿：只落 dou_order_refund.status=pending（退款申请），实际退款由管理员在
     *   网关后台手工完成后调 {@see markRefundSucceeded} 收尾；此时不改支付腿状态。
     *
     * 幂等：每腿可退额 = 腿金额 − 该腿已 succeeded 的退款合计；可重复调用补退剩余。
     *
     * @param string $orderSn
     * @param mixed $refundAmount 本次拟退总额（>0）
     * @param int $aftersaleId 关联售后单（0 表示非售后退款）
     * @param string $operatorType
     * @param int $operatorId
     * @return array ['succeeded_amount'=>float, 'pending_amount'=>float, 'refund_sns'=>array]
     */
    public function refundOrder($orderSn, $refundAmount, $aftersaleId = 0, $operatorType = 'admin', $operatorId = 0)
    {
        $orderSn = (string) $orderSn;
        $remaining = round((float) $refundAmount, 2);
        $result = array('succeeded_amount' => 0.0, 'pending_amount' => 0.0, 'refund_sns' => array());
        if ($orderSn === '' || $remaining <= 0) {
            return $result;
        }

        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return $result;
        }
        if (!OrderPolicy::isRefundable(isset($order['module']) ? $order['module'] : '')) {
            $this->logError('refund blocked for non-refundable module', array(
                'order_sn' => $orderSn,
                'module' => isset($order['module']) ? (string) $order['module'] : '',
            ));
            return $result;
        }
        $orderId = $this->resolveOrderId($order);
        if ($orderId <= 0) {
            return $result;
        }
        $userId = (int) $order['user_id'];
        $ctx = currency()->contextForOrder($order);

        try {
            DB::beginTransaction();

            // 订单行锁串行化同一订单的退款，避免并发重复计算可退额
            if (!DB::table('order')->where('id', $orderId)->lock('FOR UPDATE')->value('id')) {
                throw new \Exception('order not found');
            }

            if ((int) $aftersaleId > 0) {
                // 订单锁之后锁售后：并发派发按最新台账重新计算批准余量。
                $aftersale = DB::table('aftersale')->where('id', (int) $aftersaleId)->lock('FOR UPDATE')->find();
                if (!$aftersale || (int) $aftersale['order_id'] !== $orderId
                    || (string) $aftersale['status'] !== 'refunding') {
                    throw new \Exception('aftersale not refundable');
                }
                $assigned = 0.0;
                $succeeded = 0.0;
                $refundRows = DB::table('order_refund')->where('aftersale_id', (int) $aftersaleId)
                    ->whereIn('status', array('pending', 'succeeded'))->lock('FOR UPDATE')->select();
                foreach ((array) $refundRows as $refundRow) {
                    $assigned += (float) $refundRow['amount'];
                    if ((string) $refundRow['status'] === 'succeeded') {
                        $succeeded += (float) $refundRow['amount'];
                    }
                }
                $assigned += max(0, (float) $aftersale['refunded_amount'] - $succeeded);
                $remaining = min($remaining, max(0, round((float) $aftersale['approved_amount'] - $assigned, 2)));
                if ($remaining <= self::AMOUNT_EPSILON) {
                    DB::commit();
                    return $result;
                }
            }

            // 成功支付腿，gateway 优先（wallet 排最后）
            $legs = (array) DB::table('order_payment')
                ->where('order_id', $orderId)
                ->where('status', 'IN', array(PaymentStatus::SUCCEEDED, PaymentStatus::PARTIAL_REFUNDED))
                ->order('id ASC')
                ->lock('FOR UPDATE')
                ->select();
            usort($legs, function ($a, $b) {
                $aw = ($a['gateway'] === 'wallet') ? 1 : 0;
                $bw = ($b['gateway'] === 'wallet') ? 1 : 0;
                return $aw - $bw;
            });

            // 订单级剩余可退额（与 availableRefundAmount 同一口径）
            $available = $this->availableRefundAmount($orderId);
            if ($available <= self::AMOUNT_EPSILON) {
                DB::commit();
                return $result;
            }
            if ($remaining > $available) {
                $remaining = $available;
            }

            foreach ($legs as $leg) {
                if ($remaining <= self::AMOUNT_EPSILON) {
                    break;
                }
                $legId = (int) $leg['id'];
                $legAmount = round((float) $leg['amount'], 2);
                $alreadyRefunded = round((float) DB::table('order_refund')
                    ->where('order_payment_id', $legId)
                    ->where('status', 'succeeded')
                    ->sum('amount'), 2);
                $pendingRefunded = round((float) DB::table('order_refund')
                    ->where('order_payment_id', $legId)
                    ->where('status', 'pending')
                    ->sum('amount'), 2);
                $legRefundable = round($legAmount - $alreadyRefunded - $pendingRefunded, 2);
                if ($legRefundable <= self::AMOUNT_EPSILON) {
                    continue;
                }
                $alloc = ($remaining < $legRefundable) ? $remaining : $legRefundable;
                $alloc = round($alloc, 2);

                if ($leg['gateway'] === 'wallet') {
                    $refundSn = $this->snGenerator->generate();
                    $ok = $this->wallet->createMoney(
                        $userId,
                        (string) $operatorType,
                        (int) $operatorId,
                        'order_refund',
                        $alloc,
                        $orderSn,
                        0,
                        'order_refund',
                        (int) $aftersaleId,
                        lang('money_action_order_refund'),
                        ''
                    );
                    if (!$ok) {
                        throw new \Exception('wallet refund failed');
                    }
                    $refundId = DB::table('order_refund')->data(array(
                        'refund_sn' => $refundSn,
                        'order_id' => $orderId,
                        'aftersale_id' => (int) $aftersaleId,
                        'order_payment_id' => $legId,
                        'channel' => 'wallet',
                        'currency_code' => $ctx['code'],
                        'currency_rate' => $ctx['rate'],
                        'currency_decimal' => $ctx['decimals'],
                        'amount' => $alloc,
                        'base_amount' => currency()->toBase($alloc, $ctx),
                        'status' => 'succeeded',
                        'refunded_at' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s'),
                    ))->insert();
                    if (!$refundId) {
                        throw new \Exception('wallet refund record insert failed');
                    }
                    $newRefunded = round($alreadyRefunded + $alloc, 2);
                    $legUpdated = DB::table('order_payment')->where('id', $legId)->data(array(
                        'status' => ($newRefunded + self::AMOUNT_EPSILON >= $legAmount) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIAL_REFUNDED,
                    ))->update();
                    if ($legUpdated === false) {
                        throw new \Exception('wallet payment leg update failed');
                    }
                    $result['succeeded_amount'] = round($result['succeeded_amount'] + $alloc, 2);
                    $result['refund_sns'][] = $refundSn;
                    $remaining = round($remaining - $alloc, 2);
                } else {
                    // 网关腿：登记 pending 退款申请，待人工 / 回调收尾
                    $refundSn = $this->snGenerator->generate();
                    $refundId = DB::table('order_refund')->data(array(
                        'refund_sn' => $refundSn,
                        'order_id' => $orderId,
                        'aftersale_id' => (int) $aftersaleId,
                        'order_payment_id' => $legId,
                        'channel' => (string) $leg['gateway'],
                        'currency_code' => $ctx['code'],
                        'currency_rate' => $ctx['rate'],
                        'currency_decimal' => $ctx['decimals'],
                        'amount' => $alloc,
                        'base_amount' => currency()->toBase($alloc, $ctx),
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s'),
                    ))->insert();
                    if (!$refundId) {
                        throw new \Exception('gateway refund record insert failed');
                    }
                    $result['pending_amount'] = round($result['pending_amount'] + $alloc, 2);
                    $result['refund_sns'][] = $refundSn;
                    $remaining = round($remaining - $alloc, 2);
                }
            }

            // 按支付腿与已成功退款校准 wallet_paid，避免部分退款后快照虚高
            $walletLegTotal = (float) DB::table('order_payment')
                ->where('order_id', $orderId)
                ->where('gateway', 'wallet')
                ->where('status', 'IN', array(PaymentStatus::SUCCEEDED, PaymentStatus::PARTIAL_REFUNDED, PaymentStatus::REFUNDED))
                ->sum('amount');
            if ($walletLegTotal > 0 || $result['succeeded_amount'] > 0) {
                $walletRefunded = (float) DB::table('order_refund')
                    ->where('order_id', $orderId)
                    ->where('channel', 'wallet')
                    ->where('status', 'succeeded')
                    ->sum('amount');
                $walletNet = round($walletLegTotal - $walletRefunded, 2);
                if ($walletNet < 0) {
                    $walletNet = 0.0;
                }
                if (DB::table('order')->where('id', $orderId)->data(array('wallet_paid' => $walletNet))->update() === false) {
                    throw new \Exception('wallet_paid refund recalibration failed');
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $this->logError('refundOrder failed: ' . $e->getMessage(), array(
                'order_sn' => $orderSn,
                'amount' => $refundAmount,
                'aftersale_id' => (int) $aftersaleId,
            ));
            return array('succeeded_amount' => 0.0, 'pending_amount' => 0.0, 'refund_sns' => array());
        }

        return $result;
    }

    /**
     * 收尾一笔 pending 的网关退款（管理员在网关后台退款完成后 / 网关退款回调）。
     *
     * @param string $refundSn
     * @param string $rawCallback
     * @return float 实际收尾金额（0=未命中 / 已收尾）
     */
    public function markRefundSucceeded($refundSn, $rawCallback = '')
    {
        $refundSn = (string) $refundSn;
        if ($refundSn === '') {
            return 0.0;
        }
        $refund = DB::table('order_refund')->where('refund_sn', $refundSn)->find();
        if (!$refund || (string) $refund['status'] !== 'pending') {
            return 0.0;
        }
        $legId = (int) $refund['order_payment_id'];

        try {
            DB::beginTransaction();

            $locked = DB::table('order_refund')
                ->where('id', (int) $refund['id'])
                ->lock('FOR UPDATE')
                ->find();
            if (!$locked || (string) $locked['status'] !== 'pending') {
                DB::rollback();
                return 0.0;
            }
            $amount = round((float) $locked['amount'], 2);

            DB::table('order_refund')->where('id', (int) $locked['id'])->data(array(
                'status' => 'succeeded',
                'raw_callback' => (string) $rawCallback,
                'refunded_at' => date('Y-m-d H:i:s'),
            ))->update();

            if ($legId > 0) {
                $leg = DB::table('order_payment')->where('id', $legId)->find();
                if ($leg) {
                    $legAmount = round((float) $leg['amount'], 2);
                    $refundedSum = round((float) DB::table('order_refund')
                        ->where('order_payment_id', $legId)
                        ->where('status', 'succeeded')
                        ->sum('amount'), 2);
                    DB::table('order_payment')->where('id', $legId)->data(array(
                        'status' => ($refundedSum + self::AMOUNT_EPSILON >= $legAmount) ? PaymentStatus::REFUNDED : PaymentStatus::PARTIAL_REFUNDED,
                    ))->update();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $this->logError('markRefundSucceeded exception: ' . $e->getMessage(), array('refund_sn' => $refundSn));
            return 0.0;
        }
        return $amount;
    }

    /**
     * 把 pending 退款标记为失败，释放该腿的可退额度供后续重试。
     *
     * @param string $refundSn
     * @param string $reason
     * @return bool
     */
    public function markRefundFailed($refundSn, $reason = '')
    {
        $refundSn = (string) $refundSn;
        if ($refundSn === '') {
            return false;
        }
        $refund = DB::table('order_refund')->where('refund_sn', $refundSn)->find();
        if (!$refund || (string) $refund['status'] !== 'pending') {
            return false;
        }

        $affected = DB::table('order_refund')
            ->where('id', (int) $refund['id'])
            ->where('status', 'pending')
            ->data(array(
                'status' => 'failed',
                'raw_callback' => (string) $reason,
            ))
            ->update();

        return $affected > 0;
    }

    /**
     * 把 payment 推进到 FAILED。
     *
     * @param string $paymentSn
     * @param string $message 失败原因（写日志）
     * @param string $rawCallback
     * @return bool
     */
    public function markFailed($paymentSn, $message = '', $rawCallback = '')
    {
        return $this->markTerminal($paymentSn, PaymentStatus::FAILED, $message, $rawCallback);
    }

    /**
     * 把 payment 推进到 CLOSED（超时关闭 / 第三方说已关闭）。
     *
     * @param string $paymentSn
     * @param string $message
     * @param string $rawCallback
     * @return bool
     */
    public function markClosed($paymentSn, $message = '', $rawCallback = '')
    {
        return $this->markTerminal($paymentSn, PaymentStatus::CLOSED, $message, $rawCallback);
    }

    /**
     * 写入回调原文（不改状态），webhook 多次到达 / 状态未迁移时记录原文。
     *
     * @param string $paymentSn
     * @param string $rawCallback
     * @return bool
     */
    public function recordCallback($paymentSn, $rawCallback)
    {
        if ((string) $paymentSn === '') {
            return false;
        }
        DB::table('order_payment')
            ->where('payment_sn', $paymentSn)
            ->data(array('raw_callback' => (string) $rawCallback))
            ->update();
        return true;
    }

    /**
     * 写入离线付款凭证路径。
     *
     * @param string $paymentSn
     * @param string $payEvidencePath
     * @return bool
     */
    public function attachPayEvidence($paymentSn, $payEvidencePath)
    {
        if ((string) $paymentSn === '' || (string) $payEvidencePath === '') {
            return false;
        }
        DB::table('order_payment')
            ->where('payment_sn', $paymentSn)
            ->data(array('pay_evidence' => (string) $payEvidencePath))
            ->update();
        return true;
    }

    /**
     * 按订单取全部 payment 历史（管理后台展示用）。
     *
     * @param int $orderId
     * @return array
     */
    public function listByOrder($orderId)
    {
        return (array) DB::table('order_payment')
            ->where('order_id', (int) $orderId)
            ->order('id ASC')
            ->select();
    }

    /**
     * 找该订单最新一行带凭证的 payment 路径（attachment file 名）。
     *
     * 用于前后台订单详情页面展示离线付款凭证图片。返回原始文件路径，
     * 调用方按需用 attachment()->url() 转链。
     *
     * @param int $orderId
     * @return string 没有时返回空串
     */
    public function findLatestPayEvidence($orderId)
    {
        $row = DB::table('order_payment')
            ->where('order_id', (int) $orderId)
            ->where('pay_evidence', '<>', '')
            ->order('id DESC')
            ->find();
        if (!$row || empty($row['pay_evidence'])) {
            return '';
        }
        return (string) $row['pay_evidence'];
    }

    /**
     * 终态迁移内部实现（FAILED / CLOSED 共享）。
     *
     * @param string $paymentSn
     * @param string $targetStatus
     * @param string $message
     * @param string $rawCallback
     * @return bool
     */
    private function markTerminal($paymentSn, $targetStatus, $message, $rawCallback)
    {
        $payment = $this->findBySn($paymentSn);
        if (!$payment) {
            return false;
        }
        if ($payment['status'] === $targetStatus) {
            return true;
        }
        if (!PaymentStatus::canTransit($payment['status'], $targetStatus)) {
            $this->logError('illegal payment transition: ' . $payment['status'] . ' -> ' . $targetStatus, array(
                'payment_sn' => (string) $paymentSn,
            ));
            return false;
        }

        $data = array('status' => $targetStatus);
        if ((string) $rawCallback !== '') {
            $data['raw_callback'] = (string) $rawCallback;
        }
        DB::table('order_payment')
            ->where('id', (int) $payment['id'])
            ->data($data)
            ->update();

        if ($message !== '') {
            $this->logError('payment marked ' . $targetStatus . ': ' . $message, array(
                'payment_sn' => (string) $paymentSn,
            ));
        }
        return true;
    }

    /**
     * 解析 dou_order 主键：表列为 id，业务层字段名常为 order_id。
     *
     * @param array $order
     * @return int
     */
    private function resolveOrderId(array $order)
    {
        if (isset($order['id']) && (int) $order['id'] > 0) {
            return (int) $order['id'];
        }
        if (isset($order['order_id']) && (int) $order['order_id'] > 0) {
            return (int) $order['order_id'];
        }
        return 0;
    }

    /**
     * @param string $message
     * @param array $payload
     * @return void
     */
    private function logCritical($message, array $payload = array())
    {
        Log::critical($message, array(
            'channel' => 'payment',
            'module' => 'payment',
            'payload' => $payload,
        ));
    }

    /**
     * @param string $message
     * @param array $payload
     * @return void
     */
    private function logError($message, array $payload = array())
    {
        Log::error($message, array(
            'channel' => 'payment',
            'module' => 'payment',
            'payload' => $payload,
        ));
    }
}
