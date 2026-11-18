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

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员统计 / 账户余额服务。
 */
class UserStatsService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 获取积分总额。
     *
     * @param mixed $user_id
     * @return mixed
     */
    public function totalPoint($user_id)
    {
        if (!Config::get('features.point', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        $snapshot = DB::table('user_wallet')->where('id', $user_id)->value('point_balance');
        if ($snapshot !== null && $snapshot !== false) {
            return (int) $snapshot;
        }

        $point = DB::table('point')->where('user_id', $user_id)->order('id DESC')->value('total');

        return $point ? $point : 0;
    }

    /** 可提现额度只来自可识别奖励，充值储值不可提现。 */
    public function withdrawableMoney($user_id)
    {
        $balance = $this->totalMoney($user_id);
        if ($balance === false) {
            return 0.0;
        }
        $rows = (array) DB::table('money')->where('user_id', (int) $user_id)
            ->order('id ASC')->select();
        $funds = \Dou\Core\Service\Wallet\MoneyBalancePolicy::summarize($rows, $balance);
        return $funds['withdrawable'];
    }

    /** 获取可消费总余额（储值和奖励合计）。 */
    public function totalMoney($user_id)
    {
        if (!Config::get('features.money', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        $snapshot = DB::table('user_wallet')->where('id', $user_id)->value('money_balance');
        if ($snapshot !== null && $snapshot !== false) {
            return (float) $snapshot;
        }

        $money = DB::table('money')->where('user_id', $user_id)->order('id DESC')->value('total');

        return $money ? $money : 0;
    }

    /**
     * 获取可用优惠券数量（status=0 且未过期，与会员中心优惠券列表口径一致）。
     *
     * @param mixed $user_id
     * @return mixed
     */
    public function totalCoupon($user_id)
    {
        if (!Config::get('features.coupon', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        $rows = DB::table('coupon_log')
            ->where('user_id', $user_id)
            ->where('status', '0')
            ->field('coupon_id')
            ->select();

        $couponIds = array();
        foreach ((array) $rows as $row) {
            $couponIds[] = (int) $row['coupon_id'];
        }
        if (empty($couponIds)) {
            return 0;
        }

        // 过滤过期券：end_at 为空视为已过期（同 CouponService::ifExpires），SQL 中 NULL 比较不成立即被排除
        return (int) DB::table('coupon')
            ->whereIn('id', $couponIds)
            ->where('end_at', '>', date('Y-m-d H:i:s'))
            ->count();
    }

    /**
     * 获取收藏数量（favorites 行数，与收藏夹列表口径一致）。
     *
     * @param mixed $user_id
     * @return mixed
     */
    public function totalFavorites($user_id)
    {
        if (!Config::get('features.favorites', false)) {
            return false;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        return (int) DB::table('favorites')->where('user_id', $user_id)->count();
    }

    /**
     * 获取累计消费金额。
     *
     * @param mixed $user_id
     * @return mixed
     */
    public function totalConsumption($user_id)
    {
        if (!Config::get('features.order', false)) {
            return 0;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        $total = DB::table('order')
            ->where('user_id', $user_id)
            ->where('status', 'IN', array(OrderStatus::PAID, OrderStatus::COMPLETED))
            ->sum('order_amount');

        return floatval($total);
    }

    /**
     * 获取累计推广收益。
     *
     * @param mixed $user_id
     * @return mixed
     */
    public function totalPromote($user_id)
    {
        if (!Config::get('features.money', false)) {
            return 0;
        }

        $user_id = intval($user_id);
        if (!$user_id) {
            return 0;
        }

        $total = DB::table('money')
            ->where('user_id', $user_id)
            ->where('action', 'IN', array('direct_reward', 'indirect_reward'))
            ->sum('money');

        return floatval($total);
    }

    // -----------------------------------------------------------------------
    // 批量版（消除列表逐行 N+1，聚合保留 DB::；语义与上面单行版一一对应）
    // -----------------------------------------------------------------------

    /**
     * 批量积分总额：user_id => total（取每人最新一条），features.point 关闭返回 false。
     *
     * @param array $userIds
     * @return array|false
     */
    public function pointMap(array $userIds)
    {
        if (!Config::get('features.point', false)) {
            return false;
        }

        return $this->latestTotalMap('point', $userIds);
    }

    /**
     * 批量余额总额：user_id => total（取每人最新一条），features.money 关闭返回 false。
     *
     * @param array $userIds
     * @return array|false
     */
    public function moneyMap(array $userIds)
    {
        if (!Config::get('features.money', false)) {
            return false;
        }

        return $this->latestTotalMap('money', $userIds);
    }

    /**
     * 批量累计消费：user_id => SUM(order_amount)（status IN 1,10），features.order 关闭返回空数组。
     *
     * @param array $userIds
     * @return array
     */
    public function consumptionMap(array $userIds)
    {
        if (!Config::get('features.order', false)) {
            return array();
        }
        $ids = $this->normalizeUserIds($userIds);
        if (empty($ids)) {
            return array();
        }

        $rows = DB::table('order')
            ->whereIn('user_id', $ids)
            ->where('status', 'IN', array(OrderStatus::PAID, OrderStatus::COMPLETED))
            ->field('user_id, order_amount')
            ->select();

        $map = array();
        foreach ((array) $rows as $r) {
            $uid = (int) $r['user_id'];
            $map[$uid] = (isset($map[$uid]) ? $map[$uid] : 0) + floatval($r['order_amount']);
        }

        return $map;
    }

    /**
     * 批量推广收益：user_id => SUM(money)（action IN direct/indirect_reward），features.money 关闭返回空数组。
     *
     * @param array $userIds
     * @return array
     */
    public function promoteMap(array $userIds)
    {
        if (!Config::get('features.money', false)) {
            return array();
        }
        $ids = $this->normalizeUserIds($userIds);
        if (empty($ids)) {
            return array();
        }

        $rows = DB::table('money')
            ->whereIn('user_id', $ids)
            ->where('action', 'IN', array('direct_reward', 'indirect_reward'))
            ->field('user_id, money')
            ->select();

        $map = array();
        foreach ((array) $rows as $r) {
            $uid = (int) $r['user_id'];
            $map[$uid] = (isset($map[$uid]) ? $map[$uid] : 0) + floatval($r['money']);
        }

        return $map;
    }

    /**
     * 取每人最新一条 total（point / money 共用：order id DESC，首次出现即最新）。
     *
     * @param string $table
     * @param array $userIds
     * @return array
     */
    private function latestTotalMap($table, array $userIds)
    {
        $ids = $this->normalizeUserIds($userIds);
        if (empty($ids)) {
            return array();
        }

        $rows = DB::table($table)
            ->whereIn('user_id', $ids)
            ->order('id DESC')
            ->field('user_id, total')
            ->select();

        $map = array();
        foreach ((array) $rows as $r) {
            $uid = (int) $r['user_id'];
            if (!isset($map[$uid])) {
                $map[$uid] = $r['total'] ? $r['total'] : 0;
            }
        }

        return $map;
    }

    /**
     * 归一会员 id 集合：intval、去空、去重。
     *
     * @param array $userIds
     * @return array
     */
    private function normalizeUserIds(array $userIds)
    {
        return array_values(array_unique(array_filter(array_map('intval', $userIds))));
    }
}
