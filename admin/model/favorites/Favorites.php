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

namespace Dou\Admin\Model\Favorites;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 收藏表（favorites）
 */
class Favorites extends Model
{
    protected $table = 'favorites';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'item_id' => 'int',
        'created_at' => 'datetime:Y-m-d',
    );

    /**
     * 按业务字段 LIKE 关键字筛选（keyField + 解析后的比较值）。
     *
     * $keywordRaw 用于判定调用方是否提交了关键字；$resolvedKw 是经业务解析后的实际比较值。
     * 两者同时非空时方才生效，否则透传。
     *
     * @param Builder $query
     * @param mixed $keyField item_id | user_id
     * @param mixed $keywordRaw 未解析关键字
     * @param mixed $resolvedKw 解析后的比较值
     * @return Builder
     */
    public function scopeFilterByKeyField(Builder $query, $keyField, $keywordRaw, $resolvedKw)
    {
        $keyField = (string) $keyField;
        $keywordRaw = (string) $keywordRaw;
        if ($keywordRaw === '' || $keyField === '') {
            return $query;
        }

        return $query->where($keyField, 'LIKE', '%' . $resolvedKw . '%');
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
