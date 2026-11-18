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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单购物车读模型（购物车域）。
 *
 * 负责购物车数据装配与清空：拉取 order_cart、按 module 批量取商品、属性/会员价/积分换算、生成购物车项视图字段。
 * 强依赖 user 模块（购物车按 `user_id` 存储）：会员模块未启用时 {@see getCart} / {@see clearCart}
 * 返回空购物车或 false，调用方在入口处用 features.user 闸提示安装。
 */
class OrderCartQuery extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /** @var PricingService */
    private $pricingService;

    /** @var OrderStockGuard */
    private $stockGuard;

    /** @var array 请求级实时库存缓存 {module}_{id} => available（-1 表示不限库存） */
    private $stockCache = array();

    /** @var array 请求级库存字段探测缓存 module => bool */
    private $stockFieldCache = array();

    /**
     * @param PricingService $pricingService
     * @param OrderStockGuard $stockGuard 实时库存（stock - 锁定中数量）
     */
    public function __construct(PricingService $pricingService, OrderStockGuard $stockGuard)
    {
        $this->pricingService = $pricingService;
        $this->stockGuard = $stockGuard;
    }

    /**
     * 取指定会员购物车视图数据。
     *
     * 始终返回 list（可结算）+ invalid_list（失效/缺货），不再静默删除；
     * 清理失效行由 clearInvalidCartItems() 显式完成。
     *
     * @param int $user_id 显式入参；<=0 返回空购物车
     * @return array|bool 购物车结构数组；购物车为空返回 false
     */
    public function getCart($user_id = 0)
    {
        $this->stockCache = array();

        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) {
                $this->logError('Invalid user ID in get_cart');
                return $this->getEmptyCart();
            }

            $cart_items = DB::table('order_cart')
                ->where('user_id', $user_id)
                ->order('id DESC')
                ->select();

            if (empty($cart_items)) {
                return false;
            }

            $product_map = $this->getProductsBatch($cart_items);
            $stock_map = $this->buildStockMap($cart_items, $product_map);
            $item_sums = $this->buildItemSums($cart_items);
            $valid_qty_sums = $this->buildValidQtySums($cart_items, $product_map);

            $cart = $this->getEmptyCart();

            // 第一轮：判定每行有效性，并以首个有效行的模块作为整车主模块
            $evaluated = array();
            $module = '';
            foreach ($cart_items as $row) {
                $reason = $this->invalidReason($row, $product_map, $stock_map, $valid_qty_sums);
                $evaluated[] = array('row' => $row, 'reason' => $reason);
                if ($reason === '' && $module === '') {
                    $module = (string) $row['module'];
                }
            }
            if ($module === '') {
                $module = (string) $cart_items[0]['module'];
            }

            // 第二轮：有效行计入合计；失效行 / 跨模块行进入 invalid_list
            foreach ($evaluated as $entry) {
                $row = $entry['row'];
                $item_key = $row['module'] . '_' . $row['item_id'];
                $item = isset($product_map[$item_key]) ? $product_map[$item_key] : null;

                if ($entry['reason'] !== '') {
                    $cart['invalid_list'][] = $this->buildInvalidItem($row, $entry['reason'], $item);
                    continue;
                }
                if ((string) $row['module'] !== $module) {
                    $cart['invalid_list'][] = $this->buildInvalidItem($row, 'module_conflict', $item);
                    continue;
                }

                $stockInfo = null;
                if (isset($stock_map[$item_key]) && $stock_map[$item_key] >= 0) {
                    $stockInfo = array(
                        'available' => $stock_map[$item_key],
                        'others' => isset($item_sums[$item_key]) ? $item_sums[$item_key] - intval($row['item_number']) : 0,
                    );
                }

                $cart_item = $this->buildCartItem($row, $item, $user_id, $stockInfo);
                if (!$cart_item) {
                    $cart['invalid_list'][] = $this->buildInvalidItem($row, 'item_not_found', $item);
                    continue;
                }

                $cart['list'][] = $cart_item;
                $this->updateCartTotals($cart, $cart_item);
            }

            $cart['module'] = $module;

            return $cart;
        } catch (\Exception $e) {
            $this->logError('get_cart error: ' . $e->getMessage(), array(
                'user_id' => $user_id,
                'trace' => $e->getTraceAsString(),
            ));
            return false;
        }
    }

    /**
     * 统计购物车有效件数（角标 / cart_number）。
     *
     * 与 getCart 的 list 同口径：模块可售、商品存在、上架、属性有效、非缺货；
     * 混合模块历史数据只计首个有效模块（与 getCart 的主模块一致）。
     *
     * @param int $user_id
     * @return int
     */
    public function countCartNumber($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        $this->stockCache = array();

        try {
            $rows = DB::table('order_cart')
                ->where('user_id', $user_id)
                ->order('id DESC')
                ->select();
            if (empty($rows)) {
                return 0;
            }

            $product_map = $this->getProductsBatch($rows);
            $stock_map = $this->buildStockMap($rows, $product_map);
            $valid_qty_sums = $this->buildValidQtySums($rows, $product_map);
            $module = '';
            $total = 0;
            foreach ($rows as $row) {
                if ($this->invalidReason($row, $product_map, $stock_map, $valid_qty_sums) !== '') {
                    continue;
                }
                if ($module === '') {
                    $module = (string) $row['module'];
                }
                if ((string) $row['module'] !== $module) {
                    continue;
                }
                $total += intval($row['item_number']);
            }

            return $total;
        } catch (\Exception $e) {
            $this->logError('count_cart_number error: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * 按报价单载荷装配一次性购物车视图（不落 order_cart）。
     *
     * 立即购买 / 积分 / 套餐共用：与购物车读取走同一套属性、会员价、合计逻辑，
     * 避免"绕过购物车"的链路单独维护价格计算。
     *
     * @param int $user_id
     * @param array $payload uid/module/item_id/item_number/attribute/mode
     * @return array ok=true + cart/mode | ok=false + code/msg
     */
    public function getQuoteCart($user_id, array $payload)
    {
        $this->stockCache = array();

        $user_id = (int) $user_id;
        $module = isset($payload['module']) ? trim((string) $payload['module']) : '';
        $item_id = isset($payload['item_id']) ? intval($payload['item_id']) : 0;
        $number = isset($payload['item_number']) ? intval($payload['item_number']) : 0;
        $attribute = isset($payload['attribute']) ? (string) $payload['attribute'] : '';
        $mode = (isset($payload['mode']) && $payload['mode'] === 'point') ? 'point' : 'money';

        if ($user_id <= 0 || !OrderableModule::allows($module) || $item_id <= 0 || $number <= 0) {
            return array('ok' => false, 'code' => 'item_not_found', 'msg' => lang('order_item_wrong'));
        }

        $item = DB::table($module)->where('id', $item_id)->find();
        if (!$item) {
            return array('ok' => false, 'code' => 'item_not_found', 'msg' => lang('order_item_wrong'));
        }
        if (!OrderableModule::isPublished($module, $item)) {
            return array('ok' => false, 'code' => 'off_shelf', 'msg' => lang('order_item_off_shelf'));
        }
        $attributeIssue = $this->attributeIssue($module, $attribute, $item);
        if ($attributeIssue === 'attribute_incomplete') {
            return array('ok' => false, 'code' => 'attribute_incomplete', 'msg' => lang('attribute_required'));
        }
        if ($attributeIssue === 'attribute_invalid') {
            return array('ok' => false, 'code' => 'invalid_attribute', 'msg' => lang('attribute_invalid'));
        }

        $available = $this->stockAvailable($module, $item_id);
        if ($available >= 0 && $number > $available) {
            return array('ok' => false, 'code' => 'stock_error', 'msg' => lang('order_out_stock') . max(0, $available));
        }

        $row = array(
            'id' => 0,
            'module' => $module,
            'item_id' => $item_id,
            'item_number' => $number,
            'attribute' => $attribute,
        );
        $cart_item = $this->buildCartItem($row, $item, $user_id, $available >= 0 ? array(
            'available' => $available,
            'others' => 0,
        ) : null);
        if (!$cart_item) {
            return array('ok' => false, 'code' => 'item_not_found', 'msg' => lang('order_item_wrong'));
        }

        $cart = $this->getEmptyCart();
        $cart['module'] = $module;
        $cart['list'][] = $cart_item;
        $this->updateCartTotals($cart, $cart_item);

        return array('ok' => true, 'cart' => $cart, 'mode' => $mode);
    }

    /**
     * 校验属性串：值必须属于该商品，且覆盖所有含可选项的属性组。
     *
     * 只校验已提交值归属会放过「少选规格」的请求；这里同时校验完整性，
     * 与 OrderItemValidator::buildAttribute 的必选语义保持一致。
     *
     * @param string $module
     * @param string $attribute
     * @param array $item 商品行
     * @return string '' / attribute_invalid / attribute_incomplete
     */
    private function attributeIssue($module, $attribute, array $item)
    {
        // 属性能力关闭时按基础价处理（与 processAttributes 的跳过语义一致）
        if (!Config::get('features.attribute', false)) {
            return '';
        }

        $item_id = intval(isset($item['id']) ? $item['id'] : 0);
        $submitted = array();
        $attribute = trim((string) $attribute);
        if ($attribute !== '') {
            foreach (explode('_', $attribute) as $value_id) {
                $value_id = intval($value_id);
                if ($value_id <= 0) {
                    return 'attribute_invalid';
                }
                $row = DB::table('attribute_value')->where('id', $value_id)->find();
                if (!$row || intval($row['item_id']) !== $item_id) {
                    return 'attribute_invalid';
                }
                $submitted[$value_id] = intval($row['att_id']);
            }
        }

        $attributeSvc = Module::make('attribute');
        if (!$attributeSvc || !isset($item['category_id'])) {
            return '';
        }

        $attributeList = $attributeSvc->getAttributeList($module, $item['category_id'], $item_id);
        foreach ((array) $attributeList as $att) {
            if (empty($att['value_list'])) {
                continue;
            }
            $group_id = intval($att['att_id']);
            $matched = false;
            foreach ($submitted as $att_id) {
                if ($att_id === $group_id) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return 'attribute_incomplete';
            }
        }

        return '';
    }

    /**
     * 清空指定会员购物车。
     *
     * @param int $user_id 显式入参；<=0 直接返回 false
     * @return bool
     */
    public function clearCart($user_id = 0)
    {
        try {
            $user_id = (int) $user_id;
            if ($user_id <= 0) {
                $this->logError('Invalid user ID in clear_cart');
                return false;
            }

            return DB::table('order_cart')
                ->where('user_id', $user_id)
                ->delete() !== false;
        } catch (\Exception $e) {
            $this->logError('clear_cart error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 积分展示（数值 + 单位）：point_point 未加载时回退 user_point。
     *
     * @param mixed $point
     * @return array point, point_format
     */
    public function pointForView($point)
    {
        $p = intval($point);
        $suffix = lang('point_point', lang('user_point', ''));

        return array(
            'point' => $p,
            'point_format' => $p . $suffix,
        );
    }

    /**
     * 批量按 module 拉取商品基础信息。
     *
     * @param array $cart_items
     * @return array key 形如 "{module}_{id}"
     */
    private function getProductsBatch($cart_items)
    {
        $module_groups = array();
        foreach ($cart_items as $item) {
            $module_groups[$item['module']][] = intval($item['item_id']);
        }

        $product_map = array();
        foreach ($module_groups as $module => $ids) {
            if (!OrderableModule::allows($module)) {
                continue;
            }

            $ids = array_unique($ids);
            if (empty($ids)) {
                continue;
            }

            $products = DB::table($module)
                ->whereIn('id', $ids)
                ->select();

            if ($products) {
                foreach ($products as $product) {
                    $product_map[$module . '_' . $product['id']] = $product;
                }
            }
        }

        return $product_map;
    }

    /**
     * 构建 {module}_{id} => 实时库存；-1 表示不限库存。
     *
     * @param array $rows order_cart 行
     * @param array $product_map
     * @return array
     */
    private function buildStockMap(array $rows, array $product_map)
    {
        $map = array();
        foreach ($rows as $row) {
            $key = $row['module'] . '_' . $row['item_id'];
            if (array_key_exists($key, $map)) {
                continue;
            }
            if (!OrderableModule::allows($row['module']) || !isset($product_map[$key])) {
                $map[$key] = -1;
                continue;
            }
            $map[$key] = $this->stockAvailable($row['module'], $row['item_id']);
        }

        return $map;
    }

    /**
     * 构建 {module}_{id} => 车内总数量（含各规格行）。
     *
     * @param array $rows
     * @return array
     */
    private function buildItemSums(array $rows)
    {
        $sums = array();
        foreach ($rows as $row) {
            $key = $row['module'] . '_' . $row['item_id'];
            $sums[$key] = array_key_exists($key, $sums)
                ? $sums[$key] + intval($row['item_number'])
                : intval($row['item_number']);
        }

        return $sums;
    }

    /**
     * 实时可售库存；-1 表示不限库存（site.stock 关闭或无 stock 字段）。
     *
     * @param string $module
     * @param int $item_id
     * @return int
     */
    private function stockAvailable($module, $item_id)
    {
        $module = trim((string) $module);
        $item_id = intval($item_id);
        if ($module === '' || $item_id <= 0 || !Config::get('site.stock', false)) {
            return -1;
        }

        if (!array_key_exists($module, $this->stockFieldCache)) {
            $this->stockFieldCache[$module] = DB::fieldExist($module, 'stock');
        }
        if (!$this->stockFieldCache[$module]) {
            return -1;
        }

        $key = $module . '_' . $item_id;
        if (!array_key_exists($key, $this->stockCache)) {
            $this->stockCache[$key] = intval($this->stockGuard->realTimeStock($module, $item_id));
        }

        return $this->stockCache[$key];
    }

    /**
     * 装配单个购物车项视图数据。
     *
     * @param array $row order_cart 行
     * @param array $item 商品行
     * @param int $user_id
     * @param array|null $stockInfo available/others；null 表示不限库存
     * @return array
     */
    private function buildCartItem($row, $item, $user_id, $stockInfo = null)
    {
        $sale_price = $this->pricingService->salePrice($row['module'], $item['id'], $user_id);

        $name = isset($item['name']) && $item['name']
            ? $item['name']
            : (isset($item['title']) ? $item['title'] : '');

        $attribute_info = $this->processAttributes($row['attribute'], $item['price'], $sale_price, $row['item_id']);

        $final_price = $this->calculateFinalPrice($item['price'], $sale_price);

        $price_display = isset($item['price']) && $item['price'] > 0
            ? Util::formatPrice($item['price'])
            : lang('price_discuss');

        $pointView = $this->pointForView(isset($item['point']) ? $item['point'] : 0);

        $stock_available = -1;
        $max_number = 999;
        $stock_shortage = false;
        if (is_array($stockInfo) && isset($stockInfo['available'])) {
            $stock_available = intval($stockInfo['available']);
            $others = isset($stockInfo['others']) ? intval($stockInfo['others']) : 0;
            $max_for_row = $stock_available - $others;
            if ($max_for_row < 0) {
                $max_for_row = 0;
            }
            $max_number = $max_for_row < 999 ? $max_for_row : 999;
            $stock_shortage = intval($row['item_number']) > $max_for_row;
        }

        return array(
            'id' => intval($row['id']),
            'module' => (string) $row['module'],
            'name' => $name,
            'attribute_name' => $attribute_info['name'],
            'item_id' => intval($row['item_id']),
            'price_normal' => floatval($item['price']),
            'price' => $price_display,
            'sale_price' => $sale_price,
            'point' => $pointView['point'],
            'point_format' => $pointView['point_format'],
            'point_subtotal' => $pointView['point'] * intval($row['item_number']),
            'point_subtotal_format' => $this->pointForView($pointView['point'] * intval($row['item_number']))['point_format'],
            'url' => OrderModuleLink::itemUrl($row['module'], $item['id']),
            'image' => $this->getItemImage(isset($item['image']) ? $item['image'] : '', $row['module']),
            'vip_days' => (string) $row['module'] === 'vip_package' && isset($item['day']) ? (int) $item['day'] : 0,
            'subtotal' => $this->calculateSubtotal($final_price, $row['item_number']),
            'number' => intval($row['item_number']),
            'stock' => isset($item['stock']) ? intval($item['stock']) : 0,
            'stock_available' => $stock_available,
            'max_number' => $max_number,
            'stock_shortage' => $stock_shortage,
            'valid' => true,
            'touch_move' => 0,
            'touch_move_start' => 0,
            'attribute' => $row['attribute'],
        );
    }

    /**
     * 解析规格属性，计算属性带来的差价并更新 base/sale 价。
     *
     * @param string $attribute_ids
     * @param mixed $base_price 引用：在原价上叠加属性差价
     * @param mixed $sale_price 引用：会员/促销价数组同样叠加差价
     * @param int $item_id
     * @return array name, price_change
     */
    private function processAttributes($attribute_ids, &$base_price, &$sale_price, $item_id)
    {
        $attribute_name = '';
        $price_change = 0;

        if (!$attribute_ids || !Config::get('features.attribute', false)) {
            return array('name' => '', 'price_change' => 0);
        }

        $attribute_list = explode('_', $attribute_ids);
        if (empty($attribute_list)) {
            return array('name' => '', 'price_change' => 0);
        }

        $attribute_values = DB::table('attribute_value')
            ->whereIn('id', array_map('intval', $attribute_list))
            ->select();

        $value_map = array();
        foreach ($attribute_values as $value) {
            $value_map[$value['id']] = $value;
        }
        language()->warmup('attribute_value', array_keys($value_map), 'value');

        foreach ($attribute_list as $value_id) {
            if (isset($value_map[$value_id])) {
                $value = $value_map[$value_id];
                if ($value['item_id'] != $item_id) {
                    $this->logError('Attribute value does not belong to item', array(
                        'value_id' => $value_id,
                        'item_id' => $item_id,
                        'value_item_id' => $value['item_id'],
                    ));
                    continue;
                }
                $attribute_name .= ' ' . language()->langValue($value['value'], 'attribute_value', $value['id'], 'value');
                $price_change += floatval($value['price_change']);
            }
        }

        $base_price += $price_change;
        if ($sale_price && isset($sale_price['value'])) {
            $sale_price['value'] += $price_change;
            // 同步展示价，避免单价显示未含属性加价而小计已含
            $sale_price['format'] = Util::formatPrice($sale_price['value']);
        }

        return array('name' => trim($attribute_name), 'price_change' => $price_change);
    }

    /**
     * 计算单项最终单价：会员/促销价优先，否则原价（原价已由 processAttributes 叠加属性差价）。
     *
     * @param mixed $base_price 已含属性差价的单价
     * @param mixed $sale_price 引用：补 format 字段
     * @return float
     */
    private function calculateFinalPrice($base_price, $sale_price)
    {
        $final_price = floatval($base_price);

        if ($sale_price && isset($sale_price['value'])) {
            $final_price = floatval($sale_price['value']);

            if (!isset($sale_price['format'])) {
                $sale_price['format'] = Util::formatPrice($sale_price['value']);
            }
        }

        return $final_price;
    }

    /**
     * 解析商品图片 URL：product 走主域，其它模块按文件存储域。
     *
     * @param string $image
     * @param string $module
     * @return string
     */
    private function getItemImage($image, $module)
    {
        if (empty($image)) {
            return '';
        }

        return $module == 'product'
            ? attachment()->url($image, true)
            : attachment()->url($image);
    }

    /**
     * 计算单项小计金额（含价格为 0 时回退「面议」语言串）。
     *
     * @param mixed $price
     * @param mixed $quantity
     * @return string
     */
    private function calculateSubtotal($price, $quantity)
    {
        if ($price > 0) {
            return Util::formatPrice($price * $quantity);
        }

        return lang('price_discuss');
    }

    /**
     * 累加购物车总数 / 总金额 / 总积分。
     *
     * @param array $cart 引用
     * @param array $cart_item
     * @return void
     */
    private function updateCartTotals(&$cart, $cart_item)
    {
        $final_price = 0;
        if (isset($cart_item['sale_price']['value'])) {
            $final_price = floatval($cart_item['sale_price']['value']);
        } else {
            $final_price = floatval($cart_item['price_normal']);
        }

        $cart['total'] += intval($cart_item['number']);

        $item_total = $final_price * intval($cart_item['number']);
        $cart['item_amount'] += $item_total;
        $cart['item_amount_format'] = Util::formatPrice($cart['item_amount']);

        $cart['order_point'] += intval($cart_item['point']) * intval($cart_item['number']);
        $pointView = $this->pointForView($cart['order_point']);
        $cart['order_point_format'] = $pointView['point_format'];
    }

    /**
     * 空购物车模板。
     *
     * @return array
     */
    private function getEmptyCart()
    {
        return array(
            'list' => array(),
            'invalid_list' => array(),
            'module' => '',
            'total' => 0,
            'item_amount' => 0,
            'item_amount_format' => '0.00',
            'order_point' => 0,
            'order_point_format' => '0',
        );
    }

    /**
     * 判定购物车行的失效原因；空串表示有效。
     *
     * 库存按同商品「有效行」合计数量判定：同商品不同属性拆成多行时，单行适量但合计超库存的行同样标记缺货；
     * 属性失效 / 下架 / 不存在的行不计入合计。
     *
     * @param array $row order_cart 行
     * @param array $product_map {module}_{id} => 商品行
     * @param array $stock_map {module}_{id} => 实时库存（-1 不限）
     * @param array $qty_map {module}_{id} => 同商品有效行合计数量
     * @return string module_disabled / item_not_found / off_shelf / attribute_invalid / out_of_stock / stock_shortage / ''
     */
    private function invalidReason(array $row, array $product_map, array $stock_map, array $qty_map = array())
    {
        $issue = $this->structuralIssue($row, $product_map);
        if ($issue !== '') {
            return $issue;
        }

        $item_key = $row['module'] . '_' . $row['item_id'];
        if (isset($stock_map[$item_key]) && intval($stock_map[$item_key]) >= 0) {
            $available = intval($stock_map[$item_key]);
            if ($available === 0) {
                return 'out_of_stock';
            }
            $requested = isset($qty_map[$item_key]) ? intval($qty_map[$item_key]) : intval($row['item_number']);
            if ($requested > $available) {
                return 'stock_shortage';
            }
        }

        return '';
    }

    /**
     * 判定与库存无关的结构性失效原因；空串表示结构有效。
     *
     * @param array $row order_cart 行
     * @param array $product_map {module}_{id} => 商品行
     * @return string module_disabled / item_not_found / off_shelf / attribute_invalid / ''
     */
    private function structuralIssue(array $row, array $product_map)
    {
        $module = (string) $row['module'];
        if (!OrderableModule::allows($module)) {
            return 'module_disabled';
        }

        $item_key = $module . '_' . $row['item_id'];
        if (!isset($product_map[$item_key])) {
            return 'item_not_found';
        }
        if (!OrderableModule::isPublished($module, $product_map[$item_key])) {
            return 'off_shelf';
        }

        return $this->attributeIssue($module, $row['attribute'], $product_map[$item_key]);
    }

    /**
     * 构建 {module}_{id} => 同商品有效行合计数量（结构性失效行不计入）。
     *
     * @param array $rows
     * @param array $product_map {module}_{id} => 商品行
     * @return array
     */
    private function buildValidQtySums(array $rows, array $product_map)
    {
        $sums = array();
        foreach ($rows as $row) {
            if ($this->structuralIssue($row, $product_map) !== '') {
                continue;
            }
            $key = $row['module'] . '_' . $row['item_id'];
            $sums[$key] = array_key_exists($key, $sums)
                ? $sums[$key] + intval($row['item_number'])
                : intval($row['item_number']);
        }

        return $sums;
    }

    /**
     * 组装失效行视图（不参与合计）。
     *
     * @param array $row order_cart 行
     * @param string $reason
     * @param array|null $item 商品行（不存在时为 null）
     * @return array
     */
    private function buildInvalidItem(array $row, $reason, $item = null)
    {
        $name = '';
        $image = '';
        if (is_array($item)) {
            $name = isset($item['name']) && $item['name']
                ? $item['name']
                : (isset($item['title']) ? $item['title'] : '');
            $image = $this->getItemImage(isset($item['image']) ? $item['image'] : '', $row['module']);
        }
        if ($name === '') {
            $name = lang('order_item_off_shelf');
        }

        return array(
            'id' => intval($row['id']),
            'module' => (string) $row['module'],
            'item_id' => intval($row['item_id']),
            'name' => $name,
            'image' => $image,
            'number' => intval($row['item_number']),
            'attribute' => (string) $row['attribute'],
            'reason' => (string) $reason,
            'reason_text' => $this->invalidReasonText($reason),
        );
    }

    /**
     * @param string $reason
     * @return string
     */
    private function invalidReasonText($reason)
    {
        if ($reason === 'attribute_invalid') {
            return lang('attribute_invalid');
        }
        if ($reason === 'attribute_incomplete') {
            return lang('attribute_required');
        }
        if ($reason === 'module_conflict') {
            return lang('order_item_wrong');
        }
        if ($reason === 'out_of_stock') {
            return lang('order_cart_out_of_stock');
        }
        if ($reason === 'stock_shortage') {
            return lang('order_cart_stock_shortage');
        }

        return lang('order_item_off_shelf');
    }

    /**
     * 清空失效购物车行（不触碰有效行）。
     *
     * @param int $user_id
     * @return int 删除行数
     */
    public function clearInvalidCartItems($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return 0;
        }

        $cart = $this->getCart($user_id);
        if (!is_array($cart) || empty($cart['invalid_list'])) {
            return 0;
        }

        $ids = array();
        foreach ($cart['invalid_list'] as $row) {
            $ids[] = intval($row['id']);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return 0;
        }

        $deleted = DB::table('order_cart')
            ->where('user_id', $user_id)
            ->whereIn('id', $ids)
            ->delete();

        return $deleted === false ? 0 : count($ids);
    }

    /**
     * 记录错误：写入实例错误日志数组，同时投递到 Log::error（channel=order）。
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
