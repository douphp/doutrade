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
 * aftersale 接口端声明式路由
 *
 * 声明 /api/?route=aftersale[/...] 形态下的售后 API 映射：
 *   - 主控制器 AftersaleController：index
 *   - 子控制器 UserController（会员侧，sub='user'）：index / apply / apply_post
 *   - 子控制器 WorkController（核销端，sub='work'）：index / show(GET aftersale/work/{id}) / handle
 */

use Dou\Api\Controller\Aftersale\AftersaleController;
use Dou\Api\Controller\Aftersale\UserController;
use Dou\Api\Controller\Aftersale\WorkController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::get('aftersale', AftersaleController::class, 'index', 'aftersale');

    Route::group('aftersale', UserController::class)
        ->prefix('user/aftersale')
        ->sub('user')
        ->get(['index', 'apply'])
        ->post(['apply_post']);

    Route::group('aftersale', WorkController::class)
        ->prefix('aftersale/work')
        ->sub('work')
        ->get(['index'])
        ->post(['handle']);

    Route::resource('aftersale', WorkController::class)
        ->prefix('aftersale/work')
        ->sub('work')
        ->key('id')
        ->only(['show']);
});
