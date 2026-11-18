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

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员联系人（user_contact），供预约下单等前台场景查询
 */
class UserContact extends Model
{
    protected $table = 'user_contact';

    /**
     * @param int $id
     * @param int $userId
     * @return array|null
     */
    public static function findAndUserId($id, $userId)
    {
        return static::where('id', intval($id))
            ->where('user_id', intval($userId))
            ->first();
    }
}
