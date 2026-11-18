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

namespace Dou\Front\Model\Order;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台订单收货地址快照（order_address）模型。
 */
class OrderAddress extends Model
{
    protected $table = 'order_address';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'order_id' => 'int',
        'user_id' => 'int',
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
}
