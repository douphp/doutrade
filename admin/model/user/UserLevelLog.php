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

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员等级变动日志（user_level_log）。
 */
class UserLevelLog extends Model
{
    protected $table = 'user_level_log';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'old_level_id' => 'int',
        'new_level_id' => 'int',
        'condition_value' => 'float',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 按会员筛选；0 透传，非零精确匹配。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        $userId = (int) $userId;
        if ($userId === 0) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * 按升级方式筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $upgradeType
     * @return Builder
     */
    public function scopeFilterByUpgradeType(Builder $query, $upgradeType)
    {
        $upgradeType = (string) $upgradeType;
        if ($upgradeType === '') {
            return $query;
        }

        return $query->where('upgrade_type', $upgradeType);
    }
}
