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
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Dto\PaymentQueryRequest;
use Dou\Core\Infra\Plugin\Dto\PaymentQueryResult;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付对账服务。
 *
 * 触发场景：
 * - cron 周期任务：用户在主机面板/服务器挂 cron，按分钟级定期触发
 * - 前台 lottery 兜底：装不了 cron 的虚拟主机用户走低概率随机触发（带 flock 防并发）
 *
 * 工作原理：
 * - {@see reconcilePendingPayments()} 扫描超过等待窗口（>5 分钟）且未过 24h 的 pending payment，
 *   通过 provider 主动查询第三方状态，按结果推进本地状态机
 * - {@see expirePastDuePayments()} 把 expired_at 已到期且仍 pending 的 payment 关闭，
 *   避免无限 pending 行；offlinepay 因有人工审核流程，永远不被本步关闭
 *
 * 只处理 provider 实现了 query 能力的支付方式（plugin 包安装后由聚合器调度）；
 * offlinepay / cod 及其它未实现 query 的插件统一跳过。
 *
 * plugin 模块为可选安装：支付注册表经 {@see Module::make('plugin.registry_aggregator')} 按需解析，
 * 未安装时 {@see reconcilePendingPayments()} 直接返回空 stats，不阻断订单服务构造。
 */
class OrderReconciliation extends BaseService
{
    /** 默认每批最多处理多少条 pending payment（避免被慢网关拖死一次请求）。 */
    const DEFAULT_BATCH_LIMIT = 50;

    /** 默认等待窗口下限：太短会和正常用户支付时序撞车。 */
    const DEFAULT_MIN_AGE_SECONDS = 300;

    /** 默认等待窗口上限：太长的 pending 一律由 expirePastDuePayments 兜底。 */
    const DEFAULT_MAX_AGE_SECONDS = 86400;

    /** @var PaymentService */
    private $paymentService;

    /**
     * @param PaymentService $paymentService
     */
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * 主动对账：扫 pending payment 调 provider->query()，把结果落回本地状态机。
     *
     * @param array $options 可选：limit / min_age / max_age（秒）。
     * @return array stats: scanned / succeeded / closed / failed / pending / skipped
     */
    public function reconcilePendingPayments(array $options = array())
    {
        $stats = array(
            'scanned' => 0,
            'succeeded' => 0,
            'closed' => 0,
            'failed' => 0,
            'pending' => 0,
            'skipped' => 0,
        );

        $limit = isset($options['limit']) ? (int) $options['limit'] : self::DEFAULT_BATCH_LIMIT;
        $minAge = isset($options['min_age']) ? (int) $options['min_age'] : self::DEFAULT_MIN_AGE_SECONDS;
        $maxAge = isset($options['max_age']) ? (int) $options['max_age'] : self::DEFAULT_MAX_AGE_SECONDS;
        if ($limit <= 0) {
            $limit = self::DEFAULT_BATCH_LIMIT;
        }

        $registry = $this->resolvePaymentRegistry();
        if ($registry === null) {
            return $stats;
        }

        $paymentGroup = $registry->payment();
        if (!is_object($paymentGroup) || !method_exists($paymentGroup, 'provider')) {
            return $stats;
        }

        $now = time();
        $candidates = DB::table('order_payment')
            ->where('status', PaymentStatus::PENDING)
            ->where('created_at', '<', date('Y-m-d H:i:s', $now - $minAge))
            ->where('created_at', '>', date('Y-m-d H:i:s', $now - $maxAge))
            ->order('created_at ASC')
            ->limit($limit)
            ->select();

        foreach ((array) $candidates as $payment) {
            $stats['scanned']++;
            $gateway = isset($payment['gateway']) ? (string) $payment['gateway'] : '';
            $paymentSn = isset($payment['payment_sn']) ? (string) $payment['payment_sn'] : '';
            if ($gateway === '' || $paymentSn === '') {
                $stats['skipped']++;
                continue;
            }

            $provider = $paymentGroup->provider($gateway);
            if (!is_object($provider) || !method_exists($provider, 'query')) {
                $stats['skipped']++;
                continue;
            }

            try {
                $result = $provider->query(new PaymentQueryRequest($gateway, $paymentSn));
            } catch (\Exception $e) {
                $this->logError('reconcile query exception', array(
                    'payment_sn' => $paymentSn,
                    'gateway' => $gateway,
                    'message' => $e->getMessage(),
                ));
                $stats['skipped']++;
                continue;
            }

            if (!$result instanceof PaymentQueryResult) {
                $stats['skipped']++;
                continue;
            }

            $bucket = $this->applyQueryResult($paymentSn, $result);
            if (isset($stats[$bucket])) {
                $stats[$bucket]++;
            } else {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /**
     * 过期关闭：把 expired_at 已到且仍 pending 的 payment 标记为 closed。
     *
     * 永不关闭 offlinepay：离线付款有人工审核流程，pending 期由管理员推进/驳回。
     *
     * @param array $options 可选：limit。
     * @return int 实际关闭的 payment 数量
     */
    public function expirePastDuePayments(array $options = array())
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : self::DEFAULT_BATCH_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_BATCH_LIMIT;
        }

        $now = time();
        $candidates = DB::table('order_payment')
            ->where('status', PaymentStatus::PENDING)
            ->where('expired_at', 'IS NOT NULL')
            ->where('expired_at', '<', date('Y-m-d H:i:s', $now))
            ->where('gateway', '<>', 'offlinepay')
            ->order('expired_at ASC')
            ->limit($limit)
            ->select();

        $closed = 0;
        foreach ((array) $candidates as $payment) {
            $paymentSn = isset($payment['payment_sn']) ? (string) $payment['payment_sn'] : '';
            if ($paymentSn === '') {
                continue;
            }
            if ($this->paymentService->markClosed($paymentSn, 'expired by reconciliation')) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * 把单次 query 结果映射到本地状态机；返回命中的 stats bucket 名。
     *
     * @param string $paymentSn
     * @param PaymentQueryResult $result
     * @return string succeeded|closed|failed|pending|skipped
     */
    private function applyQueryResult($paymentSn, PaymentQueryResult $result)
    {
        $rawData = $result->getRawData();
        $rawCallback = $this->encodeRaw($rawData);

        switch ($result->getStatus()) {
            case PaymentQueryResult::SUCCEEDED:
                $txn = $result->getTransactionId();
                if ($txn === '') {
                    $this->logError('reconcile succeeded missing transaction id', array(
                        'payment_sn' => $paymentSn,
                    ));
                    return 'skipped';
                }
                return $this->paymentService->markSucceeded(
                    $paymentSn,
                    $txn,
                    $rawCallback,
                    $result->getPaidAmount(),
                    $result->getCurrency()
                )
                    ? 'succeeded'
                    : 'skipped';

            case PaymentQueryResult::CLOSED:
            case PaymentQueryResult::NOT_FOUND:
                return $this->paymentService->markClosed($paymentSn, $result->getMessage(), $rawCallback)
                    ? 'closed'
                    : 'skipped';

            case PaymentQueryResult::FAILED:
                return $this->paymentService->markFailed($paymentSn, $result->getMessage(), $rawCallback)
                    ? 'failed'
                    : 'skipped';

            case PaymentQueryResult::PENDING:
                if ($rawCallback !== '') {
                    $this->paymentService->recordCallback($paymentSn, $rawCallback);
                }
                return 'pending';
        }

        return 'skipped';
    }

    /**
     * 把第三方原文格式化为 raw_callback 字符串（JSON 优先 / 序列化兜底）。
     *
     * @param mixed $data
     * @return string
     */
    private function encodeRaw($data)
    {
        if (is_string($data)) {
            return $data;
        }
        if (empty($data)) {
            return '';
        }
        if (function_exists('json_encode')) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            return $encoded === false ? '' : $encoded;
        }
        return serialize($data);
    }

    /**
     * 按需解析 plugin 支付注册表（未安装 plugin 模块时返回 null）。
     *
     * @return object|null
     */
    private function resolvePaymentRegistry()
    {
        $registry = Module::make('plugin.registry_aggregator');
        if (!is_object($registry) || !method_exists($registry, 'payment')) {
            return null;
        }
        return $registry;
    }

    /**
     * @param string $message
     * @param array $payload
     * @return void
     */
    private function logError($message, array $payload = array())
    {
        Log::error($message, array(
            'channel' => 'order',
            'module' => 'reconciliation',
            'payload' => $payload,
        ));
    }
}
