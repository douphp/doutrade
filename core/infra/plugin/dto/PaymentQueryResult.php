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
 * 主动查询支付状态结果 DTO（对账机制使用）。
 *
 * provider 把第三方网关回执规整为下列离散状态：
 * - SUCCEEDED 第三方确认成功，需把本地 payment + order 推进到 paid
 * - PENDING   第三方仍在处理中（用户还在支付页 / 银行处理中等），下次再查
 * - CLOSED    第三方明确说该订单已关闭 / 已退款 / 已撤销，把本地 payment 转 closed
 * - NOT_FOUND 第三方查不到该 out_trade_no（典型情形：本地下单后用户根本没付钱，
 *             第三方未生成订单），可视情况转 closed
 * - FAILED    第三方明确返回失败（验签错误、网关错误等）
 */
class PaymentQueryResult
{
    const SUCCEEDED = 'succeeded';
    const PENDING = 'pending';
    const CLOSED = 'closed';
    const NOT_FOUND = 'not_found';
    const FAILED = 'failed';

    /** @var string */
    private $status;

    /** @var string */
    private $transactionId;

    /** @var string */
    private $message;

    /** @var array 第三方原文（用于审计 / 日志），不强制存储 */
    private $rawData;

    /** @var float|null 网关实际支付金额（订单币口径）；null 表示 provider 未提供 */
    private $paidAmount;

    /** @var string 网关币种；空表示 provider 未提供 */
    private $currency;

    /**
     * @param string $status 见类常量
     * @param string $transactionId 第三方流水号（SUCCEEDED 时必填）
     * @param string $message 辅助说明，FAILED / CLOSED 时可填错误原因
     * @param array $rawData 第三方网关返回的原文
     * @param float|null $paidAmount 网关实际支付金额（订单币口径）
     * @param string $currency 网关币种
     */
    public function __construct($status, $transactionId = '', $message = '', array $rawData = array(), $paidAmount = null, $currency = '')
    {
        $this->status = (string) $status;
        $this->transactionId = (string) $transactionId;
        $this->message = (string) $message;
        $this->rawData = $rawData;
        $this->paidAmount = $paidAmount === null ? null : (float) $paidAmount;
        $this->currency = (string) $currency;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getTransactionId()
    {
        return $this->transactionId;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return array
     */
    public function getRawData()
    {
        return $this->rawData;
    }

    /**
     * 网关实际支付金额（订单币口径）；null 表示 provider 未提供。
     *
     * @return float|null
     */
    public function getPaidAmount()
    {
        return $this->paidAmount;
    }

    /**
     * 网关币种；空表示 provider 未提供。
     *
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * 便捷工厂：成功结果。
     *
     * @param string $transactionId
     * @param array $rawData
     * @param float|null $paidAmount 网关实际支付金额（订单币口径）
     * @param string $currency 网关币种
     * @return self
     */
    public static function succeeded($transactionId, array $rawData = array(), $paidAmount = null, $currency = '')
    {
        return new self(self::SUCCEEDED, $transactionId, '', $rawData, $paidAmount, $currency);
    }

    /**
     * 便捷工厂：仍在处理。
     *
     * @param array $rawData
     * @return self
     */
    public static function pending(array $rawData = array())
    {
        return new self(self::PENDING, '', '', $rawData);
    }

    /**
     * 便捷工厂：第三方已关闭。
     *
     * @param string $message
     * @param array $rawData
     * @return self
     */
    public static function closed($message = '', array $rawData = array())
    {
        return new self(self::CLOSED, '', $message, $rawData);
    }

    /**
     * 便捷工厂：第三方查无此单。
     *
     * @param array $rawData
     * @return self
     */
    public static function notFound(array $rawData = array())
    {
        return new self(self::NOT_FOUND, '', '', $rawData);
    }

    /**
     * 便捷工厂：第三方失败。
     *
     * @param string $message
     * @param array $rawData
     * @return self
     */
    public static function failed($message, array $rawData = array())
    {
        return new self(self::FAILED, '', $message, $rawData);
    }
}
