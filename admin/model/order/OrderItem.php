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

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单条目（order_item）后台模型。
 *
 * 复杂视图装配（评论 url、模块图片、积分展示）仍由 OrderItemQuery / OrderItemDecorator 承担；
 * 本模型仅作为 Order::items() HasMany 的目标 + 必要 casts。
 */
class OrderItem extends Model
{
    protected $table = 'order_item';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'order_id' => 'int',
        'user_id' => 'int',
        'item_id' => 'int',
        'item_number' => 'int',
        'category_id' => 'int',
    );
}
