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
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Event\Event;
use Dou\Core\Foundation\Event\Scene\SceneNames;
use Dou\Core\Foundation\Event\Scene\SceneRegistry;
use Dou\Core\Foundation\Event\SystemEvents;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Wallet\WalletService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单状态机与付款后联动（状态域）。
 *
 * 涵盖订单状态变更、支付方式写入、订单号生成、分销奖励派发；
 * 付款后的会员侧效应（积分、等级升级）走 features.* 闸按需触发，未启用会员相关模块时直接跳过，
 * 保持本服务对 user 模块的运行时解耦。
 *
 * 关键设计：
 * - 状态值采用 {@see OrderStatus} 字符串枚举，迁移走 {@see OrderStatus::canTransit} 表驱动校验
 * - 付款联动事件（ORDER_PAID）在 DB::commit() 之后派发，避免下游 vip/money/ai 联动失败时回滚状态变更
 */
class OrderStatusTransition extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /** @var WalletService */
    private $wallet;

    /**
     * @param WalletService $wallet 钱包/积分台账
     */
    public function __construct(WalletService $wallet)
    {
        $this->wallet = $wallet;
    }

    /**
     * 变更订单状态（带行锁的原子迁移）。
     *
     * - 事务内以 order FOR UPDATE 重新读取并校验迁移，避免取消与支付回调互相覆盖
     * - 付款（PAID）会触发库存扣减、ORDER_PAID 场景派发、积分发放、分销奖励、会员等级升级
     * - 无物流插件或非 product 模块下，PAID 会顺势推进到 COMPLETED
     * - 取消（CANCELLED）解锁库存、释放优惠券、退回积分兑换订单的积分并同步关联模块状态
     * - 全额退款（REFUNDED）撤销 VIP/chat/geo 权益
     * - 派发事件统一移到 commit 之后，避免下游联动失败回滚状态
     *
     * @param string $order_sn
     * @param string $new_status {@see OrderStatus} 字符串枚举
     * @param string $operator_type 操作者类型：system/user/admin/webhook（显式入参，默认 system）
     * @param int $operator_id 操作者 ID（显式入参，默认 0）
     * @param string $ip 操作 IP（显式入参，默认空）
     * @param string $reason 变更原因（默认空）
     * @return bool true=已落定或已是目标状态；false=非法迁移 / 订单不存在 / 异常
     */
    public function changeStatus($order_sn, $new_status, $operator_type = 'system', $operator_id = 0, $ip = '', $reason = '')
    {
        $order_sn = (string) $order_sn;
        $newStatus = (string) $new_status;
        if (!OrderStatus::isValid($newStatus)) {
            $this->logError('invalid order status value: ' . $newStatus, array(
                'order_sn' => $order_sn,
            ));
            return false;
        }
        if ($order_sn === '') {
            return false;
        }

        $eventsToDispatch = array();
        $order = null;
        $fromStatus = '';

        try {
            DB::beginTransaction();

            $order = DB::table('order')->where('order_sn', $order_sn)->lock('FOR UPDATE')->find();
            if (!$order || !isset($order['id']) || !$order['id']) {
                DB::rollback();
                return false;
            }
            $order['order_id'] = (int) $order['id'];
            $orderModuleKey = str_replace('_package', '', (string) (isset($order['module']) ? $order['module'] : ''));

            $fromStatus = isset($order['status']) ? (string) $order['status'] : OrderStatus::PENDING;
            if ($fromStatus === $newStatus) {
                // 幂等重入（重复回调等）默认直接返回；若调用方显式携带原因或操作者，补一条留痕日志，
                // 便于区分「未发生变更」与「变更被吞」。
                if ($reason !== '' || $operator_type !== 'system' || (int) $operator_id > 0) {
                    DB::table('order_status_log')->data(array(
                        'order_id' => (int) $order['order_id'],
                        'from_status' => $fromStatus,
                        'to_status' => $fromStatus,
                        'reason' => (string) $reason,
                        'operator_type' => (string) $operator_type,
                        'operator_id' => (int) $operator_id,
                        'ip' => (string) $ip,
                        'created_at' => date('Y-m-d H:i:s'),
                    ))->insert();
                }
                DB::commit();
                // 部分退款并发收尾时，后到的同状态变更仍需按累计退款额补做比例冲销。
                if ($newStatus === OrderStatus::PARTIAL_REFUNDED) {
                    $this->dispatchRefundEffects($order);
                }
                return true;
            }
            if (!OrderStatus::canTransit($fromStatus, $newStatus)) {
                DB::rollback();
                $this->logError('illegal order status transition: ' . $fromStatus . ' -> ' . $newStatus, array(
                    'order_sn' => $order_sn,
                ));
                return false;
            }

            // 只有首次收款（待付款 / 待确认 → PAID）才触发库存扣减、积分、分销和场景事件；
            // REFUNDING → PAID（售后驳回）只恢复订单状态，不能二次扣库存或覆盖 paid_at。
            $isFirstPayment = in_array($fromStatus, array(OrderStatus::PENDING, OrderStatus::AWAITING_CONFIRMATION), true);

            // 没有物流插件或非 product 模块时，付款后直接置为已完成
            $effectiveStatus = $newStatus;
            if ($newStatus === OrderStatus::PAID) {
                $hasShippingPlugin = DB::tableExist('plugin')
                    && DB::table('plugin')->where('plugin_group', 'shipping')->exists();
                if (!$hasShippingPlugin || $order['module'] != 'product') {
                    $effectiveStatus = OrderStatus::COMPLETED;
                }
            }

            DB::table('order')
                ->where('id', $order['order_id'])
                ->data(array('status' => $effectiveStatus))
                ->update();
            DB::table('order_item')
                ->where('order_id', $order['order_id'])
                ->data(array('order_status' => $effectiveStatus))
                ->update();

            // 状态变更日志（每次 transition 落一行，from = 变更前、to = 实际落定状态）
            DB::table('order_status_log')->data(array(
                'order_id' => (int) $order['order_id'],
                'from_status' => $fromStatus,
                'to_status' => $effectiveStatus,
                'reason' => (string) $reason,
                'operator_type' => (string) $operator_type,
                'operator_id' => (int) $operator_id,
                'ip' => (string) $ip,
                'created_at' => date('Y-m-d H:i:s'),
            ))->insert();

            // 已完成相关字段（已有发货/完成时间不覆盖，避免售后驳回回退时重置计时）
            if ($effectiveStatus === OrderStatus::COMPLETED && $orderModuleKey !== 'vip') {
                $completedData = array(
                    'allow_aftersale' => OrderPolicy::isRefundable(isset($order['module']) ? $order['module'] : '') ? '1' : '0',
                    'allow_comment' => '1',
                );
                if (empty($order['shipped_at'])) {
                    $completedData['shipped_at'] = date('Y-m-d H:i:s');
                }
                if (empty($order['completed_at'])) {
                    $completedData['completed_at'] = date('Y-m-d H:i:s');
                }
                DB::table('order')->where('id', $order['order_id'])->data($completedData)->update();
            }

            // 取消：释放库存锁、关闭售后/评价、回滚优惠券与积分、同步关联模块
            if ($effectiveStatus === OrderStatus::CANCELLED) {
                DB::table('order')->where('id', $order['order_id'])->data(array(
                    'closed_at' => date('Y-m-d H:i:s'),
                    'cancel_reason' => (string) $reason,
                    'allow_aftersale' => '0',
                    'allow_comment' => '0',
                ))->update();
                DB::table('order_item')
                    ->where('order_id', $order['order_id'])
                    ->data(array('stock_lock' => '0'))
                    ->update();

                foreach ((array) DB::table('order_coupon')->where('order_id', $order['order_id'])->select() as $couponRow) {
                    $couponLogId = isset($couponRow['coupon_log_id']) ? (int) $couponRow['coupon_log_id'] : 0;
                    if ($couponLogId > 0 && DB::tableExist('coupon_log')) {
                        DB::table('coupon_log')
                            ->where('id', $couponLogId)
                            ->where('order_sn', $order_sn)
                            ->data(array('status' => '0', 'used_at' => null, 'order_sn' => ''))
                            ->update();
                    }
                }

                // 积分兑换订单取消时退回已扣积分（幂等）
                if ((string) $order['mode'] === 'point'
                    && Config::get('features.point', false)
                    && (int) $order['order_point'] > 0
                ) {
                    $exchanged = DB::table('point')
                        ->where('user_id', (int) $order['user_id'])
                        ->where('action', 'exchange')
                        ->where('from', $order_sn)
                        ->exists();
                    $refunded = DB::table('point')
                        ->where('user_id', (int) $order['user_id'])
                        ->where('action', 'order_refund')
                        ->where('from', $order_sn)
                        ->exists();
                    if ($exchanged && !$refunded) {
                        $this->wallet->createPoint(
                            (int) $order['user_id'],
                            'order_refund',
                            (int) $order['order_point'],
                            $order_sn,
                            'system',
                            0,
                            '',
                            0,
                            'order cancelled'
                        );
                    }
                }

                OrderModuleLink::updateOrderStatus((string) $order['module'], $order_sn, OrderStatus::CANCELLED);
            }

            // 全额退款：撤销关联权益并关闭售后/评价入口
            if ($effectiveStatus === OrderStatus::REFUNDED) {
                DB::table('order')->where('id', $order['order_id'])->data(array(
                    'allow_aftersale' => '0',
                    'allow_comment' => '0',
                ))->update();
                OrderModuleLink::revokeOnFullRefund((string) $order['module'], $order_sn);

                // 券未过期时退回，供会员继续使用；过期券不回流。
                if (DB::tableExist('order_coupon') && DB::tableExist('coupon_log') && DB::tableExist('coupon')) {
                    foreach ((array) DB::table('order_coupon')->where('order_id', $order['order_id'])->select() as $couponRow) {
                        $couponLogId = isset($couponRow['coupon_log_id']) ? (int) $couponRow['coupon_log_id'] : 0;
                        if ($couponLogId <= 0) {
                            continue;
                        }
                        $endAt = DB::table('coupon')
                            ->where('id', isset($couponRow['coupon_id']) ? (int) $couponRow['coupon_id'] : 0)
                            ->value('end_at');
                        $endTs = Util::toTimestamp($endAt);
                        if ($endTs === null || time() >= $endTs) {
                            continue;
                        }
                        DB::table('coupon_log')
                            ->where('id', $couponLogId)
                            ->where('status', '1')
                            ->data(array('status' => '0', 'used_at' => null, 'order_sn' => ''))
                            ->update();
                    }
                }
            }

            // 付款后联动（仅首次收款）
            if ($newStatus === OrderStatus::PAID && $isFirstPayment) {
                DB::table('order')->where('id', $order['order_id'])->data(array(
                    'paid_at' => date('Y-m-d H:i:s'),
                    'allow_aftersale' => ((string) $order['mode'] !== 'point'
                        && OrderPolicy::isRefundable($order['module'])) ? '1' : '0',
                ))->update();

                $items = DB::table('order_item')->where('order_id', $order['order_id'])->order('id DESC')->select();
                foreach ((array) $items as $item) {
                    // 开启库存功能时扣减实际库存：任何库存表中带 stock 字段的模块都适用，
                    // 与 OrderStockGuard::checkStock 的口径保持一致（product 额外累计销量）
                    $itemModule = isset($item['module']) ? (string) $item['module'] : '';
                    if (Config::get('site.stock', false) && $itemModule !== '' && DB::fieldExist($itemModule, 'stock')) {
                        $itemId = intval($item['item_id']);
                        $itemNumber = intval($item['item_number']);
                        $stockAffected = DB::table($itemModule)
                            ->where('id', $itemId)
                            ->where('stock', '>=', $itemNumber)
                            ->exp('stock', '-', $itemNumber);
                        if ($stockAffected === false || $stockAffected < 1) {
                            $this->logError('stock underflow guarded on order paid', array(
                                'order_sn' => $order_sn,
                                'item_id' => $itemId,
                                'number' => $itemNumber,
                            ));
                            throw new \Exception('insufficient stock on order paid');
                        }
                        if ($itemModule === 'product') {
                            DB::table('product')->where('id', $itemId)->exp('sales', '+', $itemNumber);
                        }
                    }

                    // 收集场景事件，事务外派发
                    if ($order['item_link_status']) {
                        $moduleKey = isset($item['module']) && $item['module'] !== ''
                            ? str_replace('_package', '', $item['module'])
                            : '';
                        $eventsToDispatch[] = array(
                            'scene' => SceneNames::ORDER_PAID,
                            'payload' => array(
                                'order' => $order,
                                'order_item' => $item,
                            ),
                            'module' => $moduleKey,
                        );
                    }
                }

                // 下单时锁定的库存解锁
                DB::table('order_item')->where('order_id', $order['order_id'])->data(array('stock_lock' => '0'))->update();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $this->logError('change_status error: ' . $e->getMessage(), array(
                'order_sn' => $order_sn,
                'status' => $newStatus,
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ));
            return false;
        }

        // 只在真实首次付款的提交后通知整单；不依赖商品联动开关或浏览器回跳。
        if ($newStatus === OrderStatus::PAID && $isFirstPayment) {
            try {
                Event::fire(SystemEvents::ORDER_PAID, array('order_id' => (int) $order['id'], 'order_sn' => $order_sn));
            } catch (\Exception $e) {
                $this->logError('order paid notification error: ' . $e->getMessage(), array('order_sn' => $order_sn));
            } catch (\Throwable $e) {
                $this->logError('order paid notification error: ' . $e->getMessage(), array('order_sn' => $order_sn));
            }
        }

        // 事务外派发场景事件（即使下游失败也不回滚状态变更）
        foreach ($eventsToDispatch as $event) {
            try {
                SceneRegistry::dispatch($event['scene'], $event['payload'], $event['module']);
            } catch (\Exception $e) {
                $this->logError('scene dispatch error: ' . $e->getMessage(), array(
                    'scene' => $event['scene'],
                    'order_sn' => $order_sn,
                ));
            }
        }

        // 付款后会员侧联动（积分 / 分销 / 会员升级）同样在 commit 之后执行，
        // 失败仅记日志、不回滚已落定的 PAID 状态；售后驳回回退 PAID 不重复派发。
        if ($newStatus === OrderStatus::PAID && $isFirstPayment && is_array($order)) {
            $this->dispatchPaidEffects($order);
        }

        // 整单退款：冲销该订单已发放的消费积分与分销奖励（幂等；失败仅记日志，不回滚已落定的 REFUNDED）
        if ($newStatus === OrderStatus::REFUNDED && is_array($order)) {
            $this->dispatchRefundEffects($order, 1.0);
        }

        // 部分退款：按累计退款比例增量冲销，避免“买→退大部分→保留全额奖励”套利。
        if ($newStatus === OrderStatus::PARTIAL_REFUNDED && is_array($order)) {
            $this->dispatchRefundEffects($order);
        }

        return true;
    }

    /**
     * 派发付款后的会员侧联动：消费积分、分销奖励、会员等级升级、分销等级升级。
     *
     * 设计要点：
     * - 在 changeStatus 的 DB::commit() 之后调用，任一项失败仅记日志、不回滚订单状态
     * - 积分 + 分销在同一事务内、以订单行 FOR UPDATE 作互斥：付款回调与后台重跑并发时，
     *   exists() 去重 + 写入保持原子，且恢复 createPoint / createMoney 内部行锁的有效性
     *   （脱离事务时这些 FOR UPDATE 会失效），避免重复发放
     * - 会员升级自带事务与锁，本仓库 beginTransaction 不支持嵌套，故置于该事务之外独立 try/catch
     * - 幂等：积分按 (action=shopping, from=order_sn) 守卫且 point>0 才发，分销 orderReward 自带去重，
     *   会员升级从当前等级向上迭代不会重复升级；因此后台「补发付款联动」可安全重跑
     *
     * @param array $order 已落定状态的订单行（含 order_sn、user_id、order_amount、module）
     * @return void
     */
    private function dispatchPaidEffects(array $order)
    {
        $order_sn = isset($order['order_sn']) ? (string) $order['order_sn'] : '';
        if ($order_sn === '') {
            return;
        }

        $orderModuleKey = str_replace('_package', '', (string) (isset($order['module']) ? $order['module'] : ''));

        // 积分 + 分销：同一事务、以订单行 FOR UPDATE 互斥，
        // 防止「付款回调」与「后台重跑」并发时 exists() 去重失效造成重复发放；
        // 失败仅回滚本组联动并记日志，订单状态已在更早的事务 commit，不受影响。
        try {
            DB::beginTransaction();
            DB::table('order')->where('order_sn', $order_sn)->lock('FOR UPDATE')->value('id');

            // 消费积分（按商品实付金额百分比给积分），充值套餐不参与
            if (Config::get('features.point', false)
                && $orderModuleKey !== 'vip'
                && OrderPolicy::earnsShoppingPoint(isset($order['module']) ? $order['module'] : '')
            ) {
                $granted = DB::table('point')
                    ->where('user_id', intval($order['user_id']))
                    ->where('action', 'shopping')
                    ->where('from', $order_sn)
                    ->exists();
                if (!$granted) {
                    $pointBase = isset($order['item_amount']) ? $order['item_amount'] : $order['order_amount'];
                    $point = intval(($pointBase / 100) * (Config::get('param.point_scale') ? Config::get('param.point_scale') : 1));
                    if ($point > 0) {
                        $this->wallet->createPoint($order['user_id'], 'shopping', $point, $order_sn);
                    }
                }
            }

            // 分销奖励（orderReward 内部按 from=order_sn 去重，受同一行锁保护；充值套餐不参与）
            if (OrderPolicy::earnsDistribution(isset($order['module']) ? $order['module'] : '')) {
                $this->distributionReward($order_sn);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            $this->logError('paid effect point/distribution error: ' . $e->getMessage(), array(
                'order_sn' => $order_sn,
            ));
        }

        // 会员等级 / 分销等级升级：自带事务与 FOR UPDATE，必须置于上面事务之外（本仓库 beginTransaction 不支持嵌套）
        $this->dispatchLevelUpgrades($order, $order_sn);
    }

    /**
     * 退款后按累计退款比例冲销已发放的消费积分与分销奖励。
     *
     * - 整单退款：$ratio = 1.0，冲销全部已发放奖励；
     * - 部分退款：$ratio = null，按「已成功售后退款合计 / 券后商品净额」计算目标，
     *   减去该订单/该用户已冲销额，本次只补差额。
     * 幂等：同一订单重复调用不会重复冲销；冲销额以当前可用余额封顶，
     * 余额不足部分记日志，不回滚已落定的退款状态。
     *
     * @param array $order
     * @param float|null $ratio 1.0=整单全退；null=按累计退款比例
     * @return void
     */
    private function dispatchRefundEffects(array $order, $ratio = null)
    {
        $orderSn = isset($order['order_sn']) ? (string) $order['order_sn'] : '';
        $userId = isset($order['user_id']) ? (int) $order['user_id'] : 0;
        if ($orderSn === '' || $userId <= 0) {
            return;
        }

        if ($ratio === null) {
            $ratio = $this->refundClawbackRatio($order);
            if ($ratio <= 0) {
                return;
            }
        }
        $ratio = min(1.0, max(0.0, (float) $ratio));

        // 消费积分冲销
        if (Config::get('features.point', false)) {
            try {
                DB::beginTransaction();
                if (!DB::table('order')->where('order_sn', $orderSn)->lock('FOR UPDATE')->find()) {
                    DB::rollback();
                    return;
                }

                $granted = (int) DB::table('point')
                    ->where('user_id', $userId)
                    ->where('action', 'shopping')
                    ->where('from', $orderSn)
                    ->sum('point');
                if ($granted > 0) {
                    $target = (int) floor($granted * $ratio);
                    if ($target > $granted) {
                        $target = $granted;
                    }
                    $reversed = (int) abs((float) DB::table('point')
                        ->where('user_id', $userId)
                        ->where('action', 'order_refund_shopping')
                        ->where('from', $orderSn)
                        ->sum('point'));
                    $delta = $target - $reversed;
                    if ($delta > 0) {
                        $balance = $this->currentPointBalance($userId);
                        $deduct = (int) floor(min($delta, $balance > 0 ? $balance : 0));
                        if ($deduct > 0) {
                            $this->wallet->createPoint($userId, 'order_refund_shopping', -$deduct, $orderSn, 'system', 0, '', 0, 'order refunded');
                        }
                        if ($deduct < $delta) {
                            $this->logError('point clawback insufficient balance', array(
                                'order_sn' => $orderSn,
                                'target' => $target,
                                'already' => $reversed,
                                'deducted' => $deduct,
                            ));
                        }
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                $this->logError('refund point clawback error: ' . $e->getMessage(), array('order_sn' => $orderSn));
            }
        }

        // 分销奖励冲销：同一收款人按 direct/indirect 合并后按比例冲销
        if (Config::get('features.money', false)) {
            try {
                DB::beginTransaction();
                if (!DB::table('order')->where('order_sn', $orderSn)->lock('FOR UPDATE')->find()) {
                    DB::rollback();
                    return;
                }

                $grantedByUser = array();
                foreach ((array) DB::table('money')
                    ->where('from', $orderSn)
                    ->where('action', 'IN', array('direct_reward', 'indirect_reward'))
                    ->field('user_id, money')
                    ->select() as $row) {
                    $uid = (int) $row['user_id'];
                    if ($uid <= 0) {
                        continue;
                    }
                    if (!isset($grantedByUser[$uid])) {
                        $grantedByUser[$uid] = 0.0;
                    }
                    $grantedByUser[$uid] += (float) $row['money'];
                }
                foreach ($grantedByUser as $uid => $granted) {
                    $granted = round($granted, 2);
                    if ($granted <= 0) {
                        continue;
                    }
                    $target = round($granted * $ratio, 2);
                    if ($target > $granted) {
                        $target = $granted;
                    }
                    $reversed = round(abs((float) DB::table('money')
                        ->where('user_id', $uid)
                        ->where('action', 'order_refund_reward')
                        ->where('from', $orderSn)
                        ->sum('money')), 2);
                    $delta = round($target - $reversed, 2);
                    if ($delta <= 0) {
                        continue;
                    }
                    $balance = $this->currentMoneyBalance($uid);
                    $deduct = round(min($delta, $balance > 0 ? $balance : 0), 2);
                    if ($deduct > 0) {
                        $ok = $this->wallet->createMoney($uid, 'system', 0, 'order_refund_reward', -$deduct, $orderSn, 0, '', 0, 'order refunded');
                        if (!$ok) {
                            $this->logError('reward clawback write failed', array(
                                'order_sn' => $orderSn,
                                'user_id' => $uid,
                                'deducted' => $deduct,
                            ));
                        }
                    }
                    if ($deduct < $delta) {
                        $this->logError('reward clawback insufficient balance', array(
                            'order_sn' => $orderSn,
                            'user_id' => $uid,
                            'target' => $target,
                            'already' => $reversed,
                            'deducted' => $deduct,
                        ));
                    }
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                $this->logError('refund reward clawback error: ' . $e->getMessage(), array('order_sn' => $orderSn));
            }
        }
    }

    /**
     * 部分退款奖励冲销比例：已成功售后退款合计 / 券后商品净额（均订单币口径）。
     *
     * @param array $order
     * @return float 0~1；无法计算时返回 0
     */
    private function refundClawbackRatio(array $order)
    {
        $orderId = isset($order['order_id']) ? (int) $order['order_id'] : (isset($order['id']) ? (int) $order['id'] : 0);
        if ($orderId <= 0) {
            return 0.0;
        }

        $refunded = round((float) DB::table('order_refund')
            ->where('order_id', $orderId)
            ->where('aftersale_id', '>', 0)
            ->where('status', 'succeeded')
            ->sum('amount'), 2);
        if ($refunded <= 0) {
            return 0.0;
        }

        $target = isset($order['currency_item_amount']) ? round((float) $order['currency_item_amount'], 2) : 0.0;
        if ($target <= 0 && isset($order['item_amount'])) {
            $target = round((float) currency()->fromBase((float) $order['item_amount'], currency()->contextForOrder($order)), 2);
        }
        if ($target <= 0) {
            return 0.0;
        }

        return min(1.0, $refunded / $target);
    }

    /**
     * 当前可用积分余额（最后一条流水 total，缺失回退钱包快照）。
     *
     * @param int $userId
     * @return float
     */
    private function currentPointBalance($userId)
    {
        $last = DB::table('point')->where('user_id', (int) $userId)->order('id DESC')->find();
        if (is_array($last) && isset($last['total'])) {
            return (float) $last['total'];
        }
        $snapshot = DB::table('user_wallet')->where('id', (int) $userId)->value('point_balance');
        return ($snapshot === null || $snapshot === false) ? 0.0 : (float) $snapshot;
    }

    /**
     * 当前可用余额（最后一条流水 total，缺失回退钱包快照）。
     *
     * @param int $userId
     * @return float
     */
    private function currentMoneyBalance($userId)
    {
        $last = DB::table('money')->where('user_id', (int) $userId)->order('id DESC')->find();
        if (is_array($last) && isset($last['total'])) {
            return (float) $last['total'];
        }
        $snapshot = DB::table('user_wallet')->where('id', (int) $userId)->value('money_balance');
        return ($snapshot === null || $snapshot === false) ? 0.0 : (float) $snapshot;
    }

    /**
     * 付款后为买家与获得佣金的分销商检查等级升级。
     *
     * @param array $order
     * @param string $order_sn
     * @return void
     */
    private function dispatchLevelUpgrades(array $order, $order_sn)
    {
        $userIds = array_values(array_unique(array_filter(array(
            (int) (isset($order['user_id']) ? $order['user_id'] : 0),
            (int) (isset($order['direct_user_id']) ? $order['direct_user_id'] : 0),
            (int) (isset($order['indirect_user_id']) ? $order['indirect_user_id'] : 0),
        ))));

        if (empty($userIds)) {
            return;
        }

        $userLevelService = Module::make('user.user_level');
        $distributionLevelService = Module::make('distribution.distribution_level_upgrade');

        foreach ($userIds as $userId) {
            if ($userLevelService !== null) {
                try {
                    $userLevelService->checkLevelUpgrade($userId, $order_sn);
                } catch (\Exception $e) {
                    $this->logError('paid effect user level upgrade error: ' . $e->getMessage(), array(
                        'order_sn' => $order_sn,
                        'user_id' => $userId,
                    ));
                }
            }

            if ($distributionLevelService !== null) {
                try {
                    $distributionLevelService->checkLevelUpgrade($userId, $order_sn);
                } catch (\Exception $e) {
                    $this->logError('paid effect distribution level upgrade error: ' . $e->getMessage(), array(
                        'order_sn' => $order_sn,
                        'user_id' => $userId,
                    ));
                }
            }
        }
    }

    /**
     * 重新执行某订单的付款后联动（模块权益 / 积分 / 分销 / 会员等级升级 / 分销等级升级）。
     *
     * 供后台「重新执行付款联动」入口调用：仅对已进入 PAID / COMPLETED 的订单生效；
     * 全部为幂等操作，部分成功后重跑不会重复发放。
     *
     * @param string $order_sn
     * @return bool 命中并派发返回 true；订单不存在或状态不匹配返回 false
     */
    public function retryPaidEffects($order_sn)
    {
        $order = DB::table('order')->where('order_sn', $order_sn)->find();
        if (!$order || !isset($order['id']) || !$order['id']) {
            return false;
        }

        $status = isset($order['status']) ? (string) $order['status'] : '';
        if (!in_array($status, array(OrderStatus::PAID, OrderStatus::COMPLETED), true)) {
            return false;
        }

        $order['order_id'] = (int) $order['id'];

        // 先补派 ORDER_PAID 场景（VIP / chat / geo / money 权益），再补积分/分销/等级
        if (!empty($order['item_link_status'])) {
            $items = DB::table('order_item')->where('order_id', $order['order_id'])->order('id DESC')->select();
            foreach ((array) $items as $item) {
                $moduleKey = isset($item['module']) && $item['module'] !== ''
                    ? str_replace('_package', '', $item['module'])
                    : '';
                try {
                    SceneRegistry::dispatch(SceneNames::ORDER_PAID, array(
                        'order' => $order,
                        'order_item' => $item,
                    ), $moduleKey);
                } catch (\Exception $e) {
                    $this->logError('retry scene dispatch error: ' . $e->getMessage(), array(
                        'order_sn' => (string) $order_sn,
                        'module' => $moduleKey,
                    ));
                }
            }
        }

        $this->dispatchPaidEffects($order);
        return true;
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
        try {
            $order_sn = DB::escapeString($order_sn);
            $pay_id = DB::escapeString($pay_id);

            if (empty($order_sn) || empty($pay_id)) {
                $this->logError('Empty parameters in write_pay_id');
                return false;
            }

            return DB::table('order')
                ->where('order_sn', $order_sn)
                ->data(array('pay_id' => $pay_id))
                ->update() !== false;
        } catch (\Exception $e) {
            $this->logError('write_pay_id error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 生成新订单号（年月日时分秒 + 2 位随机 + user_sn）。重名时循环重试至多 5 次，仍冲突用微秒高熵兜底。
     *
     * @param string $user_sn 显式入参；空时拼接 '00'
     * @return string
     */
    public function createOrderSn($user_sn = '')
    {
        $user_sn = $user_sn
            ? DB::escapeString($user_sn)
            : '00';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $order_sn = date('ymdHis') . str_pad((string) mt_rand(1, 99), 2, '0', STR_PAD_LEFT) . $user_sn;

            $exists = DB::table('order')
                ->where('order_sn', $order_sn)
                ->exists();

            if (!$exists) {
                return $order_sn;
            }

            usleep(1000);
        }

        // 超过重试上限：用微秒高熵兜底，避免无上限递归爆栈。
        // 预算 = order_sn varchar(20) 减去 ymdHis(12) 与 user_sn 长度，保证不溢出。
        $budget = 20 - 12 - strlen($user_sn);
        if ($budget < 1) {
            $budget = 1;
        }
        $micro = (int) (microtime(true) * 1000000) % (int) pow(10, $budget);

        return date('ymdHis') . str_pad((string) $micro, $budget, '0', STR_PAD_LEFT) . $user_sn;
    }

    /**
     * 分销奖励：仅在 distribution 模块可用时派发，未装跳过。
     *
     * @param string $order_sn
     * @return mixed false 表示未派发
     */
    public function distributionReward($order_sn)
    {
        $distribution = Module::make('distribution');
        if (!$distribution) {
            return false;
        }

        return $distribution->orderReward($order_sn);
    }

    /**
     * 记录错误：写入实例日志数组，同时投递到 Log::error（channel=order）。
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    private function logError($message, $data = array())
    {
        $this->error_log[] = array(
            'time' => time(),
            'message' => $message,
            'data' => $data,
        );

        Log::error($message, array(
            'channel' => 'order',
            'module' => 'order',
            'payload' => is_array($data) ? $data : array(),
        ));
    }
}
