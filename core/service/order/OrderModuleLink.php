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
use Dou\Core\Infra\Log\Log;
use Dou\Core\Web\Routing\ModuleRegistry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单关联模块（link_order_item）的流水表映射与读写。
 *
 * dou_order.module 存的是商品表名（vip_package / money_package / aigc_package / geo_package），
 * 真正的购买流水分别落在 vip / money / aigc_subscription / geo_subscription。历史代码直接把
 * 商品表当流水表查 order_sn，导致取消 / 删除套餐订单时 SQL 报错。本类统一收敛映射与字段守卫。
 */
class OrderModuleLink
{
    /** @var array 非套餐但可售的内容模块（与 OrderableModule 对齐） */
    private static $contentModules = array('product', 'item', 'equipment', 'course', 'download');

    /**
     * 商品模块 → 流水表定义。
     *
     * @param string $module
     * @return array|null ['table' => string, 'key' => string, 'status' => string]
     */
    public static function recordFor($module)
    {
        $module = (string) $module;
        $map = array(
            'vip' => array('table' => 'vip', 'key' => 'order_sn', 'status' => 'order_status'),
            'vip_package' => array('table' => 'vip', 'key' => 'order_sn', 'status' => 'order_status'),
            'money' => array('table' => 'money', 'key' => 'from', 'status' => ''),
            'money_package' => array('table' => 'money', 'key' => 'from', 'status' => ''),
            'aigc' => array('table' => 'aigc_subscription', 'key' => 'order_sn', 'status' => 'order_status'),
            'aigc_package' => array('table' => 'aigc_subscription', 'key' => 'order_sn', 'status' => 'order_status'),
            'geo' => array('table' => 'geo_subscription', 'key' => 'order_sn', 'status' => 'order_status'),
            'geo_package' => array('table' => 'geo_subscription', 'key' => 'order_sn', 'status' => 'order_status'),
        );

        return isset($map[$module]) ? $map[$module] : null;
    }

    /**
     * 同步关联流水表的订单状态（仅对存在状态列的表生效）。
     *
     * @param string $module
     * @param string $orderSn
     * @param string $status
     * @return bool
     */
    public static function updateOrderStatus($module, $orderSn, $status)
    {
        $orderSn = (string) $orderSn;
        $record = self::recordFor($module);
        if (!$record) {
            // 云市场自定义 link_order_item 模块：表名即模块键，且自带 order_sn / order_status 时才同步
            $base = str_replace('_package', '', (string) $module);
            if (!in_array($base, (array) Config::get('module.link_order_item'), true)
                || !DB::tableExist($module)
                || !DB::fieldExist($module, 'order_sn')
                || !DB::fieldExist($module, 'order_status')
            ) {
                return false;
            }
            $record = array('table' => (string) $module, 'key' => 'order_sn', 'status' => 'order_status');
        }
        if ($orderSn === '' || $record['status'] === '') {
            return false;
        }
        if (!DB::tableExist($record['table'])
            || !DB::fieldExist($record['table'], $record['key'])
            || !DB::fieldExist($record['table'], $record['status'])
        ) {
            return false;
        }

        try {
            DB::table($record['table'])
                ->where($record['key'], $orderSn)
                ->data(array($record['status'] => (string) $status))
                ->update();
            return true;
        } catch (\Exception $e) {
            Log::warning('order module link status update failed: ' . $e->getMessage(), array(
                'channel' => 'order',
                'payload' => array(
                    'module' => (string) $module,
                    'order_sn' => $orderSn,
                    'status' => (string) $status,
                ),
            ));
            return false;
        }
    }

    /**
     * 全额退款时撤销关联权益。
     *
     * 充值包余额不在此自动反向（可能已被消费），仅记录日志交由人工处理。
     *
     * @param string $module
     * @param string $orderSn
     * @return bool
     */
    public static function revokeOnFullRefund($module, $orderSn)
    {
        $record = self::recordFor($module);
        if (!$record) {
            // 自定义 link 模块：交给 updateOrderStatus 做列守卫与回退
            return self::updateOrderStatus($module, $orderSn, 'refunded');
        }
        if ($record['table'] === 'money') {
            Log::warning('money package refund requires manual balance handling', array(
                'channel' => 'order',
                'payload' => array(
                    'module' => (string) $module,
                    'order_sn' => (string) $orderSn,
                ),
            ));
            return false;
        }

        return self::updateOrderStatus($module, $orderSn, 'refunded');
    }

    /**
     * 订单条目详情页 URL：内容模块走各自详情页，VIP 套餐映射到 vip.show，其余套餐无前台详情页。
     *
     * @param string $module
     * @param int $itemId
     * @return string
     */
    public static function itemUrl($module, $itemId)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        if ($itemId <= 0) {
            return '';
        }

        if ($module === 'vip_package') {
            return route('vip.show', array('id' => $itemId));
        }

        $registry = new ModuleRegistry();
        if ($module === 'product'
            || in_array($module, self::$contentModules, true)
            || $registry->isColumn($module)
        ) {
            return route($module . '.show', array('id' => $itemId));
        }

        return '';
    }
}
