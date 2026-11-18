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

namespace Dou\Core\Service\Order;

use Dou\Core\Domain\Payment\PaymentStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单支付方式展示名解析（admin / front / api 共用）。
 *
 * dou_order.pay_id 存 slug；dou_order_payment.gateway 含 wallet 等非插件标识。
 * 混合支付按成功支付腿组合为「余额支付 + 网关名」。
 */
class OrderPayNameResolver extends BaseService
{
    /** @var string 余额支付腿 gateway，与 PaymentService::markSucceededByWallet 一致 */
    const WALLET_SLUG = 'wallet';

    /**
     * 单条支付腿 gateway → 展示名。
     *
     * @param mixed $gateway
     * @return string
     */
    public function resolveGateway($gateway)
    {
        $gateway = (string) $gateway;
        if ($gateway === '') {
            return '';
        }
        if ($gateway === self::WALLET_SLUG) {
            return lang('order_pay_wallet');
        }
        if (!DB::tableExist('plugin')) {
            return $gateway;
        }

        $name = (string) DB::table('plugin')->where('slug', $gateway)->value('name');

        return $name !== '' ? $name : $gateway;
    }

    /**
     * 订单 pay_id slug → 展示名（无 payment 腿时的 fallback）。
     *
     * @param mixed $payId
     * @return string
     */
    public function resolvePayId($payId)
    {
        return $this->resolveGateway((string) $payId);
    }

    /**
     * 按订单成功支付腿组合展示名；无腿时 fallback 到 pay_id。
     *
     * @param mixed $payId
     * @param int $orderId
     * @return string
     */
    public function resolveForOrder($payId, $orderId)
    {
        $orderId = (int) $orderId;
        if ($orderId > 0) {
            $rows = (array) DB::table('order_payment')
                ->where('order_id', $orderId)
                ->where('status', PaymentStatus::SUCCEEDED)
                ->order('id ASC')
                ->select();
            if (!empty($rows)) {
                $names = array();
                foreach ($rows as $row) {
                    $gateway = isset($row['gateway']) ? (string) $row['gateway'] : '';
                    $name = $this->resolveGateway($gateway);
                    if ($name !== '' && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
                if (!empty($names)) {
                    return implode(' + ', $names);
                }
            }
        }

        return $this->resolvePayId($payId);
    }
}
