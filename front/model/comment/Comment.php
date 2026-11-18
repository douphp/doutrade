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

namespace Dou\Front\Model\Comment;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台评论数据模型
 */
class Comment extends Model
{
    protected $table = 'comment';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'item_id' => 'int',
        'order_item_id' => 'int',
        'anonymous' => 'int',
        'star' => 'int',
        'if_show' => 'int',
        'if_read' => 'int',
        'created_at' => 'datetime:Y-m-d H:i',
    );

    /**
     * 按会员筛选；$userId <= 0 时透传。
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
     * 默认列表排序：id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * @param int $orderItemId
     * @return array|null
     */
    public static function findOrderItem($orderItemId)
    {
        return DB::table('order_item')->where('id', (int) $orderItemId)->find();
    }
}
