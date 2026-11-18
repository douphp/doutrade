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

namespace Dou\Admin\Model\Brand;

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台品牌数据模型
 */
class Brand extends Model
{
    use PurgesRelatedOnDelete;

    protected $table = 'brand';

    /** @var array 列表读取时格式化（image→附件URL、created_at→Y-m-d） */
    protected $casts = array(
        'id' => 'int',
        'sort' => 'int',
        'image' => 'attachment',
        'created_at' => 'datetime:Y-m-d',
    );

    /** @var array 列表批量预热附件 URL */
    protected $prefetchers = array(
        'attachment' => 'image',
    );

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'class',
        'content',
        'image',
        'keywords',
        'description',
        'sort',
        'created_at',
    );

    /**
     * 按名称关键字模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $keyword
     * @return Builder
     */
    public function scopeFilterByKeyword(Builder $query, $keyword)
    {
        $keyword = (string) $keyword;
        if ($keyword === '') {
            return $query;
        }

        return $query->where('name', 'LIKE', '%' . $keyword . '%');
    }

    /**
     * 默认列表排序：启用手动排序时 sort ASC, id DESC，否则纯 id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        $sort = !empty(Config::get('features.sort', false)) ? 'sort ASC, id DESC' : 'id DESC';

        return $query->order($sort);
    }
}
