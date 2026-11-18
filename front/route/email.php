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
 * email 邮件订阅声明式路由
 *
 * 公开入口：
 *   - /email（EmailController::index 兜底回首页）（Route::simple 展开）
 *   - POST /email（EmailController::store 订阅提交 AJAX JSON，theme/default/js/dou.js 中 POST 端点）
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Email\EmailController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::post('email', EmailController::class, 'store', 'email', 'email.store');
Route::simple('email', EmailController::class);
