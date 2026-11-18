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

namespace Dou\Admin\Model\Aftersale;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后表（aftersale）后台模型
 */
class Aftersale extends Model
{
    protected $table = 'aftersale';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'order_id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 按售后单号模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $aftersaleSn
     * @return Builder
     */
    public function scopeFilterByAftersaleSn(Builder $query, $aftersaleSn)
    {
        $aftersaleSn = (string) $aftersaleSn;
        if ($aftersaleSn === '') {
            return $query;
        }

        return $query->where('aftersale_sn', 'LIKE', '%' . $aftersaleSn . '%');
    }

    /**
     * 按申请时间下限筛选（created_at > strtotime($timeStart)）；空字符串透传。
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
     * 按申请时间上限筛选（created_at < strtotime($timeEnd)）；空字符串透传。
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
     * 默认列表排序：aftersale_id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }
}
