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

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单后台/定时任务相关动作（自动评价、自动售后失效、自动取消未付款订单）。
 *
 * 与日常请求解耦的批量动作，通常由后台 cron 或前台请求顺带触发；本类内方法均自带事务与回滚。
 */
class OrderScheduledTasks extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /** @var OrderReconciliation */
    private $reconciliation;

    /** @var OrderCancellation */
    private $cancellation;

    /**
     * @param OrderReconciliation $reconciliation 支付对账服务
     * @param OrderCancellation $cancellation 统一取消服务
     */
    public function __construct(OrderReconciliation $reconciliation, OrderCancellation $cancellation)
    {
        $this->reconciliation = $reconciliation;
        $this->cancellation = $cancellation;
    }

    /**
     * 主动对账：包装 {@see OrderReconciliation::reconcilePendingPayments()}，
     * 给 cron 调度入口和 lottery 兜底入口共用。
     *
     * @param array $options 透传给 OrderReconciliation
     * @return array stats
     */
    public function reconcilePendingPayments(array $options = array())
    {
        if (!DB::tableExist('order_payment')) {
            return array();
        }
        try {
            return $this->reconciliation->reconcilePendingPayments($options);
        } catch (\Exception $e) {
            $this->logError('reconcile_pending_payments error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 关闭超期 pending payment：包装 {@see OrderReconciliation::expirePastDuePayments()}。
     *
     * @param array $options
     * @return int closed count
     */
    public function expirePastDuePayments(array $options = array())
    {
        if (!DB::tableExist('order_payment')) {
            return 0;
        }
        try {
            return $this->reconciliation->expirePastDuePayments($options);
        } catch (\Exception $e) {
            $this->logError('expire_past_due_payments error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 超过评价时限的订单自动评价（含批量自动好评）。
     *
     * @param array $where_conditions 附加过滤条件（field => value）
     * @return bool 是否有更新发生
     */
    public function autoUpdateComment($where_conditions = array())
    {
        if (!Config::get('features.comment', false)) {
            return false;
        }

        try {
            $timeout = (Config::has('param.order_allow_comment_time')
                ? intval(Config::get('param.order_allow_comment_time'))
                : 168) * 3600;
            $time_limit = date('Y-m-d H:i:s', time() - $timeout);

            $query_obj = DB::table('order')
                ->where('status', OrderStatus::COMPLETED)
                ->where('allow_comment', '1')
                ->whereIn('module', array('product', 'item', 'equipment', 'course', 'download'))
                ->where('shipped_at', 'IS NOT NULL')
                ->where('shipped_at', '<', $time_limit)
                ->limit(200);

            if (is_array($where_conditions) && !empty($where_conditions)) {
                foreach ($where_conditions as $field => $value) {
                    if ((string) $field === 'order_id') {
                        $field = 'id';
                    }
                    $query_obj->where($field, $value);
                }
            }

            $query = $query_obj->select();
            $updated = false;

            DB::beginTransaction();

            foreach ((array) $query as $row) {
                $orderId = isset($row['id']) ? (int) $row['id'] : 0;
                if ($orderId <= 0) {
                    continue;
                }

                // 先生成默认好评，再标记条目已评论；顺序反了会永远查不到 comment=0 的条目
                $this->autoGoodComment($orderId);

                DB::table('order')
                    ->where('id', $orderId)
                    ->data(array('allow_comment' => '0'))
                    ->update();

                DB::table('order_item')
                    ->where('order_id', $orderId)
                    ->data(array('comment' => '1'))
                    ->update();

                $updated = true;
            }

            DB::commit();
            return $updated;
        } catch (\Exception $e) {
            try {
                DB::rollback();
            } catch (\Exception $rollback_e) {
                $this->logError('Rollback failed in auto_update_comment: ' . $rollback_e->getMessage());
            }

            $this->logError('auto_update_comment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 超过售后时限的订单自动取消「可申请售后」标记。
     *
     * @param array $where_conditions 附加过滤条件
     * @return bool
     */
    public function autoUpdateAftersaleStatus($where_conditions = array())
    {
        if (!Config::get('features.aftersale', false)) {
            return false;
        }

        try {
            $timeout = (Config::has('param.order_allow_aftersale_time')
                ? intval(Config::get('param.order_allow_aftersale_time'))
                : 168) * 3600;
            $time_limit = date('Y-m-d H:i:s', time() - $timeout);

            $query_obj = DB::table('order')
                ->where('status', 'IN', array(OrderStatus::COMPLETED, OrderStatus::PARTIAL_REFUNDED))
                ->where('allow_aftersale', '1')
                ->where('shipped_at', 'IS NOT NULL')
                ->where('shipped_at', '<', $time_limit)
                ->limit(200);

            if (is_array($where_conditions) && !empty($where_conditions)) {
                foreach ($where_conditions as $field => $value) {
                    if ((string) $field === 'order_id') {
                        $field = 'id';
                    }
                    $query_obj->where($field, $value);
                }
            }

            return $query_obj->data(array('allow_aftersale' => '0'))->update() !== false;
        } catch (\Exception $e) {
            $this->logError('auto_update_aftersale_status error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 自动取消未付款超时订单（同步释放库存、优惠券、钱包腿与 pending 支付）。
     *
     * @param array $where_conditions 附加过滤条件
     * @return bool
     */
    public function autoCancelOrder($where_conditions = array())
    {
        try {
            // 与收银台、支付单过期时间共用同一口径：配置 <= 0 时按默认 60 分钟
            $timeoutMinutes = OrderCheckoutOptions::payTimeoutMinutes();
            $timeout = $timeoutMinutes * 60;
            $time_limit = date('Y-m-d H:i:s', time() - $timeout);

            $query_obj = DB::table('order')
                ->where('status', OrderStatus::PENDING)
                ->where('created_at', '<', $time_limit)
                ->limit(200);

            if (is_array($where_conditions) && !empty($where_conditions)) {
                foreach ($where_conditions as $field => $value) {
                    if ((string) $field === 'order_id') {
                        $field = 'id';
                    }
                    $query_obj->where($field, $value);
                }
            }

            $query = $query_obj->select();
            $cancelled = false;

            foreach ((array) $query as $row) {
                $orderSn = isset($row['order_sn']) ? (string) $row['order_sn'] : '';
                if ($orderSn === '') {
                    continue;
                }
                if ($this->cancellation->cancel($orderSn, 'auto cancelled (timeout)', 'system', 0, '', array(OrderStatus::PENDING))) {
                    $cancelled = true;
                }
            }

            return $cancelled;
        } catch (\Exception $e) {
            $this->logError('auto_cancel_order error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 节流执行自动任务：同一进程只跑一次，跨进程 60 秒内最多跑一次。
     *
     * @return bool 是否实际执行
     */
    public function runIfDue()
    {
        static $ran = false;
        if ($ran) {
            return false;
        }
        $ran = true;

        $dir = defined('STORAGE_PATH') ? (STORAGE_PATH . 'state/') : (sys_get_temp_dir() . DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $lockFile = $dir . 'order_auto_tasks.lock';
        $fp = @fopen($lockFile, 'cb');
        if ($fp === false) {
            $this->runAutoTasks();
            return true;
        }

        try {
            if (!@flock($fp, LOCK_EX | LOCK_NB)) {
                return false;
            }

            $mtime = @filemtime($lockFile);
            if ($mtime && time() - $mtime < 60) {
                @flock($fp, LOCK_UN);
                return false;
            }
            @touch($lockFile);

            $this->runAutoTasks();
            @flock($fp, LOCK_UN);
            return true;
        } catch (\Exception $e) {
            $this->logError('order auto tasks error: ' . $e->getMessage());
            return false;
        } finally {
            @fclose($fp);
        }
    }

    /**
     * 依次执行三条自动任务。
     *
     * @return void
     */
    private function runAutoTasks()
    {
        $this->autoCancelOrder();
        $this->autoUpdateAftersaleStatus();
        $this->autoUpdateComment();
    }

    /**
     * 为指定订单的未评论项写入默认 5 星好评。
     *
     * 系统自动生成的匿名评价，不记录人工 IP；ip 字段留空，与 anonymous=1 语义保持一致。
     *
     * @param int $order_id
     * @return void
     */
    private function autoGoodComment($order_id)
    {
        try {
            $created_at = date('Y-m-d H:i:s');
            $content = lang('comment_content_default');

            $query_item = DB::table('order_item')
                ->where('order_id', $order_id)
                ->where('comment', '0')
                ->select();

            foreach ($query_item as $row) {
                $exists = DB::table('comment')
                    ->where('order_item_id', $row['id'])
                    ->exists();

                if (!$exists) {
                    DB::table('comment')->data(array(
                        'user_id' => $row['user_id'],
                        'order_item_id' => $row['id'],
                        'module' => $row['module'],
                        'item_id' => $row['item_id'],
                        'content' => $content,
                        'star' => '5',
                        'anonymous' => '1',
                        'ip' => '',
                        'created_at' => $created_at,
                    ))->insert();
                }
            }
        } catch (\Exception $e) {
            $this->logError('auto_good_comment error: ' . $e->getMessage(), array('order_id' => $order_id));
        }
    }

    /**
     * 记录错误：写入实例日志数组，同时投递到 Log::error（channel=order）。
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    private function logError($message, $data = array())
    {
        $this->error_log[] = array(
            'time' => time(),
            'message' => $message,
            'data' => $data,
        );

        Log::error($message, array(
            'channel' => 'order',
            'module' => 'order',
            'payload' => is_array($data) ? $data : array(),
        ));
    }
}
