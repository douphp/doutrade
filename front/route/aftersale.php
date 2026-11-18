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

/**
 * aftersale 会员中心声明式路由
 *
 * 声明 /user/aftersale[/<动作>] 形态下的会员售后动作映射：
 * index（我的售后，GET）/ create（申请表单，GET）/ store（提交申请，POST）/
 * show（售后详情，GET prefix/{id}）/ return_ship（回寄信息，POST extra）/ cancel（撤销申请，POST extra）。
 * 售后无 edit/update/destroy 成员级写动作（撤销/回寄为领域命令，作 extra 保留 prefix/<action>）。
 *
 * show 必须经 only() 显式开启（Route::resource 默认动作集不含 show）；漏开会让
 * route('aftersale.user.show') 落到非声明式兜底，列表详情链接指向不存在的 URL。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Aftersale\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('aftersale', UserController::class)
    ->prefix('user/aftersale')
    ->sub('user')
    ->key('id')
    ->only(['index', 'create', 'store', 'show'])
    ->post(['return_ship', 'cancel']);
