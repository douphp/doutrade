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

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台订单 AR 模型。
 *
 * 列表读取统一走 AR：casts 接管 created_at/paid_at/shipped_at 与 int 字段；
 * 业务字段（金额格式化、item_list、coupon、支付凭证等）保留在 service 端按行 enrich。
 */
class Order extends Model
{
    protected $table = 'order';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'paid_at' => 'datetime:Y-m-d H:i',
        'shipped_at' => 'datetime:Y-m-d H:i',
    );

    /**
     * 按下单会员筛选；$userId <= 0 时透传。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * 按订单状态筛选。
     *
     * `'all'` / 空字符串透传；
     * `PENDING` / `AWAITING_CONFIRMATION` 二选一时合并为 OR 分组（待付款标签囊括等待确认）；
     * 其他状态值精确匹配。
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $status)
    {
        if ($status === '' || $status === null || $status === 'all') {
            return $query;
        }

        if ($status === OrderStatus::PENDING || $status === OrderStatus::AWAITING_CONFIRMATION) {
            return $query->where(function ($query) {
                $query->where('status', OrderStatus::PENDING)
                    ->whereOr('status', OrderStatus::AWAITING_CONFIRMATION);
            });
        }

        return $query->where('status', $status);
    }

    /**
     * 默认列表排序：order_id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }
}
