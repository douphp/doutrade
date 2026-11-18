<?php

/**
 * DouPHP®，基于 MIT 协议，完整声明见根目录 LICENSE。
 */

namespace Dou\Core\Infra\Plugin\Support;

use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Exception\DomainException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/** 第三方授权状态：强随机、十分钟有效、匹配后仅消费一次。 */
class ConnectState
{
    /**
     * 签发一次性授权状态并保存上下文。
     *
     * @param string $pluginId 插件标识
     * @param array $context 授权上下文
     * @return string 授权状态令牌
     */
    public static function issue($pluginId, array $context = array())
    {
        $state = self::token();
        Session::set('connect_state_' . $pluginId, array('value' => $state, 'issued_at' => time(), 'context' => $context));
        return $state;
    }

    /**
     * 生成安全授权令牌，随机源不可用时拒绝授权。
     *
     * @return string 六十四位十六进制令牌
     */
    public static function token()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(32);
        } else {
            $strong = false;
            $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(32, $strong) : false;
            if (!$strong || !is_string($bytes) || strlen($bytes) !== 32) {
                throw new DomainException('无法安全发起授权，请联系管理员', route('user'));
            }
        }

        return bin2hex($bytes);
    }

    /**
     * 校验并消费一次性授权状态，返回签发时保存的上下文。
     *
     * @param string $pluginId 插件标识
     * @param string $receivedState 回调携带的状态令牌
     * @return array 签发时保存的授权上下文
     * @throws DomainException 状态缺失、过期或与下发值不匹配时抛出
     */
    public static function consume($pluginId, $receivedState)
    {
        $key = 'connect_state_' . $pluginId;
        $expected = Session::get($key);
        if (!is_array($expected) || !isset($expected['value'], $expected['issued_at'])
            || !is_string($expected['value']) || !is_int($expected['issued_at'])) {
            throw new DomainException('授权状态无效，请重新发起授权', route('user'));
        }

        $age = time() - $expected['issued_at'];
        if ($age < 0 || $age > 600) {
            Session::del($key);
            throw new DomainException('授权已过期，请重新发起授权', route('user'));
        }
        if (!is_string($receivedState) || $receivedState === '' || !hash_equals($expected['value'], $receivedState)) {
            throw new DomainException('授权状态无效，请重新发起授权', route('user'));
        }

        Session::del($key);
        return isset($expected['context']) && is_array($expected['context']) ? $expected['context'] : array();
    }
}
