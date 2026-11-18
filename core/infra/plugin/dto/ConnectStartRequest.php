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
 * 第三方登录发起请求 DTO。
 */
class ConnectStartRequest
{
    /** @var string */
    private $pluginId;

    /** @var string */
    private $returnUrl;

    /**
     * @param string $pluginId
     * @param string $returnUrl 登录完成后期望落地的业务 URL（可空）
     */
    public function __construct($pluginId, $returnUrl = '')
    {
        $this->pluginId = (string) $pluginId;
        $this->returnUrl = (string) $returnUrl;
    }

    /**
     * @return string
     */
    public function getPluginId()
    {
        return $this->pluginId;
    }

    /**
     * @return string
     */
    public function getReturnUrl()
    {
        return $this->returnUrl;
    }
}
