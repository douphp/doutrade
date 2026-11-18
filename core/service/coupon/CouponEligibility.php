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

use Dou\Core\Domain\Order\OrderPolicy;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 优惠券使用资格谓词。
 *
 * 依据 dou_coupon 的 scope_type / scope_value / is_first_order_only / condition
 * 判断某券对某会员的某次购物车是否可用，并计算「生效范围内金额」。
 *
 * 购物车 $cart 约定为行数组，每行至少含：
 *   - module   string  所属模块（如 product）
 *   - category_id   int     分类 ID
 *   - item_id  int     商品 ID
 *   - amount   float   该行金额
 */
class CouponEligibility extends BaseService
{
    public function __construct()
    {
    }

    /**
     * 判断券是否可用于该会员的该购物车。
     *
     * @param array $coupon dou_coupon 行
     * @param int $userId
     * @param array $cart 购物车行集合
     * @return bool
     */
    public function canUse($coupon, $userId, array $cart)
    {
        $result = $this->evaluate($coupon, $userId, $cart);

        return !empty($result['usable']);
    }

    /**
     * 判断券是否可用并返回不可用原因。
     *
     * reason 取值：not_found / disabled / not_started / expired /
     * first_order_only / out_of_scope / below_condition。
     *
     * @param array $coupon dou_coupon 行
     * @param int $userId
     * @param array $cart 购物车行集合
     * @return array usable、reason、eligible_amount
     */
    public function evaluate($coupon, $userId, array $cart)
    {
        $result = array(
            'usable' => false,
            'reason' => '',
            'eligible_amount' => 0.0,
        );

        if (!is_array($coupon) || empty($coupon)) {
            $result['reason'] = 'not_found';

            return $result;
        }

        $status = isset($coupon['status']) ? (int) $coupon['status'] : 0;
        if ($status !== 1) {
            $result['reason'] = 'disabled';

            return $result;
        }

        $time = time();
        $startTs = Util::toTimestamp(isset($coupon['start_at']) ? $coupon['start_at'] : null);
        $endTs = Util::toTimestamp(isset($coupon['end_at']) ? $coupon['end_at'] : null);
        if ($startTs === null || $startTs > $time) {
            $result['reason'] = 'not_started';

            return $result;
        }
        if ($endTs === null || $endTs <= $time) {
            $result['reason'] = 'expired';

            return $result;
        }

        if (!empty($coupon['is_first_order_only']) && !$this->isFirstOrder((int) $userId)) {
            $result['reason'] = 'first_order_only';

            return $result;
        }

        $eligibleAmount = $this->eligibleAmount($coupon, $cart);
        $result['eligible_amount'] = $eligibleAmount;
        if ($eligibleAmount <= 0) {
            $result['reason'] = 'out_of_scope';

            return $result;
        }

        $condition = isset($coupon['condition']) ? (float) $coupon['condition'] : 0.0;
        if ($eligibleAmount < $condition) {
            $result['reason'] = 'below_condition';

            return $result;
        }

        $result['usable'] = true;

        return $result;
    }

    /**
     * 券范围是否命中商品上下文（商品页 / 券中心按商品过滤）。
     *
     * $context 支持 module、category_ids（含祖先）、item_id。
     *
     * @param array $coupon
     * @param array $context
     * @return bool
     */
    public function scopeMatches($coupon, array $context)
    {
        if (!is_array($coupon) || empty($coupon)) {
            return false;
        }

        $scopeType = isset($coupon['scope_type']) ? (string) $coupon['scope_type'] : 'all';
        if ($scopeType === '' || $scopeType === 'all') {
            return true;
        }

        $scopeValue = $this->decodeScopeValue(isset($coupon['scope_value']) ? $coupon['scope_value'] : '');
        if (empty($scopeValue)) {
            return false;
        }

        return $this->lineInScope($scopeType, $scopeValue, array(
            'module' => isset($context['module']) ? (string) $context['module'] : '',
            'category_ids' => isset($context['category_ids']) ? (array) $context['category_ids'] : array(),
            'item_id' => isset($context['item_id']) ? (int) $context['item_id'] : 0,
        ));
    }

    /**
     * 计算购物车中落在券生效范围内的金额合计。
     *
     * @param array $coupon dou_coupon 行
     * @param array $cart 购物车行集合
     * @return float
     */
    public function eligibleAmount($coupon, array $cart)
    {
        $scopeType = isset($coupon['scope_type']) ? (string) $coupon['scope_type'] : 'all';
        $scopeValue = $this->decodeScopeValue(isset($coupon['scope_value']) ? $coupon['scope_value'] : '');

        $total = 0.0;
        foreach ($cart as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!OrderPolicy::canUseCoupon(isset($line['module']) ? $line['module'] : '')) {
                continue;
            }
            if ($this->lineInScope($scopeType, $scopeValue, $line)) {
                $total += isset($line['amount']) ? (float) $line['amount'] : 0.0;
            }
        }

        return Num::round($total, 2);
    }

    /**
     * 单行是否命中券范围。
     *
     * @param string $scopeType
     * @param array $scopeValue
     * @param array $line
     * @return bool
     */
    private function lineInScope($scopeType, array $scopeValue, array $line)
    {
        switch ($scopeType) {
            case 'module':
                $module = isset($line['module']) ? (string) $line['module'] : '';

                return $module !== '' && in_array($module, array_map('strval', $scopeValue), true);
            case 'category':
                $lineCategories = array();
                if (isset($line['category_ids']) && is_array($line['category_ids']) && !empty($line['category_ids'])) {
                    $lineCategories = array_map('intval', $line['category_ids']);
                } elseif (isset($line['category_id'])) {
                    $lineCategories = array((int) $line['category_id']);
                }
                if (empty($lineCategories)) {
                    return false;
                }

                return count(array_intersect($lineCategories, array_map('intval', $scopeValue))) > 0;
            case 'product':
                return isset($line['item_id'])
                    && in_array((int) $line['item_id'], array_map('intval', $scopeValue), true);
            case 'all':
            default:
                return true;
        }
    }

    /**
     * 解码 scope_value（JSON 数组）；非法 / 空一律返回空数组。
     *
     * @param mixed $raw
     * @return array
     */
    private function decodeScopeValue($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * 该会员是否尚无已支付 / 已完成订单（首单判定）。
     *
     * @param int $userId
     * @return bool
     */
    public function isFirstOrder($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        if (!DB::tableExist('order')) {
            return true;
        }

        return !DB::table('order')
            ->where('user_id', $userId)
            ->where('status', 'IN', array(OrderStatus::PAID, OrderStatus::COMPLETED))
            ->exists();
    }
}
