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
 * comment 接口端声明式路由
 *
 * 声明 /api/?route=comment[/...] 形态下的评论 API 映射：
 *   - 主控制器 CommentController：index（评论入口）、list（模块内容公开评论列表，方法名 listing）
 *   - 子控制器 UserController（会员侧，sub='user'）：index / add / store(POST comment/user) / filebox / filedel
 */

use Dou\Api\Controller\Comment\CommentController;
use Dou\Api\Controller\Comment\UserController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::get('comment', CommentController::class, 'index', 'comment');
    Route::get('comment/list', CommentController::class, 'list', 'comment');

    Route::group('comment', UserController::class)
        ->prefix('user/comment')
        ->sub('user')
        ->get(['index', 'add'])
        ->post(['filebox', 'filedel']);

    Route::resource('comment', UserController::class)
        ->prefix('user/comment')
        ->sub('user')
        ->only(['store']);
});
