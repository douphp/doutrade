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

use Dou\Core\Infra\Plugin\Dto\ConnectCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\ConnectStartRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 第三方登录（OAuth）插件 Provider 统一契约。
 */
interface ConnectPluginProviderInterface
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
     * 发起授权（OAuth 授权页跳转）
     *
     * @param ConnectStartRequest $request
     * @return string 授权页跳转 URL
     */
    public function start(ConnectStartRequest $request);

    /**
     * 处理授权回跳
     *
     * @param ConnectCallbackPayload $payload
     * @return string 业务跳转 URL（登录成功落地、绑定页等）
     */
    public function finish(ConnectCallbackPayload $payload);
}
