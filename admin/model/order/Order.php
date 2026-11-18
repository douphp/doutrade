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

namespace Dou\Admin\Model\Order;

use Dou\Admin\Model\User\User;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单表（order）后台模型
 */
class Order extends Model
{
    protected $table = 'order';
    protected $primary = 'id';

    /** @var array 列表读取时格式化（金额相关保留原样供 Util::formatPrice，仅做 int/datetime；status/aftersale_status 为 OrderStatus 字符串常量，禁止 int 强转） */
    protected $casts = array(
        'id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime:Y-m-d H:i:s',
        'paid_at' => 'datetime:Y-m-d H:i:s',
        'shipped_at' => 'datetime:Y-m-d H:i:s',
    );

    /**
     * 计入营收/客户/趋势统计的订单状态。
     *
     * @return array
     */
    public static function paidStatuses()
    {
        return array(
            OrderStatus::PAID,
            OrderStatus::COMPLETED,
            OrderStatus::REFUNDING,
            OrderStatus::REFUNDED,
            OrderStatus::PARTIAL_REFUNDED,
        );
    }

    /**
     * 统一“实付订单”作用域：有 paid_at、非积分兑换、处于已付款链状态。
     *
     * @param Builder $query
     * @return Builder
     */
    public static function scopePaidOrders(Builder $query)
    {
        return $query->where('paid_at', 'IS NOT NULL')
            ->where('mode', '<>', 'point')
            ->whereIn('status', self::paidStatuses());
    }

    /**
     * 原生 SQL 版的实付订单条件（含前导 AND）。
     *
     * @param string $alias 表别名（如 o），空串表示无别名
     * @return string
     */
    private static function paidWhereSql($alias = '')
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $statuses = array();
        foreach (self::paidStatuses() as $status) {
            $statuses[] = "'" . DB::escapeString($status) . "'";
        }

        return ' AND ' . $prefix . 'paid_at IS NOT NULL'
            . " AND " . $prefix . "mode <> 'point'"
            . ' AND ' . $prefix . 'status IN (' . implode(',', $statuses) . ')';
    }

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * 说明：
     * - 此处仅声明 order 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 OrderTrackingFormRequest::rules() 及
     *   OrderActionFormRequest::rules()。
     * - 仅用于请求层的控制参数（如 checkbox、action、confirm）不应放在此处。
     *
     * @var array
     */
    protected $fillable = array(
        'order_sn',
        'user_id',
        'direct_user_id',
        'indirect_user_id',
        'mode',
        'module',
        'category_id',
        'contact_id',
        'email',
        'pay_id',
        'shipping_id',
        'tracking_no',
        'shipping_fee',
        'item_amount',
        'order_amount',
        'order_point',
        'direct_reward',
        'indirect_reward',
        'paid_at',
        'shipped_at',
        'allow_aftersale',
        'aftersale_status',
        'allow_comment',
        'item_link_status',
        'status',
        'created_at',
    );

    /**
     * 关联下单会员（user_id → user）。
     *
     * @return \Dou\Core\Orm\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联订单条目（order_id → order_item）。
     *
     * @return \Dou\Core\Orm\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * 按下单会员筛选；传入 0 / 空透传，传入 -1 强制空集。
     *
     * @param Builder $query
     * @param mixed $userId
     * @return Builder
     */
    public function scopeFilterByUserId(Builder $query, $userId)
    {
        if ($userId === '' || $userId === null) {
            return $query;
        }
        $userId = (int) $userId;
        if ($userId === 0) {
            return $query;
        }

        return $query->where('user_id', $userId);
    }

    /**
     * 按订单号模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $orderSn
     * @return Builder
     */
    public function scopeFilterByOrderSn(Builder $query, $orderSn)
    {
        $orderSn = (string) $orderSn;
        if ($orderSn === '') {
            return $query;
        }

        return $query->where('order_sn', 'LIKE', '%' . $orderSn . '%');
    }

    /**
     * 按订单状态精确筛选；空字符串透传。
     *
     * 调用方负责把非 OrderStatus::all() 的脏值挡在外面（通过 rejectFilter
     * 强制空集语义），本 scope 仅按值落 where，与其它 filterByXxx 一致。
     *
     * @param Builder $query
     * @param mixed $status
     * @return Builder
     */
    public function scopeFilterByStatus(Builder $query, $status)
    {
        $status = (string) $status;
        if ($status === '') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * 收件人 OR 条件分组（callable）；非 callable 透传。
     *
     * @param Builder $query
     * @param mixed $callback
     * @return Builder
     */
    public function scopeApplyConsigneeGroup(Builder $query, $callback)
    {
        if (!is_callable($callback)) {
            return $query;
        }

        return $query->where($callback);
    }

    /**
     * 按下单时间下限筛选（created_at > strtotime($timeStart)）；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeStart
     * @return Builder
     */
    public function scopeFilterByTimeStart(Builder $query, $timeStart)
    {
        $timeStart = (string) $timeStart;
        if ($timeStart === '') {
            return $query;
        }

        return $query->where('created_at', '>', date('Y-m-d H:i:s', strtotime($timeStart)));
    }

    /**
     * 按下单时间上限筛选（created_at < strtotime($timeEnd)）；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $timeEnd
     * @return Builder
     */
    public function scopeFilterByTimeEnd(Builder $query, $timeEnd)
    {
        $timeEnd = (string) $timeEnd;
        if ($timeEnd === '') {
            return $query;
        }

        return $query->where('created_at', '<', date('Y-m-d H:i:s', strtotime($timeEnd)));
    }

    /**
     * 预加载下单会员关联。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithUser(Builder $query)
    {
        return $query->with('user');
    }

    /**
     * 默认列表排序：order_id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * @param int|string $orderId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByOrderId($orderId)
    {
        return static::where('id', $orderId)->first();
    }

    /**
     * @param string $orderSn
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findByOrderSn($orderSn)
    {
        return static::where('order_sn', $orderSn)->first();
    }

    /**
     * @param string $orderSn
     * @return mixed
     */
    public static function getOrderIdByOrderSn($orderSn)
    {
        return DB::table(static::tableName())->where('order_sn', $orderSn)->value('id');
    }

    /**
     * @param int|string $orderId
     * @param array $data
     * @return mixed
     */
    public static function updateOrderData($orderId, array $data)
    {
        return static::where('id', $orderId)->update($data);
    }

    /**
     * @param int|string $orderId
     * @param array $data
     * @return mixed
     */
    public static function updateOrder($orderId, array $data)
    {
        return static::where('id', $orderId)->update($data);
    }

    /**
     * @param int|string $orderId
     * @return bool
     */
    public static function deleteOrder($orderId)
    {
        return DB::table(static::tableName())->where('id', $orderId)->delete();
    }

    /**
     * @param int|string $orderId
     * @return mixed
     */
    public static function deleteOrderItems($orderId)
    {
        return DB::table('order_item')->where('order_id', $orderId)->delete();
    }

    /**
     * @param int|string $orderId
     * @param array $data
     * @return mixed
     */
    public static function updateOrderItemsData($orderId, array $data)
    {
        return DB::table('order_item')->where('order_id', $orderId)->data($data)->update();
    }

    /**
     * @param int|string $orderId
     * @return array
     */
    public static function getOrderItems($orderId)
    {
        return DB::table('order_item')
            ->where('order_id', $orderId)
            ->select();
    }

    /**
     * 按支付时间窗筛选「实付订单」：paid_at ∈ [$from, $to)。
     *
     * 销售统计「成交额」语义：以 paid_at 为时间轴，order_amount 为口径；
     * 状态层面 paid_at > 0 已隐含「曾付款过」，refunded / cancelled-after-pay
     * 的金额退减由退款表 dou_order_refund 独立扣减，本 scope 不二次过滤 status。
     *
     * @param Builder $query
     * @param int $from unix ts，含
     * @param int $to unix ts，不含
     * @return Builder
     */
    public function scopePaidWithin(Builder $query, $from, $to)
    {
        return $query->where('paid_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('paid_at', '<', date('Y-m-d H:i:s', (int) $to));
    }

    /**
     * 按所属模块筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $module
     * @return Builder
     */
    public function scopeFilterByModule(Builder $query, $module)
    {
        $module = (string) $module;
        if ($module === '') {
            return $query;
        }

        return $query->where('module', $module);
    }

    /**
     * 实付订单成交额：SUM(order_amount) WHERE paid_at ∈ [from, to) AND module ?= $module。
     *
     * @param int $from
     * @param int $to
     * @param string $module 空字符串表示不限模块
     * @return float
     */
    public static function sumPaidRevenue($from, $to, $module = '')
    {
        $q = DB::table(static::tableName())
            ->where('paid_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('paid_at', '<', date('Y-m-d H:i:s', (int) $to))
            ->where('mode', '<>', 'point')
            ->whereIn('status', self::paidStatuses());
        if ((string) $module !== '') {
            $q->where('module', (string) $module);
        }
        return (float) $q->sum('order_amount');
    }

    /**
     * 实付订单数：COUNT(*) WHERE paid_at ∈ [from, to) AND module ?= $module。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return int
     */
    public static function countPaidOrders($from, $to, $module = '')
    {
        $q = DB::table(static::tableName())
            ->where('paid_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('paid_at', '<', date('Y-m-d H:i:s', (int) $to))
            ->where('mode', '<>', 'point')
            ->whereIn('status', self::paidStatuses());
        if ((string) $module !== '') {
            $q->where('module', (string) $module);
        }
        return (int) $q->count();
    }

    /**
     * 付费会员数（去重）：COUNT(DISTINCT user_id) WHERE paid_at ∈ [from, to) AND user_id > 0。
     *
     * 排除游客单（user_id=0），避免把多个匿名访客算成同一个 DISTINCT。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return int
     */
    public static function countPayingUsers($from, $to, $module = '')
    {
        $tbl = trim(DB::tableName(static::tableName()), '`');
        $sql = "SELECT COUNT(DISTINCT user_id) FROM `" . $tbl . "`"
            . " WHERE paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND paid_at < FROM_UNIXTIME(" . (int) $to . ")"
            . " AND user_id > 0"
            . self::paidWhereSql();
        if ((string) $module !== '') {
            $sql .= " AND module = '" . DB::escapeString((string) $module) . "'";
        }
        return (int) DB::getOne($sql);
    }

    /**
     * 新付费会员数：在 [from, to) 内有付费、且历史上无更早付费记录。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return int
     */
    public static function countNewPayingUsers($from, $to, $module = '')
    {
        $tbl = trim(DB::tableName(static::tableName()), '`');
        $moduleCond = (string) $module !== ''
            ? " AND module = '" . DB::escapeString((string) $module) . "'"
            : '';
        $sql = "SELECT COUNT(*) FROM ("
            . " SELECT user_id, MIN(paid_at) AS first_pay FROM `" . $tbl . "`"
            . " WHERE paid_at IS NOT NULL AND user_id > 0" . $moduleCond . self::paidWhereSql()
            . " GROUP BY user_id"
            . " HAVING first_pay >= FROM_UNIXTIME(" . (int) $from . ") AND first_pay < FROM_UNIXTIME(" . (int) $to . ")"
            . " ) t";
        return (int) DB::getOne($sql);
    }

    /**
     * 按日聚合成交额与订单数：[ ['day' => 'YYYY-MM-DD', 'revenue' => float, 'orders' => int], ... ]。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function trendDaily($from, $to, $module = '')
    {
        $tbl = trim(DB::tableName(static::tableName()), '`');
        $sql = "SELECT DATE_FORMAT(paid_at, '%Y-%m-%d') AS day,"
            . " SUM(order_amount) AS revenue, COUNT(*) AS orders"
            . " FROM `" . $tbl . "`"
            . " WHERE paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND paid_at < FROM_UNIXTIME(" . (int) $to . ")";
        if ((string) $module !== '') {
            $sql .= " AND module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= self::paidWhereSql();
        $sql .= " GROUP BY day ORDER BY day ASC";
        $rs = DB::query($sql);
        $out = array();
        while ($r = DB::fetchAssoc($rs)) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 按月聚合：[ ['month' => 'YYYY-MM', 'revenue' => float, 'orders' => int], ... ]。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function trendByMonth($from, $to, $module = '')
    {
        $tbl = trim(DB::tableName(static::tableName()), '`');
        $sql = "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month,"
            . " SUM(order_amount) AS revenue, COUNT(*) AS orders"
            . " FROM `" . $tbl . "`"
            . " WHERE paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND paid_at < FROM_UNIXTIME(" . (int) $to . ")";
        if ((string) $module !== '') {
            $sql .= " AND module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= self::paidWhereSql();
        $sql .= " GROUP BY month ORDER BY month ASC";
        $rs = DB::query($sql);
        $out = array();
        while ($r = DB::fetchAssoc($rs)) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 订单状态漏斗：[status => count]，以 created_at（下单时刻）为窗，覆盖所有 OrderStatus 枚举值。
     *
     * 与 sumPaidRevenue 等以 paid_at 为窗的统计互补：漏斗看「下单后流向何处」，
     * 因此使用 created_at 而非 paid_at。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function statusFunnel($from, $to, $module = '')
    {
        $q = DB::table(static::tableName())
            ->field('status, COUNT(*) AS cnt')
            ->where('created_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('created_at', '<', date('Y-m-d H:i:s', (int) $to))
            ->groupBy('status');
        if ((string) $module !== '') {
            $q->where('module', (string) $module);
        }
        $rows = $q->select();
        if (!is_array($rows)) {
            $rows = array();
        }

        $out = array();
        foreach (OrderStatus::all() as $s) {
            $out[$s] = 0;
        }
        foreach ($rows as $r) {
            $key = (string) $r['status'];
            $out[$key] = (int) $r['cnt'];
        }
        return $out;
    }

    /**
     * Top N 商品：JOIN order_item ON order_id，按 paid_at 窗与可选 module 过滤。
     *
     * 返回 [['item_id','name','module','category_id','qty','revenue'], ...]
     * revenue = SUM(sale_price * item_number)（订单条目实付价 × 数量）。
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @param int $limit
     * @return array
     */
    public static function topProducts($from, $to, $module = '', $limit = 20)
    {
        $orderTbl = trim(DB::tableName(static::tableName()), '`');
        $itemTbl = trim(DB::tableName('order_item'), '`');
        $sql = "SELECT i.item_id, i.name, i.module, i.category_id,"
            . " SUM(i.item_number) AS qty,"
            . " SUM(i.sale_price * i.item_number * (o.item_amount / NULLIF(t.total, 0))) AS revenue"
            . " FROM `" . $itemTbl . "` i"
            . " INNER JOIN `" . $orderTbl . "` o ON o.id = i.order_id"
            . " INNER JOIN (SELECT order_id, SUM(sale_price * item_number) AS total FROM `" . $itemTbl . "` GROUP BY order_id) t ON t.order_id = o.id"
            . " WHERE o.paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND o.paid_at < FROM_UNIXTIME(" . (int) $to . ")"
            . self::paidWhereSql('o');
        if ((string) $module !== '') {
            $sql .= " AND i.module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " GROUP BY i.item_id, i.name, i.module, i.category_id"
            . " ORDER BY revenue DESC"
            . " LIMIT " . (int) $limit;

        $rs = DB::query($sql);
        $out = array();
        while ($r = DB::fetchAssoc($rs)) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 分类销售组成：JOIN order_item，按 category_id 分组。
     *
     * 返回 [['category_id','module','qty','revenue'], ...]
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function categoryBreakdown($from, $to, $module = '')
    {
        $orderTbl = trim(DB::tableName(static::tableName()), '`');
        $itemTbl = trim(DB::tableName('order_item'), '`');
        $sql = "SELECT i.category_id, i.module,"
            . " SUM(i.item_number) AS qty,"
            . " SUM(i.sale_price * i.item_number * (o.item_amount / NULLIF(t.total, 0))) AS revenue"
            . " FROM `" . $itemTbl . "` i"
            . " INNER JOIN `" . $orderTbl . "` o ON o.id = i.order_id"
            . " INNER JOIN (SELECT order_id, SUM(sale_price * item_number) AS total FROM `" . $itemTbl . "` GROUP BY order_id) t ON t.order_id = o.id"
            . " WHERE o.paid_at >= FROM_UNIXTIME(" . (int) $from . ")"
            . " AND o.paid_at < FROM_UNIXTIME(" . (int) $to . ")"
            . self::paidWhereSql('o');
        if ((string) $module !== '') {
            $sql .= " AND i.module = '" . DB::escapeString((string) $module) . "'";
        }
        $sql .= " GROUP BY i.category_id, i.module"
            . " ORDER BY revenue DESC";

        $rs = DB::query($sql);
        $out = array();
        while ($r = DB::fetchAssoc($rs)) {
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 支付方式聚合：GROUP BY pay_id，按 paid_at 窗筛选。
     *
     * 返回 [['pay_id','orders','revenue'], ...]
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function paymentGatewayBreakdown($from, $to, $module = '')
    {
        $q = DB::table(static::tableName())
            ->field('pay_id, COUNT(*) AS orders, SUM(order_amount) AS revenue')
            ->where('paid_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('paid_at', '<', date('Y-m-d H:i:s', (int) $to))
            ->where('mode', '<>', 'point')
            ->whereIn('status', self::paidStatuses())
            ->groupBy('pay_id')
            ->order('revenue DESC');
        if ((string) $module !== '') {
            $q->where('module', (string) $module);
        }
        $rows = $q->select();
        return is_array($rows) ? $rows : array();
    }

    /**
     * Top N 消费客户：GROUP BY user_id，按 paid_at 窗筛选；排除 user_id=0 的游客单。
     *
     * 返回 [['user_id','orders','revenue','last_pay'], ...]
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @param int $limit
     * @return array
     */
    public static function topCustomers($from, $to, $module = '', $limit = 20)
    {
        $q = DB::table(static::tableName())
            ->field('user_id, COUNT(*) AS orders, SUM(order_amount) AS revenue, MAX(paid_at) AS last_pay')
            ->where('paid_at', '>=', date('Y-m-d H:i:s', (int) $from))
            ->where('paid_at', '<', date('Y-m-d H:i:s', (int) $to))
            ->where('user_id', '>', 0)
            ->where('mode', '<>', 'point')
            ->whereIn('status', self::paidStatuses())
            ->groupBy('user_id')
            ->order('revenue DESC')
            ->limit((int) $limit);
        if ((string) $module !== '') {
            $q->where('module', (string) $module);
        }
        $rows = $q->select();
        return is_array($rows) ? $rows : array();
    }

    /**
     * 新客 vs 复购客：在窗口内付款的会员中，区分首次付费 vs 既有付费用户的订单数与金额。
     *
     * 返回 ['new_users', 'returning_users', 'new_revenue', 'returning_revenue', 'new_orders', 'returning_orders']
     *
     * @param int $from
     * @param int $to
     * @param string $module
     * @return array
     */
    public static function newVsReturning($from, $to, $module = '')
    {
        $tbl = trim(DB::tableName(static::tableName()), '`');
        $moduleCond = (string) $module !== ''
            ? " AND module = '" . DB::escapeString((string) $module) . "'"
            : '';

        $sql = "SELECT t.is_new,"
            . " COUNT(*) AS users,"
            . " SUM(t.window_orders) AS orders,"
            . " SUM(t.window_revenue) AS revenue"
            . " FROM ("
            . "   SELECT user_id,"
            . "          (CASE WHEN MIN(paid_at) >= FROM_UNIXTIME(" . (int) $from . ") THEN 1 ELSE 0 END) AS is_new,"
            . "          SUM(CASE WHEN paid_at >= FROM_UNIXTIME(" . (int) $from . ") AND paid_at < FROM_UNIXTIME(" . (int) $to . ") THEN 1 ELSE 0 END) AS window_orders,"
            . "          SUM(CASE WHEN paid_at >= FROM_UNIXTIME(" . (int) $from . ") AND paid_at < FROM_UNIXTIME(" . (int) $to . ") THEN order_amount ELSE 0 END) AS window_revenue"
            . "   FROM `" . $tbl . "`"
            . "   WHERE paid_at IS NOT NULL AND user_id > 0" . $moduleCond . self::paidWhereSql()
            . "   GROUP BY user_id"
            . "   HAVING window_orders > 0"
            . " ) t"
            . " GROUP BY t.is_new";

        $rs = DB::query($sql);
        $out = array(
            'new_users' => 0, 'returning_users' => 0,
            'new_orders' => 0, 'returning_orders' => 0,
            'new_revenue' => 0.0, 'returning_revenue' => 0.0,
        );
        while ($r = DB::fetchAssoc($rs)) {
            if ((int) $r['is_new'] === 1) {
                $out['new_users'] = (int) $r['users'];
                $out['new_orders'] = (int) $r['orders'];
                $out['new_revenue'] = (float) $r['revenue'];
            } else {
                $out['returning_users'] = (int) $r['users'];
                $out['returning_orders'] = (int) $r['orders'];
                $out['returning_revenue'] = (float) $r['revenue'];
            }
        }
        return $out;
    }
}
