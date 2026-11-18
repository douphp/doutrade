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

namespace Dou\Front\Model\Brand;

use Dou\Core\Model\Concerns\HasDistinctValues;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Front\Model\Concerns\HasAddTimeAccessors;
use Dou\Front\Model\Concerns\HasContentListFields;
use Dou\Front\Model\Concerns\HasUrlAccessor;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台品牌数据模型（brand 表）。
 *
 * 列表读取走 AR：casts 格式化 image / created_at、prefetchers 预热 url / 多语言 / 附件，
 * translatable 覆写 name / class / content / keywords / description 当前语言值。
 *
 * brand 为可选模块；调用方按 `Config::get('features.brand', false)` 短路守门后，
 * 再走 canonical scope 链 `Brand::applyDefaultOrder()->get()` / `Brand::find($id)`，
 * features 关闭时 PHP 三元短路保证 Brand 静态调用不被 evaluate，避免类未安装时 autoload fatal。
 */
class Brand extends Model
{
    use HasAddTimeAccessors;
    use HasContentListFields;
    use HasDistinctValues;
    use HasUrlAccessor;

    protected $table = 'brand';

    /**
     * 模块 schema：单（无分类）模块，主题 {list} 可列表。
     *
     * @return array
     */
    public static function moduleSchema()
    {
        return array(
            'module' => 'brand',
            'kind' => 'single',
            'listable' => true,
        );
    }

    /** @var array 列表读取时格式化（image→附件URL、created_at→Y-m-d） */
    protected $casts = array(
        'id' => 'int',
        'sort' => 'int',
        'click' => 'int',
        'image' => 'attachment',
        'created_at' => 'datetime:Y-m-d',
    );

    /** @var array toArray 额外附加：列表 Presenter 派生字段 */
    protected $appends = array('add_time_short', 'time', 'name', 'description', 'url');

    /** @var array name/class/content/keywords/description 随当前语言覆写 */
    protected $translatable = array('name', 'class', 'content', 'keywords', 'description', 'image');

    /** @var array 列表批量预热：url 构建缓存、多语言、附件 */
    protected $prefetchers = array(
        'url' => true,
        'language' => 'name,class,content,keywords,description,image',
        'attachment' => 'image',
    );

    /**
     * 已发布品牌单行。
     *
     * @param int $brandId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findPublishedById($brandId)
    {
        return static::published()->find((int) $brandId);
    }

    /**
     * 仅已发布品牌（status = 1）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', 1);
    }

    /**
     * 按 class 字段筛选；$class 为空字符串时透传。
     *
     * @param Builder $query
     * @param string $class
     * @return Builder
     */
    public function scopeFilterByClass(Builder $query, $class)
    {
        $class = (string) $class;
        if ($class === '') {
            return $query;
        }

        return $query->where('class', $class);
    }

    /**
     * 默认排序：sort ASC, id DESC（与品牌下拉一致）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }
}
