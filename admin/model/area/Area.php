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

namespace Dou\Admin\Model\Area;

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台地区数据访问
 */
class Area extends Model
{
    use PurgesRelatedOnDelete;

    protected $table = 'area';

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * @var array
     */
    protected $fillable = array(
        'parent_id',
        'lang',
        'name',
        'sort',
    );

    /**
     * 是否存在子地区
     *
     * @param int $id
     * @return bool
     */
    public static function hasChildArea($id)
    {
        return (bool) DB::table('area')->where('parent_id', (int) $id)->value('id');
    }
}
