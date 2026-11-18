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
use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderStatusTransition;
use Dou\Core\Service\Payment\PaymentService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后退款派发：桥接 {@see PaymentService::refundOrder}（gateway 先于 wallet），
 * 并据实退金额推进售后单与订单的退款状态。
 *
 * 退款落定语义：
 * - wallet 腿同步退回，立即计入 aftersale.refunded_amount；
 * - gateway 腿仅登记 pending 退款申请，需管理员在网关后台完成后调
 *   {@see PaymentService::markRefundSucceeded}，再由 {@see settle} 收尾；
 * - aftersale.refunded_amount >= approved_amount 时售后单进 REFUNDED，
 *   订单据「整单已退合计 vs order_amount」进 REFUNDED / PARTIAL_REFUNDED。
 */
class AftersaleRefundDispatcher extends BaseService
{
    /** 金额比较容差。 */
    const AMOUNT_EPSILON = 0.005;

    /** @var PaymentService */
    private $payment;

    /** @var AftersaleStatusTransition */
    private $transition;

    /** @var OrderStatusTransition */
    private $orderTransition;

    /** @var AftersaleService */
    private $aftersaleService;

    /**
     * @param PaymentService $payment
     * @param AftersaleStatusTransition $transition
     * @param OrderStatusTransition $orderTransition
     * @param AftersaleService $aftersaleService
     */
    public function __construct(
        PaymentService $payment,
        AftersaleStatusTransition $transition,
        OrderStatusTransition $orderTransition,
        AftersaleService $aftersaleService
    ) {
        $this->payment = $payment;
        $this->transition = $transition;
        $this->orderTransition = $orderTransition;
        $this->aftersaleService = $aftersaleService;
    }

    /**
     * 触发售后退款：把售后单从 APPROVED / RECEIVED / REFUND_FAILED 推进到 REFUNDING 并派发退款。
     *
     * @param int $aftersaleId
     * @param string $operatorType
     * @param int $operatorId
     * @param string $ip
     * @return bool
     */
    public function dispatch($aftersaleId, $operatorType = 'admin', $operatorId = 0, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            return false;
        }
        $order = DB::table('order')->where('id', (int) $aftersale['order_id'])->find();
        if (!$order) {
            return false;
        }
        // 积分兑换订单禁止退款：中央派发口统一拦截，所有退款路径汇聚于此
        if (isset($order['mode']) && (string) $order['mode'] === 'point') {
            return false;
        }
        if (!OrderPolicy::isRefundable($order['module'])) {
            return false;
        }
        $orderSn = (string) $order['order_sn'];

        // 已存在同售后单的 pending 网关退款时不再重复申请，等待人工收尾。
        // 必须在状态迁移前判断，避免先迁移到 REFUNDING 后空转。
        $hasPendingRefund = DB::table('order_refund')
            ->where('aftersale_id', $aftersaleId)
            ->where('status', 'pending')
            ->exists();
        if ($hasPendingRefund) {
            return false;
        }

        // 进入退款中
        $enterFrom = array(AftersaleStatus::APPROVED, AftersaleStatus::RECEIVED, AftersaleStatus::REFUND_FAILED);
        if (in_array((string) $aftersale['status'], $enterFrom, true)) {
            if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::REFUNDING, 'refund', $operatorType, $operatorId, $ip)) {
                return false;
            }
            // 订单侧进入退款中（PAID / COMPLETED / PARTIAL_REFUNDED → REFUNDING）
            $orderStatus = (string) $order['status'];
            if (in_array($orderStatus, array(OrderStatus::PAID, OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED), true)) {
                $this->orderTransition->changeStatus($orderSn, OrderStatus::REFUNDING, $operatorType, $operatorId, $ip, 'aftersale_refund');
            }
            $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        }

        if ((string) $aftersale['status'] !== AftersaleStatus::REFUNDING) {
            return false;
        }

        $approved = round((float) $aftersale['approved_amount'], 2);
        $alreadyRefunded = round((float) $aftersale['refunded_amount'], 2);
        $toRefund = round($approved - $alreadyRefunded, 2);

        if ($toRefund > self::AMOUNT_EPSILON) {
            $res = $this->payment->refundOrder($orderSn, $toRefund, $aftersaleId, $operatorType, $operatorId);
            if ($res['succeeded_amount'] > 0) {
                DB::table('aftersale')->where('id', $aftersaleId)->exp('refunded_amount', '+', $res['succeeded_amount']);
            }
        }

        return $this->settle($aftersaleId, $operatorType, $operatorId, $ip);
    }

    /**
     * 订单当前可退金额（委托 PaymentService，与退款派发同一口径）。
     *
     * @param int $orderId
     * @return float
     */
    public function availableRefundAmount($orderId)
    {
        return $this->payment->availableRefundAmount((int) $orderId);
    }

    /**
     * 退款收尾：据 aftersale.refunded_amount 判断是否全额退完，全额则推进售后 REFUNDED + 订单退款终态。
     *
     * 供 dispatch 末尾调用，也供管理员收尾网关 pending 退款（markRefundSucceeded 后）再调用一次。
     *
     * @param int $aftersaleId
     * @param string $operatorType
     * @param int $operatorId
     * @param string $ip
     * @return bool
     */
    public function settle($aftersaleId, $operatorType = 'system', $operatorId = 0, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            return false;
        }
        $approved = round((float) $aftersale['approved_amount'], 2);
        $refunded = round((float) $aftersale['refunded_amount'], 2);
        if ($approved <= 0 || $refunded + self::AMOUNT_EPSILON < $approved) {
            // 仍有网关 pending 退款未到账，停在 REFUNDING
            return true;
        }

        // 仅首次迁入 REFUNDED 时执行一次性副作用（恢复库存/销量），重复 settle 不会重复恢复；
        // 乐观锁（version）保证并发 settle 只有一方真正迁入并执行副作用。
        if ((string) $aftersale['status'] !== AftersaleStatus::REFUNDED) {
            if (!$this->transition->changeStatus($aftersaleId, AftersaleStatus::REFUNDED, 'refund', $operatorType, $operatorId, $ip)) {
                return false;
            }
            $this->aftersaleService->finalizeRefundedOrderSideEffects($aftersaleId);
        }

        // 订单退款终态：售后已退合计 vs 券后商品净额
        $order = DB::table('order')->where('id', (int) $aftersale['order_id'])->find();
        return $this->finalizeOrderRefundStatus($order, $operatorType, $operatorId, $ip);
    }

    /**
     * 网关退款超时转人工时，释放该售后单下 pending 退款占用，允许后续重试重新申请。
     *
     * @param int $aftersaleId
     * @param string $reason
     * @return void
     */
    public function failPendingRefunds($aftersaleId, $reason = '')
    {
        $aftersaleId = (int) $aftersaleId;
        if ($aftersaleId <= 0) {
            return;
        }

        $rows = DB::table('order_refund')
            ->where('aftersale_id', $aftersaleId)
            ->where('status', 'pending')
            ->select();
        foreach ((array) $rows as $row) {
            $this->payment->markRefundFailed((string) $row['refund_sn'], $reason);
        }
    }

    /**
     * 退款失败后管理员关闭售后时，把订单从「退款中」恢复到退款前状态。
     *
     * 回退口径：
     * - 该订单仍有其他未结售后时保持 REFUNDING，避免提前解除退款占位；
     * - 已发生部分退款（钱包腿已到账）时保留 PARTIAL_REFUNDED，不抹掉已退金额；
     * - 无实际退款时优先按状态日志还原退款前状态，日志缺失再按完成时间推断。
     *
     * @param int $aftersaleId 即将被关闭的售后单
     * @param string $operatorType
     * @param int $operatorId
     * @param string $ip
     * @return bool true=已恢复或无需恢复；false=售后/订单不存在或状态迁移失败
     */
    public function restoreOrderAfterRefundClosed($aftersaleId, $operatorType = 'admin', $operatorId = 0, $ip = '')
    {
        $aftersaleId = (int) $aftersaleId;
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale) {
            return false;
        }
        $order = DB::table('order')->where('id', (int) $aftersale['order_id'])->find();
        if (!$order) {
            return false;
        }
        if ((string) $order['status'] !== OrderStatus::REFUNDING) {
            return true;
        }

        // 同一订单还有其他未结售后（含另一笔退款失败）时保持退款中，等其处理完再收尾
        $hasOtherOpen = DB::table('aftersale')
            ->where('order_id', (int) $order['id'])
            ->where('id', '<>', $aftersaleId)
            ->where('status', 'NOT IN', AftersaleStatus::terminal())
            ->exists();
        if ($hasOtherOpen) {
            return true;
        }

        $refunded = round((float) $aftersale['refunded_amount'], 2);
        if ($refunded > 0) {
            $target = OrderStatus::PARTIAL_REFUNDED;
        } else {
            // 退款前状态优先取状态日志（可一次性还原 PARTIAL_REFUNDED 等历史），缺失时按完成时间推断
            $restoreFrom = (string) DB::table('order_status_log')
                ->where('order_id', (int) $order['id'])
                ->where('to_status', OrderStatus::REFUNDING)
                ->order('id DESC')
                ->value('from_status');
            $target = in_array($restoreFrom, array(OrderStatus::PAID, OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED), true)
                ? $restoreFrom
                : (!empty($order['completed_at']) ? OrderStatus::COMPLETED : OrderStatus::PAID);
        }

        if (!$this->orderTransition->changeStatus((string) $order['order_sn'], $target, $operatorType, $operatorId, $ip, 'aftersale_refund_closed')) {
            return false;
        }
        if ($target === OrderStatus::PARTIAL_REFUNDED) {
            DB::table('order')->where('id', (int) $order['id'])->data(array('refund_status' => $target))->update();
        }

        return true;
    }

    /**
     * 收尾一笔 pending 网关退款：管理员在网关后台完成退款后回填。
     *
     * 流程：标记 dou_order_refund 该笔 succeeded（{@see PaymentService::markRefundSucceeded}），
     * 把到账金额累加进 aftersale.refunded_amount，再调 {@see settle} 判定售后 / 订单是否退款终态。
     *
     * @param string $refundSn 待确认的退款单号
     * @param string $operatorType
     * @param int $operatorId
     * @param string $ip
     * @return bool true=收尾成功；false=退款单不存在 / 非 pending / 金额为 0
     */
    public function confirmGatewayRefund($refundSn, $operatorType = 'admin', $operatorId = 0, $ip = '')
    {
        $refundSn = (string) $refundSn;
        $refund = DB::table('order_refund')->where('refund_sn', $refundSn)->find();
        if (!$refund || (string) $refund['status'] !== 'pending') {
            return false;
        }
        $aftersaleId = (int) $refund['aftersale_id'];

        $amount = $this->payment->markRefundSucceeded($refundSn);
        if ($amount <= 0) {
            return false;
        }

        if ($aftersaleId > 0) {
            DB::table('aftersale')->where('id', $aftersaleId)->exp('refunded_amount', '+', $amount);
            return $this->settle($aftersaleId, $operatorType, $operatorId, $ip);
        }

        // 非售后关联（极少数）：仅推进订单退款状态
        $order = DB::table('order')->where('id', (int) $refund['order_id'])->find();
        return $this->finalizeOrderRefundStatus($order, $operatorType, $operatorId, $ip);
    }

    /**
     * 据「售后已退合计 vs 券后商品净额」推进订单退款终态（REFUNDED / PARTIAL_REFUNDED）。
     *
     * 只统计售后关联退款（aftersale_id>0），排除 overpay / late_payment / cancel 等非售后资金流；
     * 比较目标为「券后商品净额」，不含运费；目标为 0（100% 优惠等）时改用数量覆盖判定。
     *
     * @param array|false $order
     * @param string $operatorType
     * @param int $operatorId
     * @param string $ip
     * @return bool
     */
    private function finalizeOrderRefundStatus($order, $operatorType, $operatorId, $ip)
    {
        if ($order) {
            $orderId = (int) $order['id'];
            $orderSn = (string) $order['order_sn'];
            $orderRefunded = round((float) DB::table('order_refund')
                ->where('order_id', $orderId)
                ->where('aftersale_id', '>', 0)
                ->where('status', 'succeeded')
                ->sum('amount'), 2);

            // 比较目标：券后商品净额（不含运费），一律取订单币快照列；为 0 时走数量覆盖判定
            $itemTarget = isset($order['currency_item_amount']) ? round((float) $order['currency_item_amount'], 2) : 0.0;

            if ($itemTarget > 0) {
                $target = ($orderRefunded + self::AMOUNT_EPSILON >= $itemTarget)
                    ? OrderStatus::REFUNDED
                    : OrderStatus::PARTIAL_REFUNDED;
            } else {
                // 目标为 0：以「售后已退数量是否覆盖全部下单数量」判定
                $orderedQty = (int) DB::table('order_item')->where('order_id', $orderId)->sum('item_number');
                $refundedIds = array();
                foreach ((array) DB::table('aftersale')->where('order_id', $orderId)->where('status', AftersaleStatus::REFUNDED)->select() as $aftersaleRow) {
                    $refundedIds[] = (int) $aftersaleRow['id'];
                }
                $refundedQty = 0;
                if (!empty($refundedIds)) {
                    $refundedQty = (int) DB::table('aftersale_item')->whereIn('aftersale_id', $refundedIds)->sum('quantity');
                }
                $target = ($orderedQty > 0 && $refundedQty >= $orderedQty)
                    ? OrderStatus::REFUNDED
                    : OrderStatus::PARTIAL_REFUNDED;
            }

            if ((string) $order['status'] === OrderStatus::REFUNDING) {
                $this->orderTransition->changeStatus($orderSn, $target, $operatorType, $operatorId, $ip, 'aftersale_refund');
            }
            DB::table('order')->where('id', $orderId)->data(array('refund_status' => $target))->update();
        }
        return true;
    }
}
