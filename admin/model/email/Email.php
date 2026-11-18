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

namespace Dou\Admin\Model\Email;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订阅邮箱数据模型
 */
class Email extends Model
{
    protected $table = 'email';

    /**
     * @var array
     */
    protected $fillable = array(
        'user_id',
        'email',
        'ip',
        'status',
        'created_at',
    );

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
     * @param int $id
     * @return bool
     */
    public static function markAsRead($id)
    {
        return (bool) static::where('id', $id)
            ->update(array('status' => 1));
    }

    /**
     * @param array $ids
     * @return bool
     */
    public static function deleteByIds(array $ids)
    {
        return (bool) DB::table(static::tableName())
            ->where('id', 'IN', $ids)
            ->delete();
    }
}
