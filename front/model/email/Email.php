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

namespace Dou\Front\Model\Email;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台邮件订阅表模型
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
     * @param string $email
     * @return bool
     */
    public static function existsByEmail($email)
    {
        return (bool) DB::table(static::tableName())->where('email', $email)->exists();
    }

    /**
     * @param string $ip
     * @return int
     */
    public static function countUnreadByIp($ip)
    {
        return (int) DB::table(static::tableName())->where('ip', $ip)->where('status', 0)->count();
    }

    /**
     * @param array $row
     * @return void
     */
    public static function insertRow($row)
    {
        DB::table(static::tableName())->data($row)->insert();
    }
}
