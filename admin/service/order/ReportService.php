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

namespace Dou\Admin\Service\Order;

use Dou\Admin\Model\Order\Order;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderPayNameResolver;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台销售统计服务（`index.php?route=order/report/...`）。
 *
 * 所有业务方法以原始字面量参数承接控制器入参；
 * 不在方法体内调 request() helper。聚合查询全部委托给 Order 模型的终结型静态方法
 * （sumPaidRevenue / trendDaily / topProducts 等），跨表读（退款表）走 DB::table。
 */
class ReportService extends BaseService
{
    const PRESET_TODAY = 'today';
    const PRESET_YESTERDAY = 'yesterday';
    const PRESET_LAST_7 = 'last_7';
    const PRESET_LAST_30 = 'last_30';
    const PRESET_THIS_MONTH = 'this_month';
    const PRESET_LAST_MONTH = 'last_month';
    const PRESET_THIS_YEAR = 'this_year';
    const PRESET_CUSTOM = 'custom';

    const GRANULARITY_DAY = 'day';
    const GRANULARITY_MONTH = 'month';

    /** CSV 导出单次行数上限，超量提示用户缩小时间窗 */
    const EXPORT_ROW_CAP = 100000;

    /** @var OrderPayNameResolver */
    private $payNameResolver;

    /**
     * @param OrderPayNameResolver $payNameResolver 支付方式展示名解析
     */
    public function __construct(OrderPayNameResolver $payNameResolver)
    {
        $this->payNameResolver = $payNameResolver;
    }

    /**
     * 解析筛选上下文：preset / 时间窗 / 模块 / 颗粒度，统一拼装回传给所有 build* 方法。
     *
     * @param array $params 控制器从 Request 取好的扁平数组；键含
     *                      preset / date_start / date_end / module / granularity
     * @return array context
     */
    public function resolveContext(array $params)
    {
        $preset = isset($params['preset']) ? (string) $params['preset'] : self::PRESET_LAST_7;
        $dateStartRaw = isset($params['date_start']) ? trim((string) $params['date_start']) : '';
        $dateEndRaw = isset($params['date_end']) ? trim((string) $params['date_end']) : '';
        $module = isset($params['module']) ? (string) $params['module'] : '';
        $granularity = isset($params['granularity']) ? (string) $params['granularity'] : self::GRANULARITY_DAY;

        list($from, $to, $resolvedStart, $resolvedEnd) = $this->resolveTimeWindow($preset, $dateStartRaw, $dateEndRaw);

        if ($granularity !== self::GRANULARITY_MONTH) {
            $granularity = self::GRANULARITY_DAY;
        }

        $pageUrl = route('admin.order.report')
            . '&preset=' . rawurlencode($preset)
            . '&date_start=' . rawurlencode($resolvedStart)
            . '&date_end=' . rawurlencode($resolvedEnd)
            . '&module=' . rawurlencode($module)
            . '&granularity=' . rawurlencode($granularity);

        list($prevFrom, $prevTo) = $this->previousPeriod($from, $to);

        return array(
            'preset' => $preset,
            'date_start' => $resolvedStart,
            'date_end' => $resolvedEnd,
            'from' => $from,
            'to' => $to,
            'prev_from' => $prevFrom,
            'prev_to' => $prevTo,
            'module' => $module,
            'granularity' => $granularity,
            'page_url' => Util::normalizeQueryString($pageUrl),
            'modules' => $this->collectKnownModules(),
        );
    }

    /**
     * 总览页（route=order/report）：8 张 KPI 卡片 + 趋势 + 状态漏斗 + 分类组成。
     *
     * @param array $context resolveContext 返回
     * @return array
     */
    public function buildOverviewData(array $context)
    {
        $from = (int) $context['from'];
        $to = (int) $context['to'];
        $prevFrom = (int) $context['prev_from'];
        $prevTo = (int) $context['prev_to'];
        $module = (string) $context['module'];
        $granularity = (string) $context['granularity'];

        $revenue = Order::sumPaidRevenue($from, $to, $module);
        $orders = Order::countPaidOrders($from, $to, $module);
        $payingUsers = Order::countPayingUsers($from, $to, $module);
        $newPayingUsers = Order::countNewPayingUsers($from, $to, $module);
        $refundAmount = $this->sumRefundAmount($from, $to, $module);

        $prevRevenue = Order::sumPaidRevenue($prevFrom, $prevTo, $module);
        $prevOrders = Order::countPaidOrders($prevFrom, $prevTo, $module);
        $prevPayingUsers = Order::countPayingUsers($prevFrom, $prevTo, $module);
        $prevNewPayingUsers = Order::countNewPayingUsers($prevFrom, $prevTo, $module);
        $prevRefundAmount = $this->sumRefundAmount($prevFrom, $prevTo, $module);

        $aov = $orders > 0 ? Num::toFloatOrZero($revenue / $orders) : 0.0;
        $prevAov = $prevOrders > 0 ? Num::toFloatOrZero($prevRevenue / $prevOrders) : 0.0;
        $refundRate = $revenue > 0 ? Num::toFloatOrZero($refundAmount / $revenue * 100) : 0.0;
        $prevRefundRate = $prevRevenue > 0 ? Num::toFloatOrZero($prevRefundAmount / $prevRevenue * 100) : 0.0;
        $netRevenue = Num::toFloatOrZero($revenue - $refundAmount);
        $prevNetRevenue = Num::toFloatOrZero($prevRevenue - $prevRefundAmount);

        $kpis = array(
            'revenue' => $this->kpiCard($revenue, $prevRevenue, 'currency'),
            'orders' => $this->kpiCard($orders, $prevOrders, 'integer'),
            'aov' => $this->kpiCard($aov, $prevAov, 'currency'),
            'paying_users' => $this->kpiCard($payingUsers, $prevPayingUsers, 'integer'),
            'new_paying_users' => $this->kpiCard($newPayingUsers, $prevNewPayingUsers, 'integer'),
            'refund_amount' => $this->kpiCard($refundAmount, $prevRefundAmount, 'currency'),
            'refund_rate' => $this->kpiCard($refundRate, $prevRefundRate, 'percent'),
            'net_revenue' => $this->kpiCard($netRevenue, $prevNetRevenue, 'currency'),
        );

        $trend = $granularity === self::GRANULARITY_MONTH
            ? $this->fillMonthGaps((array) Order::trendByMonth($from, $to, $module), $from, $to)
            : $this->fillDateGaps((array) Order::trendDaily($from, $to, $module), $from, $to);

        $prevTrend = $granularity === self::GRANULARITY_MONTH
            ? $this->fillMonthGaps((array) Order::trendByMonth($prevFrom, $prevTo, $module), $prevFrom, $prevTo)
            : $this->fillDateGaps((array) Order::trendDaily($prevFrom, $prevTo, $module), $prevFrom, $prevTo);

        $statusFunnel = Order::statusFunnel($from, $to, $module);
        $categoryBreakdown = $this->decorateCategoryBreakdown((array) Order::categoryBreakdown($from, $to, $module));

        $charts = array(
            'trend' => array(
                'labels' => array_column($trend, 'label'),
                'revenue' => array_map('floatval', array_column($trend, 'revenue')),
                'orders' => array_map('intval', array_column($trend, 'orders')),
                'prev_revenue' => array_map('floatval', array_column($prevTrend, 'revenue')),
                'prev_orders' => array_map('intval', array_column($prevTrend, 'orders')),
            ),
            'status_funnel' => $this->decorateStatusFunnel($statusFunnel),
            'category' => array(
                'labels' => array_column($categoryBreakdown, 'category_name'),
                'values' => array_map('floatval', array_column($categoryBreakdown, 'revenue')),
            ),
        );

        return array(
            'kpis' => $kpis,
            'trend' => $trend,
            'status_funnel' => $charts['status_funnel'],
            'category_breakdown' => $categoryBreakdown,
            'charts_json' => json_encode($charts),
        );
    }

    /**
     * 商品页（route=order/report/product）：Top 商品 + 分类组成。
     *
     * @param array $context
     * @return array
     */
    public function buildProductData(array $context)
    {
        $from = (int) $context['from'];
        $to = (int) $context['to'];
        $module = (string) $context['module'];

        $topProducts = (array) Order::topProducts($from, $to, $module, 20);
        $categoryBreakdown = $this->decorateCategoryBreakdown((array) Order::categoryBreakdown($from, $to, $module));

        $totalRevenue = 0.0;
        foreach ($topProducts as $row) {
            $totalRevenue += (float) $row['revenue'];
        }
        foreach ($topProducts as &$row) {
            $row['revenue'] = (float) $row['revenue'];
            $row['qty'] = (int) $row['qty'];
            $row['share'] = $totalRevenue > 0 ? round((float) $row['revenue'] / $totalRevenue * 100, 2) : 0.0;
            $row['aov'] = $row['qty'] > 0 ? round((float) $row['revenue'] / $row['qty'], 2) : 0.0;
        }
        unset($row);

        $charts = array(
            'category' => array(
                'labels' => array_column($categoryBreakdown, 'category_name'),
                'values' => array_map('floatval', array_column($categoryBreakdown, 'revenue')),
            ),
            'top_products' => array(
                'labels' => array_column($topProducts, 'name'),
                'values' => array_map('floatval', array_column($topProducts, 'revenue')),
            ),
        );

        return array(
            'top_products' => $topProducts,
            'category_breakdown' => $categoryBreakdown,
            'charts_json' => json_encode($charts),
        );
    }

    /**
     * 客户页（route=order/report/customer）：新客 vs 复购 + Top 客户。
     *
     * @param array $context
     * @return array
     */
    public function buildCustomerData(array $context)
    {
        $from = (int) $context['from'];
        $to = (int) $context['to'];
        $module = (string) $context['module'];

        $newVsReturning = Order::newVsReturning($from, $to, $module);
        $topCustomers = Order::topCustomers($from, $to, $module, 20);

        $userIds = array();
        foreach ($topCustomers as $row) {
            $userIds[] = (int) $row['user_id'];
        }
        $usernameMap = $this->lookupUsernames($userIds);

        foreach ($topCustomers as &$row) {
            $uid = (int) $row['user_id'];
            $row['username'] = isset($usernameMap[$uid]) ? $usernameMap[$uid] : '';
            $row['revenue'] = (float) $row['revenue'];
            $row['orders'] = (int) $row['orders'];
            $row['last_pay'] = (int) $row['last_pay'];
            $row['last_pay_text'] = $row['last_pay'] > 0 ? date('Y-m-d H:i', $row['last_pay']) : '';
            $row['aov'] = $row['orders'] > 0 ? round($row['revenue'] / $row['orders'], 2) : 0.0;
        }
        unset($row);

        $charts = array(
            'new_vs_returning' => array(
                'users' => array(
                    'new' => (int) $newVsReturning['new_users'],
                    'returning' => (int) $newVsReturning['returning_users'],
                ),
                'revenue' => array(
                    'new' => (float) $newVsReturning['new_revenue'],
                    'returning' => (float) $newVsReturning['returning_revenue'],
                ),
            ),
        );

        return array(
            'new_vs_returning' => $newVsReturning,
            'top_customers' => $topCustomers,
            'charts_json' => json_encode($charts),
        );
    }

    /**
     * 支付页（route=order/report/payment）：支付方式聚合 + 退款时间序列 + 退款 Top 列表。
     *
     * @param array $context
     * @return array
     */
    public function buildPaymentData(array $context)
    {
        $from = (int) $context['from'];
        $to = (int) $context['to'];
        $module = (string) $context['module'];

        $gateways = Order::paymentGatewayBreakdown($from, $to, $module);
        $totalRevenue = 0.0;
        foreach ($gateways as $row) {
            $totalRevenue += (float) $row['revenue'];
        }
        foreach ($gateways as &$row) {
            $row['orders'] = (int) $row['orders'];
            $row['revenue'] = (float) $row['revenue'];
            $row['share'] = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 2) : 0.0;
            $row['pay_name'] = $this->resolvePayName((string) $row['pay_id']);
        }
        unset($row);

        $refundTrend = $this->refundTrendDaily($from, $to, $module);
        $refundTopOrders = $this->refundTopOrders($from, $to, $module, 20);

        $charts = array(
            'gateways' => array(
                'labels' => array_column($gateways, 'pay_name'),
                'values' => array_map('floatval', array_column($gateways, 'revenue')),
            ),
            'refund_trend' => array(
                'labels' => array_column($refundTrend, 'label'),
                'values' => array_map('floatval', array_column($refundTrend, 'amount')),
            ),
        );

        return array(
            'gateways' => $gateways,
            'refund_trend' => $refundTrend,
            'refund_top_orders' => $refundTopOrders,
            'charts_json' => json_encode($charts),
        );
    }

    /**
     * 直接把 CSV 流式写到 php://output。
     *
     * 调用方在 controller 端关闭模板渲染、设置 Content-Type / Content-Disposition / Cache-Control 后再调用。
     *
     * @param array $context
     * @return void
     */
    public function exportCsv(array $context)
    {
        $from = (int) $context['from'];
        $to = (int) $context['to'];
        $module = (string) $context['module'];

        $fh = fopen('php://output', 'w');
        if (!$fh) {
            return;
        }
        fwrite($fh, "\xEF\xBB\xBF");

        fputcsv($fh, array(
            'order_sn', 'paid_at', 'user_id', 'order_amount',
            'pay_id', 'module', 'category_id', 'status', 'created_at',
        ), ',', '"', '\\');

        $this->streamCsvRows($fh, $from, $to, $module);

        fclose($fh);
    }

    /**
     * 解析 preset 与自定义日期为 [$fromTs, $toTs, $resolvedStart, $resolvedEnd]。
     *
     * @param string $preset
     * @param string $dateStart 自定义 preset 时的起日 YYYY-MM-DD
     * @param string $dateEnd 自定义 preset 时的止日 YYYY-MM-DD
     * @return array{0:int,1:int,2:string,3:string}
     */
    private function resolveTimeWindow($preset, $dateStart, $dateEnd)
    {
        $now = time();
        $today = strtotime(date('Y-m-d', $now));

        switch ($preset) {
            case self::PRESET_TODAY:
                $from = $today;
                $to = $today + 86400;
                break;
            case self::PRESET_YESTERDAY:
                $from = $today - 86400;
                $to = $today;
                break;
            case self::PRESET_LAST_30:
                $from = $today - 30 * 86400;
                $to = $today + 86400;
                break;
            case self::PRESET_THIS_MONTH:
                $from = strtotime(date('Y-m-01', $now));
                $to = strtotime(date('Y-m-01', strtotime('+1 month', $from)));
                break;
            case self::PRESET_LAST_MONTH:
                $thisMonth = strtotime(date('Y-m-01', $now));
                $from = strtotime('-1 month', $thisMonth);
                $to = $thisMonth;
                break;
            case self::PRESET_THIS_YEAR:
                $from = strtotime(date('Y-01-01', $now));
                $to = strtotime(date('Y-01-01', strtotime('+1 year', $from)));
                break;
            case self::PRESET_CUSTOM:
                $from = $dateStart !== '' ? strtotime($dateStart) : 0;
                $to = $dateEnd !== '' ? strtotime($dateEnd) + 86400 : $today + 86400;
                if ($from <= 0 || $to <= $from) {
                    $from = $today - 6 * 86400;
                    $to = $today + 86400;
                }
                break;
            case self::PRESET_LAST_7:
            default:
                $from = $today - 6 * 86400;
                $to = $today + 86400;
                break;
        }

        $resolvedStart = date('Y-m-d', $from);
        $resolvedEnd = date('Y-m-d', $to - 86400);

        return array($from, $to, $resolvedStart, $resolvedEnd);
    }

    /**
     * 计算上一周期窗：等长前移。例如本期 6/8-6/14 → 上期 6/1-6/7。
     *
     * @param int $from
     * @param int $to
     * @return array{0:int,1:int}
     */
    private function previousPeriod($from, $to)
    {
        $duration = (int) $to - (int) $from;
        if ($duration <= 0) {
            return array(0, 0);
        }
        return array((int) $from - $duration, (int) $from);
    }

    /**
     * 构造单张 KPI 卡片数据。
     *
     * @param float|int $current
     * @param float|int $previous
     * @param string $format currency|integer|percent
     * @return array
     */
    private function kpiCard($current, $previous, $format)
    {
        $current = (float) $current;
        $previous = (float) $previous;
        $delta = $current - $previous;
        $hasCompare = $previous != 0.0;
        $deltaPercent = $hasCompare ? round($delta / abs($previous) * 100, 2) : 0.0;
        $direction = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');

        return array(
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'has_compare' => $hasCompare,
            'delta_percent' => $deltaPercent,
            'direction' => $direction,
            'format' => $format,
        );
    }

    /**
     * 按天补齐时间序列的空日期，未出现的日期 revenue/orders 补 0。
     *
     * @param array $rows trendDaily 返回，含 day/revenue/orders
     * @param int $from
     * @param int $to
     * @return array [['label' => 'YYYY-MM-DD', 'revenue' => float, 'orders' => int], ...]
     */
    private function fillDateGaps(array $rows, $from, $to)
    {
        $map = array();
        foreach ($rows as $r) {
            $map[(string) $r['day']] = $r;
        }
        $out = array();
        for ($ts = (int) $from; $ts < (int) $to; $ts += 86400) {
            $label = date('Y-m-d', $ts);
            $hit = isset($map[$label]) ? $map[$label] : array('revenue' => 0, 'orders' => 0);
            $out[] = array(
                'label' => $label,
                'revenue' => (float) $hit['revenue'],
                'orders' => (int) $hit['orders'],
            );
        }
        return $out;
    }

    /**
     * 按月补齐时间序列。
     *
     * @param array $rows trendByMonth 返回，含 month/revenue/orders
     * @param int $from
     * @param int $to
     * @return array
     */
    private function fillMonthGaps(array $rows, $from, $to)
    {
        $map = array();
        foreach ($rows as $r) {
            $map[(string) $r['month']] = $r;
        }
        $out = array();
        $cursor = strtotime(date('Y-m-01', (int) $from));
        while ($cursor < (int) $to) {
            $label = date('Y-m', $cursor);
            $hit = isset($map[$label]) ? $map[$label] : array('revenue' => 0, 'orders' => 0);
            $out[] = array(
                'label' => $label,
                'revenue' => (float) $hit['revenue'],
                'orders' => (int) $hit['orders'],
            );
            $cursor = strtotime('+1 month', $cursor);
        }
        return $out;
    }

    /**
     * 退款时间序列：按 refunded_at 日聚合，status=succeeded。
     *
     * @param int $from
     * @param int $to
     * @param string $module 退款表无 module 列，仅当传入时按 order 表反查
     * @return array [['label' => 'YYYY-MM-DD', 'amount' => float], ...]
     */
    private function refundTrendDaily($from, $to, $module)
    {
        $refundTbl = trim(DB::tableName('order_refund'), '`');
        $orderTbl = trim(DB::tableName('order'), '`');

        $sql = "SELECT DATE_FORMAT(r.refunded_at, '%Y-%m-%d') AS day, SUM(r.base_amount) AS amount"
            . " FROM `" . $refundTbl . "` r";
        if ((string) $module !== '') {
            $sql .= " INNER JOIN `" . $orderTbl . "` o ON o.id = r.order_id"
                . " AND o.module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " WHERE r.status = 'succeeded'"
            . " AND r.refunded_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND r.refunded_at < FROM_UNIXTIME(" . (int) $to . ")"
            . " GROUP BY day"
            . " ORDER BY day ASC";

        $rs = DB::query($sql);
        $rows = array();
        while ($r = DB::fetchAssoc($rs)) {
            $rows[(string) $r['day']] = $r;
        }

        $out = array();
        for ($ts = (int) $from; $ts < (int) $to; $ts += 86400) {
            $label = date('Y-m-d', $ts);
            $hit = isset($rows[$label]) ? $rows[$label] : array('amount' => 0);
            $out[] = array(
                'label' => $label,
                'amount' => (float) $hit['amount'],
            );
        }
        return $out;
    }

    /**
     * Top N 退款订单：聚合 order_refund 后 JOIN order 取 order_sn。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @param int $limit
     * @return array [['order_id','order_sn','amount','refunded_at'], ...]
     */
    private function refundTopOrders($from, $to, $module, $limit)
    {
        $refundTbl = trim(DB::tableName('order_refund'), '`');
        $orderTbl = trim(DB::tableName('order'), '`');

        $sql = "SELECT r.order_id, o.order_sn, SUM(r.base_amount) AS amount, MAX(r.refunded_at) AS refunded_at"
            . " FROM `" . $refundTbl . "` r"
            . " INNER JOIN `" . $orderTbl . "` o ON o.id = r.order_id"
            . " WHERE r.status = 'succeeded'"
            . " AND r.refunded_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND r.refunded_at < FROM_UNIXTIME(" . (int) $to . ")";
        if ((string) $module !== '') {
            $sql .= " AND o.module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " GROUP BY r.order_id, o.order_sn"
            . " ORDER BY amount DESC"
            . " LIMIT " . (int) $limit;

        $rs = DB::query($sql);
        $out = array();
        while ($r = DB::fetchAssoc($rs)) {
            $refundedTs = Util::toTimestamp($r['refunded_at']);
            $out[] = array(
                'order_id' => (int) $r['order_id'],
                'order_sn' => (string) $r['order_sn'],
                'amount' => (float) $r['amount'],
                'refunded_at' => $refundedTs !== null ? $refundedTs : 0,
                'refunded_at_text' => $refundedTs !== null ? date('Y-m-d H:i', $refundedTs) : '',
            );
        }
        return $out;
    }

    /**
     * 当前窗内退款总额。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return float
     */
    private function sumRefundAmount($from, $to, $module)
    {
        $refundTbl = trim(DB::tableName('order_refund'), '`');
        $orderTbl = trim(DB::tableName('order'), '`');

        $sql = "SELECT SUM(r.base_amount) FROM `" . $refundTbl . "` r";
        if ((string) $module !== '') {
            $sql .= " INNER JOIN `" . $orderTbl . "` o ON o.id = r.order_id"
                . " AND o.module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " WHERE r.status = 'succeeded'"
            . " AND r.refunded_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND r.refunded_at < FROM_UNIXTIME(" . (int) $to . ")";

        return (float) DB::getOne($sql);
    }

    /**
     * 拉取分类名称：批量查 dou_*_category（按 module 分库)，把 category_id → category_name 合并进结果。
     *
     * @param array $rows categoryBreakdown 返回，含 category_id/module/qty/revenue
     * @return array 同结构 + category_name
     */
    private function decorateCategoryBreakdown(array $rows)
    {
        $catsByModule = array();
        foreach ($rows as $r) {
            $module = (string) $r['module'];
            $catId = (int) $r['category_id'];
            if ($module === '' || $catId <= 0) {
                continue;
            }
            $catsByModule[$module][$catId] = true;
        }

        $nameMap = array();
        foreach ($catsByModule as $module => $idMap) {
            $tableName = $module . '_category';
            if (!DB::tableExist($tableName)) {
                continue;
            }
            $catRows = DB::table($tableName)
                ->whereIn('id', array_keys($idMap))
                ->select('id, name');
            foreach ($catRows as $c) {
                $nameMap[$module][(int) $c['id']] = (string) $c['name'];
            }
        }

        foreach ($rows as &$r) {
            $module = (string) $r['module'];
            $catId = (int) $r['category_id'];
            $r['category_id'] = $catId;
            $r['qty'] = (int) $r['qty'];
            $r['revenue'] = (float) $r['revenue'];
            $r['category_name'] = isset($nameMap[$module][$catId])
                ? $nameMap[$module][$catId]
                : ($catId > 0 ? '#' . $catId : lang('uncategorized'));
        }
        unset($r);

        return $rows;
    }

    /**
     * 给状态漏斗附加显示文案与转化率。
     *
     * @param array $funnel [status => count] 全部 OrderStatus::all() 已覆盖
     * @return array
     */
    private function decorateStatusFunnel(array $funnel)
    {
        $total = 0;
        foreach ($funnel as $cnt) {
            $total += (int) $cnt;
        }
        $out = array();
        foreach ($funnel as $status => $cnt) {
            $out[] = array(
                'status' => $status,
                'label' => lang('order_status_' . $status, $status),
                'count' => (int) $cnt,
                'share' => $total > 0 ? round((int) $cnt / $total * 100, 2) : 0.0,
                'badge' => OrderStatus::badgeClass($status),
            );
        }
        return $out;
    }

    /**
     * 批量查 user_id → username（按 user_id 列表）。
     *
     * @param array $userIds
     * @return array [user_id => username]
     */
    private function lookupUsernames(array $userIds)
    {
        if (empty($userIds)) {
            return array();
        }
        $userIds = array_unique(array_map('intval', $userIds));
        $rows = DB::table('user')
            ->whereIn('id', $userIds)
            ->select();
        if (!is_array($rows)) {
            return array();
        }
        $out = array();
        foreach ($rows as $r) {
            $name = isset($r['nickname']) ? (string) $r['nickname'] : '';
            if ($name === '' && isset($r['user_sn'])) {
                $name = (string) $r['user_sn'];
            }
            if ($name === '' && isset($r['mobile'])) {
                $name = (string) $r['mobile'];
            }
            if ($name === '' && isset($r['email'])) {
                $name = (string) $r['email'];
            }
            $out[(int) $r['id']] = $name;
        }
        return $out;
    }

    /**
     * 解析支付方式标识 → 显示名（dou_plugin.slug → dou_plugin.name，与 OrderService::detail 一致）；
     * 命中失败则原样返回。
     *
     * @param string $payId
     * @return string
     */
    private function resolvePayName($payId)
    {
        if ($payId === '') {
            return lang('order_report_pay_unknown', '-');
        }
        $name = $this->payNameResolver->resolvePayId($payId);
        return $name !== '' ? $name : (string) $payId;
    }

    /**
     * 枚举当前 dou_order 表中出现过的模块列表，供视图的「模块下拉」选项。
     *
     * @return array
     */
    private function collectKnownModules()
    {
        $rows = DB::table('order')
            ->groupBy('module')
            ->select('module');
        $out = array();
        foreach ($rows as $r) {
            $m = (string) $r['module'];
            if ($m !== '') {
                $out[] = $m;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * 流式输出 CSV 行到给定文件句柄；每 N 行 flush，10 万行硬上限。
     *
     * @param resource $fh
     * @param int $from
     * @param int $to
     * @param string $module
     * @return void
     */
    private function streamCsvRows($fh, $from, $to, $module)
    {
        $orderTbl = trim(DB::tableName('order'), '`');
        $sql = "SELECT order_sn, paid_at, user_id, order_amount, pay_id, module, category_id, status, created_at"
            . " FROM `" . $orderTbl . "`"
            . " WHERE paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND paid_at < FROM_UNIXTIME(" . (int) $to . ")"
            . " AND mode <> 'point'";
        $statuses = array();
        foreach (Order::paidStatuses() as $status) {
            $statuses[] = "'" . DB::escapeString($status) . "'";
        }
        $sql .= " AND status IN (" . implode(',', $statuses) . ")";
        if ((string) $module !== '') {
            $sql .= " AND module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " ORDER BY paid_at ASC LIMIT " . self::EXPORT_ROW_CAP;

        $rs = DB::query($sql);
        $count = 0;
        while ($r = DB::fetchAssoc($rs)) {
            fputcsv($fh, array(
                (string) $r['order_sn'],
                !empty($r['paid_at']) ? (string) $r['paid_at'] : '',
                (int) $r['user_id'],
                (string) $r['order_amount'],
                (string) $r['pay_id'],
                (string) $r['module'],
                (int) $r['category_id'],
                (string) $r['status'],
                $r['created_at'] ? (string) $r['created_at'] : '',
            ), ',', '"', '\\');
            $count++;
            if ($count % 500 === 0) {
                flush();
            }
        }
    }
}
