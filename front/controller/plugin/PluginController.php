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

namespace Dou\Front\Controller\Plugin;

use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Contract\PollablePaymentProviderInterface;
use Dou\Core\Infra\Plugin\Dto\ConnectCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\ConnectStartRequest;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Infra\Plugin\Registry\PluginRegistryAggregator;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台插件统一入口（route=plugin/{pluginId}/start|notify|finish|status）。
 *
 * 路由职责：
 *   - connect：start 跳第三方授权、finish 处理回跳
 *   - payment：notify 异步回调、finish 同步回跳、status 状态轮询
 *     （payment 发起入口为 {@see \Dou\Front\Controller\Order\OrderController::charge()}，
 *      由 cashier 表单 POST 触发）
 *   - shipping：无运行时入口
 */
class PluginController extends BaseController
{
    /** @var PluginRegistryAggregator */
    private $registry;

    /**
     * @param PluginRegistryAggregator $registry
     */
    public function __construct(PluginRegistryAggregator $registry)
    {
        $this->registry = $registry;
    }

    /**
     * 默认入口。
     *
     * @return Response
     */
    public function index()
    {
        return message()->respond(lang('illegal'), HOME_URL);
    }

    /**
     * 发起：仅 connect 类插件跳转授权页。
     *
     * payment 发起入口为 POST /order/charge，由 OrderController 调起
     * provider->start() 并把第三方 HTML 直接返回浏览器。
     *
     * @param Request $request
     * @return Response
     */
    public function start(Request $request)
    {
        $pluginId = $this->resolvePluginId($request);
        $group = $this->registry->group($pluginId);

        if ($group === 'connect') {
            $provider = $this->registry->connect()->provider($pluginId);
            $returnUrl = (string) $request->input('return_url', '');
            $url = $provider->start(new ConnectStartRequest($pluginId, $returnUrl));
            if ($url === '') {
                return redirect(HOME_URL);
            }
            return redirect($url);
        }

        return message()->respond(lang('illegal'), HOME_URL);
    }

    /**
     * 异步回调（仅 payment 适用）。
     *
     * @param Request $request
     * @return Response
     */
    public function notify(Request $request)
    {
        $pluginId = $this->resolvePluginId($request);
        $provider = $this->registry->payment()->provider($pluginId);
        if ($provider === null) {
            return $this->response('fail');
        }

        try {
            $result = $provider->notify(new PaymentCallbackPayload(
                $pluginId, (array) $request->post(), $request->rawBody(), $request->headers()
            ));
        } catch (\Exception $e) {
            return $this->notifyError($pluginId, $e);
        } catch (\Throwable $e) {
            return $this->notifyError($pluginId, $e);
        }

        return $result instanceof Response ? $result : $this->response((string) $result);
    }

    /**
     * 同步回跳：payment 验签后跳订单页 / connect 处理 OAuth 回跳。
     *
     * @param Request $request
     * @return Response
     */
    public function finish(Request $request)
    {
        $pluginId = $this->resolvePluginId($request);
        $group = $this->registry->group($pluginId);

        if ($group === 'payment') {
            $provider = $this->registry->payment()->provider($pluginId);
            $query = (array) $request->get();
            // 本站入口参数不是网关签名内容；POST 正文仍完整保留。
            unset($query['route']);
            $data = array_merge($query, (array) $request->post());
            $redirect = $provider->finish(new PaymentCallbackPayload($pluginId, $data, $request->rawBody(), $request->headers()));
            return redirect($redirect);
        }

        if ($group === 'connect') {
            $provider = $this->registry->connect()->provider($pluginId);
            $redirect = $provider->finish(new ConnectCallbackPayload($pluginId, (array) $request->get(), auth('front') ? auth('front')->user() : array()));
            return redirect($redirect);
        }

        return redirect(HOME_URL);
    }

    /**
     * 主动状态查询（仅实现 PollablePaymentProviderInterface 的 payment 插件支持，
     * 如微信 Native 扫码、支付宝当面付的前端轮询）。
     *
     * @param Request $request
     * @return Response
     */
    public function status(Request $request)
    {
        $pluginId = $this->resolvePluginId($request);
        $provider = $this->registry->payment()->provider($pluginId);
        if (!$provider instanceof PollablePaymentProviderInterface) {
            return $this->response('FAIL');
        }

        $payload = new PaymentCallbackPayload($pluginId, array_merge((array) $request->post(), (array) $request->get()));

        return $this->response((string) $provider->status($payload));
    }

    /**
     * @param Request $request
     * @return string
     */
    private function resolvePluginId(Request $request)
    {
        $pluginId = $request->route('plugin_id', '');
        if (!preg_match('/^[a-z0-9_-]+$/', $pluginId)) {
            return '';
        }

        return $pluginId;
    }

    /**
     * 统一处理支付回调异常：记录日志并返回网关可重试的失败响应。
     *
     * @param string $pluginId 支付插件标识
     * @param \Exception|\Throwable $error 回调处理异常
     * @return Response HTTP 500 的失败响应
     */
    private function notifyError($pluginId, $error)
    {
        Log::error('payment notify exception: ' . $error->getMessage(), array('channel' => 'pay', 'plugin' => $pluginId));
        return $this->response('fail', 500);
    }
}
