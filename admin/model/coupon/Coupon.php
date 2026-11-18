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

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 优惠券表（coupon）
 */
class Coupon extends Model
{
    use PurgesRelatedOnDelete;

    protected $table = 'coupon';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'sort' => 'int',
        'face_value' => 'float',
        'max_discount' => 'float',
        'condition' => 'float',
        'limit_per_user' => 'int',
        'total_quantity' => 'int',
        'issued_quantity' => 'int',
        'is_first_order_only' => 'int',
        'created_at' => 'datetime:Y-m-d',
        'start_at' => 'datetime:Y-m-d',
        'end_at' => 'datetime:Y-m-d',
        'status' => 'data_lang:coupon_status_',
    );

    protected $fillable = array(
        'name',
        'coupon_sn',
        'type',
        'face_value',
        'max_discount',
        'limit_per_user',
        'total_quantity',
        'issued_quantity',
        'is_first_order_only',
        'condition',
        'scope_type',
        'scope_value',
        'start_at',
        'end_at',
        'brief',
        'image',
        'status',
        'sort',
        'created_at',
    );

    /**
     * 默认列表排序：sort ASC, id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }

    /**
     * @param int|string $id
     * @return mixed
     */
    public static function getNameById($id)
    {
        return DB::table(static::tableName())->where('id', (int) $id)->value('name');
    }
}
