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
 * coupon 模块声明式路由
 *
 * 优惠券入口（需登录）：
 *   - 券中心：/coupon（详情地址重定向回券中心）
 *   - 领取动作：/coupon/claim
 *
 * 会员中心：
 *   - 子控制器 UserController（sub='user'）：/user/coupon（我的优惠券）
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Coupon\CouponController;
use Dou\Front\Controller\Coupon\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::post('coupon/claim', CouponController::class, 'claim', 'coupon', 'coupon.claim');
Route::simple('coupon', CouponController::class);

Route::group('coupon', UserController::class)
    ->prefix('user/coupon')
    ->sub('user')
    ->get(['index']);
