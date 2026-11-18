<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Core\Infra\Plugin\Dto;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 发起支付请求 DTO。
 *
 * 一次支付尝试对应一行 dou_order_payment 记录；paymentSn 即该行主键（业务流水号），
 * 作为第三方网关的 out_trade_no 传入。orderSn 仅作展示与回查用途。
 */
class PaymentRequest
{
    /** @var string */
    private $pluginId;

    /** @var string */
    private $paymentSn;

    /** @var string */
    private $orderSn;

    /** @var float */
    private $orderAmount;

    /** @var string 订单币种（ISO 4217；空表示插件默认/基准币） */
    private $currencyCode;

    /**
     * @param string $pluginId
     * @param string $paymentSn 支付流水号（dou_order_payment.payment_sn）
     * @param string $orderSn 订单号（dou_order.order_sn）
     * @param mixed $orderAmount 订单币金额
     * @param string $currencyCode 订单币种
     */
    public function __construct($pluginId, $paymentSn, $orderSn, $orderAmount, $currencyCode = '')
    {
        $this->pluginId = (string) $pluginId;
        $this->paymentSn = (string) $paymentSn;
        $this->orderSn = (string) $orderSn;
        $this->orderAmount = (float) $orderAmount;
        $this->currencyCode = strtoupper(trim((string) $currencyCode));
    }

    /**
     * @return string
     */
    public function getPluginId()
    {
        return $this->pluginId;
    }

    /**
     * @return string
     */
    public function getPaymentSn()
    {
        return $this->paymentSn;
    }

    /**
     * @return string
     */
    public function getOrderSn()
    {
        return $this->orderSn;
    }

    /**
     * @return float
     */
    public function getOrderAmount()
    {
        return $this->orderAmount;
    }

    /**
     * 订单币种代码（空表示插件默认币种）。
     *
     * @return string
     */
    public function getCurrencyCode()
    {
        return $this->currencyCode;
    }
}
