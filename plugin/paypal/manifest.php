<?php

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'plugin_group' => 'payment',
    'provider' => 'Dou\\Plugin\\Paypal\\PaypalProvider',
    'currencies' => array('USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'HKD'),
    'currency_field' => 'currency_code',
);
