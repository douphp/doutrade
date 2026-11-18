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
 * 第三方登录回跳上下文 DTO。
 */
class ConnectCallbackPayload
{
    /** @var string */
    private $pluginId;

    /** @var array */
    private $query;

    /** @var array|null 当前已登录会员（用于绑定流程，未登录为 null） */
    private $userProfile;

    /**
     * @param string $pluginId
     * @param array $query
     * @param array|null $userProfile
     */
    public function __construct($pluginId, array $query, $userProfile = null)
    {
        $this->pluginId = (string) $pluginId;
        $this->query = $query;
        $this->userProfile = is_array($userProfile) ? $userProfile : null;
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
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return array|null
     */
    public function getUserProfile()
    {
        return $this->userProfile;
    }
}
