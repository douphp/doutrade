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
use Dou\Core\Domain\Aftersale\AftersaleType;
use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}
class AftersaleService extends BaseService
{
    /** @var AftersaleItemQuery 按行可申请余量查询 */
    private $itemQuery;

    /**
     * @param AftersaleItemQuery $itemQuery
     * @return void 返回结果。
     */
    public function __construct(AftersaleItemQuery $itemQuery)
    {
        $this->itemQuery = $itemQuery;
    }
    /**
     * 生成售后单号（8 位零填充随机）。重名时循环重试至多 5 次，仍冲突用日期+随机兜底。
     *
     * @return string 返回结果。
     */
    public function createAftersaleSn()
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $aftersale_sn = str_pad((string) mt_rand(1, 99999999), 8, '0', STR_PAD_LEFT);

            $exists = DB::table('aftersale')->where('aftersale_sn', $aftersale_sn)->exists();
            if (!$exists) {
                return $aftersale_sn;
            }

            usleep(1000);
        }

        // 超过重试上限：日期 + 8 位随机兜底（总长 20，与 aftersale_sn varchar(20) 对齐），避免无上限递归爆栈。
        return date('ymdHis') . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * 执行ifCanAftersale操作。
     *
     * @param mixed $order_id 参数order_id。
     * @param mixed $user_id 参数user_id。
     * @return bool 返回结果。
     */
    public function ifCanAftersale($order_id, $user_id)
    {
        // 订单状态与模块策略：充值套餐是现金等价物，积分兑换订单不允许退款，均不开放售后
        $order = DB::table('order')
            ->field('id, module, mode, status, allow_aftersale, shipped_at')
            ->where('id', intval($order_id))
            ->where('user_id', intval($user_id))
            ->where('status', 'IN', array(OrderStatus::PAID, OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED))
            ->find();

        if (!is_array($order)
            || ((string) $order['allow_aftersale'] !== '1' && (string) $order['status'] !== OrderStatus::PAID)
            || (isset($order['mode']) && (string) $order['mode'] === 'point')
            || !OrderPolicy::isRefundable($order['module'])) {
            return false;
        }

        // 一单只处理一次：售后进入实质处理（未撤销/未驳回）后，该订单不再开放第二次申请
        if ($this->hasProcessedAftersale($order['id'])) {
            return false;
        }

        // 申请资格不得延长固定时限：始终以发货时间 + 配置小时为准
        if (!empty($order['shipped_at'])) {
            $timeout = (Config::has('param.order_allow_aftersale_time')
                ? intval(Config::get('param.order_allow_aftersale_time'))
                : 168) * 3600;
            $shippedAt = strtotime((string) $order['shipped_at']);
            if ($shippedAt !== false && $shippedAt + $timeout <= time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * 订单是否已有进入实质处理的售后单。
     *
     * 撤销/驳回视为未处理，买家仍可在固定时限内再次申请；其余状态一律视为已处理。
     *
     * @param int $orderId 订单 ID
     * @return bool
     */
    private function hasProcessedAftersale($orderId)
    {
        return DB::table('aftersale')
            ->where('order_id', intval($orderId))
            ->where('status', 'NOT IN', array(AftersaleStatus::REJECTED, AftersaleStatus::CANCELLED))
            ->exists();
    }

    /**
     * 订单商品清单（售后详情/申请页共用）。
     *
     * @param mixed $order_id 订单 ID
     * @param array $aftersale_item_list 售后单已关联的商品 item_id 列表
     * @param bool $show_all 是否展示全部并标记选中项
     * @param array $order_item_ids 仅展示指定 order_item.id（申请页只传可退行；空数组表示不过滤）
     * @param array $quantity_map order_item.id => 剩余可退数量（覆盖展示数量）
     * @return array
     */
    public function getAftersaleItemList($order_id, $aftersale_item_list = array(), $show_all = false, array $order_item_ids = array(), array $quantity_map = array())
    {
        $aftersale_item_list = is_array($aftersale_item_list) ? $aftersale_item_list : array();
        $item_list = array();

        /* 获取产品列表 */
        $rows = DB::table('order_item')
            ->where('order_id', intval($order_id))
            ->order('id DESC')
            ->select();

        // 订单币快照：外币订单的单价按订单币列展示，旧单回退基准列
        $order = DB::table('order')->where('id', intval($order_id))->find();
        $orderCtx = currency()->contextForOrder($order);
        $useOrderCurrency = !currency()->isBase($orderCtx);

        foreach ((array) $rows as $row) {
            if (!empty($order_item_ids) && !in_array((int) $row['id'], $order_item_ids, true)) {
                continue;
            }

            $cur = false;
            if ($show_all) {
                $cur = in_array($row['item_id'], $aftersale_item_list) ? true : false;
            } else {
                if ($aftersale_item_list && !in_array($row['item_id'], $aftersale_item_list)) {
                    continue;
                }
            }

            // 格式化价格（外币订单取订单币单价）
            if ($useOrderCurrency && isset($row['currency_price'])) {
                $price = Util::formatCurrency($row['currency_price'], $orderCtx);
            } else {
                $price = Util::formatPrice($row['price']);
            }
            $image = DB::table($row['module'])->where('id', intval($row['item_id']))->value('image');
            $url = route($row['module'] . '.show', ['id' => $row['item_id']]);
            $itemNumber = isset($quantity_map[(int) $row['id']])
                ? (int) $quantity_map[(int) $row['id']]
                : (int) $row['item_number'];

            $item_list[] = array(
                "order_item_id" => (int) $row['id'],
                "attribute_name" => isset($row['attribute_name']) ? (string) $row['attribute_name'] : '',
                "item_id" => $row['item_id'],
                "name" => $row['name'],
                "thumb" => attachment()->url($image, true),
                "url" => $url,
                "cur" => $cur,
                "item_number" => $itemNumber,
                "price" => $price
            );
        }

        return $item_list;
    }

    /**
     * 取得某售后单关联的商品 item_id 集合（从 dou_aftersale_item 卫星表反查）。
     *
     * 读路径统一经 dou_aftersale_item 卫星表；无对应行时返回空数组。
     *
     * @param int $aftersale_id 售后单 ID
     * @return array 整型 item_id 列表
     */
    public function getItemIdsByAftersaleId($aftersale_id)
    {
        $aftersaleId = (int) $aftersale_id;
        if ($aftersaleId <= 0) {
            return array();
        }
        $rows = DB::table('aftersale_item')
            ->where('aftersale_id', $aftersaleId)
            ->field('item_id')
            ->select();
        $itemIds = array();
        foreach ((array) $rows as $row) {
            $itemIds[] = (int) $row['item_id'];
        }
        return $itemIds;
    }

    /**
     * 取得某售后单最后一条管理员/卖家备注（从 dou_aftersale_log 卫星表反查）。
     *
     * 读路径统一经 dou_aftersale_log 卫星表对外提供。
     * 优先取最新一条 action ∈ approve/refund/reject 的 note；都没有则取任意最新一条。
     *
     * @param int $aftersale_id 售后单 ID
     * @return string 处理备注串（无记录时返回空串）
     */
    public function getHandleRecordByAftersaleId($aftersale_id)
    {
        $aftersaleId = (int) $aftersale_id;
        if ($aftersaleId <= 0) {
            return '';
        }
        $note = (string) DB::table('aftersale_log')
            ->where('aftersale_id', $aftersaleId)
            ->where('action', 'IN', array('approve', 'refund', 'reject'))
            ->order('id DESC')
            ->value('note');
        if ($note !== '') {
            return $note;
        }
        return (string) DB::table('aftersale_log')
            ->where('aftersale_id', $aftersaleId)
            ->order('id DESC')
            ->value('note');
    }

    /**
     * 售后退款落定后的订单侧副作用：订单 aftersale_status=2、标记售后行、断开关联模块。
     *
     * 后台派发、网关确认、自动流转与工作台手工退款共用；仅在售后单已进 REFUNDED 时执行。
     *
     * @param int $aftersale_id 售后单 ID
     * @return void
     */
    public function finalizeRefundedOrderSideEffects($aftersale_id)
    {
        $aftersaleId = (int) $aftersale_id;
        if ($aftersaleId <= 0) {
            return;
        }
        $aftersale = DB::table('aftersale')->where('id', $aftersaleId)->find();
        if (!$aftersale || (string) $aftersale['status'] !== AftersaleStatus::REFUNDED) {
            return;
        }
        $order = DB::table('order')
            ->field('id, order_sn, module, item_link_status, shipped_at')
            ->where('id', (int) $aftersale['order_id'])
            ->find();
        if (!$order) {
            return;
        }

        DB::table('order')->where('id', (int) $order['id'])->data(array('aftersale_status' => 2))->update();

        // 未发货退款或退货退款回补库存；已发货仅退款不回补。
        $restoreStock = ((string) $order['module'] === 'product')
            && Config::get('site.stock', false)
            && (empty($order['shipped_at'])
                || AftersaleType::requiresReturn(isset($aftersale['type']) ? (string) $aftersale['type'] : ''));
        $orderId = (int) $order['id'];
        $rows = DB::table('aftersale_item')->where('aftersale_id', $aftersaleId)->select();
        foreach ((array) $rows as $row) {
            $orderItemId = isset($row['order_item_id']) ? (int) $row['order_item_id'] : 0;
            $itemId = (int) $row['item_id'];
            $quantity = (int) $row['quantity'];
            // 售后明细恒含 order_item_id；缺失即跳过该行，不做 order_id + item_id 历史回退匹配
            if ($quantity <= 0 || $orderItemId <= 0) {
                continue;
            }

            DB::table('order_item')
                ->where('id', $orderItemId)
                ->where('order_id', $orderId)
                ->data(array('aftersale' => 1))
                ->update();

            if (!$restoreStock || $itemId <= 0) {
                continue;
            }

            // 与支付扣减对称恢复：库存加回、销量回退（销量不得为负）。
            // 幂等由 settle() 的「仅首次迁入 REFUNDED 才执行本方法」保证，重复 settle 不会重复加库存。
            DB::query("UPDATE `" . DB::tableName('product') . "`"
                . " SET `stock` = `stock` + " . $quantity
                . ", `sales` = CASE WHEN `sales` >= " . $quantity . " THEN `sales` - " . $quantity . " ELSE 0 END"
                . " WHERE `id` = " . $itemId);
        }

        // 关联模块撤销：整单退款由 OrderStatusTransition::REFUNDED -> OrderModuleLink::revokeOnFullRefund 统一处理。
        // 此处不再按内联 status=0 写库：既避免部分退款误撤权益，也修复 vip_package → vip 等映射被绕过的问题。

        // 售后已实质处理完成：不再恢复申请资格，该订单后续不可再次申请（撤销/驳回路径另行恢复）。
    }

    /**
     * 售后被撤销/驳回后恢复订单的「再次申请」资格。
     *
     * 撤销、后台驳回、系统自动撤销时调用；仍受固定时限（发货时间 + 配置小时）与
     * 「一单只处理一次」约束，且仅当仍有可退余量、模块支持退款、非积分订单时置回 1。
     *
     * @param int $order_id 订单 ID
     * @return void
     */
    public function restoreAftersaleEligibility($order_id)
    {
        $orderId = (int) $order_id;
        if ($orderId <= 0) {
            return;
        }

        $order = DB::table('order')
            ->field('id, module, mode, status, shipped_at')
            ->where('id', $orderId)
            ->find();
        if (!is_array($order)) {
            return;
        }

        // 整单已退完、或本就不支持退款的订单不开放
        if ((string) $order['status'] === OrderStatus::REFUNDED
            || !OrderPolicy::isRefundable(isset($order['module']) ? $order['module'] : '')
            || (isset($order['mode']) && (string) $order['mode'] === 'point')) {
            return;
        }

        // 已有实质处理过的售后单时不再开放（撤销/驳回除外，可在固定时限内再次申请）
        if ($this->hasProcessedAftersale($orderId)) {
            return;
        }

        // 售后申请期限与定时任务同口径：自发货时间起算，未发货不受限
        if (!empty($order['shipped_at'])) {
            $timeout = (Config::has('param.order_allow_aftersale_time')
                ? intval(Config::get('param.order_allow_aftersale_time'))
                : 168) * 3600;
            $shippedAt = strtotime((string) $order['shipped_at']);
            if ($shippedAt !== false && $shippedAt + $timeout <= time()) {
                return;
            }
        }

        // 剩余可退数量与净额同时为正才开放，避免为 0 元 / 已退完的行反复生成空售后单
        $hasRemaining = false;
        foreach ($this->itemQuery->refundableItems($orderId) as $line) {
            if ((int) $line['quantity_left'] > 0 && (float) $line['amount_left'] > 0) {
                $hasRemaining = true;
                break;
            }
        }
        if (!$hasRemaining) {
            return;
        }

        DB::table('order')->where('id', $orderId)->data(array('allow_aftersale' => '1'))->update();
    }
}
