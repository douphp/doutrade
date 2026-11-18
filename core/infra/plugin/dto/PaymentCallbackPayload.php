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

namespace Dou\Core\Infra\Plugin\Dto;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付回调上下文 DTO。
 */
class PaymentCallbackPayload
{
    /** @var string */
    private $pluginId;

    /** @var array */
    private $requestData;

    /** @var string 原始请求字节，验签前不得重新编码 */
    private $rawBody;

    /** @var array 小写请求头名 => 值 */
    private $headers;

    /**
     * @param string $pluginId
     * @param array $requestData
     * @param string $rawBody 未解析的原始请求体
     * @param array $headers 请求头
     */
    public function __construct($pluginId, array $requestData, $rawBody = '', array $headers = array())
    {
        $this->pluginId = (string) $pluginId;
        $this->requestData = $requestData;
        $this->rawBody = (string) $rawBody;
        $this->headers = array();
        foreach ($headers as $name => $value) {
            $this->headers[strtolower(str_replace('_', '-', (string) $name))] = $value;
        }
    }

    /**
     * @return string
     */
    public function getPluginId()
    {
        return $this->pluginId;
    }

    /**
     * @return array
     */
    public function getRequestData()
    {
        return $this->requestData;
    }

    /**
     * 获取未解析的原始请求体。
     *
     * @return string 原始请求字节
     */
    public function getRawBody()
    {
        return $this->rawBody;
    }

    /**
     * 按名称读取请求头，名称大小写与下划线不敏感。
     *
     * @param string $name 请求头名称
     * @param mixed $default 未命中时的默认值
     * @return mixed 请求头值或默认值
     */
    public function getHeader($name, $default = '')
    {
        $name = strtolower(str_replace('_', '-', (string) $name));
        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }
}
