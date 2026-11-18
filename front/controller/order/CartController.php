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

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Order\CartService;
use Dou\Front\Service\Order\CheckoutService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 购物车控制器（route=order/cart[/...]）
 */
class CartController extends BaseController
{
    /** @var CartService */
    private $cartService;

    /** @var OrderCore */
    private $order;

    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /** @var CheckoutService */
    private $checkoutService;

    /**
     * @param CartService $cartService 购物车业务
     * @param OrderCore $orderCore
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
     * @param CheckoutService $checkoutService 立即购买报价单
     */
    public function __construct(
        CartService $cartService,
        OrderCore $orderCore,
        NavigationBuilder $nav,
        SeoResolver $seo,
        CheckoutService $checkoutService
    ) {
        $this->cartService = $cartService;
        $this->order = $orderCore;
        $this->nav = $nav;
        $this->seo = $seo;
        $this->checkoutService = $checkoutService;
    }

    /**
     * 购物车页（route=order/cart）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $cart = $this->order->getCart((int) auth('front')->id());
        if ($cart && $cart['module'] !== 'product' && Config::get('param.order_quick_buy')) {
            return redirect(route('order.checkout'));
        }

        return $this->view('order.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('order_cart'),

            // 页面骨架
            'rec' => 'cart',

            // 数据
            'cart' => $cart,
            'head' => lang('order_cart'),
        ]);
    }

    /**
     * 加入购物车（route=order/cart/store）
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $module = $request->extendId('module', 'product');
        $itemId = $request->integer('item_id', 0);
        $number = $request->integer('number', 1);
        $mode = $request->alpha('mode', 'money');
        $action = $request->alpha('action', 'addtocart');
        $from = $request->alpha('from');
        $replace = !empty($request->input('replace'));

        // 套餐类统一走立即购买报价单（充值 money.dwt 等普通表单无需再改模板）
        if (substr($module, -8) === '_package') {
            $action = 'buynow';
        }

        // 收集规格属性参数
        $attParams = array();
        foreach ((array) $request->all() as $key => $val) {
            if (strncmp($key, 'att_', 4) === 0) {
                $attParams[$key] = $val;
            }
        }

        // 立即购买：走报价单，不触碰购物车
        if ($action === 'buynow') {
            $result = $this->checkoutService->prepareQuote(auth('front')->id(), $module, $itemId, $number, $mode, $attParams);
            if (empty($result['ok'])) {
                if ($from === 'js') {
                    ApiResponse::throwError(
                        ApiCodes::BUSINESS_RULE_VIOLATION,
                        (string) $result['msg'],
                        array('reason' => isset($result['code']) ? (string) $result['code'] : ''),
                        422
                    );
                }
                return message()->respond($result['msg'], route('order.cart'));
            }

            $jumpUrl = route('order.checkout', array(), array(
                'query' => array('quote_token' => (string) $result['quote_token']),
            ));
            if ($from === 'js') {
                ApiResponse::throwSuccess(array('jump_url' => $jumpUrl));
            }

            return redirect($jumpUrl);
        }

        $result = $this->cartService->addToCart(auth('front')->id(), $module, $itemId, $number, $mode, $attParams, $replace);

        if (!$result['ok']) {
            if ($from === 'js') {
                ApiResponse::throwError(
                    ApiCodes::BUSINESS_RULE_VIOLATION,
                    (string) $result['msg'],
                    array('reason' => isset($result['code']) ? (string) $result['code'] : ''),
                    422
                );
            }
            return message()->respond($result['msg'], route('order.cart'));
        }

        // 确定跳转目标
        if ($result['mode'] === 'point') {
            $jumpUrl = route('order.checkout', array(), array('query' => array('mode' => 'point')));
        } elseif ($result['module'] === 'product') {
            $jumpUrl = Config::get('param.order_quick_buy') ? route('order.checkout') : route('order.cart');
        } else {
            $jumpUrl = route('order.checkout');
        }

        if ($from === 'js') {
            ApiResponse::throwSuccess(array('jump_url' => $jumpUrl));
        }

        return redirect($jumpUrl);
    }

    /**
     * 清空失效购物车行（route=order/cart/clear_invalid）
     *
     * @param Request $request
     * @return Response
     */
    public function clearInvalid(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $payload = $this->cartService->clearInvalid((int) auth('front')->id());
        if ($request->wantsJson()) {
            ApiResponse::throwSuccess($payload);
        }

        return redirect(route('order.cart'));
    }

    /**
     * 更新购物车数量（route=order/cart/update，JSON 响应）
     *
     * @param Request $request
     * @return Response
     */
    public function update(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $cartId = $request->integer('id', 0);
        $number = $request->integer('number', 1);

        $data = $this->cartService->updateCartItem(auth('front')->id(), $cartId, $number);
        if (empty($data['ok'])) {
            ApiResponse::throwError(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                isset($data['msg']) ? (string) $data['msg'] : lang('order_item_wrong'),
                array('code' => isset($data['code']) ? (string) $data['code'] : ''),
                422
            );
        }

        ApiResponse::throwSuccess($data);
    }

    /**
     * 删除购物车条目（route=order/cart/destroy）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $cartId = $request->integer('id', 0);
        $this->cartService->deleteCartItem(auth('front')->id(), $cartId);
        return redirect(route('order.cart'));
    }

    /**
     * 断言当前请求已登录，未登录则跳转（AJAX 请求返回 JSON）。
     *
     * @param Request $request
     * @return Response|null
     */
    private function assertLogin(Request $request)
    {
        if (auth('front') && auth('front')->check()) {
            return;
        }

        $loginUrl = route('user.login');
        if ($request->input('from') === 'js') {
            ApiResponse::throwError(
                ApiCodes::UNAUTHORIZED,
                lang('login_timeout'),
                array(),
                401
            );
        }
        return redirect($loginUrl);
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
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('order'),
            'if_connect_plugin' => plugin()->hasConnect(),
            'code_head' => Config::get('site.code_head', ''),
            'cart_total' => $cartTotal,
        );
    }
}
