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

namespace Dou\Api\Middleware;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Middleware\AbstractUserAuthMiddleware;
use Dou\Core\Web\Http\ApiResponse;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 端会员认证中间件
 *
 * 共有鉴权骨架（路由段 → UserAuthPolicy 决策 → 三态分支 + work 子策略）由
 * {@see AbstractUserAuthMiddleware} 承载；本类只负责 API 端特有的：
 * - 在 HTTP 边界从 `Authorization: Bearer <token>` 请求头取出 API 会话 token
 *   （经 `Request::bearerToken()` 抽取），显式传给 `auth('api')` guard 解析登录态
 * - 拒绝时直接 send JSON 错误响应（401 未登录 / 403 无 work 身份）后 exit
 */
class UserAuthMiddleware extends AbstractUserAuthMiddleware
{
    /**
     * @return string
     */
    protected function configFile()
    {
        return API_PATH . 'init/middleware.php';
    }

    /**
     * @return array
     */
    protected function resolveContext()
    {
        $auth = auth('api');
        if (!$auth || !method_exists($auth, 'resolveUserContext')) {
            return array('ok' => false);
        }

        return $auth->resolveUserContext((string) request()->bearerToken());
    }

    /**
     * @param array $context
     * @return void
     */
    protected function inject(array $context)
    {
        auth('api')->hydrate($context);
    }

    /**
     * @return bool
     */
    protected function hasWorkIdentity()
    {
        return (int) auth('api')->workId() > 0;
    }

    /**
     * @return void
     */
    protected function rejectUnauthenticated()
    {
        $msg = lang_has('login_timeout') ? (string) lang('login_timeout') : 'login_timeout';
        ApiResponse::error(ApiCodes::UNAUTHORIZED, $msg, array(), 401)->send();
        exit;
    }

    /**
     * @return void
     */
    protected function rejectForbidden()
    {
        $msg = lang_has('work_no_permission') ? (string) lang('work_no_permission') : 'work_no_permission';
        ApiResponse::error(ApiCodes::FORBIDDEN, $msg, array(), 403)->send();
        exit;
    }
}
