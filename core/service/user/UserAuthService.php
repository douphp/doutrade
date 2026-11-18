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

namespace Dou\Core\Service\User;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class UserAuthService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param array $user
     * @param string $field
     * @param bool $remember
     * @return mixed
     */
    public function login($user, $field, $remember = false)
    {
        $loginCount = $user['login_count'] + 1;

        if ($remember) {
            $this->token($user['id']);
        }

        DB::table('user')->where('id', $user['id'])->data(array(
            'login_count' => $loginCount,
        ))->update();

        $onTime = time();

        if (defined('IS_API')) {
            // API 端凭证：签发不透明随机 token（库存 sha256，明文仅此一次返回）。
            $apiToken = (new ApiTokenService())->issue((int) $user['id']);
            return array(
                'user_id' => $user['id'],
                'token' => $apiToken,
                'field' => $field,
            );
        }

        // 前台 session 路径：与 front/facade/Auth.php 的 session shell 校验公式一致（单字段）。
        $shell = md5($user[$field] . $user['password'] . DOU_SHELL);

        session_regenerate_id(true);
        Session::set('user_id', $user['id']);
        Session::set('shell', $shell);
        Session::set('ontime', $onTime);
        Session::set('field', $field);
        csrf()->generate('static_user');
    }

    /**
     * @param mixed $userId
     * @return bool
     */
    public function token($userId)
    {
        $userId = intval($userId);
        if (!$userId) {
            return false;
        }

        $token = Str::randomHex();
        $tokenHash = hash('sha256', $token);
        $tokenExpire = time() + 30 * 24 * 3600;

        DB::table('user')->where('id', $userId)->data(array(
            'token' => $tokenHash,
            'token_expires_at' => date('Y-m-d H:i:s', $tokenExpire),
        ))->update();

        $this->setCookie(DOU_TOKEN, $token, $tokenExpire, '/', 'Lax');
        return true;
    }

    /**
     * @param mixed $name
     * @param mixed $value
     * @param int $expire
     * @param string $path
     * @param string $samesite
     * @return void
     */
    public function setCookie($name, $value, $expire = 0, $path = '/', $samesite = 'Lax')
    {
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie($name, $value, array(
                'expires' => $expire,
                'path' => $path,
                'domain' => '',
                'secure' => IS_HTTPS,
                'httponly' => true,
                'samesite' => $samesite,
            ));
        } else {
            $pathWithSameSite = $path . '; samesite=' . strtolower($samesite);
            setcookie($name, $value, $expire, $pathWithSameSite, '', IS_HTTPS, true);
        }
    }

    /**
     * @param mixed $name
     * @param string $path
     * @return void
     */
    public function deleteCookie($name, $path = '/')
    {
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            setcookie($name, '', array(
                'expires' => time() - 3600,
                'path' => $path,
                'domain' => '',
                'secure' => IS_HTTPS,
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        } else {
            setcookie($name, '', time() - 3600, $path . '; samesite=lax', '', IS_HTTPS, true);
        }
    }

    /**
     * @param mixed $userId
     * @param int $failLimit
     * @param int $lockSeconds
     * @return void
     */
    public function loginFail($userId, $failLimit = 5, $lockSeconds = 900)
    {
        $userId = intval($userId);
        if (!$userId) {
            return;
        }

        $user = DB::table('user')->where('id', $userId)->find();
        $failCount = intval($user['login_fail_count']);
        // 锁定已过期时从 0 重新计数，避免“解禁后一次输错立即再次锁定”的持续 DoS
        $lockTs = !empty($user['login_locked_at']) ? strtotime((string) $user['login_locked_at']) : false;
        if ($lockTs !== false && $lockTs <= time()) {
            $failCount = 0;
        }
        $failCount++;

        if ($failCount >= $failLimit) {
            DB::table('user')->where('id', $userId)->data(array(
                'login_fail_count' => $failCount,
                // 锁定截止时间 = 当前时间 + 锁定时长；login_locked_at 为 datetime 字段
                'login_locked_at' => date('Y-m-d H:i:s', time() + $lockSeconds),
            ))->update();
            return;
        }

        DB::table('user')->where('id', $userId)->data(array(
            'login_fail_count' => $failCount,
        ))->update();
    }

    /**
     * @param mixed $userId
     * @return void
     */
    public function loginSuccess($userId)
    {
        $userId = intval($userId);
        if (!$userId) {
            return;
        }

        DB::table('user')->where('id', $userId)->data(array(
            'login_fail_count' => 0,
            'login_locked_at' => null,
        ))->update();
    }

    /**
     * @param mixed $userId
     * @return int
     */
    public function isLocked($userId)
    {
        $userId = intval($userId);
        if (!$userId) {
            return 0;
        }

        $lockTime = DB::table('user')->where('id', $userId)->value('login_locked_at');
        $lockTs = $lockTime ? strtotime((string) $lockTime) : false;
        if ($lockTs !== false && $lockTs > time()) {
            return $lockTs - time();
        }

        return 0;
    }

    /**
     * 清除会员的 Web remember-me 凭证（只影响 cookie 登录，不影响 API token）。
     *
     * @param mixed $userId
     * @return void
     */
    public function clearRememberToken($userId)
    {
        $userId = intval($userId);
        if (!$userId) {
            return;
        }

        DB::table('user')->where('id', $userId)->data(array(
            'token' => '',
            'token_expires_at' => null,
        ))->update();
    }

    /**
     * 吊销会员的全部会话凭证：Web remember-me + API token。
     *
     * 用于改密、找回密码、封禁/注销等场景；调用方负责按需清理当前浏览器 cookie。
     *
     * @param mixed $userId
     * @return void
     */
    public function revokeAllSessions($userId)
    {
        $userId = intval($userId);
        if (!$userId) {
            return;
        }

        $this->clearRememberToken($userId);
        (new ApiTokenService())->revokeAllForUser($userId);
    }

    /**
     * 指定 IP 在窗口期内登录失败次数是否达到 $limit。
     *
     * IP 由 shell 层从 Request 取好显式传入，core 层无 HTTP 感知。
     *
     * @param string $ip 来源 IP，由调用方从 Request::ip() 取好后传入
     * @param int $limit
     * @param int $window
     * @return bool
     */
    public function ipRateLimit($ip, $limit = 10, $window = 600)
    {
        $ip = (string) $ip;
        if ($ip === '') {
            return false;
        }
        // user_log.created_at 为 datetime 字符串，必须用同格式比较；
        // 若传 unix 时间戳，MySQL 会把 datetime 转为 YYYYMMDDHHMMSS 数值（恒大于时间戳），窗口条件永远为真
        $since = date('Y-m-d H:i:s', time() - $window);
        $count = DB::table('user_log')
            ->where('ip', $ip)
            ->where('action', UserLogAction::LOGIN_FAIL)
            ->where('created_at', '>', $since)
            ->count();

        return $count >= $limit;
    }

    /**
     * @return array
     */
    public function checkLoginState()
    {
        $userId = (int) Session::get('user_id', 0);
        if ($userId <= 0) {
            return array('msg' => 'fail');
        }
        $shell = (string) Session::get('shell', '');
        if ($shell === '') {
            return array('msg' => 'fail');
        }

        $field = (string) Session::get('field', '');
        if ($field === '') {
            $field = Config::get('param.login_mode') == 'mobile' ? 'mobile' : 'email';
        }
        $user = DB::table('user')->where('id', $userId)->find();
        $checkShell = is_array($user)
            ? hash_equals(md5($user[$field] . $user['password'] . DOU_SHELL), $shell)
            : false;
        if ($checkShell) {
            return array('msg' => 'ok', 'user_id' => $userId);
        }
        return array('msg' => 'fail');
    }
}
