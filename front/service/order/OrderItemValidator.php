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
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderableModule;
use Dou\Core\Service\Order\OrderService as OrderCore;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单商品校验（加购 / 立即购买 / 结算共用）。
 *
 * 把「模块白名单、商品存在、上架状态、规格归属、数量上限、库存」收敛到一处，
 * 避免加购、报价单、结算各自维护一套判定。
 */
class OrderItemValidator extends BaseService
{
    /** 单行数量上限（与 dou_order_cart.item_number smallint 保持安全余量） */
    const MAX_NUMBER = 999;

    /** @var OrderCore */
    private $order;

    /**
     * @param OrderCore $order 订单核心门面（库存校验 / 实时库存）
     */
    public function __construct(OrderCore $order)
    {
        $this->order = $order;
    }

    /**
     * 数量归一：小于 1 取 1，大于上限取上限。
     *
     * @param mixed $number
     * @return int
     */
    public function clampNumber($number)
    {
        $number = intval($number);
        if ($number < 1) {
            return 1;
        }
        if ($number > self::MAX_NUMBER) {
            return self::MAX_NUMBER;
        }

        return $number;
    }

    /**
     * 校验待加购 / 待下单商品，并返回规范化后的商品行、规格串与数量。
     *
     * @param string $module
     * @param int $itemId
     * @param int $number
     * @param string $mode 'money' | 'point'
     * @param array $attParams 请求中的 att_xxx 参数
     * @return array ok/code/msg/module/item/number/attribute/mode
     */
    public function validate($module, $itemId, $number, $mode, array $attParams = array())
    {
        $module = trim((string) $module);
        $itemId = intval($itemId);
        $mode = $mode === 'point' ? 'point' : 'money';
        $number = $this->clampNumber($number);

        // 套餐类按份开通/入账，一份即一个周期；多余数量只会重复收款
        if (OrderPolicy::isSinglePurchase($module)) {
            $number = 1;
        }

        if (!OrderableModule::allows($module)) {
            return $this->fail('item_not_found', lang('order_item_wrong'));
        }

        $item = DB::table($module)->where('id', $itemId)->find();
        if (!$item) {
            return $this->fail('item_not_found', lang('order_item_wrong'));
        }

        // 积分兑换只能用于显式配置了 point 的商品
        if ($mode === 'point'
            && (!Config::get('features.point', false)
                || !isset($item['point'])
                || intval($item['point']) <= 0)
        ) {
            return $this->fail('point_not_supported', lang('order_point_not_supported', lang('order_item_wrong')));
        }

        if (!OrderableModule::isPublished($module, $item)) {
            return $this->fail('off_shelf', lang('order_item_off_shelf'));
        }

        // 0 元商品无法形成有效支付单，加购阶段直接拦截；积分兑换按 point 独立校验
        if ($mode !== 'point' && $module === 'product' && isset($item['price']) && floatval($item['price']) <= 0) {
            return $this->fail('price_invalid', lang('order_price_invalid', lang('order_item_wrong')));
        }

        $attributeResult = $this->buildAttribute($module, $item, $attParams);
        if (!$attributeResult['ok']) {
            return $attributeResult;
        }

        $checkStock = $this->order->checkStock($module, $itemId, $number);
        if ($checkStock['code'] !== 'without_limit') {
            return $this->fail('stock_error', (string) $checkStock['msg']);
        }

        return array(
            'ok' => true,
            'code' => '',
            'msg' => '',
            'module' => $module,
            'item' => $item,
            'number' => $number,
            'attribute' => $attributeResult['attribute'],
            'mode' => $mode,
        );
    }

    /**
     * 解析并校验规格参数，返回可写入 order_cart.attribute 的 id 串。
     *
     * 与历史实现保持一致：只收集商品已配置的属性项；传入值必须属于该商品。
     *
     * @param string $module
     * @param array $item 商品行
     * @param array $attParams
     * @return array ok/code/msg/attribute
     */
    public function buildAttribute($module, array $item, array $attParams)
    {
        $attribute = '';
        $attributeSvc = Module::make('attribute');
        if ($attributeSvc && isset($item['category_id'])) {
            $attributeList = $attributeSvc->getAttributeList($module, $item['category_id'], $item['id']);
            $attributeArray = array();
            foreach ($attributeList as $att) {
                $valueId = intval(isset($attParams['att_' . $att['att_id']]) ? $attParams['att_' . $att['att_id']] : 0);
                // 有可选项的属性视为必选；缺值直接拒绝，避免绕过规格下单
                if ($valueId <= 0) {
                    if (!empty($att['value_list'])) {
                        return $this->fail('attribute_required', lang('attribute_required'));
                    }
                    continue;
                }
                // 值必须属于该商品，且必须属于当前属性（防止把 A 属性的值塞到 B 属性键下）
                $belongs = DB::table('attribute_value')
                    ->where('id', $valueId)
                    ->where('item_id', intval($item['id']))
                    ->where('att_id', intval($att['att_id']))
                    ->exists();
                if (!$belongs) {
                    return $this->fail('invalid_attribute', lang('attribute_invalid'));
                }
                $attributeArray[] = $valueId;
            }
            $attribute = implode('_', $attributeArray);
        }

        return array(
            'ok' => true,
            'code' => '',
            'msg' => '',
            'attribute' => $attribute,
        );
    }

    /**
     * 聚合库存校验：车内同商品（跨规格）数量 + 本次数量 不得超过实时库存。
     *
     * 必须在数据库事务之外调用（realTimeStock 只查库存，不触发自动任务）。
     *
     * @param string $module
     * @param int $itemId
     * @param int $sumInCart 车内同商品已有数量
     * @param int $additional 本次新增数量
     * @return array ok/code/msg/available
     */
    public function checkAggregateStock($module, $itemId, $sumInCart, $additional)
    {
        $module = trim((string) $module);
        $itemId = intval($itemId);
        if ($module === '' || $itemId <= 0) {
            return $this->fail('invalid_params', lang('order_item_wrong'));
        }

        if (!Config::get('site.stock', false) || !DB::fieldExist($module, 'stock')) {
            return array('ok' => true, 'code' => '', 'msg' => '', 'available' => 0);
        }

        $available = $this->order->realTimeStock($module, $itemId);
        if (intval($sumInCart) + intval($additional) > $available) {
            return $this->fail('stock_error', lang('order_out_stock') . $available, $available);
        }

        return array('ok' => true, 'code' => '', 'msg' => '', 'available' => $available);
    }

    /**
     * @param string $code
     * @param string $msg
     * @param int|null $available
     * @return array
     */
    private function fail($code, $msg, $available = null)
    {
        $result = array('ok' => false, 'code' => $code, 'msg' => $msg);
        if ($available !== null) {
            $result['available'] = $available;
        }

        return $result;
    }
}
