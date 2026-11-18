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

namespace Dou\Api\Facade;

use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Auth\GuardContract;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\ApiTokenService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API（小程序）会员 token 鉴权 Guard。
 *
 * 实现 {@see GuardContract}：API 端凭证为「**不透明随机 token**」（登录时签发，
 * 客户端经 `Authorization: Bearer <token>` 请求头回传），由 {@see ApiTokenService}
 * 承载签发 / 解析 / 吊销。**不**实现 {@see \Dou\Core\Foundation\Auth\StatefulGuardContract}（没有
 * session 型的 attempt/login/logout 流程；登录由小程序端发起，API 端只验证已发放凭证）。
 *
 * token 由 HTTP 边界（{@see \Dou\Api\Middleware\UserAuthMiddleware} / controller）
 * 从请求头取出后**显式传入**本类方法；本类不读 Request / 超全局。
 *
 * 业务调用面统一使用 `auth('api')->method()` 完整写法。
 *
 * 业务属性查询（VIP / 工作端 / 分销）由 {@see \Dou\Core\Service\User\UserService::isVip()}
 * / {@see \Dou\Core\Service\User\UserService::isWork()} / {@see \Dou\Core\Service\User\UserService::isDistribution()}
 * 承担；模板/响应聚合由 {@see \Dou\Api\Service\View\UserStateBuilder} 承担，
 * 本类**不**提供 isVip / isWork / isDistribution / buildAuthState 方法。
 */
class Auth extends BaseService implements GuardContract
{
    /** @var int */
    private $userId = 0;

    /** @var array */
    private $userProfile = array();

    /** @var int */
    private $workId = 0;

    /** @var array */
    private $workRow = array();

    /** @var ApiTokenService|null */
    private $apiTokenService = null;

    /**
     */
    public function __construct()
    {
    }

    // ---------------------------------------------------------------------
    // GuardContract 实现
    // ---------------------------------------------------------------------

    /**
     * 当前 API 调用者会员 ID；未鉴权返回 0。
     *
     * @return int
     */
    public function id()
    {
        return $this->userId;
    }

    /**
     * 当前 API 调用者会员资料行；未鉴权返回空数组。
     *
     * @return array
     */
    public function user()
    {
        return $this->userProfile;
    }

    /**
     * 当前请求是否已通过 API 鉴权。
     *
     * @return bool
     */
    public function check()
    {
        return $this->userId > 0;
    }

    /**
     * 当前请求是否未鉴权。
     *
     * @return bool
     */
    public function guest()
    {
        return $this->userId <= 0;
    }

    /**
     * 当前工作端 ID（无工作端身份返回 0）。
     *
     * @return int
     */
    public function workId()
    {
        return $this->workId;
    }

    /**
     * 当前工作端详情数组（无工作端身份返回空数组）。
     *
     * @return array
     */
    public function work()
    {
        return $this->workRow;
    }

    // ---------------------------------------------------------------------
    // 身份注入与重置
    // ---------------------------------------------------------------------

    /**
     * 注入身份上下文。
     *
     * token 只能在 HTTP 边界取得，本类不读 Request；非数组上下文一律按未鉴权处理
     * （正常请求由 {@see \Dou\Api\Middleware\UserAuthMiddleware} 解析后传入数组）。
     *
     * @param array|null $context
     * @return void
     */
    public function hydrate($context = null)
    {
        if (!is_array($context)) {
            $this->reset();
            return;
        }

        $ok = isset($context['ok']) ? (bool) $context['ok'] : false;
        if (!$ok) {
            $this->reset();
            return;
        }

        $this->userId = isset($context['userId']) ? (int) $context['userId'] : 0;
        $this->userProfile = (isset($context['userProfile']) && is_array($context['userProfile']))
            ? $context['userProfile']
            : array();
        $this->workRow = (isset($context['work']) && is_array($context['work']))
            ? $context['work']
            : array();
        $this->workId = isset($context['workId'])
            ? (int) $context['workId']
            : (isset($this->workRow['id']) ? (int) $this->workRow['id'] : 0);
    }

    /**
     * 重置身份缓存。
     *
     * @return void
     */
    public function reset()
    {
        $this->userId = 0;
        $this->userProfile = array();
        $this->workId = 0;
        $this->workRow = array();
    }

    // ---------------------------------------------------------------------
    // 鉴权
    // ---------------------------------------------------------------------

    /**
     * 校验登录凭证 token 是否有效。
     *
     * 该方法是 API 端鉴权的底层入口；token 由 HTTP 边界（中间件 / controller）
     * 经 {@see \Dou\Core\Web\Http\Request::bearerToken()} 从 `Authorization: Bearer`
     * 请求头取出后显式传入。输出按既有形态供 `route=user/check_login_state` 等接口直接吐出。
     *
     * @param string $token 客户端回传的 API 会话 token
     * @return array
     */
    public function checkLoginState($token)
    {
        $data = array('msg' => 'fail');

        $token = (string) $token;
        if ($token === '') {
            $data['reason'] = 'missing_credential';
            return $data;
        }

        $userId = $this->getApiTokenService()->resolve($token);
        if ($userId <= 0) {
            $data['reason'] = 'invalid_credential';
            return $data;
        }

        $user = DB::table('user')->where('id', $userId)->find();
        if (!is_array($user)) {
            $data['reason'] = 'invalid_credential';
            return $data;
        }

        if (UserStatus::normalize(isset($user['status']) ? $user['status'] : '') !== UserStatus::ACTIVE) {
            $this->getApiTokenService()->revoke($token);
            $data['reason'] = 'account_disabled';
            return $data;
        }

        $data['msg'] = 'ok';
        $data['user_id'] = $user['id'];
        if ($user['mobile']) {
            $data['phone'] = 'yes';
        } else {
            $data['phone'] = 'no';
            $data['login_phone'] = (bool) Config::get('param.login_phone', false);
        }

        return $data;
    }

    /**
     * 解析指定 token 的会员上下文，统一供 {@see hydrate()} 与中间件使用。
     *
     * @param string $token 客户端回传的 API 会话 token
     * @return array
     */
    public function resolveUserContext($token)
    {
        $check = $this->checkLoginState($token);
        if (!isset($check['msg']) || $check['msg'] !== 'ok' || empty($check['user_id'])) {
            return $this->emptyContext();
        }

        $userId = (int) $check['user_id'];
        $userProfile = $this->buildUserProfile($userId);
        $workRow = $this->fetchWorkRow($userId);

        return array(
            'ok' => true,
            'userId' => $userId,
            'userProfile' => $userProfile,
            'work' => $workRow,
            'workId' => isset($workRow['id']) ? (int) $workRow['id'] : 0,
        );
    }

    // ---------------------------------------------------------------------
    // 内部辅助
    // ---------------------------------------------------------------------

    /**
     * 构建 API 端轻量级会员资料。
     *
     * @param int $userId
     * @return array
     */
    private function buildUserProfile($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }

        $user = DB::table('user')->where('id', $userId)->find();
        $payload = array('user_id' => $userId);
        if (is_array($user)) {
            $payload['user_sn'] = isset($user['user_sn']) ? $user['user_sn'] : '';
            $payload['user_name'] = isset($user['nickname']) ? $user['nickname'] : '';
            $payload['account'] = isset($user['email']) && $user['email']
                ? $user['email']
                : (isset($user['mobile']) ? $user['mobile'] : '');
        }

        return $payload;
    }

    /**
     * 读取指定会员的工作端详情（无身份返回空数组）。
     *
     * @param int $userId
     * @return array
     */
    private function fetchWorkRow($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }

        $service = $this->getUserService();
        if (!$service) {
            return array();
        }

        $row = $service->work($userId);
        return is_array($row) ? $row : array();
    }

    /**
     * @return array
     */
    private function emptyContext()
    {
        return array(
            'ok' => false,
            'userId' => 0,
            'userProfile' => array(),
            'work' => array(),
            'workId' => 0,
        );
    }

    /**
     * 始终从容器读取最新 user 服务，避免构造时机过早。
     *
     * @return \Dou\Core\Service\User\UserService|null
     */
    private function getUserService()
    {
        return user();
    }

    /**
     * API 会话 token 服务（懒加载）。
     *
     * @return ApiTokenService
     */
    private function getApiTokenService()
    {
        if ($this->apiTokenService === null) {
            $this->apiTokenService = new ApiTokenService();
        }
        return $this->apiTokenService;
    }
}
