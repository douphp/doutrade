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

namespace Dou\Core\Service\Coupon;

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 优惠券试算 / 结算可用券视图。
 *
 * 折扣算法与 CheckoutService、我的优惠券、券中心共用，避免多处各算一套。
 */
class CouponQuoteService extends BaseService
{
    /** @var CouponEligibility */
    private $eligibility;

    /**
     * @param CouponEligibility $eligibility
     */
    public function __construct(CouponEligibility $eligibility)
    {
        $this->eligibility = $eligibility;
    }

    /**
     * 计算券对生效范围内金额的抵扣额（percent 按 max_discount 封顶，统一取 2 位）。
     *
     * @param array $coupon
     * @param float $eligibleAmount
     * @return float
     */
    public function discount(array $coupon, $eligibleAmount)
    {
        $eligibleAmount = (float) $eligibleAmount;
        if ($eligibleAmount <= 0) {
            return 0.0;
        }

        $type = isset($coupon['type']) ? (string) $coupon['type'] : 'money';
        if ($type === 'percent') {
            $faceValue = isset($coupon['face_value']) ? (float) $coupon['face_value'] : 0.0;
            $amount = $eligibleAmount * ($faceValue / 100);
            $cap = isset($coupon['max_discount']) ? (float) $coupon['max_discount'] : 0.0;
            if ($cap > 0 && $amount > $cap) {
                $amount = $cap;
            }
        } else {
            $amount = isset($coupon['face_value']) ? (float) $coupon['face_value'] : 0.0;
        }

        if ($amount > $eligibleAmount) {
            $amount = $eligibleAmount;
        }

        return Num::round($amount > 0 ? $amount : 0.0, 2);
    }

    /**
     * 判断券对当前购物车是否可用，并给出预计优惠。
     *
     * @param array $coupon
     * @param int $userId
     * @param array $cartLines
     * @return array usable/reason/reason_text/eligible_amount/discount/discount_format
     */
    public function evaluate(array $coupon, $userId, array $cartLines)
    {
        $result = $this->eligibility->evaluate($coupon, $userId, $cartLines);
        $discount = $result['usable'] ? $this->discount($coupon, $result['eligible_amount']) : 0.0;

        return array(
            'usable' => !empty($result['usable']),
            'reason' => (string) $result['reason'],
            'reason_text' => $result['reason'] === ''
                ? ''
                : lang('coupon_reason_' . $result['reason'], $result['reason']),
            'eligible_amount' => (float) $result['eligible_amount'],
            'discount' => $discount,
            'discount_format' => Util::formatPrice($discount),
        );
    }

    /**
     * 结算页可用券列表（已领取、未使用且对当前购物车可用）。
     *
     * @param int $userId
     * @param array $cartLines
     * @return array
     */
    public function listForCheckout($userId, array $cartLines)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }

        $logs = DB::table('coupon_log')
            ->where('user_id', $userId)
            ->where('status', '0')
            ->order('id DESC')
            ->select();
        if (empty($logs)) {
            return array();
        }

        $couponIds = array();
        foreach ($logs as $log) {
            $couponIds[] = (int) $log['coupon_id'];
        }
        $couponIds = array_values(array_unique($couponIds));

        $rows = DB::table('coupon')->whereIn('id', $couponIds)->select();
        $couponById = array();
        foreach ((array) $rows as $row) {
            $couponById[(int) $row['id']] = $row;
        }

        $list = array();
        $seen = array();
        foreach ($logs as $log) {
            $couponId = (int) $log['coupon_id'];
            if (isset($seen[$couponId]) || !isset($couponById[$couponId])) {
                continue;
            }
            $seen[$couponId] = true;

            $coupon = language()->langBox($couponById[$couponId], 'coupon', 'name, brief');
            $quote = $this->evaluate($coupon, $userId, $cartLines);
            if (!$quote['usable'] || $quote['discount'] <= 0) {
                continue;
            }

            $list[] = array(
                'id' => (int) $coupon['id'],
                'coupon_log_id' => (int) $log['id'],
                'name' => isset($coupon['name']) ? $coupon['name'] : '',
                'type' => isset($coupon['type']) ? $coupon['type'] : 'money',
                'money' => CouponService::faceValueLabel($coupon),
                'condition' => isset($coupon['condition']) ? $coupon['condition'] : 0,
                'condition_text' => CouponService::conditionText($coupon),
                'brief' => isset($coupon['brief']) ? $coupon['brief'] : '',
                'start_at' => !empty($coupon['start_at']) ? date('Y-m-d', strtotime($coupon['start_at'])) : '',
                'end_at' => !empty($coupon['end_at']) ? date('Y-m-d', strtotime($coupon['end_at'])) : '',
                'discount' => $quote['discount'],
                'discount_format' => $quote['discount_format'],
                'usable' => true,
            );
        }

        return $list;
    }
}
