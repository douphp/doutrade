<?php

namespace Dou\Plugin\Amazon;

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Plugin\Dto\ConnectCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\ConnectStartRequest;
use Dou\Core\Infra\Plugin\Support\ConnectHttp;
use Dou\Core\Infra\Plugin\Support\ConnectState;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\SnsLoginService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 亚马逊账号登录业务服务（Login with Amazon，OAuth2 授权码流程）。
 *
 * 与微信/QQ 登录一致，走标准服务端授权码流程：
 *   start()  -> 跳转亚马逊授权页（response_type=code）
 *   finish() -> 用授权码换取访问令牌，拉取用户资料，交由 SnsLoginService 统一落地
 * 依赖通过 helper / 门面 plugin() 解析。
 */
class AmazonService extends BaseService
{
    const PLUGIN_ID = 'amazon';

    /** @var SnsLoginService */
    private $coordinator;

    /**
     * @param SnsLoginService $coordinator
     */
    public function __construct(SnsLoginService $coordinator)
    {
        $this->coordinator = $coordinator;
    }

    /**
     * @param ConnectStartRequest $request
     * @return string
     */
    public function start(ConnectStartRequest $request)
    {
        unset($request);

        $config = $this->buildConfig();
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new DomainException('亚马逊登录配置不完整，请联系管理员。', route('user'));
        }

        $state = ConnectState::issue(self::PLUGIN_ID);

        $params = array(
            'client_id' => $config['client_id'],
            'scope' => 'profile',
            'response_type' => 'code',
            'redirect_uri' => $this->callbackUrl(),
            'state' => $state,
        );

        return 'https://www.amazon.com/ap/oa?' . http_build_query($params);
    }

    /**
     * @param ConnectCallbackPayload $payload
     * @return string
     */
    public function finish(ConnectCallbackPayload $payload)
    {
        $query = $payload->getQuery();

        ConnectState::consume(self::PLUGIN_ID, isset($query['state']) ? $query['state'] : null);

        $code = isset($query['code']) && is_string($query['code']) ? $query['code'] : '';
        if ($code === '' || isset($query['error'])) {
            throw new DomainException('无法获取亚马逊授权信息，请联系管理员', route('user'));
        }

        $config = $this->buildConfig();
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new DomainException('亚马逊登录配置不完整，请联系管理员。', route('user'));
        }

        $tokenInfo = $this->exchangeCode($code, $config['client_id'], $config['client_secret']);
        $accessToken = (is_array($tokenInfo) && isset($tokenInfo['access_token']) && is_string($tokenInfo['access_token'])) ? $tokenInfo['access_token'] : '';
        if ($accessToken === '') {
            throw new DomainException('无法获取亚马逊访问令牌，请联系管理员', route('user'));
        }

        $profile = $this->fetchProfile($accessToken);
        if (!is_array($profile)) {
            $profile = array();
        }

        $openid = isset($profile['user_id']) && is_string($profile['user_id']) ? $profile['user_id'] : '';
        if ($openid === '') {
            throw new DomainException('无法获取亚马逊用户资料，请联系管理员', route('user'));
        }

        $nickname = '';
        if (isset($profile['name']) && is_string($profile['name']) && !Check::illegalChar($profile['name'])) {
            $nickname = $profile['name'];
        }

        $sns = array(
            'group' => self::PLUGIN_ID,
            'apptype' => self::PLUGIN_ID,
            'openid' => $openid,
            'unionid' => '',
            'nickname' => $nickname,
            'avatar' => '',
            'sex' => 1,
        );

        // 亚马逊登录沿用旧插件语义：首次登录无需绑定站内账号，直接自动注册。
        return $this->coordinator->resolve($sns, $payload->getUserProfile(), 'openid', true);
    }

    /**
     * @return string
     */
    private function callbackUrl()
    {
        return ROOT_URL . 'index.php?route=plugin/' . self::PLUGIN_ID . '/finish';
    }

    /**
     * @return array
     */
    private function buildConfig()
    {
        $plugin = plugin()->getWithConfig(self::PLUGIN_ID);
        $config = (is_array($plugin) && isset($plugin['config']) && is_array($plugin['config'])) ? $plugin['config'] : array();

        return array(
            'client_id' => isset($config['client_id']) ? (string) $config['client_id'] : '',
            'client_secret' => isset($config['client_secret']) ? (string) $config['client_secret'] : '',
        );
    }

    /**
     * 以授权码换取访问令牌。
     *
     * @param string $code
     * @param string $clientId
     * @param string $clientSecret
     * @return array|null
     */
    private function exchangeCode($code, $clientId, $clientSecret)
    {
        $fields = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->callbackUrl(),
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        );

        return ConnectHttp::requestJson('POST', 'https://api.amazon.com/auth/o2/token', http_build_query($fields),
            array('Content-Type: application/x-www-form-urlencoded'));
    }

    /**
     * 拉取亚马逊用户资料（user_id / name / email）。
     *
     * @param string $accessToken
     * @return array|null
     */
    private function fetchProfile($accessToken)
    {
        return ConnectHttp::requestJson('GET', 'https://api.amazon.com/user/profile', array(),
            array('Authorization: Bearer ' . $accessToken));
    }
}
