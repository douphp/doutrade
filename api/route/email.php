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
 * email 接口端声明式路由
 *
 * 声明 /api/?route=email[/...] 的邮件订阅 API：
 *   - EmailController：index(GET email) / store(POST email)
 */

use Dou\Api\Controller\Email\EmailController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::get('email', EmailController::class, 'index', 'email');
    Route::post('email', EmailController::class, 'store', 'email');
});
