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

namespace Dou\Core\Domain\Order;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单模块能力策略。
 *
 * 充值套餐是现金等价物：到账金额可以高于售价，因此必须同时禁止
 * 余额购买、优惠券、消费积分、分销佣金与退款，否则会形成套利闭环。
 */
class OrderPolicy
{
    /**
     * 现金等价物模块（订单 module 可能存 money_package 或历史 money）。
     *
     * @param mixed $module
     * @return bool
     */
    public static function isCashEquivalent($module)
    {
        $module = (string) $module;

        return in_array($module, array('money_package', 'money'), true);
    }

    /**
     * @param mixed $module
     * @return bool
     */
    public static function canUseWallet($module)
    {
        // 储值余额仅供线下消费，购物订单不再支持余额支付。
        return false;
    }

    public static function canUseCod($module)
    {
        return (string) $module === 'product';
    }

    /**
     * @param mixed $module
     * @return bool
     */
    public static function canUseCoupon($module)
    {
        return !self::isCashEquivalent($module);
    }

    /**
     * @param mixed $module
     * @return bool
     */
    public static function earnsShoppingPoint($module)
    {
        return !self::isCashEquivalent($module);
    }

    /**
     * @param mixed $module
     * @return bool
     */
    public static function earnsDistribution($module)
    {
        return !self::isCashEquivalent($module);
    }

    /**
     * @param mixed $module
     * @return bool
     */
    public static function isRefundable($module)
    {
        return !self::isCashEquivalent($module);
    }

    /**
     * 套餐类商品按一份开通/入账，数量必须归一为 1。
     *
     * @param mixed $module
     * @return bool
     */
    public static function isSinglePurchase($module)
    {
        $module = (string) $module;

        return substr($module, -8) === '_package';
    }
}
