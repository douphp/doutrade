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

namespace Dou\Admin\Model\Plugin;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 插件表（plugin）
 */
class Plugin extends Model
{
    protected $table = 'plugin';
    protected $primary = 'slug';

    /**
     * 允许批量写入的字段（持久化层）。
     *
     * @var array
     */
    protected $fillable = array(
        'slug',
        'name',
        'config',
        'plugin_group',
        'allow_client',
        'description',
    );

    /**
     * @param string $uniqueId
     * @return array|null
     */
    public static function findByUniqueId($uniqueId)
    {
        return static::where('slug', $uniqueId)->first();
    }

    /**
     * @param string $uniqueId
     * @return mixed
     */
    public static function getNameByUniqueId($uniqueId)
    {
        return DB::table(static::tableName())->where('slug', $uniqueId)->value('name');
    }

    /**
     * @param string $uniqueId
     * @return mixed
     */
    public static function getEnabledUniqueId($uniqueId)
    {
        return DB::table(static::tableName())->where('slug', $uniqueId)->value('slug');
    }
}
