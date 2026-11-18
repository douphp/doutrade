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

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 验证码凭据签名与一次性消费服务。
 *
 * 小程序没有可靠 session，验证码下发接口把 account/ontime/code/type 签名后作为不透明
 * token 交给客户端；登录/注册提交时服务端只信签名内的载荷，避免客户端篡改账号或时间。
 * 消费记录按过期小时存储于共享 state 目录，以 flock 保证同一凭据并发只成功一次。
 * 多实例部署须与限流状态目录一样共享该目录；密钥复用站点 DOU_SHELL。
 */
class VerificationTokenService extends BaseService
{
    /** 默认有效期（秒） */
    const TTL = 300;

    /**
     * 生成签名 token。
     *
     * @param array $payload account / ontime / code / type 等
     * @param int $ttl
     * @return string 空串表示签名载荷无法编码
     */
    public function issue(array $payload, $ttl = self::TTL)
    {
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + min(86400, max(1, (int) $ttl));
        $payload['jti'] = \Dou\Core\Support\Str::randomHex(32);
        if (!isset($payload['purpose'])) {
            $payload['purpose'] = isset($payload['type']) ? (string) $payload['type'] : '';
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return '';
        }

        $body = $this->base64UrlEncode($json);
        $signature = hash_hmac('sha256', $body, $this->secret());

        return $body . '.' . $signature;
    }

    /**
     * 校验 token 并返回签名载荷。
     *
     * @param string $token
     * @return array|null 非法/过期返回 null
     */
    public function verify($token)
    {
        $token = (string) $token;
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $body = $parts[0];
        $signature = $parts[1];
        $expected = hash_hmac('sha256', $body, $this->secret());
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($body);
        if ($json === '') {
            return null;
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= time() || empty($payload['jti']) || !preg_match('/^[a-f0-9]{64}$/', (string) $payload['jti'])) {
            return null;
        }

        return $payload;
    }

    /** 校验账号、用途及验证码并消费；失败次数和消费标记在同一个文件锁内更新。 */
    public function consumeCode($token, $account, $type, $purpose, $code)
    {
        $payload = $this->verify($token);
        if (!is_array($payload) || !isset($payload['account'], $payload['type'], $payload['purpose'], $payload['code'])
            || (string) $payload['account'] !== (string) $account || (string) $payload['type'] !== (string) $type
            || (string) $payload['purpose'] !== (string) $purpose) {
            return false;
        }
        return $this->consumePayload($payload, hash_equals((string) $payload['code'], md5((string) $code . $this->secret())));
    }

    /** 微信手机号等已验证的持有凭据也只能消费一次。 */
    public function consume($token, $purpose)
    {
        $payload = $this->verify($token);
        return is_array($payload) && isset($payload['purpose']) && (string) $payload['purpose'] === (string) $purpose
            && $this->consumePayload($payload, true);
    }

    protected function stateDirectory()
    {
        return STORAGE_PATH . 'state/verification/';
    }

    private function consumePayload(array $payload, $correct)
    {
        $dir = $this->stateDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir . date('YmdH', (int) $payload['exp']) . '.php';
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            return false;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            return false;
        }
        $prefix = "<?php exit; ?>\n";
        $raw = stream_get_contents($handle);
        $states = $raw === '' ? array() : json_decode(substr($raw, strlen($prefix)), true);
        // 存储损坏必须拒绝，不能把已消费的凭据重新当成首次使用。
        if (!is_array($states) || ($raw !== '' && strpos($raw, $prefix) !== 0)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        $key = (string) $payload['jti'];
        $state = isset($states[$key]) ? $states[$key] : array('attempts' => 0, 'used' => false);
        $ok = (int) $payload['exp'] > time() && empty($state['used']) && (int) $state['attempts'] < 3 && $correct;
        $state['attempts'] = (int) $state['attempts'] + 1;
        $state['used'] = !empty($state['used']) || $ok;
        $states[$key] = $state;
        $encoded = $prefix . json_encode($states);
        rewind($handle);
        $written = fwrite($handle, $encoded);
        $saved = $written === strlen($encoded) && ftruncate($handle, strlen($encoded)) && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        // 凭据最多一天有效，只回收已不可能再被使用的整小时状态文件。
        foreach ((array) glob($dir . '*.php') as $oldFile) {
            if (basename($oldFile) < date('YmdH', time() - 172800) . '.php') {
                @unlink($oldFile);
            }
        }
        return $ok && $saved;
    }

    /**
     * @return string
     */
    private function secret()
    {
        return defined('DOU_SHELL') ? (string) DOU_SHELL : '';
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
