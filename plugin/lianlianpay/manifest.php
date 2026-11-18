<?php

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'plugin_group' => 'payment',
    'provider' => 'Dou\\Plugin\\Lianlianpay\\LianlianpayProvider',
    'currencies' => array('CNY'),
);
