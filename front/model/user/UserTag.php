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

namespace Dou\Front\Model\User;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员标签（user_tag）：一会员多标签，供运营分群。
 */
class UserTag extends Model
{
    protected $table = 'user_tag';

    /**
     * 按会员过滤。
     *
     * @param Builder $query
     * @param int $userId
     * @return Builder
     */
    public function scopeFilterByUser(Builder $query, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return $query;
        }
        return $query->where('user_id', $userId);
    }
}
