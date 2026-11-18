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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台购物车业务层（加购 / 改数量 / 删条目）。
 *
 * 控制器仅负责参数收集与响应格式化；商品校验与库存校验统一走 {@see OrderItemValidator}。
 * 跨模块加购不再静默清空购物车，而是返回 cart_module_conflict，由客户端确认后带 replace=1 重试。
 */
class CartService extends BaseService
{
    /** @var OrderCore */
    private $order;

    /** @var PricingService */
    private $pricingService;

    /** @var OrderItemValidator */
    private $itemValidator;

    /**
     * @param OrderCore $order
     * @param PricingService $pricingService 定价服务
     * @param OrderItemValidator|null $itemValidator 商品校验（加购 / 报价 / 下单共用）
     *
     * 注：$itemValidator 不声明类型，隐式可空参数自 PHP 8.4 起已弃用，
     * 而显式写作 ?OrderItemValidator 需要 PHP 7.1+，会破坏 5.6 兼容。
     */
    public function __construct(OrderCore $order, PricingService $pricingService, $itemValidator = null)
    {
        $this->order = $order;
        $this->pricingService = $pricingService;
        $this->itemValidator = $itemValidator ? $itemValidator : new OrderItemValidator($order);
    }

    /**
     * 加入购物车
     *
     * @param int $userId
     * @param string $module 商品所属模块（product / item / equipment / course / download）
     * @param int $itemId
     * @param int $number
     * @param string $mode 'money' | 'point'
     * @param array $attParams 请求中 att_xxx 参数（规格属性）
     * @param bool $replace 跨模块冲突时是否清空购物车后加入
     * @return array ok=true + mode/module/cart_number | ok=false + code/msg
     */
    public function addToCart($userId, $module, $itemId, $number, $mode, array $attParams = array(), $replace = false)
    {
        // 与下单共用用户互斥锁，防止快照提交时删除并发加购/改量。
        $guard = new OrderSubmissionGuard();
        $lock = $guard->lockUser((int) $userId);
        if (empty($lock['ok'])) {
            return array_merge(array('deleted' => 0, 'cart_number' => 0), $lock);
        }
        try {
            return $this->doAddToCart($userId, $module, $itemId, $number, $mode, $attParams, $replace);
        } finally {
            $guard->unlockUser($lock['handle']);
        }
    }

    private function doAddToCart($userId, $module, $itemId, $number, $mode, array $attParams = array(), $replace = false)
    {
        // VIP 套餐统一走立即购买报价单，不允许进入购物车
        if (trim((string) $module) === 'vip_package') {
            return array(
                'ok' => false,
                'code' => 'package_buynow_only',
                'msg' => lang('order_package_buynow_only'),
            );
        }

        $validated = $this->itemValidator->validate($module, $itemId, $number, $mode, $attParams);
        if (!$validated['ok']) {
            return $validated;
        }

        $module = $validated['module'];
        $itemId = intval($validated['item']['id']);
        $number = intval($validated['number']);
        $attribute = (string) $validated['attribute'];
        $mode = $validated['mode'];

        // 快速购买 / 积分兑换：购物车只保留当前商品
        if (Config::get('param.order_quick_buy') || $mode === 'point') {
            $replace = true;
        }

        try {
            DB::beginTransaction();

            // 锁用户行，串行化同一会员的购物车写入（order_cart 无唯一键，锁新行无效）
            DB::table('user')->where('id', intval($userId))->lock('FOR UPDATE')->value('id');

            $hasOtherModule = false;
            $moduleRows = DB::table('order_cart')
                ->where('user_id', intval($userId))
                ->field('module')
                ->groupBy('module')
                ->select();
            foreach ((array) $moduleRows as $row) {
                if ((string) $row['module'] !== $module) {
                    $hasOtherModule = true;
                    break;
                }
            }

            if ($hasOtherModule && !$replace) {
                DB::rollback();

                return array(
                    'ok' => false,
                    'code' => 'cart_module_conflict',
                    'msg' => lang('order_cart_module_conflict'),
                );
            }

            if ($replace) {
                $this->order->clearCart(intval($userId));
            }

            $cartRow = DB::table('order_cart')
                ->where('user_id', intval($userId))
                ->where('module', $module)
                ->where('item_id', $itemId)
                ->where('attribute', $attribute)
                ->find();

            // 聚合库存：先按单行上限截断出目标数量，再校验「其它规格行 + 本行目标数量」
            $oldNumber = $cartRow ? intval($cartRow['item_number']) : 0;
            $newNumber = $this->itemValidator->clampNumber($oldNumber + $number);
            $sumInCart = (int) DB::table('order_cart')
                ->where('user_id', intval($userId))
                ->where('module', $module)
                ->where('item_id', $itemId)
                ->sum('item_number');
            $stockCheck = $this->itemValidator->checkAggregateStock($module, $itemId, $sumInCart - $oldNumber, $newNumber);
            if (!$stockCheck['ok']) {
                DB::rollback();

                return $stockCheck;
            }

            if ($cartRow) {
                $updated = DB::table('order_cart')
                    ->where('id', intval($cartRow['id']))
                    ->data(array('item_number' => $newNumber))
                    ->update();
                if ($updated === false) {
                    DB::rollback();

                    return array('ok' => false, 'code' => 'cart_error', 'msg' => lang('order_item_wrong'));
                }
            } else {
                $inserted = DB::table('order_cart')->data(array(
                    'user_id' => intval($userId),
                    'module' => $module,
                    'item_id' => $itemId,
                    'item_number' => $newNumber,
                    'attribute' => $attribute,
                ))->insert();
                if ($inserted === false) {
                    DB::rollback();

                    return array('ok' => false, 'code' => 'cart_error', 'msg' => lang('order_item_wrong'));
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();

            return array('ok' => false, 'code' => 'cart_error', 'msg' => lang('order_item_wrong'));
        }

        return array(
            'ok' => true,
            'mode' => $mode,
            'module' => $module,
            'cart_number' => $this->order->countCartNumber(intval($userId)),
        );
    }

    /**
     * 更新购物车商品数量
     *
     * @param int $userId
     * @param int $cartId
     * @param int $number
     * @return array JSON-ready 数组；ok=false 表示不支持本次修改并携带提示
     */
    public function updateCartItem($userId, $cartId, $number)
    {
        // 与下单共用用户互斥锁，防止快照提交时删除并发加购/改量。
        $guard = new OrderSubmissionGuard();
        $lock = $guard->lockUser((int) $userId);
        if (empty($lock['ok'])) {
            return array_merge(array('deleted' => 0, 'cart_number' => 0), $lock);
        }
        try {
            return $this->doUpdateCartItem($userId, $cartId, $number);
        } finally {
            $guard->unlockUser($lock['handle']);
        }
    }

    private function doUpdateCartItem($userId, $cartId, $number)
    {
        if (!empty(Config::get('param.order_quick_buy'))) {
            return array('ok' => false, 'code' => 'cart_update_unsupported', 'msg' => lang('order_cart_update_unsupported'));
        }

        $userId = (int) $userId;
        $cartId = (int) $cartId;
        $number = $this->itemValidator->clampNumber($number);
        $msg = '';

        try {
            DB::beginTransaction();

            // 与加购共用用户行锁：同会员多规格行并发改数量时按顺序判定聚合库存
            DB::table('user')->where('id', $userId)->lock('FOR UPDATE')->value('id');

            $cartRow = DB::table('order_cart')
                ->where('id', $cartId)
                ->where('user_id', $userId)
                ->find();
            if (!$cartRow) {
                DB::rollback();

                return array('ok' => false, 'code' => 'cart_item_not_found', 'msg' => lang('order_cart_item_not_found'));
            }

            $module = (string) $cartRow['module'];
            if ($module !== 'product') {
                DB::rollback();

                return array('ok' => false, 'code' => 'cart_update_unsupported', 'msg' => lang('order_cart_update_unsupported'));
            }

            // 同商品其它规格行已占数量
            $others = (int) DB::table('order_cart')
                ->where('user_id', $userId)
                ->where('module', $module)
                ->where('item_id', intval($cartRow['item_id']))
                ->where('id', '<>', $cartId)
                ->sum('item_number');

            $checkStock = $this->itemValidator->checkAggregateStock($module, $cartRow['item_id'], $others, $number);
            if (!$checkStock['ok']) {
                $available = isset($checkStock['available']) ? intval($checkStock['available']) : 0;
                $maxForRow = $available - $others;
                if ($maxForRow < 0) {
                    $maxForRow = 0;
                }
                if ($maxForRow > 0) {
                    $number = min($number, $maxForRow);
                    $msg = lang('order_out_stock') . $maxForRow;
                } else {
                    // 缺货时不写 0 / NULL，保持原数量并回传提示
                    $number = intval($cartRow['item_number']);
                    $msg = lang('order_out_stock') . 0;
                }
            }

            $updated = DB::table('order_cart')
                ->where('user_id', $userId)
                ->where('id', $cartId)
                ->data(array('item_number' => $number))
                ->update();
            if ($updated === false) {
                DB::rollback();

                return array('ok' => false, 'code' => 'cart_error', 'msg' => lang('order_item_wrong'));
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();

            return array('ok' => false, 'code' => 'cart_error', 'msg' => lang('order_item_wrong'));
        }

        $cart = $this->order->getCart($userId);
        if (!$cart) {
            return array(
                'ok' => true,
                'subtotal' => '',
                'total' => 0,
                'item_number' => $number,
                'item_amount' => Util::formatPrice(0),
                'cart_number' => 0,
                'msg' => $msg,
            );
        }

        $itemPrice = DB::table($module)->where('id', $cartRow['item_id'])->value('price');
        $salePrice = $this->pricingService->salePrice($module, $cartRow['item_id'], $userId);
        $itemPrice = $salePrice ? $salePrice['value'] : $itemPrice;

        $attributeInvalid = false;
        if (Config::get('features.attribute', false) && $cartRow['attribute']) {
            foreach (explode('_', $cartRow['attribute']) as $valueId) {
                $attrValue = DB::table('attribute_value')->where('id', intval($valueId))->find();
                if (!$attrValue || $attrValue['item_id'] != $cartRow['item_id']) {
                    $attributeInvalid = true;
                    break;
                }
                $itemPrice = $itemPrice + $attrValue['price_change'];
            }
        }

        $subtotal = (!$attributeInvalid && $itemPrice > 0)
            ? Util::formatPrice($itemPrice * $number)
            : ($attributeInvalid ? '' : lang('price_discuss'));

        if ($attributeInvalid) {
            $msg = lang('attribute_invalid');
        }

        return array(
            'ok' => true,
            'subtotal' => $subtotal,
            'total' => $cart['total'],
            'item_number' => $number,
            'item_amount' => Util::formatPrice($cart['item_amount']),
            'cart_number' => $this->order->countCartNumber($userId),
            'msg' => $msg,
        );
    }

    /**
     * 清空失效购物车行。
     *
     * @param int $userId
     * @return array deleted/cart_number
     */
    public function clearInvalid($userId)
    {
        // 与下单共用用户互斥锁，防止快照提交时删除并发加购/改量。
        $guard = new OrderSubmissionGuard();
        $lock = $guard->lockUser((int) $userId);
        if (empty($lock['ok'])) {
            return array_merge(array('deleted' => 0, 'cart_number' => 0), $lock);
        }
        try {
            return $this->doClearInvalid($userId);
        } finally {
            $guard->unlockUser($lock['handle']);
        }
    }

    private function doClearInvalid($userId)
    {
        $deleted = $this->order->clearInvalidCartItems((int) $userId);

        return array(
            'deleted' => (int) $deleted,
            'cart_number' => $this->order->countCartNumber((int) $userId),
        );
    }

    /**
     * 删除购物车条目
     *
     * @param int $userId
     * @param int $cartId
     * @return array
     */
    public function deleteCartItem($userId, $cartId)
    {
        // 与下单共用用户互斥锁，防止快照提交时删除并发加购/改量。
        $guard = new OrderSubmissionGuard();
        $lock = $guard->lockUser((int) $userId);
        if (empty($lock['ok'])) {
            return array_merge(array('deleted' => 0, 'cart_number' => 0), $lock);
        }
        try {
            return $this->doDeleteCartItem($userId, $cartId);
        } finally {
            $guard->unlockUser($lock['handle']);
        }
    }

    private function doDeleteCartItem($userId, $cartId)
    {
        DB::table('order_cart')->where('id', $cartId)->where('user_id', $userId)->delete();

        return array(
            'cart_number' => $this->order->countCartNumber((int) $userId),
        );
    }
}
