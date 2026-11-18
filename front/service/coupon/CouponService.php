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

namespace Dou\Front\Service\Coupon;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Coupon\CouponService as CouponCore;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台优惠券业务
 */
class CouponService extends BaseService
{
    /** @var CouponCore */
    private $coupon;

    /**
     * @param CouponCore $coupon
     */
    public function __construct(CouponCore $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * 领取优惠券（事务 + 券行锁，保证限领与总量不被并发击穿）。
     *
     * @param int $userId
     * @param int $couponId
     * @param string $ip 客户端 IP，入库 coupon_log.ip
     * @return array ok、reason、coupon
     */
    public function claimCoupon($userId, $couponId, $ip = '')
    {
        $userId = (int) $userId;
        $couponId = (int) $couponId;
        if ($userId <= 0 || $couponId <= 0) {
            return array('ok' => false, 'reason' => 'not_found', 'coupon' => array());
        }

        try {
            DB::beginTransaction();

            $coupon = DB::table('coupon')->where('id', $couponId)->lock('FOR UPDATE')->find();
            if (!is_array($coupon)) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'not_found', 'coupon' => array());
            }

            if ((int) $coupon['status'] !== 1) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'disabled', 'coupon' => $coupon);
            }

            $now = time();
            $startTs = Util::toTimestamp(isset($coupon['start_at']) ? $coupon['start_at'] : null);
            $endTs = Util::toTimestamp(isset($coupon['end_at']) ? $coupon['end_at'] : null);
            if ($startTs === null || $startTs > $now) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'not_started', 'coupon' => $coupon);
            }
            if ($endTs === null || $endTs <= $now) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'expired', 'coupon' => $coupon);
            }

            if (!empty($coupon['is_first_order_only']) && DB::tableExist('order')) {
                $hasPaidOrder = DB::table('order')
                    ->where('user_id', $userId)
                    ->where('status', 'IN', array(OrderStatus::PAID, OrderStatus::COMPLETED))
                    ->exists();
                if ($hasPaidOrder) {
                    DB::rollback();

                    return array('ok' => false, 'reason' => 'first_order_only', 'coupon' => $coupon);
                }
            }

            $issued = isset($coupon['issued_quantity']) ? (int) $coupon['issued_quantity'] : 0;
            $total = isset($coupon['total_quantity']) ? (int) $coupon['total_quantity'] : 0;
            if ($total > 0 && $issued >= $total) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'sold_out', 'coupon' => $coupon);
            }

            $limit = isset($coupon['limit_per_user']) ? (int) $coupon['limit_per_user'] : 1;
            if ($limit > 0) {
                $claimed = DB::table('coupon_log')
                    ->where('coupon_id', $couponId)
                    ->where('user_id', $userId)
                    ->count();
                if ($claimed >= $limit) {
                    DB::rollback();

                    return array('ok' => false, 'reason' => 'limit_reached', 'coupon' => $coupon);
                }
            }

            $claimedAt = date('Y-m-d H:i:s');
            DB::table('coupon_log')->insert(array(
                'user_id' => $userId,
                'coupon_id' => $couponId,
                'claimed_at' => $claimedAt,
                'ip' => (string) $ip,
                'created_at' => $claimedAt,
            ));

            $affected = DB::table('coupon')
                ->where('id', $couponId)
                ->data(array('issued_quantity' => $issued + 1))
                ->update();
            if ($affected === false || $affected < 1) {
                DB::rollback();

                return array('ok' => false, 'reason' => 'failed', 'coupon' => $coupon);
            }

            DB::commit();

            return array('ok' => true, 'reason' => '', 'coupon' => $coupon);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('coupon claim error', array(
                'user_id' => $userId,
                'coupon_id' => $couponId,
                'message' => $e->getMessage(),
            ));

            return array('ok' => false, 'reason' => 'failed', 'coupon' => array());
        }
    }

    /**
     * 券中心分页（转发 core 读模型）。
     *
     * @param int $userId
     * @param int|string $page
     * @param string $pageUrl
     * @return array coupon_list、pager
     */
    public function getCouponCenterPage($userId, $page, $pageUrl)
    {
        return $this->coupon->getCouponCenterPage($userId, $page, $pageUrl);
    }

    /**
     * 指定券对当前会员的领取态（用于领券后更新按钮）。
     *
     * @param int $userId
     * @param int $couponId
     * @return array
     */
    public function getCouponState($userId, $couponId)
    {
        $couponId = (int) $couponId;
        foreach ($this->coupon->getCouponList((int) $userId) as $item) {
            if ((int) $item['id'] === $couponId) {
                return $item;
            }
        }

        return array('claimable' => false);
    }

    /**
     * 我的优惠券分页。
     *
     * @param int $userId
     * @param int $page
     * @param string $pageUrl
     * @return array coupon_list、pager
     */
    public function buildCouponListData($userId, $page, $pageUrl)
    {
        $result = DB::table('coupon_log')
            ->where('user_id', (int) $userId)
            ->order('id DESC')
            ->paginate(16, $page, $pageUrl);

        $rows = (array) $result['list'];
        $couponIds = array();
        foreach ($rows as $row) {
            $couponIds[] = (int) $row['coupon_id'];
        }

        $couponById = array();
        if (!empty($couponIds)) {
            $couponRows = DB::table('coupon')->whereIn('id', array_values(array_unique($couponIds)))->select();
            foreach ((array) $couponRows as $couponRow) {
                $couponById[(int) $couponRow['id']] = $couponRow;
            }
        }

        $couponList = array();
        foreach ($rows as $row) {
            $couponId = (int) $row['coupon_id'];
            if (!isset($couponById[$couponId])) {
                continue;
            }

            $coupon = language()->langBox($couponById[$couponId], 'coupon', 'name, brief');
            $scopeType = isset($coupon['scope_type']) ? (string) $coupon['scope_type'] : 'all';
            $couponList[] = array(
                'id' => (int) $row['id'],
                'coupon_id' => $couponId,
                'coupon_log_id' => (int) $row['id'],
                'name' => isset($coupon['name']) ? $coupon['name'] : '',
                'coupon_sn' => isset($coupon['coupon_sn']) ? $coupon['coupon_sn'] : '',
                'type' => lang('coupon_type_' . (isset($coupon['type']) ? $coupon['type'] : 'money')),
                'type_value' => isset($coupon['type']) ? $coupon['type'] : 'money',
                'money' => CouponCore::faceValueLabel($coupon),
                'condition' => isset($coupon['condition']) ? $coupon['condition'] : 0,
                'condition_text' => CouponCore::conditionText($coupon),
                'start_at' => !empty($coupon['start_at']) ? date('Y-m-d', strtotime($coupon['start_at'])) : '',
                'end_at' => !empty($coupon['end_at']) ? date('Y-m-d', strtotime($coupon['end_at'])) : '',
                'brief' => isset($coupon['brief']) ? $coupon['brief'] : '',
                'scope_type' => $scopeType,
                'scope_text' => lang('coupon_scope_' . $scopeType, $scopeType),
                'status' => $this->coupon->statusByLog($coupon, $row),
            );
        }

        return array(
            'coupon_list' => $couponList,
            'pager' => $result['pager'],
        );
    }
}
