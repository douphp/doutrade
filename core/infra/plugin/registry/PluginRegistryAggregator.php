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

namespace Dou\Core\Infra\Plugin\Registry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 三类插件注册中心的聚合器。
 *
 * 前台 PluginController 依赖该聚合器按 plugin_group 派发到对应 Registry。
 */
class PluginRegistryAggregator
{
    /** @var PaymentPluginRegistry */
    private $payment;

    /** @var ConnectPluginRegistry */
    private $connect;

    /** @var ShippingPluginRegistry */
    private $shipping;

    /**
     * @param PaymentPluginRegistry $payment
     * @param ConnectPluginRegistry $connect
     * @param ShippingPluginRegistry $shipping
     */
    public function __construct(
        PaymentPluginRegistry $payment,
        ConnectPluginRegistry $connect,
        ShippingPluginRegistry $shipping
    ) {
        $this->payment = $payment;
        $this->connect = $connect;
        $this->shipping = $shipping;
    }

    /**
     * 返回 payment Registry。
     *
     * @return PaymentPluginRegistry
     */
    public function payment()
    {
        return $this->payment;
    }

    /**
     * 返回 connect Registry。
     *
     * @return ConnectPluginRegistry
     */
    public function connect()
    {
        return $this->connect;
    }

    /**
     * 返回 shipping Registry。
     *
     * @return ShippingPluginRegistry
     */
    public function shipping()
    {
        return $this->shipping;
    }

    /**
     * 解析插件 ID 所属 group。
     *
     * @param string $pluginId
     * @return string '' / payment / connect / shipping
     */
    public function group($pluginId)
    {
        if ($this->payment->has($pluginId)) {
            return 'payment';
        }
        if ($this->connect->has($pluginId)) {
            return 'connect';
        }
        if ($this->shipping->has($pluginId)) {
            return 'shipping';
        }
        return '';
    }

    /**
     * 合并三类 Registry 的所有 meta。
     *
     * @return array
     */
    public function allMeta()
    {
        return array_merge(
            $this->payment->allMeta(),
            $this->connect->allMeta(),
            $this->shipping->allMeta()
        );
    }
}
