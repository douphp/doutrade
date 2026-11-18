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
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API（小程序）会话 token 服务。
 *
 * 承载 API 端「不透明随机 token」凭证的全生命周期：签发 / 解析 / 吊销。
 * 设计要点：
 * - token 为 64 字符十六进制随机串（256-bit 熵，CSPRNG），仅签发时以明文返回一次；
 * - 库内只存 sha256(token)（user_token.token_hash），泄库不可逆推有效凭证；
 * - 带过期时间（默认 30 天），按行存储 → 支持按设备吊销与「退出所有设备」；
 * - 解析侧 sha256 定位 + hash_equals 复核，恒定时间比较。
 *
 * 调用面：
 * - 签发：{@see \Dou\Core\Service\User\UserAuthService::login()}（IS_API 分支）、
 *   api WeixinController 各登录路径
 * - 解析：{@see \Dou\Api\Facade\Auth::resolveUserContext()}
 * - 吊销：api 登出 / 改密等会话失效场景
 */
class ApiTokenService extends BaseService
{
    /** token 有效期（秒）：30 天，与 remember-me 凭证一致 */
    const TTL = 2592000;

    /**
     * 为指定会员签发新 token。
     *
     * 顺手清理该会员已过期的 token 行，避免表无界膨胀；不吊销其它有效 token
     * （多设备并存）。
     *
     * @param int $userId
     * @param string $ip 客户端 IP，由 shell 层从 Request 取好显式传入（可空）
     * @return string 明文 token（仅此一次可见）；userId 非法时返回空串
     */
    public function issue($userId, $ip = '')
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return '';
        }

        $now = time();
        DB::table('user_token')
            ->where('user_id', $userId)
            ->where('expires_at', '<', date('Y-m-d H:i:s', $now))
            ->delete();

        $token = Str::randomHex(32);
        DB::table('user_token')->data(array(
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', $now + self::TTL),
            'last_active_at' => date('Y-m-d H:i:s', $now),
            'client_ip' => (string) $ip,
            'created_at' => date('Y-m-d H:i:s', $now),
        ))->insert();

        return $token;
    }

    /**
     * 解析 token，返回对应会员 ID。
     *
     * 格式非法 / 未命中 / 已过期一律返回 0；过期行就地删除。
     * 命中后按 60 秒节流刷新 last_active 心跳。
     *
     * @param string $token
     * @return int 会员 ID，无效时 0
     */
    public function resolve($token)
    {
        $token = (string) $token;
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return 0;
        }

        $hash = hash('sha256', $token);
        $row = DB::table('user_token')->where('token_hash', $hash)->find();
        if (!is_array($row)) {
            return 0;
        }
        if (!hash_equals((string) $row['token_hash'], $hash)) {
            return 0;
        }

        $now = time();
        $expiresTs = Util::toTimestamp($row['expires_at']);
        if ($expiresTs === null || $expiresTs <= $now) {
            DB::table('user_token')->where('id', $row['id'])->delete();
            return 0;
        }

        $lastActiveTs = Util::toTimestamp($row['last_active_at']);
        if ($lastActiveTs === null || $now - $lastActiveTs > 60) {
            DB::table('user_token')
                ->where('id', $row['id'])
                ->data(array('last_active_at' => date('Y-m-d H:i:s', $now)))
                ->update();
        }

        return (int) $row['user_id'];
    }

    /**
     * 吊销单个 token（当前设备登出）。
     *
     * @param string $token
     * @return void
     */
    public function revoke($token)
    {
        $token = (string) $token;
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return;
        }

        DB::table('user_token')->where('token_hash', hash('sha256', $token))->delete();
    }

    /**
     * 吊销指定会员的全部 token（退出所有设备 / 改密 / 封禁场景）。
     *
     * @param int $userId
     * @return void
     */
    public function revokeAllForUser($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        DB::table('user_token')->where('user_id', $userId)->delete();
    }
}
