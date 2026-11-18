<?php

namespace Dou\Plugin\Amazon;

use Dou\Core\Infra\Plugin\Contract\ConnectPluginProviderInterface;
use Dou\Core\Infra\Plugin\Dto\ConnectCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\ConnectStartRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 亚马逊账号登录（Login with Amazon）Provider。
 */
class AmazonProvider implements ConnectPluginProviderInterface
{
    /** @var AmazonService */
    private $service;

    /**
     * @param AmazonService $service
     */
    public function __construct(AmazonService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function pluginId()
    {
        return 'amazon';
    }

    /**
     * @return array
     */
    public function meta()
    {
        return array(
            'name' => '亚马逊账号登录',
            'description' => '接入 Login with Amazon，让网站支持亚马逊账号快速登录。需前往 https://developer.amazon.com/loginwithamazon/console/site/lwa/overview.html 注册应用并创建安全配置文件。',
            'ver' => '1.0',
            'plugin_group' => 'connect',
            'allow_client' => 'ALL',
            'config' => array(
                array(
                    'field' => 'client_id',
                    'name' => 'CLIENT ID（客户端编号）',
                    'desc' => '在 Login with Amazon 开发者控制台注册应用后生成的 Client ID。安全配置文件的「Web 设置」中，允许的源填站点地址，允许返回 URL 需登记本站回调地址（index.php?route=plugin/amazon/finish）。',
                    'value' => '',
                ),
                array(
                    'field' => 'client_secret',
                    'name' => 'CLIENT SECRET（客户端密钥）',
                    'desc' => '与 Client ID 配对的 Client Secret，用于服务端以授权码换取访问令牌。',
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
