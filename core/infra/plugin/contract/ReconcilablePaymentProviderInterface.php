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

use Dou\Core\Infra\Plugin\Dto\PaymentQueryRequest;
use Dou\Core\Infra\Plugin\Dto\PaymentQueryResult;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支持主动对账的支付插件 Provider 契约。
 *
 * 与 {@see PaymentPluginProviderInterface} 的区别：
 * - PaymentPluginProviderInterface 只保证「能发起支付 + 接收异步回调」
 * - ReconcilablePaymentProviderInterface 额外保证「能主动向第三方查询某次支付的真实状态」
 *
 * OrderReconciliation 扫 pending payment 时用 `$provider instanceof
 * ReconcilablePaymentProviderInterface` 检查，不支持对账的插件直接跳过。
 *
 * 实现矩阵（首版）：
 * - alipay  实现（SDK 提供 AlipayTradeQueryRequest）
 * - wxpay   实现（SDK 提供 OrderQuery）
 * - 其它    不实现（offlinepay / cod 语义上不适用；alipaywap/alipayf2f/paypal/
 *           lianlianpay/fomopay/bankpay 后续按需补）
 */
interface ReconcilablePaymentProviderInterface extends PaymentPluginProviderInterface
{
    /**
     * 主动向第三方网关查询某次支付的真实状态。
     *
     * @param PaymentQueryRequest $request
     * @return PaymentQueryResult
     */
    public function query(PaymentQueryRequest $request);
}
