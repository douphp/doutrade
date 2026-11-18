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

namespace Dou\Core\Service\Order;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 统一取消订单：状态机 + 关闭 pending 支付 + 退回钱包腿。
 *
 * 前端 / 工作台 / 后台批量 / 自动取消共用，避免各路径各写一份直接 UPDATE 导致：
 * 状态无守卫、库存锁未释放、优惠券未退回、钱包退款遗漏、关联套餐表 SQL 报错。
 */
class OrderCancellation extends BaseService
{
    /** @var OrderStatusTransition */
    private $transition;

    /** @var PaymentService */
    private $paymentService;

    /**
     * @param OrderStatusTransition $transition
     * @param PaymentService $paymentService
     */
    public function __construct(OrderStatusTransition $transition, PaymentService $paymentService)
    {
        $this->transition = $transition;
        $this->paymentService = $paymentService;
    }

    /**
     * 取消订单。
     *
     * @param string $orderSn
     * @param string $reason
     * @param string $operatorType system/user/admin/work
     * @param int $operatorId
     * @param string $ip
     * @param array $allowedStatuses 允许取消的源状态
     * @return bool
     */
    public function cancel($orderSn, $reason = '', $operatorType = 'system', $operatorId = 0, $ip = '', array $allowedStatuses = array(OrderStatus::PENDING))
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return false;
        }

        $order = DB::table('order')->where('order_sn', $orderSn)->find();
        if (!$order) {
            return false;
        }

        $status = isset($order['status']) ? (string) $order['status'] : '';
        if (!in_array($status, $allowedStatuses, true)) {
            return false;
        }

        if (!$this->transition->changeStatus($orderSn, OrderStatus::CANCELLED, $operatorType, $operatorId, $ip, $reason)) {
            return false;
        }

        // 订单已取消：关闭仍 pending 的支付尝试，防止用户继续在网关付款
        $this->paymentService->closePendingForOrder($orderSn, $reason !== '' ? $reason : 'order cancelled');

        // 已成功但未凑满订单的网关腿：登记待退款
        $this->paymentService->refundCapturedLegsOnCancel($orderSn, $reason);

        // 退回已扣的钱包腿（混合支付未付满场景）
        $this->paymentService->refundWalletPaymentOnCancel($orderSn);

        return true;
    }
}
