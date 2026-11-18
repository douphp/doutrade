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
 * comment 后台声明式路由
 *
 * 声明 ?route=comment[/<action>] 形态：评论审核（列表 / 标记已读 / 回复 / 显隐 / 清空 / 推荐位）。
 */

use Dou\Admin\Controller\Comment\CommentController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('comment', CommentController::class)->name('admin.')
    ->prefix('comment')
    ->get(['index', 'read', 'set'])
    ->post(['reply', 'show_hidden', 'del_all']);
