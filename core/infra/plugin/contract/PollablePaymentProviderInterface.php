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

namespace Dou\Core\Infra\Plugin\Contract;

use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支持前端轮询主动查状态的支付插件 Provider 契约。
 *
 * 与 {@see ReconcilablePaymentProviderInterface} 的区别：
 * - Reconcilable 面向后台/对账任务，向第三方网关查询某次支付的真实状态；
 * - Pollable 面向前端收银台轮询，用于扫码支付（如微信 Native、支付宝当面付）
 *   场景下前端定期询问「该二维码是否已被扫码支付」。
 *
 * 前台 {@see \Dou\Front\Controller\Plugin\PluginController::status()} 通过
 * `$provider instanceof PollablePaymentProviderInterface` 判定是否支持轮询，
 * 替代原先的 `method_exists($provider, 'status')` 鸭子类型判定。
 */
interface PollablePaymentProviderInterface extends PaymentPluginProviderInterface
{
    /**
     * 前端轮询查询支付状态（扫码场景）。
     *
     * @param PaymentCallbackPayload $payload
     * @return string 通常是 SUCCESS / FAIL 或 trade_state
     */
    public function status(PaymentCallbackPayload $payload);
}
