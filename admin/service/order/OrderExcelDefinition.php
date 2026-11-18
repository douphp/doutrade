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

namespace Dou\Admin\Service\Order;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单导出列声明。
 *
 * 一行一订单：商品明细拼成单列，另出总件数一列（与订货单导出惯例一致）。
 * 金额一律输出原始数值，不附加货币符号，便于二次对账计算。
 */
class OrderExcelDefinition
{
    /**
     * 导出列：键 => 语言文案键（顺序即 Excel 列顺序）。
     *
     * @return array
     */
    public static function labels()
    {
        return array(
            'order_sn' => 'order_order_sn',
            'user_email' => 'order_email',
            'contact_name' => 'order_contact',
            'phone' => 'order_phone',
            'region' => 'order_region',
            'address' => 'order_address',
            'item_list' => 'order_item',
            'item_number' => 'order_item_number',
            'item_amount' => 'order_item_amount',
            'shipping_fee' => 'order_shipping_fee',
            'order_amount' => 'order_order_amount',
            'order_point' => 'order_point',
            'currency_code' => 'order_currency',
            'currency_amount' => 'order_currency_amount',
            'pay_name' => 'order_payment',
            'status_format' => 'order_status',
            'tracking_no' => 'order_tracking_no',
            'created_at' => 'order_created_at',
            'paid_at' => 'order_paid_at',
        );
    }

    /**
     * 表头行。
     *
     * @return array
     */
    public static function head()
    {
        $head = array();
        foreach (self::labels() as $langKey) {
            $head[] = lang($langKey);
        }

        return $head;
    }

    /**
     * 单行数据。
     *
     * @param array $order 已由 OrderService 补齐 region / item_list / pay_name 等派生字段
     * @return array
     */
    public static function row(array $order)
    {
        $line = array();
        foreach (array_keys(self::labels()) as $field) {
            $line[] = isset($order[$field]) ? $order[$field] : '';
        }

        return $line;
    }
}
