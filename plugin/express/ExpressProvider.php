<?php

namespace Dou\Plugin\Express;

use Dou\Core\Infra\Plugin\Contract\ShippingPluginProviderInterface;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 快递配送 Provider。
 */
class ExpressProvider implements ShippingPluginProviderInterface
{
    /** @var ExpressService */
    private $service;

    /**
     * @param ExpressService $service
     */
    public function __construct(ExpressService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function pluginId()
    {
        return 'express';
    }

    /**
     * @return array
     */
    public function meta()
    {
        return array(
            'name' => '快递配送',
            'description' => '速度快，价格实惠，超重不加价。',
            'ver' => '1.0',
            'plugin_group' => 'shipping',
            'allow_client' => 'ALL',
            'config' => array(
                array(
                    'field' => 'fee',
                    'name' => '配送费用',
                    'desc' => '如果无需运费，则留空或为零。',
                    'value' => 10,
                ),
                array(
                    'field' => 'free',
                    'name' => '满多少钱包邮',
                    'desc' => '设置用户买满多少钱后包邮，如果不设定则留空或为零。',
                    'value' => 0,
                ),
            ),
        );
    }

    /**
     * @return array
     */
    public function methods()
    {
        return $this->service->methods();
    }
}
