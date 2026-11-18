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
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付插件 Provider 统一契约。
 */
interface PaymentPluginProviderInterface
{
    /**
     * 插件唯一 ID（对应 plugin.slug）
     *
     * @return string
     */
    public function pluginId();

    /**
     * 插件元信息（用于后台管理、列表展示、配置 schema）
     *
     * @return array
     */
    public function meta();

    /**
     * 发起支付
     *
     * @param PaymentRequest $request
     * @return string HTML 内容
     */
    public function start(PaymentRequest $request);

    /**
     * 异步回调
     *
     * @param PaymentCallbackPayload $payload
     * @return string|\Dou\Core\Web\Http\Response 网关正文或包含 HTTP 状态的应答
     */
    public function notify(PaymentCallbackPayload $payload);

    /**
     * 同步回跳处理
     *
     * @param PaymentCallbackPayload $payload
     * @return string 跳转 URL
     */
    public function finish(PaymentCallbackPayload $payload);
}
