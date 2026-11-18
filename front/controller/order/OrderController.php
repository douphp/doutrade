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

namespace Dou\Front\Controller\Order;

use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单模块默认入口控制器（route=order）。
 *
 * 业务动作按域拆分到 CartController（购物车）/ CheckoutController（结算）/
 * CashierController（收银台）/ UserController（会员中心我的订单）；本控制器只把
 * 裸 order 入口薄派发到购物车页。
 */
class OrderController extends BaseController
{
    /**
     * 默认入口（route=order）→ 购物车
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return redirect(route('order.cart'));
    }
}
