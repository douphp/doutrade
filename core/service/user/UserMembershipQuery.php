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

namespace Dou\Core\Service\User;

use Dou\Core\Domain\Distribution\DistributionStatus;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Domain\Vip\VipStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员扩展身份读模型（VIP / 工作端 / 分销）。
 *
 * 各方法均按对应 features.* 闸做短路返回；调用方无需自行判断模块是否启用。
 */
class UserMembershipQuery extends BaseService
{
    /** @var array 请求级 VIP 状态缓存：user_id => 视图结构 */
    private static $vipCache = array();

    /**
     */
    public function __construct()
    {
    }

    /**
     * 工作端身份详情；未启用或无记录返回 null。
     *
     * @param mixed $user_id
     * @return array|null
     */
    public function work($user_id)
    {
        if (empty(Config::get('features.work', false))) {
            return null;
        }

        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return null;
        }

        $work = DB::table('work')
            ->where('user_id', $user_id)
            ->where('status', 1)
            ->find();

        return is_array($work) && !empty($work) ? $work : null;
    }

    /**
     * VIP 状态详情；未启用 vip 模块或会员无记录返回 false / 空数组。
     *
     * 有效性按「所有 completed 记录的并集」判定：任一记录覆盖当前时间即为生效中；
     * 仅存在未来记录时为待生效；全部过期才为已过期。不能只取 end_at 最大的一条，
     * 否则未来续费记录会掩盖当前空窗或被误判为生效中。
     *
     * @param mixed $user_id
     * @return array|bool
     */
    public function vip($user_id)
    {
        if (!Config::get('features.vip', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        if (array_key_exists($user_id, self::$vipCache)) {
            return self::$vipCache[$user_id];
        }

        // 有效性只认 order_status = completed；不再按 price>0 过滤，
        // 否则后台手动/赠送（price=0）开通的会员会被误判为无效 VIP。
        $rows = DB::table('vip')
            ->where('user_id', $user_id)
            ->where('order_status', OrderStatus::COMPLETED)
            ->order('end_at DESC, id DESC')
            ->select();

        return self::$vipCache[$user_id] = $this->formatVipState((array) $rows);
    }

    /**
     * 批量 VIP 状态：user_id => [text,class,status,state,start_at,end_at]，
     * 未启用 vip 模块返回空数组。语义与 vip() 单行版一致，供列表消除逐行 N+1。
     *
     * @param array $userIds
     * @return array
     */
    public function vipMap(array $userIds)
    {
        if (!Config::get('features.vip', false)) {
            return array();
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (empty($ids)) {
            return array();
        }

        $missing = array();
        foreach ($ids as $id) {
            if (!array_key_exists($id, self::$vipCache)) {
                $missing[] = $id;
            }
        }

        if (!empty($missing)) {
            $rows = DB::table('vip')
                ->whereIn('user_id', $missing)
                ->where('order_status', OrderStatus::COMPLETED)
                ->order('end_at DESC, id DESC')
                ->select();

            $grouped = array();
            foreach ($missing as $id) {
                $grouped[$id] = array();
            }
            foreach ((array) $rows as $vip_log) {
                $uid = isset($vip_log['user_id']) ? (int) $vip_log['user_id'] : 0;
                if (isset($grouped[$uid])) {
                    $grouped[$uid][] = $vip_log;
                }
            }
            foreach ($grouped as $uid => $userRows) {
                self::$vipCache[$uid] = $this->formatVipState($userRows);
            }
        }

        $map = array();
        foreach ($ids as $id) {
            if (isset(self::$vipCache[$id]) && !empty(self::$vipCache[$id])) {
                $map[$id] = self::$vipCache[$id];
            }
        }

        return $map;
    }

    /**
     * 清空 VIP 状态缓存；传 user_id 时只清该会员。
     *
     * @param mixed $user_id
     * @return void
     */
    public static function flush($user_id = null)
    {
        if ($user_id === null) {
            self::$vipCache = array();
            return;
        }

        unset(self::$vipCache[(int) $user_id]);
    }

    /**
     * 把一名会员的全部 completed 记录格式化为模板 / API 统一读取的视图结构。
     *
     * start_at / end_at 是 DATETIME 明文，必须经 Util::toTimestamp 归一化后再判定；
     * 直接与时间戳比较会被 (int) 截成年份（如 2026-10-27 → 2026）。
     *
     * @param array $rows
     * @return array
     */
    private function formatVipState(array $rows)
    {
        if (empty($rows)) {
            return array();
        }

        $now = time();
        $activeRow = null;
        $activeStartTs = 0;
        $activeEndTs = 0;
        $futureStartTs = null;
        $maxEndTs = 0;
        $maxEndStartTs = 0;

        foreach ($rows as $row) {
            $startTs = Util::toTimestamp(isset($row['start_at']) ? $row['start_at'] : null);
            $endTs = Util::toTimestamp(isset($row['end_at']) ? $row['end_at'] : null);
            if ($endTs === null) {
                continue;
            }

            $startTs = $startTs === null ? 0 : (int) $startTs;
            $endTs = (int) $endTs;
            // 结束不晚于开始视为无效区间，与 VipStatus::classify 保持一致。
            if ($startTs > 0 && $startTs >= $endTs) {
                continue;
            }

            if ($endTs > $maxEndTs) {
                $maxEndTs = $endTs;
                $maxEndStartTs = $startTs;
            }

            if ($startTs <= $now && $endTs > $now && $endTs > $activeEndTs) {
                $activeRow = $row;
                $activeStartTs = $startTs;
                $activeEndTs = $endTs;
            }

            if ($startTs > $now && ($futureStartTs === null || $startTs < $futureStartTs)) {
                $futureStartTs = $startTs;
            }
        }

        if ($maxEndTs <= 0) {
            return array();
        }

        if ($activeRow !== null) {
            $state = VipStatus::ACTIVE;
            $status = 'yes';
            $text = lang('vip_viping', 'VIP');
            $class = 'viping';
            $startTs = $activeStartTs;
        } elseif ($futureStartTs !== null) {
            $state = VipStatus::PENDING;
            $status = 'no';
            $text = lang('vip_vipending', 'VIP');
            $class = 'vipend';
            $startTs = $futureStartTs;
        } else {
            $state = VipStatus::EXPIRED;
            $status = 'no';
            $text = lang('vip_vipend', 'VIP');
            $class = 'vipend';
            $startTs = $maxEndStartTs;
        }

        return array(
            'text' => $text,
            'class' => $class,
            'status' => $status,
            'state' => $state,
            'start_at' => $startTs > 0 ? date('Y-m-d', $startTs) : '',
            'end_at' => $maxEndTs > 0 ? date('Y-m-d', $maxEndTs) : '',
        );
    }

    /**
     * 分销身份详情；未启用或会员无记录返回 false。
     *
     * @param mixed $user_id
     * @return array|bool
     */
    public function distribution($user_id)
    {
        if (!Config::get('features.distribution', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        $distribution = DB::table('distribution')
            ->where('user_id', $user_id)
            ->where('status', DistributionStatus::APPROVED)
            ->order('id DESC')
            ->find();

        return $distribution;
    }
}
