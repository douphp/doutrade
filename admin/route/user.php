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
 * user 后台声明式路由
 *
 * 声明 ?route=user[/<sub>]/<action> 形态：会员主控制器 + 联系字段 / 等级 / 操作日志三个子控制器。
 */

use Dou\Admin\Controller\User\ContactController;
use Dou\Admin\Controller\User\LevelController;
use Dou\Admin\Controller\User\LevelLogController;
use Dou\Admin\Controller\User\LogController;
use Dou\Admin\Controller\User\UserController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::resource('user', UserController::class)
        ->get(['set'])
        ->post(['action', 'set_status']);

    Route::resource('user', ContactController::class)
        ->prefix('user/contact')
        ->sub('contact')
        ->except(['create', 'store']);

    Route::resource('user', LevelController::class)
        ->prefix('user/level')
        ->sub('level');

    Route::group('user', LevelLogController::class)
        ->prefix('user/level_log')
        ->sub('level_log')
        ->get(['index']);

    Route::group('user', LogController::class)
        ->prefix('user/log')
        ->sub('log')
        ->get(['index']);
});
