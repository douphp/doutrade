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

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Order\UserService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 我的订单会员中心控制器（/user/order/*）
 */
class UserController extends BaseController
{
    /** @var UserService */
    private $userService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var OrderCore */
    private $order;

    /**
     * @param UserService $userService 会员视角订单视图业务
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param OrderCore $orderCore 购物车角标统计
     */
    public function __construct(
        UserService $userService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        OrderCore $orderCore
    ) {
        $this->userService = $userService;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->order = $orderCore;
    }

    /**
     * 我的订单列表（/user/order）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->route('page', 1);
        $status = (string) $request->input('status', 'all');
        if ($status !== 'all' && !OrderStatus::isValid($status)) {
            $status = 'all';
        }
        $data = $this->userService->buildOrderListData(
            auth('front')->id(),
            $page,
            route('order.user'),
            $status
        );

        $urHere = $this->breadcrumb->build('user', 'order_my');

        return $this->view('order_user.dwt', $this->userCenterLayout('order_list') + [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'order_my'),

            // 页面骨架
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'pager' => $data['pager'],
            'head' => lang('order_my'),
            'status' => $status,
            'status_list' => $data['status_list'],
            'order_list' => $data['list'],
        ]);
    }

    /**
     * 订单详情（/user/order/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $orderSn = $request->digits('order_sn');
        $order = $this->userService->buildOrderShowData(auth('front')->id(), $orderSn);
        if (!$order) {
            return redirect(route('order.user'));
        }

        $urHere = $this->breadcrumb->build('user', 'order_show');

        return $this->view('order_user.dwt', $this->userCenterLayout('order') + [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'order_show'),

            // 页面骨架
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('order_show'),
            'order' => $order,
        ]);
    }

    /**
     * 取消订单（/user/order/cancel）
     *
     * @param Request $request
     * @return Response
     */
    public function cancel(Request $request)
    {
        $orderSn = $request->digits('order_sn');
        $result = $this->userService->cancelOrder(auth('front')->id(), $orderSn);
        if (!$result['ok']) {
            throw new DomainException(lang('order_cancel_wrong'), route('order.user'));
        }
        if (!empty($result['refund_amount']) && $result['refund_amount'] > 0) {
            return message()->respond(
                sprintf(lang('order_cancel_wallet_refunded'), $result['refund_amount_format']),
                route('order.user')
            );
        }
        return redirect(route('order.user'));
    }

    /**
     * 会员中心订单视图的布局覆盖：复用 order_user.dwt 会员中心骨架，
     * 把 cur / 中导航 / 会员中心子导航回到 user 语境，并标记 rec 高亮。
     *
     * @param string $rec 当前会员中心子页面标记（order_list / order）
     * @return array
     */
    private function userCenterLayout($rec)
    {
        return array(
            'rec' => $rec,
            'nav_middle_list' => $this->nav->middle(0, 'user', 0),
            'link_user_center' => $this->buildLinkUserCenter('user'),
            'code_head' => Config::get('site.code_head', ''),
        );
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        $cartTotal = 0;
        if (auth('front') && method_exists(auth('front'), 'check') && auth('front')->check()
            && method_exists(auth('front'), 'id')) {
            $userId = (int) auth('front')->id();
            if ($userId > 0) {
                $cartTotal = (int) $this->order->countCartNumber($userId);
            }
        }

        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_bottom_list' => $this->nav->bottom(),
            'if_connect_plugin' => plugin()->hasConnect(),
            'cart_total' => $cartTotal,
        );
    }
}
