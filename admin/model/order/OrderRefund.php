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

namespace Dou\Admin\Model\Order;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单退款单（order_refund）后台模型。
 *
 * 每笔退款一行，按 order_payment_id 指向原始支付（混合支付下网关/余额分别退款各成一行）。
 */
class OrderRefund extends Model
{
    protected $table = 'order_refund';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'order_id' => 'int',
        'aftersale_id' => 'int',
        'order_payment_id' => 'int',
        'refunded_at' => 'datetime:Y-m-d H:i:s',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 按订单筛选；$orderId <= 0 透传。
     *
     * @param Builder $query
     * @param mixed $orderId
     * @return Builder
     */
    public function scopeFilterByOrderId(Builder $query, $orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId <= 0) {
            return $query;
        }

        return $query->where('order_id', $orderId);
    }

    /**
     * 按退款状态筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $status)
    {
        $status = (string) $status;
        if ($status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }
}
