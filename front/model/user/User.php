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
 * 前台会员数据模型（user 表）。
 */
class User extends Model
{
    protected $table = 'user';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'status' => 'string',
        'login_count' => 'int',
        'created_at' => 'datetime:Y-m-d',
    );

    /** @var array */
    protected $fillable = array(
        'user_sn',
        'level_id',
        'direct_user_id',
        'indirect_user_id',
        'mobile',
        'email',
        'password',
        'token',
        'token_expires_at',
        'reset_token',
        'reset_token_expires_at',
        'sex',
        'nickname',
        'avatar',
        'pay_code',
        'pay_code_updated_at',
        'login_count',
        'login_fail_count',
        'login_locked_at',
        'status',
        'created_at',
    );
}
