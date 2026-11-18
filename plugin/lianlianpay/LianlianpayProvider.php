<?php

namespace Dou\Plugin\Lianlianpay;

use Dou\Core\Infra\Plugin\Contract\PaymentPluginProviderInterface;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 连连支付 Provider。
 */
class LianlianpayProvider implements PaymentPluginProviderInterface
{
    /** @var LianlianpayService */
    private $service;

    /**
     * @param LianlianpayService $service
     */
    public function __construct(LianlianpayService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function pluginId()
    {
        return 'lianlianpay';
    }

    /**
     * @return array
     */
    public function meta()
    {
        return array(
            'name' => 'lianlianpay',
            'description' => '连连支付。',
            'ver' => '1.0',
            'plugin_group' => 'payment',
            'allow_client' => 'ALL',
            'config' => array(
                array(
                    'field' => 'oid_partner',
                    'name' => '商户编号',
                    'desc' => '商户编号是商户在连连钱包支付平台上开设的商户号码，为18位数字。',
                    'value' => '',
                ),
                array(
                    'field' => 'rsa_private_key',
                    'name' => '商户私钥',
                    'type' => 'textarea',
                    'desc' => '秘钥格式注意不能修改（左对齐，右边有回车符），通过 openssl 工具生成。',
                    'value' => '',
                ),
                array(
                    'field' => 'key',
                    'name' => '安全检验码',
                    'desc' => '手动设置，以数字和字母组成的字符。',
                    'value' => '',
                ),
            ),
        );
    }

    /**
     * @param PaymentRequest $request
     * @return string
     */
    public function start(PaymentRequest $request)
    {
        return $this->service->start($request);
    }

    /**
     * @param PaymentCallbackPayload $payload
     * @return string
     */
    public function notify(PaymentCallbackPayload $payload)
    {
        return $this->service->notify($payload);
    }

    /**
     * @param PaymentCallbackPayload $payload
     * @return string
     */
    public function finish(PaymentCallbackPayload $payload)
    {
        return $this->service->finish($payload);
    }
}
