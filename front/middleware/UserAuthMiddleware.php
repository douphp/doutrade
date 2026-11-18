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

namespace Dou\Front\Middleware;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Middleware\AbstractUserAuthMiddleware;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\HttpResponseException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台会员认证中间件
 *
 * 共有鉴权骨架（路由段 → UserAuthPolicy 决策 → 三态分支 + work 子策略）由
 * {@see AbstractUserAuthMiddleware} 承载；本类只负责前台特有的：
 * - 走 `auth('front')` guard 解析登录态、注入身份缓存
 * - 拒绝时跳登录页（XHR `from=js` 改返 JSON 401 + jump_url）
 */
class UserAuthMiddleware extends AbstractUserAuthMiddleware
{
    /**
     * @return string
     */
    protected function configFile()
    {
        return FRONT_PATH . 'init/middleware.php';
    }

    /**
     * @return array
     */
    protected function resolveContext()
    {
        $auth = auth('front');
        if (!$auth || !method_exists($auth, 'resolveUserContext')) {
            return array('ok' => false);
        }

        return $auth->resolveUserContext();
    }

    /**
     * @param array $context
     * @return void
     */
    protected function inject(array $context)
    {
        auth('front')->hydrate($context);
    }

    /**
     * @return bool
     */
    protected function hasWorkIdentity()
    {
        return (int) auth('front')->workId() > 0;
    }

    /**
     * @return void
     */
    protected function rejectUnauthenticated()
    {
        $message = lang_has('login_timeout') ? (string) lang('login_timeout') : 'Unauthorized';
        $loginUrl = route('user.login');

        if (request()->input('from') === 'js') {
            ApiResponse::throwError(ApiCodes::UNAUTHORIZED, $message, array(), 401, array('jump_url' => $loginUrl));
        }

        $current = HTTP . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $glue = (strpos($loginUrl, '?') !== false) ? '&' : '?';
        throw new HttpResponseException(redirect($loginUrl . $glue . 'redirect=' . urlencode($current)));
    }

    /**
     * @return void
     */
    protected function rejectForbidden()
    {
        throw new HttpResponseException(redirect(route('user')));
    }
}
