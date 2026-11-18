<?php

/**
 * DouPHP®，基于 MIT 协议，完整声明见根目录 LICENSE。
 */

namespace Dou\Core\Infra\Plugin\Support;

use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/** 登录接口只接受通过证书验证、成功状态的 JSON 响应。 */
class ConnectHttp
{
    /**
     * 发起 HTTPS 请求并仅返回证书校验通过、状态码 2xx 的 JSON 响应。
     *
     * @param string $method HTTP 方法
     * @param string $url 请求地址
     * @param array|string $data 请求数据（GET 时作为 query）
     * @param array $headers 请求头
     * @return array|null 解码后的响应数组；网络、状态码或格式异常时返回 null
     */
    public static function requestJson($method, $url, $data = array(), array $headers = array())
    {
        $response = Client::request($method, $url, $data, $headers, array(
            'verify_ssl' => true,
            'connect_timeout' => 10,
            'timeout' => 30,
            'return_meta' => true,
        ));
        if (!is_array($response) || !empty($response['errno'])
            || !isset($response['http_code'], $response['body'])
            || $response['http_code'] < 200 || $response['http_code'] >= 300 || !is_string($response['body'])) {
            return null;
        }
        $decoded = json_decode($response['body'], true);
        return is_array($decoded) ? $decoded : null;
    }
}
