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
 * order 接口端声明式路由
 *
 * 声明 /api/?route=order[/...] 形态下的订单 API 映射：
 *   - 主控制器 OrderController：index
 *   - 子控制器 UserController（会员侧，sub='user'）：index / show(order/user/{order_sn}) / cancel
 *   - 子控制器 CartController（购物车，sub='cart'）：cart_number(GET) / store(POST order/cart) / update(PUT order/cart/{id}) / destroy(DELETE order/cart/{id}) / clear_invalid
 *   - 子控制器 CheckoutController（结算，sub='checkout'）：index / checkout_post / success / preview / buyNow(POST order/buynow)
 *   - 子控制器 CashierController（收银台，sub='cashier'）：index / payment / offlinepay / pay_evidence / status(GET)
 *   - 子控制器 WorkController（核销端，sub='work'）：index / show(order/work/{order_sn}) / order_cancel / pay_check / tracking
 */

use Dou\Api\Controller\Order\CartController;
use Dou\Api\Controller\Order\CashierController;
use Dou\Api\Controller\Order\CheckoutController;
use Dou\Api\Controller\Order\OrderController;
use Dou\Api\Controller\Order\UserController;
use Dou\Api\Controller\Order\WorkController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('api.')->group(function () {
    Route::get('order', OrderController::class, 'index', 'order');

    Route::group('order', UserController::class)
        ->prefix('user/order')
        ->sub('user')
        ->get(['index'])
        ->post(['cancel']);

    Route::resource('order', UserController::class)
        ->prefix('user/order')
        ->sub('user')
        ->key('order_sn')
        ->only(['show']);

    Route::group('order', CartController::class)
        ->prefix('order/cart')
        ->sub('cart')
        ->root('cart_number')
        ->get(['cart_number']);

    Route::group('order', CartController::class)
        ->prefix('order/cart')
        ->sub('cart')
        ->post(['clear_invalid']);

    Route::resource('order', CartController::class)
        ->prefix('order/cart')
        ->sub('cart')
        ->only(['store', 'update', 'destroy']);

    Route::group('order', CheckoutController::class)
        ->prefix('order/checkout')
        ->sub('checkout')
        ->post(['index', 'checkout_post', 'success', 'preview']);

    // 立即购买 / 积分 / 套餐：签发报价单，不写入购物车
    Route::post('order/buynow', CheckoutController::class, 'buyNow', 'order', 'api.order.buynow', array(), 'checkout');

    Route::group('order', CashierController::class)
        ->prefix('order/cashier')
        ->sub('cashier')
        ->get(['status'])
        ->post(['index', 'payment', 'offlinepay', 'pay_evidence']);

    Route::group('order', WorkController::class)
        ->prefix('order/work')
        ->sub('work')
        ->get(['index'])
        ->post(['order_cancel', 'pay_check', 'tracking']);

    Route::resource('order', WorkController::class)
        ->prefix('order/work')
        ->sub('work')
        ->key('order_sn')
        ->only(['show']);
});
