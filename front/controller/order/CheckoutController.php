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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Order\CheckoutService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 结算控制器（route=order/checkout[/...]）
 *
 * 预览与下单统一走 CheckoutService::preview()/createOrder()，不再把运费/优惠券写入 Session；
 * 报价 token 通过 URL -> 表单隐藏域传递，Session 仅作为旧页面/旧缓存 JS 的兼容回退。
 */
class CheckoutController extends BaseController
{
    /** @var CheckoutService */
    private $checkoutService;

    /** @var OrderCore */
    private $order;

    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param CheckoutService $checkoutService 结算 / 下单业务
     * @param OrderCore $orderCore
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
     */
    public function __construct(
        CheckoutService $checkoutService,
        OrderCore $orderCore,
        NavigationBuilder $nav,
        SeoResolver $seo
    ) {
        $this->checkoutService = $checkoutService;
        $this->order = $orderCore;
        $this->nav = $nav;
        $this->seo = $seo;
    }

    /**
     * 结算页（route=order/checkout）
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

        $source = $this->resolveSource($request);
        $preview = $this->checkoutService->preview((int) auth('front')->id(), $source, 0, '', 0);
        if (empty($preview['ok'])) {
            return message()->respond((string) $preview['msg'], route('order.cart'));
        }

        $submitToken = uniqid('chk_', true);

        return $this->view('order.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('order_checkout'),

            // 页面骨架
            'rec' => 'checkout',

            // 数据
            'head' => lang('order_checkout'),
            'cart' => $preview['cart'],
            'shipping_list' => $preview['shipping_list'],
            'coupon_list' => $preview['coupon_list'],
            'order' => $this->orderView($preview),
            'quote_token' => isset($source['token']) ? (string) $source['token'] : '',
            'submit_token' => $submitToken,
            'act' => 'add',
        ]);
    }

    /**
     * 结算预览（route=order/checkout/preview，JSON 响应）
     *
     * @param Request $request
     * @return Response
     */
    public function preview(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $result = $this->runPreview($request);
        if (empty($result['ok'])) {
            ApiResponse::throwError(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                (string) $result['msg'],
                array('reason' => isset($result['code']) ? (string) $result['code'] : ''),
                422
            );
        }

        ApiResponse::throwSuccess($this->amountPayload($result));
    }

    /**
     * 提交订单（route=order/checkout/success）
     *
     * @param Request $request
     * @return Response
     */
    public function success(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $contactId = $request->integer('contact_id');
        $mode = $request->alpha('mode', 'money');

        $quoteToken = (string) $request->input('quote_token', '');
        if ($quoteToken !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $quoteToken)) {
            $quoteToken = '';
        }
        $source = $quoteToken !== '' ? array('type' => 'quote', 'token' => $quoteToken) : array('type' => 'cart');

        $submitToken = (string) $request->input('submit_token', '');

        $shippingRaw = (string) $request->input('shipping_id', '');
        $shippingId = preg_match('/^[A-Za-z0-9_-]+$/', $shippingRaw) ? $shippingRaw : '';

        $couponId = $request->integer('coupon_id', 0);
        $result = $this->checkoutService->createOrder((int) auth('front')->id(), array(
            'contact_id' => $contactId,
            'shipping_id' => $shippingId,
            'mode' => $mode,
            'coupon_id' => $couponId,
            'postcode' => $request->postcode('postcode'),
            'source' => $source,
            'submit_token' => $submitToken,
        ));

        if (!$result['ok']) {
            $reason = isset($result['code']) ? (string) $result['code'] : '';
            if (in_array($reason, array('cart_empty', 'quote_invalid', 'price_changed'), true)) {
                $redirect = route('order.cart');
            } else {
                $redirect = route('order.checkout', array(), array(
                    'query' => $quoteToken !== '' ? array('quote_token' => $quoteToken) : array(),
                ));
            }
            throw new DomainException((string) $result['msg'], $redirect);
        }

        if ($result['mode'] === 'money' && $result['order_amount'] == 0) {
            return $this->respond($request, route('order.user'));
        }

        if ($result['mode'] === 'money') {
            $cashierUrl = route('order.cashier', array(), array('query' => array('order_sn' => $result['order_sn'])));
            return $this->respond($request, $cashierUrl);
        }

        $orderDetailUrl = route('order.user.show', array('order_sn' => $result['order_sn']));

        return $this->respond($request, $orderDetailUrl);
    }

    /**
     * 联系人列表 JSON（route=order/checkout/contact_list，供结算页动态加载）
     *
     * @param Request $request
     * @return Response
     */
    public function contactList(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $contactId = $request->integer('contact_id');
        $userId = auth('front')->id();
        if (!$contactId) {
            $contactId = (int) DB::table('user_contact')
                ->where('user_id', $userId)
                ->order('`is_default` DESC, id DESC')
                ->value('id');
        }

        ApiResponse::throwSuccess(array(
            'contact_list' => user() ? user()->contactList($userId, $contactId) : array(),
        ));
    }

    /**
     * 单条联系人详情 JSON（route=order/checkout/contact_select）
     *
     * @param Request $request
     * @return Response
     */
    public function contactSelect(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $contactId = $request->integer('contact_id');
        $contact = $contactId
            ? DB::table('user_contact')->where('id', $contactId)->where('user_id', auth('front')->id())->find()
            : array();
        if ($contact) {
            $contact['name'] = UserContactQuery::displayName($contact);
            $contact['address_full'] = user() ? user()->addressFull($contact) : '';
        }

        ApiResponse::throwSuccess(array('contact' => $contact));
    }

    /**
     * 执行结算预览。
     *
     * @param Request $request
     * @return array
     */
    private function runPreview(Request $request)
    {
        $shippingRaw = (string) $request->input('shipping_id', '');
        $shippingId = preg_match('/^[A-Za-z0-9_-]+$/', $shippingRaw) ? $shippingRaw : '';
        $couponId = $request->integer('coupon_id', 0);

        return $this->checkoutService->preview(
            (int) auth('front')->id(),
            $this->resolveSource($request),
            $request->integer('contact_id', 0),
            (string) $shippingId,
            (int) $couponId
        );
    }

    /**
     * 解析结算来源：请求 token 优先，Session 作为旧页面回退。
     *
     * @param Request $request
     * @return array
     */
    private function resolveSource(Request $request)
    {
        $mode = $request->alpha('mode', 'money');
        $token = (string) $request->input('quote_token', '');
        if ($token !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
            return array('type' => 'quote', 'token' => $token, 'mode' => $mode);
        }

        return array('type' => 'cart', 'mode' => $mode);
    }

    /**
     * @param array $preview
     * @return array
     */
    private function orderView(array $preview)
    {
        return array(
            'mode' => $preview['mode'],
            'shipping_id' => $preview['shipping_id'],
            'coupon_id' => $preview['coupon_id'],
            'shipping_fee_format' => $preview['amount']['shipping_fee_format'],
            'coupon_amount_format' => $preview['amount']['coupon_amount_format'],
            'order_amount_format' => $preview['amount']['order_amount_format'],
            'order_point' => isset($preview['cart']['order_point']) ? (int) $preview['cart']['order_point'] : 0,
        );
    }

    /**
     * @param array $result
     * @return array
     */
    private function amountPayload(array $result)
    {
        return array(
            'shipping_id' => $result['shipping_id'],
            'coupon_id' => $result['coupon_id'],
            'coupon_error' => isset($result['coupon_error']) ? $result['coupon_error'] : '',
            'shipping_fee' => $result['amount']['shipping_fee_format'],
            'coupon_amount' => $result['amount']['coupon_amount_format'],
            'order_amount' => $result['amount']['order_amount_format'],
            'shipping_fee_format' => $result['amount']['shipping_fee_format'],
            'coupon_amount_format' => $result['amount']['coupon_amount_format'],
            'order_amount_format' => $result['amount']['order_amount_format'],
            'amount' => $result['amount'],
        );
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
        if ($request->wantsJson()) {
            ApiResponse::throwError(
                ApiCodes::UNAUTHORIZED,
                lang('login_timeout'),
                array(),
                401,
                array('redirect_url' => $loginUrl)
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
