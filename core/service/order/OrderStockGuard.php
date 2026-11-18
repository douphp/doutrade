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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 库存校验与实时库存计算（库存域）。
 *
 * checkStock 会按 60 秒节流触发 {@see OrderScheduledTasks::runIfDue()} 回收过期订单锁定库存，
 * 再以「stock - 锁定中数量」得到 realTimeStock 供下单链路使用。
 */
class OrderStockGuard extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /** @var OrderScheduledTasks */
    private $scheduledTasks;

    /**
     * @param OrderScheduledTasks $scheduledTasks 校验前需先回收过期订单
     */
    public function __construct(OrderScheduledTasks $scheduledTasks)
    {
        $this->scheduledTasks = $scheduledTasks;
    }

    /**
     * 校验某商品库存是否可下单。
     *
     * - 校验前按 60 秒节流回收过期未付款订单占用
     * - 仅当目标表有 stock 字段时进行库存判断
     * - site.stock 关闭时不卡库存（保留实时库存值供前端展示）
     *
     * @param string $module
     * @param int $item_id
     * @param int $number
     * @return array code/real_time_stock/msg
     */
    public function checkStock($module, $item_id, $number)
    {
        try {
            $module = DB::escapeString($module);
            $item_id = intval($item_id);
            $number = intval($number);

            if (empty($module) || $item_id <= 0 || $number <= 0) {
                return array(
                    'code' => 'invalid_params',
                    'msg' => lang('order_invalid_params'),
                );
            }

            // 校验购买限制前，按 60 秒节流回收过期订单占用（避免每个条目都扫一遍 pending）
            $this->scheduledTasks->runIfDue();

            if (DB::fieldExist($module, 'stock')) {
                $stock = DB::table($module)
                    ->where('id', $item_id)
                    ->value('stock');

                $real_time_stock = 0;
                if ($stock) {
                    $real_time_stock = $this->realTimeStock($module, $item_id);
                }

                if (Config::get('site.stock', false) && $number > $real_time_stock) {
                    return array(
                        'code' => 'out_stock',
                        'real_time_stock' => $real_time_stock,
                        'msg' => lang('order_out_stock') . $real_time_stock,
                    );
                }
            }

            return array(
                'code' => 'without_limit',
                'msg' => '',
            );
        } catch (\Exception $e) {
            $this->logError('check_stock error: ' . $e->getMessage());
            return array(
                'code' => 'error',
                'msg' => lang('order_check_error'),
            );
        }
    }

    /**
     * 实时可售库存：商品 stock 减去 order_item 中 stock_lock=1 的数量之和。
     *
     * @param string $module
     * @param int $item_id
     * @return int >= 0
     */
    public function realTimeStock($module, $item_id, $currentRead = false)
    {
        try {
            $module = DB::escapeString($module);
            $item_id = intval($item_id);

            if (empty($module) || $item_id <= 0) {
                return 0;
            }

            $lockedQuery = DB::table('order_item')
                ->where('module', $module)
                ->where('item_id', $item_id)
                ->where('stock_lock', '1');
            $stockQuery = DB::table($module)->where('id', $item_id);
            if ($currentRead) {
                // 下单已持有商品行锁，必须读当前版本而非事务早期的一致性快照。
                $stockQuery->lock('FOR UPDATE');
                $stock_lock = 0;
                foreach ((array) $lockedQuery->lock('FOR UPDATE')->field('item_number')->select() as $row) {
                    $stock_lock += (int) $row['item_number'];
                }
            } else {
                $stock_lock = $lockedQuery->sum('item_number');
            }

            $stock = $stockQuery->value('stock');

            $real_time_stock = intval($stock) - intval($stock_lock);

            return $real_time_stock > 0 ? $real_time_stock : 0;
        } catch (\Exception $e) {
            $this->logError('real_time_stock error: ' . $e->getMessage());
            return 0;
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
