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
 * 主动查询支付状态请求 DTO（对账机制使用）。
 *
 * 由 OrderReconciliation 周期性扫 dou_order_payment 中 status=pending 的行，传入本 DTO
 * 调用 provider 的 query()；provider 实现需把 paymentSn 作为 out_trade_no 提交给
 * 第三方网关。
 */
class PaymentQueryRequest
{
    /** @var string */
    private $pluginId;

    /** @var string */
    private $paymentSn;

    /**
     * @param string $pluginId
     * @param string $paymentSn
     */
    public function __construct($pluginId, $paymentSn)
    {
        $this->pluginId = (string) $pluginId;
        $this->paymentSn = (string) $paymentSn;
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
}
