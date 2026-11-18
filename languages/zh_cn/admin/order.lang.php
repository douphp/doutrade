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

// 设置

// 订单中心
$_LANG['order'] = '订单';
$_LANG['order_cart'] = '购物车';
$_LANG['order_list'] = '订单列表';
$_LANG['order_show'] = '订单详情';
$_LANG['order_reconcile'] = '立即对账';
$_LANG['order_item'] = '订单商品';
$_LANG['order_shipping_select'] = '选择配送方式';
$_LANG['order_tracking_submit'] = '填入快递单号';
$_LANG['order_pay_check'] = '确认收款';
$_LANG['order_retry_paid'] = '补发付款联动';
$_LANG['order_retry_paid_success'] = '付款联动已重新执行，已发放的项目不会重复处理';
$_LANG['order_retry_paid_invalid'] = '仅已付款 / 已完成的订单才能重新执行付款联动';
$_LANG['order_delete'] = '订单删除';
$_LANG['order_cancel'] = '取消订单';
$_LANG['order_item_amount'] = '商品金额';
$_LANG['order_item_amount_gross'] = '商品原价';
$_LANG['order_item_amount_net'] = '商品实付';
$_LANG['order_order_amount'] = '订单金额';
$_LANG['order_shipping'] = '配送方式';
$_LANG['order_shipping_fee'] = '配送费用';
$_LANG['order_shipping_free'] = '免运费';
$_LANG['order_shipping_free_cue'] = '满d%包邮';
$_LANG['order_pay_time_out'] = '订单需要在<b>d%</b>内完成支付，否则订单会被自动取消';
$_LANG['order_pay_time_out_short'] = '剩余<b>d%</b>';
$_LANG['order_pay_time_out_day'] = '天';
$_LANG['order_pay_time_out_hour'] = '小时';
$_LANG['order_pay_time_out_minute'] = '分钟';
$_LANG['order_pay_time_out_second'] = '秒';
$_LANG['order_pay_time_out_expired'] = '支付已超时';
$_LANG['order_pay_time_out_error'] = '无法计算剩余支付时间';
$_LANG['order_consignee'] = '收货信息';

// 订单数据
$_LANG['order_order_sn'] = '订单编号';
$_LANG['order_email'] = '会员名';
$_LANG['order_point'] = '消耗积分';
$_LANG['order_point_unit'] = '积分';
$_LANG['order_phone'] = '手机号';
$_LANG['order_contact'] = '联系人';
$_LANG['order_address'] = '地址';
$_LANG['order_postcode'] = '邮政编码';
$_LANG['order_region'] = '所在地区';
$_LANG['order_item_number'] = '商品件数';
$_LANG['order_currency'] = '订单币种';
$_LANG['order_currency_amount'] = '客户实付（订单币）';
$_LANG['order_tracking_no'] = '快递单号';
$_LANG['order_paid_at'] = '付款时间';
$_LANG['order_payment'] = '支付方式';
$_LANG['order_pay_wallet'] = '余额支付';
$_LANG['order_pay_evidence'] = '付款凭证';
$_LANG['order_shipping'] = '配送方式';
$_LANG['order_status'] = '订单状态';
$_LANG['order_created_at'] = '下单时间';
$_LANG['order_name'] = '商品名称';
$_LANG['order_price'] = '商品价格';
$_LANG['order_shipped_at'] = '发货时间';
$_LANG['order_allow_aftersale'] = '是否允许申请售后';
$_LANG['order_allow_aftersale_0'] = '不允许';
$_LANG['order_allow_aftersale_1'] = '允许';
$_LANG['order_aftersale_status'] = '售后状态';
$_LANG['order_aftersale_status_0'] = '未申请';
$_LANG['order_aftersale_status_1'] = '已申请';
$_LANG['order_aftersale_status_2'] = '退款成功';

// 订单状态
$_LANG['order_status_all'] = '全部状态';
$_LANG['order_status_awaiting_confirmation'] = '待确认';
$_LANG['order_status_cancelled'] = '已取消';
$_LANG['order_status_pending'] = '未付款';
$_LANG['order_status_paid'] = '待发货';
$_LANG['order_status_completed'] = '已完成';
$_LANG['order_status_refunding'] = '退款中';
$_LANG['order_status_refunded'] = '已退款';
$_LANG['order_status_partial_refunded'] = '部分退款';

// 提示信息
$_LANG['order_select_empty'] = '没有选择订单';
$_LANG['order_cancel_success'] = '订单取消成功';
$_LANG['order_cancel_partial'] = '已取消 %d 个订单，%d 个订单取消失败';
$_LANG['order_delete_success'] = '订单取消成功';

// 参数设置项
$_LANG['order_order_pay_timeout_lang'] = '未付款超时时间';
$_LANG['order_order_pay_timeout_value'] = '60';
$_LANG['order_order_pay_timeout_cue'] = '设定未付款订单超时时间，超时后将自动取消订单，请输入数字（单位为分钟），默认为60分钟，未安装支付插件时此设置无效';
$_LANG['order_order_allow_aftersale_time_lang'] = '可申请售后期限';
$_LANG['order_order_allow_aftersale_time_value'] = '168';
$_LANG['order_order_allow_aftersale_time_cue'] = '设定可申请售后的期限，请输入数字（单位为小时），完成订单后开始计算，默认为168小时（7天），未安装售后模块时此设置无效';
$_LANG['order_order_allow_comment_time_lang'] = '订单商品可评论期限';
$_LANG['order_order_allow_comment_time_value'] = '168';
$_LANG['order_order_allow_comment_time_cue'] = '设定可订单商品可评论期限（超时将自动好评），请输入数字（单位为小时），完成订单后开始计算，默认为168小时（7天），未安装评论模块时此设置无效';
$_LANG['order_order_quick_buy_lang'] = '启用快速购买模式';
$_LANG['order_order_quick_buy_value'] = '0';
$_LANG['order_order_quick_buy_cue'] = '输入1表示启用，0表示关闭，快速购买模式无需选择收货地址且跳过购物车直接进入结算页面';

// 销售统计（route=order/report/...）
$_LANG['order_report'] = '销售统计';
$_LANG['order_report_overview'] = '总览';
$_LANG['order_report_product'] = '商品分析';
$_LANG['order_report_customer'] = '客户分析';
$_LANG['order_report_payment'] = '支付分析';
$_LANG['order_report_export'] = '导出 CSV';

// 时间预设
$_LANG['order_report_preset_today'] = '今天';
$_LANG['order_report_preset_yesterday'] = '昨天';
$_LANG['order_report_preset_last_7'] = '最近 7 天';
$_LANG['order_report_preset_last_30'] = '最近 30 天';
$_LANG['order_report_preset_this_month'] = '本月';
$_LANG['order_report_preset_last_month'] = '上月';
$_LANG['order_report_preset_this_year'] = '今年';
$_LANG['order_report_preset_custom'] = '自定义';

// 筛选条
$_LANG['order_report_module_all'] = '全部模块';
$_LANG['order_report_granularity_day'] = '按天';
$_LANG['order_report_granularity_month'] = '按月';

// KPI 卡片
$_LANG['order_report_revenue'] = '销售额';
$_LANG['order_report_orders'] = '订单数';
$_LANG['order_report_aov'] = '客单价';
$_LANG['order_report_paying_users'] = '付费会员';
$_LANG['order_report_new_paying_users'] = '新付费会员';
$_LANG['order_report_refund_amount'] = '退款金额';
$_LANG['order_report_refund_rate'] = '退款率';
$_LANG['order_report_net_revenue'] = '净销售额';
$_LANG['order_report_compare_period'] = '上一周期';

// 表头与图表标题
$_LANG['order_report_trend_title'] = '销售趋势（双 Y 轴：销售额 / 订单数）';
$_LANG['order_report_status'] = '状态';
$_LANG['order_report_count'] = '数量';
$_LANG['order_report_share'] = '占比';
$_LANG['order_report_status_funnel'] = '订单状态分布';
$_LANG['order_report_category_breakdown'] = '分类销售组成';
$_LANG['order_report_top_products'] = 'Top 20 商品';
$_LANG['order_report_top_customers'] = 'Top 20 客户';
$_LANG['order_report_product_name'] = '商品名称';
$_LANG['order_report_module'] = '模块';
$_LANG['order_report_qty'] = '数量';

// 客户分析
$_LANG['order_report_new_vs_returning'] = '新客 vs 复购客';
$_LANG['order_report_new_users'] = '新客';
$_LANG['order_report_returning_users'] = '复购客';
$_LANG['order_report_username'] = '会员';
$_LANG['order_report_last_pay'] = '最近付款';

// 支付分析
$_LANG['order_report_payment_methods'] = '支付方式';
$_LANG['order_report_refund_trend'] = '退款趋势';
$_LANG['order_report_top_refunds'] = '退款 Top 订单';
$_LANG['order_report_pay_name'] = '支付方式';
$_LANG['order_report_pay_unknown'] = '未知';
$_LANG['order_report_order_sn'] = '订单号';
$_LANG['order_report_refunded_at'] = '退款时间';

$_LANG['uncategorized'] = '未分类';
