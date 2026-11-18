<?php

namespace Dou\Plugin\Paypal;

use Dou\Core\Infra\Plugin\Contract\PaymentPluginProviderInterface;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * PayPal Provider。
 */
class PaypalProvider implements PaymentPluginProviderInterface
{
    /** @var PaypalService */
    private $service;

    /**
     * @param PaypalService $service
     */
    public function __construct(PaypalService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function pluginId()
    {
        return 'paypal';
    }

    /**
     * @return array
     */
    public function meta()
    {
        return array(
            'name' => 'paypal',
            'description' => '网上交易时，买家的交易资金直接打入卖家paypal账户。',
            'ver' => '1.0',
            'plugin_group' => 'payment',
            'allow_client' => 'ALL',
            'config' => array(
                array(
                    'field' => 'seller_email',
                    'name' => 'paypal帐户',
                    'desc' => '收款 paypal 账号。',
                    'value' => '',
                ),
                array(
                    'field' => 'currency_code',
                    'name' => '支付货币',
                    'type' => 'select',
                    'option' => array(
                        '美元' => 'USD',
                        '欧元' => 'EUR',
                        '英镑' => 'GBP',
                        '澳元' => 'AUD',
                        '加元' => 'CAD',
                        '日元' => 'JPY',
                        '港元' => 'HKD',
                    ),
                    'value' => 'USD',
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
