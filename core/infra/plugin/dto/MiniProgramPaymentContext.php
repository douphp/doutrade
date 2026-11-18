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
 * 小程序支付上下文。
 *
 * AppID 来自小程序模块配置，商户号/密钥由支付插件自行读取；
 * openid 来自小程序登录后的 user_sns，不是配置项。
 */
class MiniProgramPaymentContext
{
    /** @var string */
    private $openid;

    /** @var string */
    private $appid;

    /** @var int */
    private $userId;

    /**
     * @param string $openid
     * @param string $appid
     * @param int $userId
     */
    public function __construct($openid = '', $appid = '', $userId = 0)
    {
        $this->openid = trim((string) $openid);
        $this->appid = trim((string) $appid);
        $this->userId = (int) $userId;
    }

    /**
     * @return string
     */
    public function getOpenid()
    {
        return $this->openid;
    }

    /**
     * @return string
     */
    public function getAppid()
    {
        return $this->appid;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->userId;
    }
}
