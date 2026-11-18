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
 * coupon 后台声明式路由
 *
 * 声明 ?route=coupon[/<sub>]/<action> 形态：优惠券主控制器 + CouponLog 领取/使用日志子控制器。
 */

use Dou\Admin\Controller\Coupon\CouponController;
use Dou\Admin\Controller\Coupon\CouponLogController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::get('coupon/products', CouponController::class, 'products', 'coupon', 'coupon.products');

    Route::resource('coupon', CouponController::class)
        ->post(['action']);

    Route::group('coupon', CouponLogController::class)
        ->prefix('coupon/log')
        ->sub('log')
        ->get(['index'])
        ->post(['action'])
        ->delete(['destroy']);
});
