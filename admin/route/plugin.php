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
 * plugin 后台声明式路由
 *
 * 声明 ?route=plugin[/<action>] 形态：后台插件管理 CRUD + 安装 install / 禁用 disable。
 * 名前缀使用 'admin.plugin' 以避免与前台 system_reserved 家族条目 'plugin' 重名。
 */

use Dou\Admin\Controller\Plugin\PluginController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('plugin', PluginController::class)
    ->name('admin.')
    ->key('slug', '[A-Za-z][A-Za-z0-9_\\-]{0,29}')
    ->get(['install'])
    ->post(['disable']);
