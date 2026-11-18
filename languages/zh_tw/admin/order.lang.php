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

// 系統設定

// 訂單中心
$_LANG['order'] = '訂單中心';
$_LANG['order_cart'] = '購物車';
$_LANG['order_list'] = '訂單列表';
$_LANG['order_show'] = '訂單詳情';
$_LANG['order_reconcile'] = '立即對帳';
$_LANG['order_item'] = '訂單商品';
$_LANG['order_shipping_select'] = '選擇配送方式';
$_LANG['order_tracking_submit'] = '填入快遞單號';
$_LANG['order_pay_check'] = '確認收款';
$_LANG['order_retry_paid'] = '補發付款聯動';
$_LANG['order_retry_paid_success'] = '付款聯動已重新執行，已發放的項目不會重複處理';
$_LANG['order_retry_paid_invalid'] = '僅已付款 / 已完成的訂單才能重新執行付款聯動';
$_LANG['order_delete'] = '訂單刪除';
$_LANG['order_cancel'] = '取消訂單';
$_LANG['order_item_amount'] = '商品金額';
$_LANG['order_item_amount_gross'] = '商品原價';
$_LANG['order_item_amount_net'] = '商品實付';
$_LANG['order_order_amount'] = '訂單金額';
$_LANG['order_shipping'] = '配送方式';
$_LANG['order_shipping_fee'] = '配送費用';
$_LANG['order_shipping_free'] = '免運費';
$_LANG['order_shipping_free_cue'] = '滿d%免運';
$_LANG['order_pay_time_out'] = '訂單需要在<b>d%</b>內完成付款，否則訂單會被自動取消';
$_LANG['order_pay_time_out_short'] = '剩餘<b>d%</b>';
$_LANG['order_pay_time_out_day'] = '天';
$_LANG['order_pay_time_out_hour'] = '小時';
$_LANG['order_pay_time_out_minute'] = '分鐘';
$_LANG['order_pay_time_out_second'] = '秒';
$_LANG['order_pay_time_out_expired'] = '付款已逾時';
$_LANG['order_pay_time_out_error'] = '無法計算剩餘付款時間';
$_LANG['order_consignee'] = '收貨資訊';

// 訂單資料
$_LANG['order_order_sn'] = '訂單編號';
$_LANG['order_email'] = '會員名';
$_LANG['order_point'] = '消耗點數';
$_LANG['order_point_unit'] = '點數';
$_LANG['order_phone'] = '手機號';
$_LANG['order_contact'] = '聯絡人';
$_LANG['order_address'] = '地址';
$_LANG['order_postcode'] = '郵遞區號';
$_LANG['order_region'] = '所在地區';
$_LANG['order_item_number'] = '商品件數';
$_LANG['order_currency'] = '訂單幣別';
$_LANG['order_currency_amount'] = '客戶實付（訂單幣）';
$_LANG['order_tracking_no'] = '快遞單號';
$_LANG['order_paid_at'] = '付款時間';
$_LANG['order_payment'] = '付款方式';
$_LANG['order_pay_wallet'] = '餘額付款';
$_LANG['order_pay_evidence'] = '付款憑證';
$_LANG['order_shipping'] = '配送方式';
$_LANG['order_status'] = '訂單狀態';
$_LANG['order_created_at'] = '下單時間';
$_LANG['order_name'] = '商品名稱';
$_LANG['order_price'] = '商品價格';
$_LANG['order_shipped_at'] = '出貨時間';
$_LANG['order_allow_aftersale'] = '是否允許申請售後';
$_LANG['order_allow_aftersale_0'] = '不允許';
$_LANG['order_allow_aftersale_1'] = '允許';
$_LANG['order_aftersale_status'] = '售後狀態';
$_LANG['order_aftersale_status_0'] = '未申請';
$_LANG['order_aftersale_status_1'] = '已申請';
$_LANG['order_aftersale_status_2'] = '退款成功';

// 訂單狀態
$_LANG['order_status_all'] = '全部狀態';
$_LANG['order_status_awaiting_confirmation'] = '待確認';
$_LANG['order_status_cancelled'] = '已取消';
$_LANG['order_status_pending'] = '未付款';
$_LANG['order_status_paid'] = '待出貨';
$_LANG['order_status_completed'] = '已完成';
$_LANG['order_status_refunding'] = '退款中';
$_LANG['order_status_refunded'] = '已退款';
$_LANG['order_status_partial_refunded'] = '部分退款';

// 提示訊息
$_LANG['order_select_empty'] = '沒有選擇訂單';
$_LANG['order_cancel_success'] = '訂單取消成功';
$_LANG['order_cancel_partial'] = '已取消 %d 個訂單，%d 個訂單取消失敗';
$_LANG['order_delete_success'] = '訂單取消成功';

// 參數設定項
$_LANG['order_order_pay_timeout_lang'] = '未付款逾時時間';
$_LANG['order_order_pay_timeout_value'] = '60';
$_LANG['order_order_pay_timeout_cue'] = '設定未付款訂單逾時時間，逾時後將自動取消訂單，請輸入數字（單位為分鐘），預設為60分鐘，未安裝付款外掛時此設定無效';
$_LANG['order_order_allow_aftersale_time_lang'] = '可申請售後期限';
$_LANG['order_order_allow_aftersale_time_value'] = '168';
$_LANG['order_order_allow_aftersale_time_cue'] = '設定可申請售後的期限，請輸入數字（單位為小時），完成訂單後開始計算，預設為168小時（7天），未安裝售後模組時此設定無效';
$_LANG['order_order_allow_comment_time_lang'] = '訂單商品可評論期限';
$_LANG['order_order_allow_comment_time_value'] = '168';
$_LANG['order_order_allow_comment_time_cue'] = '設定可訂單商品可評論期限（逾時將自動好評），請輸入數字（單位為小時），完成訂單後開始計算，預設為168小時（7天），未安裝評論模組時此設定無效';
$_LANG['order_order_quick_buy_lang'] = '啟用快速購買模式';
$_LANG['order_order_quick_buy_value'] = '0';
$_LANG['order_order_quick_buy_cue'] = '輸入1表示啟用，0表示關閉，快速購買模式無需選擇收貨地址且跳過購物車直接進入結算頁面';

// 銷售統計（route=order/report/...）
$_LANG['order_report'] = '銷售統計';
$_LANG['order_report_overview'] = '總覽';
$_LANG['order_report_product'] = '商品分析';
$_LANG['order_report_customer'] = '客戶分析';
$_LANG['order_report_payment'] = '付款分析';
$_LANG['order_report_export'] = '匯出 CSV';

// 時間預設
$_LANG['order_report_preset_today'] = '今天';
$_LANG['order_report_preset_yesterday'] = '昨天';
$_LANG['order_report_preset_last_7'] = '最近 7 天';
$_LANG['order_report_preset_last_30'] = '最近 30 天';
$_LANG['order_report_preset_this_month'] = '本月';
$_LANG['order_report_preset_last_month'] = '上月';
$_LANG['order_report_preset_this_year'] = '今年';
$_LANG['order_report_preset_custom'] = '自訂';

// 篩選條
$_LANG['order_report_module_all'] = '全部模組';
$_LANG['order_report_granularity_day'] = '按天';
$_LANG['order_report_granularity_month'] = '按月';

// KPI 卡片
$_LANG['order_report_revenue'] = '銷售額';
$_LANG['order_report_orders'] = '訂單數';
$_LANG['order_report_aov'] = '客單價';
$_LANG['order_report_paying_users'] = '付費會員';
$_LANG['order_report_new_paying_users'] = '新付費會員';
$_LANG['order_report_refund_amount'] = '退款金額';
$_LANG['order_report_refund_rate'] = '退款率';
$_LANG['order_report_net_revenue'] = '淨銷售額';
$_LANG['order_report_compare_period'] = '上一週期';

// 表頭與圖表標題
$_LANG['order_report_trend_title'] = '銷售趨勢（雙 Y 軸：銷售額 / 訂單數）';
$_LANG['order_report_status'] = '狀態';
$_LANG['order_report_count'] = '數量';
$_LANG['order_report_share'] = '佔比';
$_LANG['order_report_status_funnel'] = '訂單狀態分佈';
$_LANG['order_report_category_breakdown'] = '分類銷售組成';
$_LANG['order_report_top_products'] = 'Top 20 商品';
$_LANG['order_report_top_customers'] = 'Top 20 客戶';
$_LANG['order_report_product_name'] = '商品名稱';
$_LANG['order_report_module'] = '模組';
$_LANG['order_report_qty'] = '數量';

// 客戶分析
$_LANG['order_report_new_vs_returning'] = '新客 vs 回購客';
$_LANG['order_report_new_users'] = '新客';
$_LANG['order_report_returning_users'] = '回購客';
$_LANG['order_report_username'] = '會員';
$_LANG['order_report_last_pay'] = '最近付款';

// 付款分析
$_LANG['order_report_payment_methods'] = '付款方式';
$_LANG['order_report_refund_trend'] = '退款趨勢';
$_LANG['order_report_top_refunds'] = '退款 Top 訂單';
$_LANG['order_report_pay_name'] = '付款方式';
$_LANG['order_report_pay_unknown'] = '未知';
$_LANG['order_report_order_sn'] = '訂單號';
$_LANG['order_report_refunded_at'] = '退款時間';

$_LANG['uncategorized'] = '未分類';
