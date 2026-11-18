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
 * 后台会员操作日志表（user_log）AR 模型。
 *
 * 列表读取由 LogService 负责筛选与分页；本模型仅声明 casts 接管 created_at/result/id 等格式化，
 * 便于 service 端直接 toArray() 消费。
 */
class UserLog extends Model
{
    protected $table = 'user_log';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'result' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
    );
}
