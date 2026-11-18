<?php

/* 连连支付通知验签，原生 JSON 解码保留参与签名的原始字段值。 */
require_once __DIR__ . '/llpay_core.function.php';
require_once __DIR__ . '/llpay_md5.function.php';
require_once __DIR__ . '/llpay_rsa.function.php';

class LLpayNotify
{
    public $llpay_config;
    public $notifyResp = array();
    public $result = false;

    public function __construct($llpay_config)
    {
        $this->llpay_config = $llpay_config;
    }

    public function LLpayNotify($llpay_config)
    {
        $this->__construct($llpay_config);
    }

    public function verifyNotify($raw = null)
    {
        $this->result = false;
        $this->notifyResp = array();
        $data = json_decode($raw === null ? file_get_contents('php://input') : $raw, true);
        if (!is_array($data) || empty($this->llpay_config['oid_partner']) || empty($data['sign'])
            || !is_string($data['sign']) || !isset($data['oid_partner'], $data['sign_type'])
            || (string) $data['oid_partner'] !== (string) $this->llpay_config['oid_partner']
            || $data['sign_type'] !== $this->llpay_config['sign_type']) {
            return false;
        }
        foreach ($data as $value) {
            if (!is_scalar($value)) {
                return false;
            }
        }
        if (!$this->getSignVeryfy($data, $data['sign'])) {
            return false;
        }
        $this->notifyResp = $data;
        $this->result = true;
        return true;
    }

    public function verifyReturn($data = null)
    {
        $data = is_array($data) ? $data : $_POST;
        return isset($data['res_data']) && is_string($data['res_data']) && $this->verifyNotify($data['res_data']);
    }

    public function getSignVeryfy($para_temp, $sign)
    {
        $prestr = createLinkstring(argSort(paraFilter($para_temp)));
        switch (strtoupper(trim($this->llpay_config['sign_type']))) {
            case 'MD5':
                return !empty($this->llpay_config['key']) && md5Verify($prestr, $sign, $this->llpay_config['key']);
            case 'RSA':
                return Rsaverify($prestr, $sign);
        }
        return false;
    }
}
