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
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;
use Dou\Core\Infra\Plugin\PluginClient;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Order\OrderCheckoutOptions;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Payment\PaymentCurrencyPolicy;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\Order\ChargeFormRequest;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Order\CashierService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 收银台控制器（route=order/cashier[/...]）
 */
class CashierController extends BaseController
{
    /** @var CashierService */
    private $cashierService;

    /** @var OrderCore */
    private $order;

    /** @var PaymentService */
    private $paymentService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param CashierService $cashierService 收银台业务
     * @param OrderCore $orderCore
     * @param PaymentService $paymentService
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
     */
    public function __construct(
        CashierService $cashierService,
        OrderCore $orderCore,
        PaymentService $paymentService,
        NavigationBuilder $nav,
        SeoResolver $seo
    ) {
        $this->cashierService = $cashierService;
        $this->order = $orderCore;
        $this->paymentService = $paymentService;
        $this->nav = $nav;
        $this->seo = $seo;
    }

    /**
     * 收银台（route=order/cashier）
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

        $orderSn = $request->digits('order_sn');
        $order = $this->cashierService->getCashierOrder(auth('front')->id(), $orderSn);
        if (!$order) {
            return redirect(route('user'));
        }

        $order['charge_url'] = route('order.cashier.charge');
        $order['cashier_token'] = csrf()->token('static_user');
        $order['order_link'] = route('order.user.show', ['order_sn' => $order['order_sn']]);

        $remaining = $this->cashierService->remainingAmount($order);
        $order['gateway_remain_format'] = Util::formatCurrency($remaining, currency()->contextForOrder($order));

        return $this->view('order.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('order_cashier'),

            // 页面骨架
            'rec' => 'cashier',

            // 数据
            'head' => lang('order_cashier'),
            'order' => $order,
            'payment_list' => $this->order->getPaymentList((string) $order['currency_code'], $order['module']),
        ]);
    }

    /**
     * 发起支付（route=order/cashier/charge，POST）。
     *
     * cashier 选定支付方式后 form POST 到这里；本方法负责：
     * 1. token 校验、订单归属校验
     * 2. 写订单 pay_id（保留兼容字段）
     * 3. 通过 PaymentService 创建 dou_order_payment 行（status=pending）
     * 4. 调起对应支付插件 provider，把第三方 HTML 直接返回给浏览器
     *
     * 离线付款 / 货到付款仍走各自独立入口（order/cashier/pay_evidence、order/cashier/cod），
     * 不经过本方法。
     *
     * @param ChargeFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function charge(ChargeFormRequest $formRequest, Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $data = $formRequest->validated();
        $orderSn = isset($data['order_sn']) ? (string) $data['order_sn'] : '';
        $payId = strtolower(trim(isset($data['pay_id']) ? (string) $data['pay_id'] : ''));
        if ($payId === 'wallet' || (string) $request->input('use_wallet', '') === '1') {
            return message()->respond(lang('order_payment_invalid', lang('illegal')), route('order.user'));
        }
        if ($payId !== '' && !preg_match('/^[a-z0-9_-]+$/', $payId)) {
            return message()->respond(lang('illegal'), route('order.cashier', [], ['query' => ['order_sn' => $orderSn]]));
        }
        if ($payId === '') {
            return message()->respond(lang('illegal'), route('order.cashier', [], ['query' => ['order_sn' => $orderSn]]));
        }
        if (PluginClient::isDeprecated($payId)) {
            return message()->respond(lang('order_payment_invalid', lang('illegal')), route('order.cashier', [], ['query' => ['order_sn' => $orderSn]]));
        }

        $userId = (int) auth('front')->id();
        $order = $this->cashierService->getPayOrder($userId, $orderSn);
        if (!$order) {
            return redirect(route('user'));
        }

        $cashierUrl = route('order.cashier', [], ['query' => ['order_sn' => $order['order_sn']]]);
        if ($payId === 'cod' && !\Dou\Core\Domain\Order\OrderPolicy::canUseCod($order['module'])) {
            return message()->respond(lang('order_payment_invalid', lang('illegal')), $cashierUrl);
        }
        $this->paymentService->closePendingWalletIntents($orderSn, 'wallet payment disabled');

        // 先校验网关，避免用户选错支付方式时已经发生余额扣款
        $provider = null;
        if ($payId !== '') {
            $payPlugin = plugin()->getWithConfig($payId);
            if (!is_array($payPlugin)
                || !isset($payPlugin['plugin_group'])
                || (string) $payPlugin['plugin_group'] !== 'payment'
                || empty($payPlugin['name'])
            ) {
                return redirect($cashierUrl);
            }

            $pluginRegistry = Module::make('plugin.registry_aggregator');
            if (!is_object($pluginRegistry) || !method_exists($pluginRegistry, 'payment')) {
                return redirect($cashierUrl);
            }

            $paymentGroup = $pluginRegistry->payment();
            if (!is_object($paymentGroup) || !method_exists($paymentGroup, 'provider')) {
                return redirect($cashierUrl);
            }

            $provider = $paymentGroup->provider($payId);
            if ($provider === null) {
                return redirect($cashierUrl);
            }

            // 网关必须支持订单币种，避免把外币金额交给只认一种币的插件
            $orderCtx = currency()->contextForOrder($order);
            $pluginRow = plugin()->getBySlug($payId);
            $rawConfig = isset($pluginRow['config']) ? $pluginRow['config'] : array();
            if (!PaymentCurrencyPolicy::supports($payId, $orderCtx['code'], $rawConfig)) {
                return message()->respond(
                    lang('order_payment_currency_unsupported', lang('order_payment_invalid', lang('illegal'))),
                    $cashierUrl
                );
            }
        }

        // 网关应收金额：以支付台账中已成功的支付腿为准计算剩余
        $chargeAmount = $this->cashierService->remainingAmount($order);
        if ($chargeAmount <= 0) {
            if ($this->paymentService->tryFinalizeOrder((string) $order['order_sn'])) {
                return redirect(route('order.user.show', ['order_sn' => $order['order_sn']]));
            }
            return message()->respond(lang('order_payment_invalid', lang('illegal')), $cashierUrl);
        }

        $timeoutMin = OrderCheckoutOptions::payTimeoutMinutes();
        $expiredAt = time() + $timeoutMin * 60;

        $orderCtx = currency()->contextForOrder($order);
        $payment = $this->paymentService->createForOrder(
            (int) $order['order_id'],
            (string) $order['order_sn'],
            $payId,
            $chargeAmount,
            $expiredAt,
            $orderCtx['code'],
            currency()->toBase($chargeAmount, $orderCtx)
        );

        // 网关启动失败必须关闭刚创建的 pending 腿，避免留下永远收不到回调的支付尝试；
        // 待结算钱包腿保留（重试会复用，取消/超时统一关闭），余额尚未扣除
        try {
            $html = $provider->start(new PaymentRequest(
                $payId,
                $payment['payment_sn'],
                (string) $order['order_sn'],
                $chargeAmount,
                $orderCtx['code']
            ));
        } catch (\Exception $e) {
            $this->paymentService->markClosed((string) $payment['payment_sn'], 'provider start failed: ' . $e->getMessage());
            Log::error('payment provider start failed', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $order['order_sn'],
                    'pay_id' => $payId,
                    'payment_sn' => (string) $payment['payment_sn'],
                    'error' => $e->getMessage(),
                ),
            ));
            // 配置不完整等业务性失败：直接展示具体原因，便于站长按提示处理
            if ($e instanceof DomainException) {
                return message()->respond($e->getMessage(), $cashierUrl);
            }
            return message()->respond(lang('order_payment_start_failed'), $cashierUrl);
        }

        if (!is_string($html) || $html === '') {
            $this->paymentService->markClosed((string) $payment['payment_sn'], 'provider start returned empty');
            Log::error('payment provider start returned empty', array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'order_sn' => (string) $order['order_sn'],
                    'pay_id' => $payId,
                    'payment_sn' => (string) $payment['payment_sn'],
                ),
            ));
            return message()->respond(lang('order_payment_start_failed'), $cashierUrl);
        }

        // 网关启动成功后再落支付方式，避免插件启动失败残留错误 pay_id
        $this->order->writePayId($order['order_sn'], $payId);

        $order['cashier_url'] = $cashierUrl;

        return $this->view('order.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('order_cashier'),

            // 页面骨架
            'rec' => 'charge',

            // 数据
            'head' => lang('order_cashier'),
            'order' => $order,
            'payment' => (string) $html,
        ]);
    }

    /**
     * 微信公众号 OAuth GET 续接：只恢复会话中已授权的同一次支付。
     *
     * @param Request $request 当前请求
     * @return Response 收银台页面、跳转或错误提示响应
     */
    public function wxpay_oauth(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }
        $state = $request->get('state', '');
        $code = $request->get('code', '');
        if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/', $state)) {
            return redirect(route('order.user'));
        }
        $context = Session::get('wxpay_oauth', array(), $state);
        if (!is_array($context) || empty($context['state']) || !hash_equals((string) $context['state'], $state)) {
            return redirect(route('order.user'));
        }
        // 验证过的 state 只消费一次，拒绝重放授权 code。
        Session::del('wxpay_oauth', $state);
        if (!is_string($code) || $code === '' || strlen($code) > 512
            || !isset($context['user_id'], $context['expires'], $context['payment_sn'], $context['order_sn'], $context['amount'], $context['currency'], $context['appid'])
            || (int) $context['user_id'] !== (int) auth('front')->id() || (int) $context['expires'] <= time()) {
            return redirect(route('order.user'));
        }
        $payment = $this->paymentService->findBySn($context['payment_sn']);
        $order = $this->cashierService->getPayOrder((int) auth('front')->id(), $context['order_sn']);
        if (!$payment || !$order || (string) $payment['gateway'] !== 'wxpay' || (string) $payment['status'] !== 'pending'
            || (string) $payment['order_sn'] !== (string) $context['order_sn'] || (int) $payment['order_id'] !== (int) $order['order_id']
            || (!empty($payment['expired_at']) && strtotime($payment['expired_at']) <= time())
            || (string) $context['currency'] !== 'CNY' || (string) $payment['currency_code'] !== 'CNY' || (string) $order['currency_code'] !== 'CNY'
            || abs((float) $payment['amount'] - (float) $context['amount']) >= 0.005
            || abs($this->cashierService->remainingAmount($order) - (float) $context['amount']) >= 0.005) {
            return redirect(route('order.user'));
        }
        $cashierUrl = route('order.cashier', array(), array('query' => array('order_sn' => $order['order_sn'])));
        try {
            $provider = Module::make('plugin.registry_aggregator')->payment()->provider('wxpay');
            if (!$provider instanceof \Dou\Plugin\Wxpay\WxpayProvider) {
                throw new DomainException('微信支付当前不可用。', $cashierUrl);
            }
            $html = $provider->resumeJsapi(new PaymentRequest('wxpay', $payment['payment_sn'], $order['order_sn'], $payment['amount'], 'CNY'), $code, $context['appid']);
            if (!is_string($html) || $html === '') {
                throw new DomainException('微信支付启动失败，请重试。', $cashierUrl);
            }
            $this->order->writePayId($order['order_sn'], 'wxpay');
        } catch (\Exception $e) {
            $this->paymentService->markClosed($payment['payment_sn'], 'wxpay oauth resume failed');
            Log::error('wxpay oauth resume failed: ' . $e->getMessage(), array('channel' => 'payment', 'payment_sn' => $payment['payment_sn']));
            return message()->respond(lang('order_payment_start_failed'), $cashierUrl);
        }
        $order['cashier_url'] = $cashierUrl;
        return $this->view('order.dwt', array(
            'page_title' => $this->seo->pageTitle('order_cashier'), 'rec' => 'charge',
            'head' => lang('order_cashier'), 'order' => $order, 'payment' => $html,
        ));
    }

    /**
     * 上传线下付款凭证（route=order/cashier/pay_evidence）
     *
     * 失败路径统一指向 cashier（GET），避免 dou_msg.dwt 的 history.go(-1) 回到
     * POST /order/cashier/charge 历史项时触发浏览器原生「确认重新提交表单」对话框。
     *
     * @param Request $request
     * @return Response
     */
    public function payEvidence(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $orderSn = $request->digits('order_sn', '', 'post');
        $paymentSn = (string) $request->post('payment_sn', '');
        $userId = auth('front')->id();
        $order = DB::table('order')->where('order_sn', $orderSn)->where('user_id', $userId)->find();
        if (!$order) {
            return redirect(route('order.user'));
        }

        $cashierUrl = route('order.cashier', array(), array('query' => array('order_sn' => $orderSn)));

        if (empty($_FILES['pay_evidence']['name'])) {
            return message()->respond(lang('order_pay_evidence_cue'), $cashierUrl);
        }

        $walletCheck = $this->cashierService->assertPaymentReady($userId, $orderSn, $paymentSn);
        if (empty($walletCheck['ok'])) {
            return message()->respond($walletCheck['msg'], $cashierUrl);
        }

        try {
            $payEvidence = attachment()->store('order', $orderSn, UploadedFile::fromGlobals('pay_evidence'), 'main', AttachmentUploadOptions::create()->withImageWidth(800)->withPrimaryKey('order_sn')->withBusinessField('pay_evidence')->withUploader('user', (int) $userId));
        } catch (DomainException $e) {
            return message()->respond($e->getMessage(), $cashierUrl);
        }

        if (!$this->cashierService->savePayEvidence($userId, $orderSn, $paymentSn, $payEvidence)) {
            return message()->respond(lang('order_payment_invalid', lang('illegal')), $cashierUrl);
        }
        return redirect(route('order.user'));
    }

    /**
     * 货到付款（route=order/cashier/cod）
     *
     * @param Request $request
     * @return Response
     */
    public function cod(Request $request)
    {
        $resp = $this->assertLogin($request);
        if ($resp) {
            return $resp;
        }

        $orderSn = $request->digits('order_sn', '', 'post');
        $paymentSn = (string) $request->post('payment_sn', '');
        $userId = auth('front')->id();
        $order = DB::table('order')->where('order_sn', $orderSn)->where('user_id', $userId)->find();
        if (!$order) {
            return redirect(route('order.user'));
        }

        if (!$this->cashierService->submitCod($userId, $orderSn, $paymentSn)) {
            return message()->respond(lang('order_payment_invalid', lang('illegal')), route('order.user'));
        }
        return redirect(route('order.user'));
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
