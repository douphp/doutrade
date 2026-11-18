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
 * order 后台声明式路由
 *
 * 声明 ?route=order[/<sub>]/<action> 形态：订单主控制器（支付审核 / 物流 / 对账 / 推荐位）+
 * Report 报表子控制器（销售 / 商品 / 客户 / 支付 / 导出）。
 */

use Dou\Admin\Controller\Order\OrderController;
use Dou\Admin\Controller\Order\ReportController;
use Dou\Core\Web\Routing\Route;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

Route::name('admin.')->compositeModule()->group(function () {
    Route::group('order', OrderController::class)
        ->prefix('order')
        ->get(['index', 'show', 'set'])
        ->post(['pay_check', 'pay_reject', 'fail_refund', 'retry_paid', 'tracking', 'action', 'reconcile']);

    Route::group('order', ReportController::class)
        ->prefix('order/report')
        ->sub('report')
        ->get(['index', 'product', 'customer', 'payment', 'export']);
});
