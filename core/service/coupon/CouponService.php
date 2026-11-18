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
 * 优惠券通用读模型。
 */
class CouponService extends BaseService
{
    /** @var CouponEligibility|null */
    private $eligibility;

    /**
     * @param CouponEligibility|null $eligibility 可选：商品上下文过滤时需要
     *
     * 注：不声明类型，隐式可空参数自 PHP 8.4 起已弃用，
     * 而显式写作 ?CouponEligibility 需要 PHP 7.1+，会破坏 5.6 兼容。
     */
    public function __construct($eligibility = null)
    {
        // 容器按类型提示自动注入；去掉类型声明后在此显式恢复同样的解析语义：
        // 类文件缺席（可选模块未安装）时保持 null，否则实例化默认实现。
        if ($eligibility === null && class_exists('Dou\\Core\\Service\\Coupon\\CouponEligibility')) {
            $eligibility = new CouponEligibility();
        }
        $this->eligibility = $eligibility;
    }

    /**
     * 在售券列表（可按商品上下文过滤，用于商品页 / 结算前展示）。
     *
     * @param int $user_id
     * @param array $context module / category_ids / item_id
     * @return array
     */
    public function getCouponList($user_id = 0, array $context = array())
    {
        $now = date('Y-m-d H:i:s');
        $rows = DB::table('coupon')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->where('status', '1')
            ->order('sort ASC, id DESC')
            ->select();

        return $this->decorateCouponList((array) $rows, (int) $user_id, $context);
    }

    /**
     * 券中心分页。
     *
     * @param int $user_id
     * @param int|string $page
     * @param string $pageUrl
     * @param array $context
     * @return array coupon_list、pager
     */
    public function getCouponCenterPage($user_id, $page, $pageUrl, array $context = array())
    {
        $now = date('Y-m-d H:i:s');
        $page = (int) $page > 0 ? (int) $page : 1;
        $result = DB::table('coupon')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>', $now)
            ->where('status', '1')
            ->order('sort ASC, id DESC')
            ->paginate(16, $page, $pageUrl);

        return array(
            'coupon_list' => $this->decorateCouponList((array) $result['list'], (int) $user_id, $context),
            'pager' => $result['pager'],
        );
    }

    /**
     * @param array $rows dou_coupon 行
     * @param int $userId
     * @param array $context
     * @return array
     */
    private function decorateCouponList(array $rows, $userId, array $context)
    {
        $rows = $this->filterByContext($rows, $context);
        $logData = $this->userLogData($userId, $rows);
        $logMap = $logData['map'];

        $firstOrderAllowed = true;
        $hasFirstOrderCoupon = false;
        foreach ($rows as $row) {
            if (!empty($row['is_first_order_only'])) {
                $hasFirstOrderCoupon = true;
                break;
            }
        }
        if ($hasFirstOrderCoupon && $userId > 0 && $this->eligibility) {
            $firstOrderAllowed = $this->eligibility->isFirstOrder($userId);
        }

        $couponList = array();
        foreach ($rows as $row) {
            $row = language()->langBox($row, 'coupon', 'name, brief');
            $couponId = (int) $row['id'];
            $log = isset($logMap[$couponId]) ? $logMap[$couponId] : array();
            $status = $this->statusByLog($row, $log);
            $remain = $this->remainCount($row);

            $limit = isset($row['limit_per_user']) ? (int) $row['limit_per_user'] : 0;
            $claimCount = isset($logData['counts'][$couponId]) ? (int) $logData['counts'][$couponId] : 0;
            $limitReached = $limit > 0 && $claimCount >= $limit;
            $claimable = !$limitReached && $remain !== 0;

            if ($claimable) {
                $status = array('value' => 'get', 'format' => lang('coupon_status_get'));
            } elseif ($remain === 0 && $claimCount === 0) {
                $status = array('value' => 'sold_out', 'format' => lang('coupon_sold_out'));
            } elseif (!empty($logData['has_unused'][$couponId])) {
                $status = array('value' => 'got', 'format' => lang('coupon_status_got'));
            } elseif (!empty($logData['has_used'][$couponId])) {
                $status = array('value' => 'used', 'format' => lang('coupon_status_used'));
            }

            if (!empty($row['is_first_order_only']) && !$firstOrderAllowed) {
                $claimable = false;
                $status = array('value' => 'first_order_only', 'format' => lang('coupon_reason_first_order_only'));
            }

            $scopeType = isset($row['scope_type']) ? (string) $row['scope_type'] : 'all';

            $couponList[] = array(
                'id' => $couponId,
                'name' => isset($row['name']) ? $row['name'] : '',
                'coupon_sn' => isset($row['coupon_sn']) ? $row['coupon_sn'] : '',
                'type' => lang('coupon_type_' . (isset($row['type']) ? $row['type'] : 'money')),
                'type_value' => isset($row['type']) ? $row['type'] : 'money',
                'money' => self::faceValueLabel($row),
                'condition' => isset($row['condition']) ? $row['condition'] : 0,
                'condition_text' => self::conditionText($row),
                'start_at' => !empty($row['start_at']) ? date('Y-m-d', strtotime($row['start_at'])) : '',
                'end_at' => !empty($row['end_at']) ? date('Y-m-d', strtotime($row['end_at'])) : '',
                'brief' => isset($row['brief']) ? $row['brief'] : '',
                'image' => isset($row['image']) ? $row['image'] : '',
                'image_url' => (!empty($row['image']) && function_exists('attachment'))
                    ? attachment()->url($row['image'])
                    : '',
                'scope_type' => $scopeType,
                'scope_text' => lang('coupon_scope_' . $scopeType, $scopeType),
                'remain' => $remain,
                'remain_text' => $this->remainText($row, $remain),
                'claimable' => $claimable,
                'class' => $status['value'] !== 'get' ? ' got' : '',
                'status' => $status,
            );
        }

        return $couponList;
    }

    /**
     * @param array $rows
     * @param array $context
     * @return array
     */
    private function filterByContext(array $rows, array $context)
    {
        if (empty($context) || !$this->eligibility) {
            return $rows;
        }

        $filtered = array();
        foreach ($rows as $row) {
            if ($this->eligibility->scopeMatches($row, $context)) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * 一次取出该会员在给定券集合中的领取记录。
     *
     * @param int $userId
     * @param array $coupons
     * @return array map（最新一条）、counts、has_unused、has_used
     */
    private function userLogData($userId, array $coupons)
    {
        $data = array(
            'map' => array(),
            'counts' => array(),
            'has_unused' => array(),
            'has_used' => array(),
        );
        if ($userId <= 0 || empty($coupons)) {
            return $data;
        }

        $couponIds = array();
        foreach ($coupons as $row) {
            $couponIds[] = (int) $row['id'];
        }

        $logs = DB::table('coupon_log')
            ->where('user_id', (int) $userId)
            ->whereIn('coupon_id', $couponIds)
            ->order('id DESC')
            ->select();

        foreach ((array) $logs as $log) {
            $couponId = (int) $log['coupon_id'];
            $data['counts'][$couponId] = isset($data['counts'][$couponId]) ? $data['counts'][$couponId] + 1 : 1;
            if (!isset($data['map'][$couponId])) {
                $data['map'][$couponId] = $log;
            }
            if (empty($log['used_at'])) {
                $data['has_unused'][$couponId] = true;
            } else {
                $data['has_used'][$couponId] = true;
            }
        }

        return $data;
    }

    /**
     * @param int $coupon_id
     * @return array|bool|null
     */
    public function getCoupon($coupon_id)
    {
        $coupon = DB::table('coupon')->where('id', (int) $coupon_id)->find();

        if ($coupon) {
            $coupon['condition_text'] = self::conditionText($coupon);
        }

        return $coupon;
    }

    /**
     * 券状态（按券 + 会员取第一条领取记录，供后台日志列表等兼容调用）。
     *
     * @param int $coupon_id
     * @param int $user_id
     * @return array value/format
     */
    public function status($coupon_id, $user_id = 0)
    {
        $coupon = DB::table('coupon')->where('id', (int) $coupon_id)->find();
        $log = DB::table('coupon_log')
            ->where('coupon_id', (int) $coupon_id)
            ->where('user_id', (int) $user_id)
            ->find();

        return $this->statusByLog(is_array($coupon) ? $coupon : array(), is_array($log) ? $log : array());
    }

    /**
     * 按具体领取记录计算状态。
     *
     * @param array $coupon
     * @param array $log
     * @return array value/format
     */
    public function statusByLog($coupon, $log)
    {
        $coupon = is_array($coupon) ? $coupon : array();
        $log = is_array($log) ? $log : array();

        if (!empty($log['used_at'])) {
            return array(
                'value' => 'used',
                'format' => lang('coupon_status_used'),
            );
        }

        if ($this->ifExpires($coupon)) {
            return array(
                'value' => 'expires',
                'format' => lang('coupon_status_expires'),
            );
        }

        if (!empty($log['claimed_at'])) {
            return array(
                'value' => 'got',
                'format' => lang('coupon_status_got'),
            );
        }

        return array(
            'value' => 'get',
            'format' => lang('coupon_status_get'),
        );
    }

    /**
     * @param int|array $coupon 券 ID 或 dou_coupon 行
     * @return bool
     */
    public function ifExpires($coupon)
    {
        if (is_array($coupon)) {
            $end_at = isset($coupon['end_at']) ? $coupon['end_at'] : null;
        } else {
            $end_at = DB::table('coupon')->where('id', (int) $coupon)->value('end_at');
        }
        $endTs = Util::toTimestamp($end_at);

        return $endTs === null || time() >= $endTs;
    }

    /**
     * 订单优惠券视图（PC / API / 工作端 / 后台共用）。
     *
     * @param int $orderId
     * @param array|null $ctx 订单币上下文；null 表示按基准币展示（后台）
     * @return array coupons/coupon_amount/coupon_amount_format/coupon（单数兼容别名）
     */
    public function getOrderCouponView($orderId, $ctx = null)
    {
        $rows = DB::table('order_coupon')
            ->where('order_id', (int) $orderId)
            ->order('id ASC')
            ->select();

        $useCurrency = ($ctx !== null && !currency()->isBase($ctx));
        $coupons = array();
        $couponAmount = 0.0;
        foreach ((array) $rows as $row) {
            $amount = isset($row['amount']) ? (float) $row['amount'] : 0.0;
            if ($useCurrency && isset($row['currency_amount'])) {
                $amount = (float) $row['currency_amount'];
            }
            $couponAmount += $amount;
            $coupon = $this->getCoupon(isset($row['coupon_id']) ? $row['coupon_id'] : 0);
            if (is_array($coupon)) {
                $coupon = language()->langBox($coupon, 'coupon', 'name, brief');
            }
            $coupons[] = array(
                'coupon_id' => isset($row['coupon_id']) ? (int) $row['coupon_id'] : 0,
                'coupon_log_id' => isset($row['coupon_log_id']) ? (int) $row['coupon_log_id'] : 0,
                'name' => (is_array($coupon) && !empty($coupon['name']))
                    ? $coupon['name']
                    : (string) (isset($row['coupon_id']) ? $row['coupon_id'] : ''),
                'condition_text' => is_array($coupon) && isset($coupon['condition_text']) ? $coupon['condition_text'] : '',
                'brief' => is_array($coupon) && isset($coupon['brief']) ? $coupon['brief'] : '',
                'type' => isset($row['type']) ? $row['type'] : (is_array($coupon) && isset($coupon['type']) ? $coupon['type'] : ''),
                'amount' => $amount,
                'amount_format' => $ctx !== null ? Util::formatCurrency($amount, $ctx) : Util::formatBasePrice($amount),
            );
        }

        $couponAmount = Num::round($couponAmount, 2);
        $view = array(
            'coupons' => $coupons,
            'coupon_amount' => $couponAmount,
            'coupon_amount_format' => $ctx !== null ? Util::formatCurrency($couponAmount, $ctx) : Util::formatBasePrice($couponAmount),
            'coupon' => false,
        );

        if (!empty($coupons)) {
            $legacy = $coupons[0];
            $legacy['amount_format'] = $ctx !== null ? Util::formatCurrency($couponAmount, $ctx) : Util::formatBasePrice($couponAmount);
            $view['coupon'] = $legacy;
        }

        return $view;
    }

    /**
     * 面值展示：percent 输出 10%，money 输出格式化金额。
     *
     * @param array $coupon
     * @return string
     */
    public static function faceValueLabel(array $coupon)
    {
        $type = isset($coupon['type']) ? (string) $coupon['type'] : '';
        $faceValue = isset($coupon['face_value']) ? $coupon['face_value'] : (isset($coupon['money']) ? $coupon['money'] : '');
        if ($type === 'percent') {
            return $faceValue !== '' ? $faceValue . '%' : '';
        }

        return Util::formatPrice($faceValue);
    }

    /**
     * 商品分类路径（自身 + 祖先），供券范围匹配。
     *
     * @param int $categoryId
     * @return array
     */
    public static function categoryPath($categoryId)
    {
        $categoryId = (int) $categoryId;
        if ($categoryId <= 0 || !DB::tableExist('product_category')) {
            return array();
        }

        $parentMap = array();
        foreach ((array) DB::table('product_category')->field('id, parent_id')->select() as $row) {
            $parentMap[(int) $row['id']] = (int) $row['parent_id'];
        }

        $path = array();
        $seen = array();
        while ($categoryId > 0 && !isset($seen[$categoryId])) {
            $path[] = $categoryId;
            $seen[$categoryId] = true;
            $categoryId = isset($parentMap[$categoryId]) ? (int) $parentMap[$categoryId] : 0;
        }

        return $path;
    }

    /**
     * 使用门槛文案。
     *
     * @param array $coupon
     * @return string
     */
    public static function conditionText(array $coupon)
    {
        $condition = isset($coupon['condition']) ? $coupon['condition'] : 0;

        return preg_replace('/d%/Ums', Util::formatPrice($condition), lang('coupon_condition_text'));
    }

    /**
     * 剩余数量：-1 表示不限量。
     *
     * @param array $coupon
     * @return int
     */
    private function remainCount(array $coupon)
    {
        $total = isset($coupon['total_quantity']) ? (int) $coupon['total_quantity'] : 0;
        if ($total <= 0) {
            return -1;
        }
        $issued = isset($coupon['issued_quantity']) ? (int) $coupon['issued_quantity'] : 0;

        return max(0, $total - $issued);
    }

    /**
     * @param array $coupon
     * @param int $remain
     * @return string
     */
    private function remainText(array $coupon, $remain)
    {
        if ($remain < 0) {
            return lang('coupon_remain_unlimited');
        }
        if ($remain === 0) {
            return lang('coupon_sold_out');
        }

        return preg_replace('/d%/Ums', (string) $remain, lang('coupon_remain_text'));
    }
}
