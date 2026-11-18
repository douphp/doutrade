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
use Dou\Core\Web\Routing\ModuleRegistry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 可下单模块白名单（购物车 / 结算共用）。
 *
 * 历史实现直接以请求参数作为表名查询，任意存在 price 列的表（如 dou_vip / dou_money）都可能被塞进购物车。
 * 本类把可下单范围收敛为：product、link_order_item 声明的套餐表、以及明确可售的内容模块；
 * 并统一要求目标表存在 price 列，避免把台账 / 系统表当成商品。
 */
class OrderableModule
{
    /** @var array 请求级判定缓存 */
    private static $cache = array();

    /** @var array 明确允许的非 product / 非套餐模块 */
    private static $allowed = array(
        'item',
        'equipment',
        'course',
        'download',
    );

    /** @var array 有明确「上架」语义的模块（status = '1' 可售；其余模块不做上下架拦截） */
    private static $published = array(
        'product',
        'equipment',
        'course',
        'download',
        'aigc_package',
        'geo_package',
        'vip_package',
    );

    /** @var array 系统 / 台账表，即使存在 price 列也不允许下单 */
    private static $denied = array(
        'admin',
        'aftersale',
        'attribute',
        'book',
        'brand',
        'certificate',
        'chat',
        'comment',
        'config',
        'consultation',
        'coupon',
        'dh',
        'distribution',
        'email',
        'faq',
        'favorites',
        'file',
        'form',
        'geo',
        'guestbook',
        'health',
        'job',
        'landing',
        'language',
        'link',
        'money',
        'onepic',
        'open',
        'order',
        'partner',
        'plugin',
        'point',
        'service',
        'share',
        'sms',
        'sn',
        'store',
        'tag',
        'team',
        'user',
        'vip',
        'vote',
        'weixin',
        'withdraw',
        'work',
    );

    /**
     * 判断模块是否可作为订单商品来源。
     *
     * @param string $module 数据库表名（如 product / vip_package / item）
     * @return bool
     */
    public static function allows($module)
    {
        $module = trim((string) $module);
        if ($module === '') {
            return false;
        }
        if (array_key_exists($module, self::$cache)) {
            return self::$cache[$module];
        }

        self::$cache[$module] = self::check($module);

        return self::$cache[$module];
    }

    /**
     * 判断商品行是否处于可售（上架）状态。
     *
     * 仅对有明确 status 语义的模块生效；传入行必须包含 status 字段，
     * 缺字段时按不可售处理（fail-closed），避免调用方漏 select 导致绕过。
     *
     * @param string $module 数据库表名
     * @param array $item 商品行（至少含 id/status）
     * @return bool
     */
    public static function isPublished($module, array $item)
    {
        $module = trim((string) $module);
        if (!in_array($module, self::$published, true)) {
            return true;
        }

        return isset($item['status']) && (string) $item['status'] === '1';
    }

    /**
     * 实际判定。
     *
     * @param string $module
     * @return bool
     */
    private static function check($module)
    {
        if (!DB::tableExist($module) || !DB::fieldExist($module, 'price')) {
            return false;
        }

        if ($module === 'product') {
            return true;
        }

        // 系统 / 台账表优先拒绝（vip / money / aigc / geo 等虽然可能出现在 link_order_item 中，
        // 但其表本身是流水台账，不可作为商品购买）
        if (in_array($module, self::$denied, true)) {
            return false;
        }

        $base = $module;
        if (substr($module, -8) === '_package') {
            $base = substr($module, 0, -8);
        }

        // link_order_item 声明的套餐表（vip_package / money_package / aigc_package / geo_package）
        if ($base !== $module && in_array($base, (array) Config::get('module.link_order_item'), true)) {
            return true;
        }

        // link_order_item 声明的自定义可售模块（如 authorize 等云模块，表名即模块键）
        if (in_array($module, (array) Config::get('module.link_order_item'), true)) {
            return true;
        }

        if (in_array($module, self::$allowed, true)) {
            return true;
        }

        // 其余仅允许模块账本中登记为栏目 / 单表的内容模块（仍需存在 price 列）
        $registry = new ModuleRegistry();

        return $registry->isColumn($module) || $registry->isSingle($module);
    }
}
