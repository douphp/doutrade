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
 * point 接口端声明式路由
 *
 * 声明 /api/?route=point[/...] 形态下的积分 API 映射：
 *   - 主控制器 PointController：index
 *   - 子控制器 UserController（会员侧，sub='user'）：index
 */

use Dou\Api\Controller\Point\PointController;
use Dou\Api\Controller\Point\UserController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::get('point', PointController::class, 'index', 'point');

    Route::group('point', UserController::class)
        ->prefix('user/point')
        ->sub('user')
        ->get(['index']);
});
