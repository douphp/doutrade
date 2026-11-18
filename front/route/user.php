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
 * user 会员模块声明式路由
 *
 * 子控制器 AuthController（sub='auth'，公开登录入口，声明优先）：
 *   /user/login           → login()              账号密码登录表单
 *   /user/register        → register()           注册表单
 *   /user/login_phone     → loginPhone()         手机验证码登录表单
 *   /user/password_reset  → passwordReset()      找回密码流程
 *   /user/logout          → logout()             退出登录
 *
 * 主控制器 UserController（会员中心首页与文件 / 地区数据 AJAX）：
 *   /user                → UserController::index 会员中心首页
 *   /user/area           → UserController::area  省市区 JSON
 *   /user/filebox        → UserController::filebox 文件上传
 *   /user/filedel        → UserController::filedel 文件删除
 *
 * 其它子控制器（sub）：
 *   - ProfileController（sub='profile'）：edit / password / sns 等会员资料维护
 *   - VerificationController（sub='verify'）：verification / mobile / email 等核验
 *   - ContactController（sub='contact'）：地址簿 CRUD + JSON 列表
 *
 * 公开匿名访问（user/login / user/register / user/password_reset 等）的 auth 模式由
 * front/init/middleware.php 中 auth_modes 显式登记为 public；其余 user/* 默认 required。
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\User\AuthController;
use Dou\Front\Controller\User\ContactController;
use Dou\Front\Controller\User\ProfileController;
use Dou\Front\Controller\User\UserController;
use Dou\Front\Controller\User\VerificationController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('user', AuthController::class)
    ->prefix('user')
    ->sub('auth')
    ->name('user')
    ->get(['register', 'login', 'login_phone', 'password_reset', 'logout'])
    ->post(['register_post', 'login_post', 'login_phone_post', 'password_reset_post']);

Route::get('user', UserController::class, 'index', 'user');
Route::post('user/area', UserController::class, 'area', 'user');
Route::post('user/filebox', UserController::class, 'filebox', 'user');
Route::post('user/filedel', UserController::class, 'filedel', 'user');

Route::group('user', ProfileController::class)
    ->prefix('user/profile')
    ->sub('profile')
    ->get(['edit', 'password', 'sns', 'sns_link'])
    ->post(['edit_post', 'password_post']);

Route::group('user', VerificationController::class)
    ->prefix('user/verify')
    ->sub('verify')
    ->get(['verification', 'mobile', 'email'])
    ->post(['verification_post', 'mobile_post', 'email_post']);

Route::resource('user', ContactController::class)
    ->prefix('user/contact')
    ->sub('contact')
    ->get(['set'])
    ->post(['list_json', 'info']);
