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
 * favorites 会员中心声明式路由
 *
 * 声明 /user/favorites[/<动作>] 形态下的会员收藏动作映射：index（我的收藏）/
 * store（加入收藏，AJAX）/ delete（取消收藏，AJAX）。
 *
 * store / delete 的出站 URL 经 core/utility/Fix.php 统一注入给前端 JS。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Favorites\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('favorites', UserController::class)
    ->prefix('user/favorites')
    ->sub('user')
    ->only(['index', 'store', 'destroy']);
