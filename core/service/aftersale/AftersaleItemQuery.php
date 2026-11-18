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

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Num;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 按行可申请数量 / 金额查询（扣减历史已申请 / 已退）。
 *
 * 一笔订单的同一 order_item 可能被多次申请售后；本服务给出每行「还能再申请多少件 / 多少钱」，
 * 供买家申请页与卖家审核做上限校验，避免超额退款。
 */
class AftersaleItemQuery extends BaseService
{
    /**
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * 计算订单各行的可售后余量。
     *
     * @param int $orderId
     * @param int $excludeAftersaleId 需排除的售后单 ID（工作台重写自身明细时传当前单）
     * @return array order_item.id => ['order_item_id','item_id','name','module','quantity_total','quantity_used','quantity_left','price','unit_net','line_net','amount_left']
     *               price = unit_net = 券后净单价（优惠按行分摊后）；line_net = 行券后净额；amount_left = 剩余可退净额
     */
    public function refundableItems($orderId, $excludeAftersaleId = 0)
    {
        $orderId = (int) $orderId;
        $excludeAftersaleId = (int) $excludeAftersaleId;
        $orderItems = DB::table('order_item')->where('order_id', $orderId)->order('id DESC')->select();

        $order = DB::table('order')->where('id', $orderId)->find();
        $order = is_array($order) ? $order : array();

        // 可退金额一律按订单币种快照列计算（建单恒填充）；积分兑换订单不产生现金退款
        $isPointOrder = isset($order['mode']) && (string) $order['mode'] === 'point';

        // 已申请过（非终态驳回 / 取消）的行数量汇总；重写当前单时可排除自身。
        // 已实际退过款却被驳回的售后（退款失败后人工关闭）仍占用数量，避免同一商品重复退款。
        $usedRows = DB::query("SELECT ai.`order_item_id`, SUM(ai.`quantity`) AS used"
            . " FROM " . DB::tableName('aftersale_item') . " ai"
            . " INNER JOIN " . DB::tableName('aftersale') . " a ON a.`id` = ai.`aftersale_id`"
            . " WHERE ai.`order_id` = " . $orderId
            . " AND (a.`status` NOT IN ('rejected','cancelled') OR a.`refunded_amount` > 0)"
            . ($excludeAftersaleId > 0 ? " AND ai.`aftersale_id` <> " . $excludeAftersaleId : '')
            . " GROUP BY ai.`order_item_id`");
        $usedMap = array();
        if ($usedRows) {
            while ($r = DB::fetchAssoc($usedRows)) {
                $usedMap[(int) $r['order_item_id']] = (int) $r['used'];
            }
        }

        // 行商品总额（优惠分摊基数）
        $grossMap = array();
        $totalGross = 0.0;
        foreach ((array) $orderItems as $row) {
            $orderItemId = (int) $row['id'];
            $total = (int) $row['item_number'];
            $unitPrice = Num::toFloatOrZero(isset($row['currency_sale_price']) ? $row['currency_sale_price'] : 0);
            $gross = round($unitPrice * $total, 2);
            $grossMap[$orderItemId] = $gross;
            $totalGross += $gross;
        }
        $totalGross = round($totalGross, 2);

        // 订单优惠总额：一律累加订单币快照 currency_amount；积分兑换订单不产生现金退款
        $totalDiscount = 0.0;
        if (!$isPointOrder && $totalGross > 0) {
            foreach ((array) DB::table('order_coupon')->where('order_id', $orderId)->select() as $couponRow) {
                $totalDiscount += isset($couponRow['currency_amount']) ? (float) $couponRow['currency_amount'] : 0.0;
            }
            $totalDiscount = round($totalDiscount, 2);
            if ($totalDiscount < 0) {
                $totalDiscount = 0.0;
            }
            if ($totalDiscount > $totalGross) {
                $totalDiscount = $totalGross;
            }
        }

        // 按行商品总额占比分摊优惠，末行承接尾差（避免逐行四舍五入导致合计偏差）
        $discountMap = array();
        $allocated = 0.0;
        $lastPositiveId = 0;
        foreach ((array) $orderItems as $row) {
            $orderItemId = (int) $row['id'];
            if ($grossMap[$orderItemId] > 0) {
                $lastPositiveId = $orderItemId;
            }
        }
        foreach ((array) $orderItems as $row) {
            $orderItemId = (int) $row['id'];
            $gross = $grossMap[$orderItemId];
            $discount = 0.0;
            if ($totalDiscount > 0 && $totalGross > 0 && $gross > 0) {
                if ($orderItemId === $lastPositiveId) {
                    $discount = round($totalDiscount - $allocated, 2);
                } else {
                    $discount = round($totalDiscount * $gross / $totalGross, 2);
                }
                if ($discount < 0) {
                    $discount = 0.0;
                }
                if ($discount > $gross) {
                    $discount = $gross;
                }
            }
            $discountMap[$orderItemId] = $discount;
            $allocated = round($allocated + $discount, 2);
        }

        $result = array();
        foreach ((array) $orderItems as $row) {
            $orderItemId = (int) $row['id'];
            $total = (int) $row['item_number'];
            $used = isset($usedMap[$orderItemId]) ? (int) $usedMap[$orderItemId] : 0;
            $left = $total - $used;
            if ($left < 0) {
                $left = 0;
            }

            $lineNet = $isPointOrder ? 0.0 : round($grossMap[$orderItemId] - $discountMap[$orderItemId], 2);
            if ($lineNet < 0) {
                $lineNet = 0.0;
            }
            $unitNet = $total > 0 ? round($lineNet / $total, 2) : 0.0;
            $amountLeft = $total > 0 ? round($lineNet * $left / $total, 2) : 0.0;
            if ($amountLeft > $lineNet) {
                $amountLeft = $lineNet;
            }

            $result[$orderItemId] = array(
                'order_item_id' => $orderItemId,
                'item_id' => (int) $row['item_id'],
                'name' => isset($row['name']) ? (string) $row['name'] : '',
                'module' => isset($row['module']) ? (string) $row['module'] : '',
                'quantity_total' => $total,
                'quantity_used' => $used,
                'quantity_left' => $left,
                'price' => $unitNet,
                'unit_net' => $unitNet,
                'line_net' => $lineNet,
                'amount_left' => $amountLeft,
            );
        }

        return $result;
    }

    /**
     * 校验买家提交的售后行是否在可申请余量内，并据单价折算申请金额。
     *
     * @param int $orderId
     * @param array $lines [ ['order_item_id' => int, 'quantity' => int], ... ]
     * @param int $excludeAftersaleId 需排除的售后单 ID（工作台重写自身明细时传当前单）
     * @return array ['valid' => bool, 'error' => string, 'items' => array, 'apply_amount' => float]
     *               items 元素含 order_item_id/item_id/quantity/amount，供写入 dou_aftersale_item
     */
    public function validateApplyLines($orderId, array $lines, $excludeAftersaleId = 0)
    {
        $refundable = $this->refundableItems($orderId, $excludeAftersaleId);
        $items = array();
        $applyAmount = 0.0;

        foreach ($lines as $line) {
            $orderItemId = (int) (isset($line['order_item_id']) ? $line['order_item_id'] : 0);
            $quantity = (int) (isset($line['quantity']) ? $line['quantity'] : 0);
            if ($orderItemId <= 0 || $quantity <= 0) {
                continue;
            }
            if (!isset($refundable[$orderItemId])) {
                return array('valid' => false, 'error' => 'invalid_items', 'items' => array(), 'apply_amount' => 0.0);
            }
            if ($quantity > $refundable[$orderItemId]['quantity_left']) {
                return array('valid' => false, 'error' => 'quantity_over', 'items' => array(), 'apply_amount' => 0.0);
            }
            // 按「券后行净额」折算：整行全退取行净额，部分退按数量占比；不得超过剩余可退净额
            $rowTotal = (int) $refundable[$orderItemId]['quantity_total'];
            $lineNet = (float) $refundable[$orderItemId]['line_net'];
            $amount = ($rowTotal > 0 && $quantity < $rowTotal)
                ? round($lineNet * $quantity / $rowTotal, 2)
                : round($lineNet, 2);
            if ($amount > $refundable[$orderItemId]['amount_left']) {
                $amount = round($refundable[$orderItemId]['amount_left'], 2);
            }
            if ($amount < 0) {
                $amount = 0.0;
            }
            $applyAmount += $amount;
            $items[] = array(
                'order_item_id' => $orderItemId,
                'item_id' => $refundable[$orderItemId]['item_id'],
                'quantity' => $quantity,
                'amount' => $amount,
            );
        }

        if (empty($items)) {
            return array('valid' => false, 'error' => 'invalid_items', 'items' => array(), 'apply_amount' => 0.0);
        }

        return array('valid' => true, 'error' => '', 'items' => $items, 'apply_amount' => round($applyAmount, 2));
    }
}
