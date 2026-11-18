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
 * order 模块声明式路由
 *
 * 子控制器（公开）：
 *   - CartController（sub='cart'）：/order/cart（购物车）/ /order/cart/store / update / destroy / clear_invalid
 *   - CheckoutController（sub='checkout'）：/order/checkout（结算）/ /order/checkout/success /
 *     /order/checkout/preview / /order/checkout/contact_list / /order/checkout/contact_select
 *   - CashierController（sub='cashier'）：/order/cashier（收银台）/ /order/cashier/charge /
 *     /order/cashier/pay_evidence / /order/cashier/cod
 *
 * 默认入口：
 *   - /order（OrderController::index 重定向到 /order/cart）（Route::simple 展开）
 *
 * 会员中心：
 *   - 子控制器 UserController（sub='user'）：/user/order（我的订单）/ /user/order/{order_sn} / /user/order/cancel
 */

use Dou\Core\Web\Routing\Route;
use Dou\Front\Controller\Order\CartController;
use Dou\Front\Controller\Order\CashierController;
use Dou\Front\Controller\Order\CheckoutController;
use Dou\Front\Controller\Order\OrderController;
use Dou\Front\Controller\Order\UserController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::group('order', CartController::class)
    ->prefix('order/cart')
    ->sub('cart')
    ->get(['index', 'destroy', 'clear_invalid'])
    ->post(['store', 'update']);

Route::group('order', CheckoutController::class)
    ->prefix('order/checkout')
    ->sub('checkout')
    ->get(['index'])
    ->post(['success', 'preview', 'contact_list', 'contact_select']);

Route::group('order', CashierController::class)
    ->prefix('order/cashier')
    ->sub('cashier')
    ->get(['index', 'wxpay_oauth'])
    ->post(['charge', 'pay_evidence', 'cod']);

Route::simple('order', OrderController::class);

// cancel 为字面量动作，与 resource show 的 {order_sn} 由 specificity 区分（字面量优先）。
Route::group('order', UserController::class)
    ->prefix('user/order')
    ->sub('user')
    ->get(['index', 'cancel']);

Route::resource('order_user', UserController::class)
    ->prefix('user/order')
    ->sub('user')
    ->name('order.user')
    ->key('order_sn')
    ->only(['show']);
