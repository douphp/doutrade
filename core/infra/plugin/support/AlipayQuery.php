<?php

/**
 * DouPHP®，基于 MIT 协议，完整声明见根目录 LICENSE。
 */

namespace Dou\Core\Infra\Plugin\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/** 三种支付宝渠道共享查询客户端，避免同一请求加载多个同名 SDK 服务。 */
class AlipayQuery
{
    /**
     * 按渠道加载对应 SDK 并构建查询客户端。
     *
     * @param string $pluginId 支付插件标识（alipay / alipayf2f / alipaywap）
     * @param array $config 插件配置（gatewayUrl / app_id / merchant_private_key / alipay_public_key / charset / sign_type）
     * @return \AopClient 已注入配置的支付宝 SDK 客户端
     */
    public static function client($pluginId, array $config)
    {
        $sdk = PLUGIN_PATH . $pluginId . '/sdk/';
        $classes = array(
            'AopClient' => 'aop/AopClient.php',
            'AlipayTradeQueryRequest' => 'aop/request/AlipayTradeQueryRequest.php',
            'SignData' => 'aop/SignData.php',
            'LtLogger' => 'lotusphp_runtime/Logger/Logger.php',
        );
        foreach ($classes as $class => $file) {
            if (!class_exists($class, false)) {
                require_once $sdk . $file;
            }
        }

        $client = new \AopClient();
        $client->gatewayUrl = $config['gatewayUrl'];
        $client->appId = $config['app_id'];
        $client->rsaPrivateKey = $config['merchant_private_key'];
        $client->alipayrsaPublicKey = $config['alipay_public_key'];
        $client->postCharset = $config['charset'];
        $client->signType = $config['sign_type'];
        return $client;
    }

    /**
     * 按商户订单号查询支付宝交易，返回归一化后的响应数组。
     *
     * @param string $pluginId 支付插件标识
     * @param array $config 插件配置
     * @param string $paymentSn 本地支付流水号（对应 out_trade_no）
     * @return array 支付宝查单响应
     * @throws \RuntimeException 响应结构无效时抛出
     */
    public static function execute($pluginId, array $config, $paymentSn)
    {
        $client = self::client($pluginId, $config);
        $request = new \AlipayTradeQueryRequest();
        $request->setBizContent(json_encode(array('out_trade_no' => $paymentSn)));
        $response = $client->execute($request);
        if (!is_object($response) || !isset($response->alipay_trade_query_response)) {
            throw new \RuntimeException('支付宝查单未返回有效结果');
        }
        $raw = json_decode(json_encode($response->alipay_trade_query_response), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('支付宝查单返回格式无效');
        }
        return $raw;
    }
}
