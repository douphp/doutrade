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
 * user 接口端声明式路由
 *
 * 声明 /api/?route=user[/...] 形态下的会员 API 映射：
 *   - 主控制器 UserController：覆盖会员中心 / 登录注册 / 找回密码 / 资料维护 / SNS / 地区 / 文件等动作
 *   - 子控制器 WeixinController（sub='weixin'）：get_phone / login（无 index）
 *   - 子控制器 WorkController（sub='work'）：index
 *   - 子控制器 ContactController（sub='contact'）：index / create / store / edit / update / delete / set / list_json / info
 */

use Dou\Api\Controller\User\ContactController;
use Dou\Api\Controller\User\UserController;
use Dou\Api\Controller\User\WeixinController;
use Dou\Api\Controller\User\WorkController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::group('user', UserController::class)
        ->prefix('user')
        ->get([
            'index',
            'register', 'password_reset',
            'edit', 'password',
        ])
        ->post([
            'register_post', 'login_post', 'login_phone_post', 'password_reset_post',
            'edit_post', 'password_post',
            'logout',
            'sns', 'sns_link',
            'area',
            'filebox', 'filedel',
            'check_login_state',
            'upload_avatar',
        ]);

    // weixin 子控制器为单条 flat 路由，name 段（user.weixin.*）与 module.action 推导不一致，
    // 故保留显式 name（在 api. 组前缀下幂等）；module 归一为父 user + sub('weixin')。
    Route::get('user/weixin/get_phone', WeixinController::class, 'get_phone', 'user', 'api.user.weixin.get_phone', array(), 'weixin');
    Route::get('user/weixin/login', WeixinController::class, 'login', 'user', 'api.user.weixin.login', array(), 'weixin');

    Route::group('user', WorkController::class)
        ->prefix('user/work')
        ->sub('work')
        ->get(['index']);

    Route::group('user', ContactController::class)
        ->prefix('user/contact')
        ->sub('contact')
        ->get(['index', 'create', 'edit'])
        ->post(['store', 'update', 'destroy', 'set', 'info', 'list_json']);
});
