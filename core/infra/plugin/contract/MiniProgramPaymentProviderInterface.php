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

namespace Dou\Core\Infra\Plugin\Contract;

use Dou\Core\Infra\Plugin\Dto\MiniProgramPaymentContext;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序支付能力契约（可选实现）。
 *
 * 只有明确声明支持小程序的支付插件才实现本接口；返回的是原生动作描述，
 * 而不是网页 HTML。核心/API 只负责校验、建支付腿与分发。
 */
interface MiniProgramPaymentProviderInterface
{
    /**
     * 小程序发起支付。
     *
     * 返回数组约定：
     *   - type=wxpay    : payment_sn + pay_params（wx.requestPayment 参数）
     *   - type=navigate : payment_sn + page + query（跳转原生页面，如离线付款上传）
     *   - type=paid     : payment_sn（无需再支付）
     *   - type=none     : 无可用动作
     *
     * @param PaymentRequest $request
     * @param MiniProgramPaymentContext $context
     * @return array
     */
    public function startMiniProgram(PaymentRequest $request, MiniProgramPaymentContext $context);
}
