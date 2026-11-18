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

namespace Dou\Admin\Model\User;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员等级表（user_level）。
 */
class UserLevel extends Model
{
    protected $table = 'user_level';
    protected $primary = 'id';

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * 说明：
     * - 此处仅声明 user_level 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 LevelFormRequest::rules()。
     * - 仅用于请求层的控制参数（如 confirm）不应放在此处。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'upgrade_type',
        'upgrade_condition',
        'good_discount',
        'icon',
        'created_at',
    );

    /**
     * 按 level_id 升序的等级全量列表。
     *
     * @return array
     */
    public static function selectAllOrdered()
    {
        return static::order('id ASC')->get();
    }

    /**
     * 名称查询。
     *
     * @param int|string $levelId
     * @return mixed
     */
    public static function getNameById($levelId)
    {
        return DB::table(static::tableName())->where('id', $levelId)->value('name');
    }

    /**
     * 按等级名称取 level_id。
     *
     * @param string $name
     * @return int
     */
    public static function getLevelIdByName($name)
    {
        $v = DB::table(static::tableName())->where('name', $name)->value('id');
        return $v ? (int) $v : 0;
    }
}
