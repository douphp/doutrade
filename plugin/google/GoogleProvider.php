<?php

namespace Dou\Plugin\Google;

use Dou\Core\Infra\Plugin\Contract\ConnectPluginProviderInterface;
use Dou\Core\Infra\Plugin\Dto\ConnectCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\ConnectStartRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Google 登录 Provider。
 *
 * 基于 Google OAuth 2.0 / OpenID Connect 协议：
 * - start  跳转 Google 授权页（含账号选择器，一键登录体验）
 * - finish 处理 Google 回跳，用 code 换 access_token 再取用户信息
 *
 * 与微信登录的差异：
 * - Google 用 sub（用户唯一 ID）作为 openid，无 unionid 概念
 * - code 换 token 为 POST 请求（微信为 GET）
 * - 获取用户信息用 Bearer token（微信为 query 参数）
 * - loginIdMode 固定为 'openid'（Google 无 unionid）
 */
class GoogleProvider implements ConnectPluginProviderInterface
{
    /** @var GoogleService */
    private $service;

    /**
     * @param GoogleService $service
     */
    public function __construct(GoogleService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function pluginId()
    {
        return 'google';
    }

    /**
     * @return array
     */
    public function meta()
    {
        return array(
            'name' => 'Google 登录',
            'description' => '接入 Google OAuth 2.0 一键登录，支持 Google 账号快速登录/注册，打通账号体系。',
            'ver' => '1.0',
            'plugin_group' => 'connect',
            'allow_client' => 'ALL',
            'config' => array(
                array(
                    'field' => 'client_id',
                    'name' => 'Client ID',
                    'type' => 'text',
                    'desc' => 'Google OAuth 2.0 客户端 ID（xxxxxxxx.apps.googleusercontent.com），'
                        . '在 Google Cloud Console → API 和服务 → 凭据 → OAuth 2.0 客户端 ID 中创建。'
                        . '创建时需在「已授权的重定向 URI」中添加：'
                        . ROOT_URL . 'index.php?route=plugin/google/finish',
                    'value' => '',
                ),
                array(
                    'field' => 'client_secret',
                    'name' => 'Client Secret',
                    'type' => 'textarea',
                    'desc' => 'Google OAuth 2.0 客户端密钥，与 Client ID 配对使用。'
                        . '在创建 OAuth 2.0 客户端 ID 时生成，注意保密。',
                    'value' => '',
                ),
            ),
        );
    }

    /**
     * @param ConnectStartRequest $request
     * @return string
     */
    public function start(ConnectStartRequest $request)
    {
        return $this->service->start($request);
    }

    /**
     * @param ConnectCallbackPayload $payload
     * @return string
     */
    public function finish(ConnectCallbackPayload $payload)
    {
        return $this->service->finish($payload);
    }
}
