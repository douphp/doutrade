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

namespace Dou\Admin\Model\Point;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员积分流水表（point）
 */
class Point extends Model
{
    protected $table = 'point';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'point' => 'int',
        'total' => 'int',
        'created_at' => 'datetime:Y-m-d H:i',
    );

    /**
     * 按会员 user_id 筛选；0 透传。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        $userId = (int) $userId;
        if ($userId === 0) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * 按发生时间下限筛选（created_at > strtotime($timeStart)）；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeStart
     * @return Builder
     */
    public function scopeFilterByTimeStart(Builder $query, $timeStart)
    {
        $timeStart = (string) $timeStart;
        if ($timeStart === '') {
            return $query;
        }

        return $query->where('created_at', '>', date('Y-m-d H:i:s', strtotime($timeStart)));
    }

    /**
     * 按发生时间上限筛选（created_at < strtotime($timeEnd)）；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeEnd
     * @return Builder
     */
    public function scopeFilterByTimeEnd(Builder $query, $timeEnd)
    {
        $timeEnd = (string) $timeEnd;
        if ($timeEnd === '') {
            return $query;
        }

        return $query->where('created_at', '<', date('Y-m-d H:i:s', strtotime($timeEnd)));
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

    /**
     * 分享审核列表展示用：该用户该分享单最近一次 share 动作的积分
     *
     * @param int $userId
     * @param string $shareSn
     * @return mixed
     */
    public static function getPointValueForShare($userId, $shareSn)
    {
        return DB::table(static::tableName())
            ->where('user_id', (int) $userId)
            ->where('action', 'share')
            ->where('from', $shareSn)
            ->order('id DESC')
            ->value('point');
    }
}
