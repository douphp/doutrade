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
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Order\UserService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序「我的订单」子控制器（route=order/user/*）
 */
class UserController extends BaseController
{
    /** @var UserService */
    private $userService;

    /**
     * @param UserService $userService 我的订单视图业务
     */
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * 我的订单列表（route=order/user）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        if (!Module::has('order')) {
            return ApiResponse::success(array(
                'title' => lang('order_my'),
                'order_list' => array(),
                'status_list' => array(),
                'payment' => plugin()->defaultPaymentSlug(),
            ));
        }

        $page = $request->integer('page', 1);
        $status = (string) $request->input('status', 'all');
        if ($status !== 'all' && !OrderStatus::isValid($status)) {
            $status = 'all';
        }
        $result = $this->userService->buildOrderListData(
            auth('api')->id(),
            $page,
            '',
            $status
        );

        return ApiResponse::success(array(
            'title' => lang('order_my'),
            'order_list' => $result['list'],
            'status_list' => $result['status_list'],
            'payment' => plugin()->defaultPaymentSlug(),
        ));
    }

    /**
     * 订单详情（route=order/user/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        if (!Module::has('order')) {
            return ApiResponse::success(array(
                'title' => lang('order_show'),
                'order' => array(),
                'payment' => plugin()->defaultPaymentSlug(),
            ));
        }

        $orderSn = $request->digits('order_sn');
        $order = $this->userService->buildOrderShowData(auth('api')->id(), $orderSn);

        return ApiResponse::success(array(
            'title' => lang('order_show'),
            'order' => $order ? $order : array(),
            // 付款按钮显示
            'payment' => plugin()->defaultPaymentSlug($order ? currency()->contextForOrder($order)['code'] : ''),
        ));
    }

    /**
     * 取消订单（route=order/user/cancel）
     *
     * @param Request $request
     * @return Response
     */
    public function cancel(Request $request)
    {
        if (!Module::has('order')) {
            return ApiResponse::success(array('ok' => false));
        }

        $orderSn = $request->digits('order_sn');
        $result = $this->userService->cancelOrder(auth('api')->id(), $orderSn);
        if (!$result['ok']) {
            throw new DomainException(lang('order_cancel_wrong'));
        }

        return ApiResponse::success(array(
            'ok' => true,
            'refund_amount' => isset($result['refund_amount']) ? $result['refund_amount'] : 0.0,
            'refund_amount_format' => isset($result['refund_amount_format']) ? $result['refund_amount_format'] : '',
        ));
    }
}
