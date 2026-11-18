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

namespace Dou\Api\Controller\Order;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序订单控制器（主控制器，仅承载购物车首页 order 一级动作）
 *
 * 购物车增删改、结算、收银台、我的订单已分别拆到
 * {@see CartController} / {@see CheckoutController} / {@see CashierController} / {@see UserController}。
 */
class OrderController extends BaseController
{
    /** @var OrderCore */
    private $order;

    /**
     * @param OrderCore $orderCore
     */
    public function __construct(OrderCore $orderCore)
    {
        $this->order = $orderCore;
    }

    /**
     * 购物车（route=order）
     *
     * @return Response
     */
    public function index()
    {
        $userId = $this->mustLoginUserId();

        return ApiResponse::success(array(
            'title' => lang('order_cart'),
            'cart' => $this->order->getCart($userId),
            'cart_number' => $this->order->countCartNumber($userId),
        ));
    }

    /**
     * 登录校验并返回 user_id。
     *
     * features.user 关闭场景已由 Router 的 Module::assertUserAvailable() 闸住，
     * 本方法仅判定登录态。
     *
     * @return int
     */
    private function mustLoginUserId()
    {
        $userId = auth('api') ? (int) auth('api')->id() : 0;
        if ($userId > 0) {
            return $userId;
        }
        ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
    }
}
