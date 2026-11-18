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
 * brand 后台声明式路由
 *
 * 声明 ?route=brand[/<action>] 形态：单表内容模块，标准 CRUD + 批量动作 action。
 */

use Dou\Admin\Controller\Brand\BrandController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::resource('brand', BrandController::class)->name('admin.')
    ->post(['action']);
