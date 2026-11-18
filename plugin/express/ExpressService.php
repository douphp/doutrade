<?php

namespace Dou\Plugin\Express;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 快递配送业务服务（依赖通过 helper / 门面 plugin() 解析）。
 */
class ExpressService extends BaseService
{
    const PLUGIN_ID = 'express';

    /**
     * @return array
     */
    public function methods()
    {
        $plugin = plugin()->getWithConfig(self::PLUGIN_ID);
        $config = (is_array($plugin) && isset($plugin['config']) && is_array($plugin['config'])) ? $plugin['config'] : array();

        return array(
            array(
                'id' => self::PLUGIN_ID,
                'name' => isset($plugin['name']) ? $plugin['name'] : '快递配送',
                'price' => isset($config['fee']) ? (float) $config['fee'] : 0.0,
                'desc' => '快递配送。',
            ),
        );
    }
}
