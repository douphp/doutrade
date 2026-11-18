<?php

namespace Dou\Plugin\Google;

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
 * Google 登录业务服务。
 *
 * 基于 Google OAuth 2.0 / OpenID Connect 协议：
 * - start   生成 state 存 Session，构造 Google 授权页 URL（含账号选择器）
 * - finish  验证 state → code 换 access_token → 获取用户信息 → 调 SnsLoginService 落地
 *
 * 关键约束（来自 Google 官方文档）：
 * - 授权页：GET https://accounts.google.com/o/oauth2/v2/auth
 * - 换 token：POST https://oauth2.googleapis.com/token（form-urlencoded）
 * - 用户信息：GET https://www.googleapis.com/oauth2/v3/userinfo（Bearer token）
 * - scope 用 'openid email profile' 获取 OpenID Connect + 邮箱 + 基本资料
 * - sub 是 Google 用户唯一 ID，作为 openid 存入 user_sns
 * - redirect_uri 必须与 Google Cloud Console 中注册的完全一致
 */
class GoogleService extends BaseService
{
    /** 插件 ID */
    const PLUGIN_ID = 'google';

    /** Google OAuth 授权页端点 */
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Google OAuth Token 端点 */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Google UserInfo 端点 */
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

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
     * 发起授权：生成 state 并返回 Google 授权页 URL。
     *
     * @param ConnectStartRequest $request
     * @return string
     * @throws DomainException 配置缺失时抛出
     */
    public function start(ConnectStartRequest $request)
    {
        unset($request);

        $config = $this->buildConfig();
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new DomainException('Google 登录配置不完整，请联系管理员。', route('user'));
        }

        $state = ConnectState::issue(self::PLUGIN_ID);

        $callbackUrl = ROOT_URL . 'index.php?route=plugin/' . self::PLUGIN_ID . '/finish';

        $params = array(
            'client_id' => $config['client_id'],
            'redirect_uri' => $callbackUrl,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        );

        return self::AUTH_URL . '?' . http_build_query($params, '', '&');
    }

    /**
     * 处理回跳：验证 state → 换 token → 取用户信息 → 调 SnsLoginService 落地。
     *
     * @param ConnectCallbackPayload $payload
     * @return string 跳转 URL
     * @throws DomainException state 不匹配 / 授权失败 / 获取用户信息失败时抛出
     */
    public function finish(ConnectCallbackPayload $payload)
    {
        $query = $payload->getQuery();

        // 验证 state，防 CSRF
        ConnectState::consume(self::PLUGIN_ID, isset($query['state']) ? $query['state'] : null);

        // 用户拒绝授权时 Google 回跳带 error 参数，无 code
        $code = isset($query['code']) && is_string($query['code']) ? $query['code'] : '';
        if ($code === '' || isset($query['error'])) {
            throw new DomainException('Google 授权失败，请重试', route('user'));
        }

        $config = $this->buildConfig();
        if ($config['client_id'] === '' || $config['client_secret'] === '') {
            throw new DomainException('Google 登录配置不完整，请联系管理员。', route('user'));
        }
        $callbackUrl = ROOT_URL . 'index.php?route=plugin/' . self::PLUGIN_ID . '/finish';

        // 用 code 换 access_token
        $tokenInfo = $this->fetchToken($config['client_id'], $config['client_secret'], $code, $callbackUrl);
        if (!is_array($tokenInfo) || empty($tokenInfo['access_token']) || !is_string($tokenInfo['access_token'])) {
            throw new DomainException('无法获取 Google 授权信息，请联系管理员', route('user'));
        }

        // 获取用户信息
        $userInfo = $this->fetchUserinfo($tokenInfo['access_token']);
        if (!is_array($userInfo)) {
            throw new DomainException('无法获取 Google 用户信息，请联系管理员', route('user'));
        }

        // sub 是 Google 用户唯一 ID，作为 openid
        $openid = isset($userInfo['sub']) && is_string($userInfo['sub']) ? $userInfo['sub'] : '';
        if ($openid === '') {
            throw new DomainException('无法获取 Google 用户标识，请联系管理员', route('user'));
        }

        // 昵称过滤非法字符
        $nickname = '';
        if (isset($userInfo['name']) && is_string($userInfo['name']) && !Check::illegalChar($userInfo['name'])) {
            $nickname = $userInfo['name'];
        }

        $sns = array(
            'group' => 'google',
            'apptype' => 'google',
            'openid' => $openid,
            'unionid' => '', // Google 无 unionid 概念
            'nickname' => $nickname,
            'avatar' => isset($userInfo['picture']) && is_string($userInfo['picture']) ? $userInfo['picture'] : '',
            'sex' => 0, // Google userinfo 不返回性别
        );

        // Google 登录走标准绑定流程（nobind=false）：
        // - openid 已关联 → 直接登录
        // - 未关联且未登录 → 跳绑定页（用户可选择注册新号或绑定已有号）
        return $this->coordinator->resolve($sns, $payload->getUserProfile(), 'openid', false);
    }

    /**
     * 用 authorization code 换 access_token。
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param string $code
     * @param string $redirectUri
     * @return array|null
     */
    private function fetchToken($clientId, $clientSecret, $code, $redirectUri)
    {
        $params = array(
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        );

        return ConnectHttp::requestJson('POST', self::TOKEN_URL, http_build_query($params, '', '&'),
            array('Content-Type: application/x-www-form-urlencoded'));
    }

    /**
     * 用 access_token 获取用户信息。
     *
     * @param string $accessToken
     * @return array|null
     */
    private function fetchUserinfo($accessToken)
    {
        return ConnectHttp::requestJson('GET', self::USERINFO_URL, array(), array('Authorization: Bearer ' . $accessToken));
    }

    /**
     * @return array
     */
    private function buildConfig()
    {
        $plugin = plugin()->getWithConfig(self::PLUGIN_ID);
        $config = isset($plugin['config']) && is_array($plugin['config']) ? $plugin['config'] : array();

        return array(
            'client_id' => isset($config['client_id']) ? trim($config['client_id']) : '',
            'client_secret' => isset($config['client_secret']) ? trim($config['client_secret']) : '',
        );
    }
}
