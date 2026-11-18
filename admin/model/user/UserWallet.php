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
 * 会员账户快照（user_wallet）：money/point 余额 + 累计统计聚合视图。
 *
 * 主键为 user_id（一会员一行）；流水仍以 dou_money / dou_user_point 为权威，
 * 本表是供后台会员列表快速读取的冷字段快照。
 */
class UserWallet extends Model
{
    protected $table = 'user_wallet';

    protected $primary = 'id';
}
