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
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Order\CheckoutService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序结算 / 下单子控制器（route=order/checkout/*、order/buynow）
 *
 * 金额预览与下单均走 CheckoutService 的纯函数结算（不读写 Session、不信任客户端金额）；
 * 立即购买 / 积分 / 套餐通过 order/buynow 签发报价单，不写入购物车。
 */
class CheckoutController extends BaseController
{
    /** @var CheckoutService */
    private $checkoutService;

    /**
     * @param CheckoutService $checkoutService 结算 / 下单业务
     */
    public function __construct(CheckoutService $checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    /**
     * 结算页数据（route=order/checkout）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return $this->renderPreview($request);
    }

    /**
     * 结算预览（route=order/checkout/preview）
     *
     * @param Request $request
     * @return Response
     */
    public function preview(Request $request)
    {
        return $this->renderPreview($request);
    }

    /**
     * 立即购买 / 积分 / 套餐：签发报价单（route=order/buynow）
     *
     * @param Request $request
     * @return Response
     */
    public function buyNow(Request $request)
    {
        $userId = $this->mustLoginUserId();

        $module = Check::extendId($request->input('module', '')) ? (string) $request->input('module', '') : 'product';
        $itemId = Check::number($request->input('item_id', '')) ? (int) $request->input('item_id', 0) : 0;
        $itemNumber = Check::number($request->input('item_number', '')) ? (int) $request->input('item_number', 1) : 1;
        $mode = Check::letter($request->input('mode', '')) ? (string) $request->input('mode', 'money') : 'money';

        $attParams = array();
        foreach ((array) $request->post() as $key => $value) {
            if (strncmp($key, 'att_', 4) === 0) {
                $attParams[$key] = $value;
            }
        }

        $result = $this->checkoutService->prepareQuote($userId, $module, $itemId, $itemNumber, $mode, $attParams);
        if (!$result['ok']) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                (string) $result['msg'],
                array('reason' => isset($result['code']) ? (string) $result['code'] : ''),
                422
            );
        }

        return ApiResponse::success(array(
            'quote_token' => $result['quote_token'],
            'mode' => $result['mode'],
            'cart' => $result['cart'],
        ));
    }

    /**
     * 下单提交（route=order/checkout/checkout_post）
     *
     * @param Request $request
     * @return Response
     */
    public function checkoutPost(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $mode = $request->alpha('mode', 'money');
        $contactId = $request->integer('contact_id', 0);
        $shippingRaw = (string) $request->input('shipping_id', '');
        $shippingId = preg_match('/^[A-Za-z0-9_-]+$/', $shippingRaw) ? $shippingRaw : '';
        $couponId = $request->integer('coupon_id', 0);
        $postcode = $request->postcode('postcode');

        $source = array('type' => 'cart');
        $quoteToken = (string) $request->input('quote_token', '');
        if ($quoteToken !== '') {
            $source = array('type' => 'quote', 'token' => $quoteToken);
        }

        $result = $this->checkoutService->createOrder($userId, array(
            'contact_id' => $contactId,
            'shipping_id' => $shippingId,
            'mode' => $mode,
            'coupon_id' => $couponId,
            'postcode' => $postcode,
            'source' => $source,
            'submit_token' => (string) $request->input('submit_token', ''),
        ));

        if (!$result['ok']) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                (string) $result['msg'],
                array(
                    'reason' => isset($result['code']) ? (string) $result['code'] : '',
                    'amount' => isset($result['amount']) ? $result['amount'] : null,
                ),
                422
            );
        }

        if ($result['mode'] === 'money' && (float) $result['order_amount'] > 0) {
            $cashierUrl = Url::urlMini('order', 'cashier') . '?order_sn=' . $result['order_sn'];
        } else {
            // 积分兑换 / 优惠券打到 0 元的订单已直接收尾，跳订单列表而不是空收银台
            $cashierUrl = Url::urlMini('order', 'user');
        }

        return ApiResponse::success(array(
            'cashier_url' => $cashierUrl,
            'order_sn' => isset($result['order_sn']) ? $result['order_sn'] : '',
            'mode' => $result['mode'],
        ));
    }

    /**
     * 下单成功页数据（route=order/checkout/success）
     *
     * @param Request $request
     * @return Response
     */
    public function success(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn');
        // 只下发成功页所需字段，避免暴露 pay_id / ip / memo / user_id 等内部数据
        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->field('order_sn, mode, module, currency_code, currency_rate, currency_decimal, order_amount, currency_order_amount, status, created_at')
            ->find();

        if ($order) {
            $orderCtx = currency()->contextForOrder($order);
            $order['order_amount_format'] = Util::formatCurrency(currency()->orderAmount($order), $orderCtx);
        }

        return ApiResponse::success(array(
            'title' => lang('order_success'),
            'order' => $order ? $order : array(),
        ));
    }

    /**
     * 渲染结算预览响应。
     *
     * @param Request $request
     * @return Response
     */
    private function renderPreview(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $mode = $request->alpha('mode', 'money');

        $source = array('type' => 'cart', 'mode' => $mode);
        $quoteToken = (string) $request->input('quote_token', '');
        if ($quoteToken !== '') {
            $source = array('type' => 'quote', 'token' => $quoteToken, 'mode' => $mode);
        }

        $result = $this->checkoutService->preview(
            $userId,
            $source,
            $request->integer('contact_id', 0),
            (string) $request->input('shipping_id', ''),
            $request->integer('coupon_id', 0)
        );

        if (!$result['ok']) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                (string) $result['msg'],
                array(
                    'reason' => isset($result['code']) ? (string) $result['code'] : '',
                    'amount' => isset($result['amount']) ? $result['amount'] : null,
                ),
                422
            );
        }

        $amount = $result['amount'];

        return ApiResponse::success(array(
            'title' => lang('order_checkout'),
            'cart' => $result['cart'],
            'shipping_list' => $result['shipping_list'],
            'shipping_id' => $result['shipping_id'],
            'coupon_list' => $result['coupon_list'],
            'coupon_id' => $result['coupon_id'],
            'coupon_error' => isset($result['coupon_error']) ? $result['coupon_error'] : '',
            'amount' => array(
                'item_amount' => $amount['item_amount'],
                'item_amount_format' => $amount['item_amount_format'],
                'shipping_fee' => $amount['shipping_fee'],
                'shipping_fee_format' => $amount['shipping_fee_format'],
                'coupon_amount' => $amount['coupon_amount'],
                'coupon_amount_format' => $amount['coupon_amount_format'],
                'order_amount' => $amount['order_amount'],
                'order_amount_format' => $amount['order_amount_format'],
            ),
            'order' => array(
                'mode' => $result['mode'],
                'shipping_fee_format' => $amount['shipping_fee_format'],
                'order_amount_format' => $amount['order_amount_format'],
            ),
            'mode' => $result['mode'],
            'source' => $result['source'],
        ));
    }

    /**
     * 登录校验并返回 user_id。
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
