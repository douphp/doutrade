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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 配送（shipping）插件 Provider 统一契约。
 */
interface ShippingPluginProviderInterface
{
    /**
     * 插件唯一 ID（对应 plugin.slug）
     *
     * @return string
     */
    public function pluginId();

    /**
     * 插件元信息（含 plugin_group / config schema 等）
     *
     * @return array
     */
    public function meta();

    /**
     * 配送方式列表（id / name / price / desc）
     *
     * @return array
     */
    public function methods();
}
