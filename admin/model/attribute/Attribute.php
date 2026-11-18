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

namespace Dou\Admin\Model\Attribute;

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Core\Support\CategoryIds;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台款式属性数据访问
 */
class Attribute extends Model
{
    use PurgesRelatedOnDelete;

    /** @var string */
    protected $table = 'attribute';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $fillable = array(
        'category_id',
        'name',
        'type',
        'sort',
    );

    /**
     * 按商品分类筛选（含子分类，且并入 category_id=0 的全局属性）；$catId <= 0 时透传。
     *
     * @param Builder $query
     * @param mixed $catId
     * @return Builder
     */
    public function scopeFilterByProductCategory(Builder $query, $catId)
    {
        $catId = (int) $catId;
        if ($catId <= 0) {
            return $query;
        }

        $categoryIdList = array_merge(
            array(0),
            CategoryIds::subtree('product_category', $catId)
        );

        return $query->where('category_id', 'IN', $categoryIdList);
    }

    /**
     * 默认列表排序：sort ASC, att_id ASC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id ASC');
    }

    /**
     * @param int $attId
     * @return bool
     */
    public static function hasValueRecords($attId)
    {
        return (bool) DB::table('attribute_value')->where('att_id', (int) $attId)->value('id');
    }
}
