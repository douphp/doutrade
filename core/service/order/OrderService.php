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

use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单核心服务（薄编排门面）。
 *
 * 按变化原因把订单业务拆分到下列主题域子服务，本类只做组合与对外稳定签名：
 * - {@see OrderCartQuery}        购物车装配/清空
 * - {@see OrderStatusTransition} 状态机、付款联动、订单号、分销奖励
 * - {@see OrderItemQuery}        订单条目视图、可评论判定
 * - {@see OrderStockGuard}       库存校验与实时库存
 * - {@see OrderScheduledTasks}   自动评价/售后/取消等批量动作
 * - {@see OrderCheckoutOptions}  支付/配送插件列表、付款剩余时间文案
 *
 * 与 user 模块解耦：会员侧增强（积分 / 等级 / 分销）由子服务在 features.* 闸下按需解析，
 * 未启用对应模块时直接跳过；本门面不持有 UserLevelService。
 */
class OrderService extends BaseService
{
    /** @var OrderCartQuery */
    private $cartQuery;

    /** @var OrderStatusTransition */
    private $statusTransition;

    /** @var OrderItemQuery */
    private $itemQuery;

    /** @var OrderStockGuard */
    private $stockGuard;

    /** @var OrderScheduledTasks */
    private $scheduledTasks;

    /** @var OrderCheckoutOptions */
    private $checkoutOptions;

    /** @var OrderCancellation */
    private $cancellation;

    /**
     * @param OrderCartQuery $cartQuery
     * @param OrderStatusTransition $statusTransition
     * @param OrderItemQuery $itemQuery
     * @param OrderStockGuard $stockGuard
     * @param OrderScheduledTasks $scheduledTasks
     * @param OrderCheckoutOptions $checkoutOptions
     * @param OrderCancellation $cancellation
     */
    public function __construct(
        OrderCartQuery $cartQuery,
        OrderStatusTransition $statusTransition,
        OrderItemQuery $itemQuery,
        OrderStockGuard $stockGuard,
        OrderScheduledTasks $scheduledTasks,
        OrderCheckoutOptions $checkoutOptions,
        OrderCancellation $cancellation
    ) {
        $this->cartQuery = $cartQuery;
        $this->statusTransition = $statusTransition;
        $this->itemQuery = $itemQuery;
        $this->stockGuard = $stockGuard;
        $this->scheduledTasks = $scheduledTasks;
        $this->checkoutOptions = $checkoutOptions;
        $this->cancellation = $cancellation;
    }

    // -----------------------------------------------------------------------
    // 购物车（OrderCartQuery）
    // -----------------------------------------------------------------------

    /**
     * 取指定会员购物车视图数据。
     *
     * @param int $user_id 显式入参；<=0 返回空购物车
     * @return array|bool
     */
    public function getCart($user_id = 0)
    {
        return $this->cartQuery->getCart($user_id);
    }

    /**
     * 统计购物车有效件数（角标 / cart_number）。
     *
     * @param int $user_id
     * @return int
     */
    public function countCartNumber($user_id)
    {
        return $this->cartQuery->countCartNumber($user_id);
    }

    /**
     * 清空失效购物车行（不触碰有效行）。
     *
     * @param int $user_id
     * @return int 删除行数
     */
    public function clearInvalidCartItems($user_id)
    {
        return $this->cartQuery->clearInvalidCartItems($user_id);
    }

    /**
     * 按报价单载荷装配一次性购物车视图（不落 order_cart）。
     *
     * @param int $user_id
     * @param array $payload
     * @return array
     */
    public function getQuoteCart($user_id, array $payload)
    {
        return $this->cartQuery->getQuoteCart($user_id, $payload);
    }

    /**
     * 清空指定会员购物车。
     *
     * @param int $user_id 显式入参；<=0 直接返回 false
     * @return bool
     */
    public function clearCart($user_id = 0)
    {
        return $this->cartQuery->clearCart($user_id);
    }

    /**
     * 积分展示（数值 + 单位）。
     *
     * @param mixed $point
     * @return array point, point_format
     */
    public function pointForView($point)
    {
        return $this->cartQuery->pointForView($point);
    }

    // -----------------------------------------------------------------------
    // 状态机 / 付款后联动（OrderStatusTransition）
    // -----------------------------------------------------------------------

    /**
     * 变更订单状态（含付款后联动 / 完成态切换）。
     *
     * @param string $order_sn
     * @param int|string $new_status
     * @return bool
     */
    public function changeStatus($order_sn, $new_status)
    {
        return $this->statusTransition->changeStatus($order_sn, $new_status);
    }

    /**
     * 写订单的支付方式 slug。
     *
     * @param string $order_sn
     * @param string $pay_id
     * @return bool
     */
    public function writePayId($order_sn, $pay_id)
    {
        return $this->statusTransition->writePayId($order_sn, $pay_id);
    }

    /**
     * 生成新订单号。
     *
     * @param string $user_sn
     * @return string
     */
    public function createOrderSn($user_sn = '')
    {
        return $this->statusTransition->createOrderSn($user_sn);
    }

    /**
     * 分销奖励派发；模块未装时返回 false。
     *
     * @param string $order_sn
     * @return mixed
     */
    public function distributionReward($order_sn)
    {
        return $this->statusTransition->distributionReward($order_sn);
    }

    /**
     * 重新执行付款后联动（积分 / 分销 / 会员升级），仅对 PAID / COMPLETED 订单生效；幂等可重跑。
     *
     * @param string $order_sn
     * @return bool
     */
    public function retryPaidEffects($order_sn)
    {
        return $this->statusTransition->retryPaidEffects($order_sn);
    }

    /**
     * 统一取消订单（状态机 + 关闭 pending 支付 + 退钱包腿）。
     *
     * @param string $order_sn
     * @param string $reason
     * @param string $operator_type
     * @param int $operator_id
     * @param string $ip
     * @param array $allowed_statuses
     * @return bool
     */
    public function cancelOrder($order_sn, $reason = '', $operator_type = 'system', $operator_id = 0, $ip = '', array $allowed_statuses = array(OrderStatus::PENDING))
    {
        return $this->cancellation->cancel($order_sn, $reason, $operator_type, $operator_id, $ip, $allowed_statuses);
    }

    /**
     * 发货 / 更新物流。
     *
     * - 仅 PAID（首次完成）与 COMPLETED（修改物流）可用
     * - 首次完成经状态机推进，失败不写物流字段
     * - shipped_at 仅首次写入，修改物流不覆盖
     *
     * @param int $order_id
     * @param string $shipping_id
     * @param string $tracking_no
     * @param string $operator_type
     * @param int $operator_id
     * @param string $ip
     * @return bool
     */
    public function fulfill($order_id, $shipping_id, $tracking_no, $operator_type = 'admin', $operator_id = 0, $ip = '')
    {
        try {
            return DB::transaction(function () use ($order_id, $shipping_id, $tracking_no, $operator_type, $operator_id, $ip) {
                return $this->fulfillLocked($order_id, $shipping_id, $tracking_no, $operator_type, $operator_id, $ip);
            });
        } catch (\Exception $e) {
            return false;
        }
    }

    private function fulfillLocked($order_id, $shipping_id, $tracking_no, $operator_type, $operator_id, $ip)
    {
        $order = DB::table('order')->where('id', intval($order_id))->lock('FOR UPDATE')->find();
        if (!$order) {
            return false;
        }

        $status = isset($order['status']) ? (string) $order['status'] : '';
        if ($status !== OrderStatus::PAID && $status !== OrderStatus::COMPLETED) {
            return false;
        }

        $shippingId = trim((string) $shipping_id);
        if ($shippingId === '0') {
            $shippingId = '';
        }
        if ($shippingId !== '') {
            $plugin = plugin()->getWithConfig($shippingId);
            if (!is_array($plugin)
                || !isset($plugin['plugin_group'])
                || (string) $plugin['plugin_group'] !== 'shipping'
            ) {
                return false;
            }
        }

        $trackingNo = trim((string) $tracking_no);
        // 发货/改物流必须同时提供物流方式与单号，禁止以空值清空已有物流
        if ($shippingId === '' || $trackingNo === '') {
            return false;
        }
        $now = date('Y-m-d H:i:s');

        if ($status === OrderStatus::PAID) {
            if (!$this->statusTransition->changeStatus(
                (string) $order['order_sn'],
                OrderStatus::COMPLETED,
                $operator_type,
                $operator_id,
                $ip,
                'shipped'
            )) {
                return false;
            }

            $updated = DB::table('order')->where('id', (int) $order['id'])->data(array(
                'shipping_id' => $shippingId,
                'tracking_no' => $trackingNo,
                'shipped_at' => $now,
                'allow_aftersale' => OrderPolicy::isRefundable(isset($order['module']) ? $order['module'] : '') ? '1' : '0',
            ))->update();
            if ($updated === false || DB::table('order_item')->where('order_id', (int) $order['id'])->data(array('stock_lock' => '0'))->update() === false) {
                throw new \Exception('shipping update failed');
            }

            return true;
        }

        // 已完成订单：仅更新物流，不覆盖首次发货时间
        $data = array(
            'shipping_id' => $shippingId,
            'tracking_no' => $trackingNo,
        );
        if (empty($order['shipped_at'])) {
            $data['shipped_at'] = $now;
        }
        if (DB::table('order')->where('id', (int) $order['id'])->data($data)->update() === false) {
            throw new \Exception('shipping update failed');
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // 订单条目（OrderItemQuery）
    // -----------------------------------------------------------------------

    /**
     * 取单订单的明细视图列表。
     *
     * @param int $order_id
     * @param string $user_id
     * @param array|null $ctx 订单币上下文；null 表示基准币展示
     * @return array
     */
    public function getOrderItem($order_id, $user_id = '', $ctx = null)
    {
        return $this->itemQuery->getOrderItem($order_id, $user_id, $ctx);
    }

    /**
     * 校验某 order_item 是否可由指定会员发起评价。
     *
     * @param int $order_item_id
     * @param int $user_id 显式入参
     * @return bool
     */
    public function ifCanComment($order_item_id, $user_id)
    {
        return $this->itemQuery->ifCanComment($order_item_id, (int) $user_id);
    }

    // -----------------------------------------------------------------------
    // 库存（OrderStockGuard）
    // -----------------------------------------------------------------------

    /**
     * 校验某商品库存是否可下单。
     *
     * @param string $module
     * @param int $item_id
     * @param int $number
     * @return array
     */
    public function checkStock($module, $item_id, $number)
    {
        return $this->stockGuard->checkStock($module, $item_id, $number);
    }

    /**
     * 实时可售库存（stock - 锁定中数量）。
     *
     * @param string $module
     * @param int $item_id
     * @return int
     */
    public function realTimeStock($module, $item_id, $currentRead = false)
    {
        return $this->stockGuard->realTimeStock($module, $item_id, $currentRead);
    }

    // -----------------------------------------------------------------------
    // 定时任务（OrderScheduledTasks）
    // -----------------------------------------------------------------------

    /**
     * 按 60 秒节流执行自动取消/售后过期/自动好评。
     *
     * @return bool 是否实际执行
     */
    public function runScheduledTasksIfDue()
    {
        return $this->scheduledTasks->runIfDue();
    }

    /**
     * 超过评价时限的订单自动评价。
     *
     * @param array $where_conditions
     * @return bool
     */
    public function autoUpdateComment($where_conditions = array())
    {
        return $this->scheduledTasks->autoUpdateComment($where_conditions);
    }

    /**
     * 超过售后时限的订单自动取消「可申请售后」标记。
     *
     * @param array $where_conditions
     * @return bool
     */
    public function autoUpdateAftersaleStatus($where_conditions = array())
    {
        return $this->scheduledTasks->autoUpdateAftersaleStatus($where_conditions);
    }

    /**
     * 自动取消未付款超时订单。
     *
     * @param array $where_conditions
     * @return bool
     */
    public function autoCancelOrder($where_conditions = array())
    {
        return $this->scheduledTasks->autoCancelOrder($where_conditions);
    }

    // -----------------------------------------------------------------------
    // 结算选项（OrderCheckoutOptions）
    // -----------------------------------------------------------------------

    /**
     * 取启用的支付方式插件列表。
     *
     * @param string $currency 订单币种代码；非空时只保留支持该币种的网关
     * @param string $module 订单模块
     * @param string $client 客户端：WEB（默认）| MP
     * @return array
     */
    public function getPaymentList($currency = '', $module = '', $client = 'WEB')
    {
        return $this->checkoutOptions->getPaymentList($currency, $module, $client);
    }

    /**
     * 取启用的配送方式插件列表。
     *
     * @return array
     */
    public function getShippingList()
    {
        return $this->checkoutOptions->getShippingList();
    }

    /**
     * 秒数 → 时分秒展示串。
     *
     * @param int $time_limit
     * @return string
     */
    public function timeLimit($time_limit)
    {
        return $this->checkoutOptions->timeLimit($time_limit);
    }

    /**
     * 订单付款剩余时间文案。
     *
     * @param string|int $created_at
     * @param bool $short
     * @param bool $only_number
     * @return string
     */
    public function payTimeLimit($created_at = '', $short = false, $only_number = false)
    {
        return $this->checkoutOptions->payTimeLimit($created_at, $short, $only_number);
    }

    // -----------------------------------------------------------------------
    // 后台
    // -----------------------------------------------------------------------

    /**
     * 后台退款占位：实际退款由各支付插件实现，本基类返回 false。
     *
     * @param int $order_id
     * @param mixed $amount
     * @param string $remark
     * @return bool
     */
    public function adminRefund($order_id, $amount, $remark = '')
    {
        return false;
    }
}
