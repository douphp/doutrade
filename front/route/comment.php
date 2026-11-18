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
 * comment 模块声明式路由
 *
 * 公开评论入口：
 *   - /comment（CommentController::index 仅作登录态分流到会员中心）（Route::simple 展开）
 *
 * 会员中心：
 *   - 子控制器 UserController（sub='user'）：/user/comment（我的评论，GET）/
 *     /user/comment/create（发表评论表单，GET）/ /user/comment（提交评论，POST）
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Comment\CommentController;
use Dou\Front\Controller\Comment\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::simple('comment', CommentController::class);

Route::resource('comment', UserController::class)
    ->prefix('user/comment')
    ->sub('user')
    ->only(['index', 'create', 'store']);
