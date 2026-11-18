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

namespace Dou\Admin\Model\Coupon;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 优惠券领取/使用记录表（coupon_log）
 */
class CouponLog extends Model
{
    protected $table = 'coupon_log';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'coupon_id' => 'int',
        'status' => 'data_lang:coupon_log_status_',
        'claimed_at' => 'datetime:Y-m-d',
        'created_at' => 'datetime:Y-m-d',
    );

    /**
     * 按券筛选；空字符串/null 透传。
     *
     * @param Builder $query
     * @param mixed $couponId
     * @return Builder
     */
    public function scopeFilterByCouponId(Builder $query, $couponId)
    {
        if ($couponId === '' || $couponId === null) {
            return $query;
        }

        return $query->where('coupon_id', $couponId);
    }

    /**
     * 按 used_at 起始时间筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $timeStart
     * @return Builder
     */
    public function scopeFilterByUsedAfter(Builder $query, $timeStart)
    {
        $timeStart = (string) $timeStart;
        if ($timeStart === '') {
            return $query;
        }

        return $query->where('used_at', '>', strtotime($timeStart));
    }

    /**
     * 按 used_at 结束时间筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $timeEnd
     * @return Builder
     */
    public function scopeFilterByUsedBefore(Builder $query, $timeEnd)
    {
        $timeEnd = (string) $timeEnd;
        if ($timeEnd === '') {
            return $query;
        }

        return $query->where('used_at', '<', strtotime($timeEnd));
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
     * @param array $ids
     * @return mixed
     */
    public static function deleteWhereIdIn(array $ids)
    {
        if (empty($ids)) {
            return false;
        }
        return DB::table(static::tableName())->where('id', 'IN', $ids)->delete();
    }
}
