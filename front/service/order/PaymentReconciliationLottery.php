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

namespace Dou\Front\Service\Order;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\Aftersale\AftersaleAutoFlow;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderScheduledTasks;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台请求兜底对账触发器（适用于装不了 cron 的虚拟主机用户）。
 *
 * 触发策略：
 * - 每次请求按低概率（默认 1%）抽签
 * - 抽中后用文件锁 `flock` 非阻塞抢占，单进程独占执行
 * - 通过 register_shutdown_function 把实际对账动作推到响应输出完成之后，
 *   保证不阻塞用户当前请求
 *
 * 装了 cron 的用户应直接调度 {@see OrderScheduledTasks::reconcilePendingPayments()} 与
 * {@see OrderScheduledTasks::expirePastDuePayments()}，无需依赖本触发器。
 */
class PaymentReconciliationLottery extends BaseService
{
    /** 默认抽签分母：约 1/100 概率命中。 */
    const DEFAULT_LOTTERY_DENOM = 100;

    /** 单次兜底处理上限，避免拖慢宿主请求生命周期。 */
    const DEFAULT_BATCH_LIMIT = 20;

    /** 锁文件名（统一放在 storage/state 目录）。 */
    const LOCK_FILE = 'payment_reconcile.lock';

    /** @var OrderScheduledTasks */
    private $scheduler;

    /** @var AftersaleAutoFlow */
    private $aftersaleAutoFlow;

    /**
     * @param OrderScheduledTasks $scheduler
     * @param AftersaleAutoFlow $aftersaleAutoFlow 售后超时自动流转（与对账并列兜底调度）
     */
    public function __construct(OrderScheduledTasks $scheduler, AftersaleAutoFlow $aftersaleAutoFlow)
    {
        $this->scheduler = $scheduler;
        $this->aftersaleAutoFlow = $aftersaleAutoFlow;
    }

    /**
     * 尝试抽签并安排对账：抽中则注册 shutdown 钩子；否则立刻返回。
     *
     * @return bool true=抽中且已安排；false=未抽中 / 关闭
     */
    public function tryTrigger()
    {
        if (!Config::get('features.order', false)) {
            return false;
        }
        if (Config::get('param.payment_reconcile_lottery_off', false)) {
            return false;
        }

        $denom = (int) Config::get('param.payment_reconcile_lottery_denom', self::DEFAULT_LOTTERY_DENOM);
        if ($denom < 1) {
            $denom = self::DEFAULT_LOTTERY_DENOM;
        }
        if (mt_rand(1, $denom) !== 1) {
            return false;
        }

        $batchLimit = (int) Config::get('param.payment_reconcile_batch_limit', self::DEFAULT_BATCH_LIMIT);
        if ($batchLimit <= 0) {
            $batchLimit = self::DEFAULT_BATCH_LIMIT;
        }

        $scheduler = $this->scheduler;
        $self = $this;
        $hook = function () use ($scheduler, $batchLimit, $self) {
            $self->runReconcileUnderLock($scheduler, $batchLimit);
        };

        if (function_exists('register_shutdown_function')) {
            register_shutdown_function($hook);
        } else {
            $hook();
        }
        return true;
    }

    /**
     * 锁内执行对账动作；上锁失败说明已有其它进程在跑，直接放过。
     *
     * @param OrderScheduledTasks $scheduler
     * @param int $batchLimit
     * @return void
     */
    public function runReconcileUnderLock(OrderScheduledTasks $scheduler, $batchLimit)
    {
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $lockDir = $this->resolveLockDir();
        $lockFile = $lockDir . self::LOCK_FILE;

        $fp = @fopen($lockFile, 'cb');
        if ($fp === false) {
            return;
        }

        try {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                @fclose($fp);
                return;
            }

            try {
                $scheduler->reconcilePendingPayments(array('limit' => $batchLimit));
                $scheduler->expirePastDuePayments(array('limit' => $batchLimit));
                $this->aftersaleAutoFlow->run($batchLimit);
            } catch (\Exception $e) {
                Log::error('payment_reconcile_lottery error: ' . $e->getMessage(), array(
                    'channel' => 'order',
                    'module' => 'reconciliation',
                ));
            }

            @flock($fp, LOCK_UN);
        } catch (\Exception $e) {
            // 任何异常都吃掉，绝不影响主请求
        }

        @fclose($fp);
    }

    /**
     * 锁文件目录：复用站点 storage/state，确保可写；无则创建。
     *
     * @return string 末尾带 DIRECTORY_SEPARATOR
     */
    private function resolveLockDir()
    {
        $dir = defined('STORAGE_PATH') ? (STORAGE_PATH . 'state/') : (sys_get_temp_dir() . DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}
