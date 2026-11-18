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

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员联系人（user_contact）：后台预约联系人与会员联系人共用同一张表。
 */
class UserContact extends Model
{
    protected $table = 'user_contact';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'is_default' => 'int',
        'sort' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );

    /** @var array */
    protected $fillable = array(
        'user_id',
        'name',
        'phone',
        'id_card',
        'province',
        'city',
        'district',
        'address',
        'tag',
        'is_default',
        'sort',
        'created_at',
    );
}
