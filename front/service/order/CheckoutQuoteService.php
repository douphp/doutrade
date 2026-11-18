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

namespace Dou\Front\Service\Order;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 结算报价单签名服务（立即购买 / 积分 / 套餐）。
 *
 * 报价单只承载「买什么」与报价时的商品金额，不承载价格信任：预览与下单仍按商品表现价重算。
 * 签名复用 VerificationTokenService 的 HMAC-SHA256 + base64url 方案（密钥 DOU_SHELL），
 * 不新增表，PHP 5.6+ 可用；nonce 同时作为提交幂等键。
 */
class CheckoutQuoteService extends BaseService
{
    /** 默认有效期（秒） */
    const TTL = 1800;

    /**
     * 生成签名报价单。
     *
     * @param array $payload uid/module/item_id/item_number/attribute/mode/quote_amount
     * @param int $ttl
     * @return string 空串表示密钥缺失或载荷无法编码
     */
    public function issue(array $payload, $ttl = self::TTL)
    {
        $secret = $this->secret();
        if ($secret === '') {
            return '';
        }

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + max(1, (int) $ttl);
        if (empty($payload['nonce'])) {
            $payload['nonce'] = $this->nonce();
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }

        $body = $this->base64UrlEncode($json);

        return $body . '.' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * 校验签名与有效期，返回载荷。
     *
     * @param string $token
     * @return array|null
     */
    public function verify($token)
    {
        $token = (string) $token;
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        $secret = $this->secret();
        if ($secret === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $parts[0], $secret);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }

        $json = $this->base64UrlDecode($parts[0]);
        if ($json === '') {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= time()) {
            return null;
        }

        return $payload;
    }

    /**
     * 校验报价单并绑定当前会员。
     *
     * @param string $token
     * @param int $userId
     * @return array|null
     */
    public function verifyForUser($token, $userId)
    {
        $payload = $this->verify($token);
        if (!$payload) {
            return null;
        }

        $uid = isset($payload['uid']) ? (int) $payload['uid'] : 0;
        if ($uid <= 0 || $uid !== (int) $userId) {
            return null;
        }

        return $payload;
    }

    /**
     * @return string
     */
    private function secret()
    {
        return defined('DOU_SHELL') ? (string) DOU_SHELL : '';
    }

    /**
     * @return string
     */
    private function nonce()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(8));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(8));
        }

        return md5(uniqid((string) mt_rand(), true));
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64UrlDecode($value)
    {
        $value = strtr((string) $value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : '';
    }
}
