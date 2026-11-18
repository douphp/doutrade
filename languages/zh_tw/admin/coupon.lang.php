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
$_LANG['coupon'] = '優惠券';
$_LANG['coupon_list'] = '優惠券列表';
$_LANG['coupon_create'] = '新增優惠券';
$_LANG['coupon_edit'] = '編輯優惠券';
$_LANG['coupon_delete'] = '刪除優惠券';
$_LANG['coupon_del_have_log'] = '"d%"已經有使用者領取，您不能刪除';
$_LANG['coupon_add_succes'] = '新增優惠券成功';
$_LANG['coupon_edit_succes'] = '編輯優惠券成功';
$_LANG['coupon_amount'] = '優惠金額';
$_LANG['coupon_condition_text'] = '滿d%可用';
$_LANG['coupon_got'] = '已領';
$_LANG['coupon_used'] = '已使用';

// 優惠券
$_LANG['coupon_id'] = '優惠券編號';
$_LANG['coupon_name'] = '優惠券名稱';
$_LANG['coupon_coupon_sn'] = '優惠券碼';
$_LANG['coupon_type'] = '類型';
$_LANG['coupon_type_money'] = '優惠金額';
$_LANG['coupon_type_percent'] = '優惠百分比';
$_LANG['coupon_face_value'] = '面值';
$_LANG['coupon_money'] = '面值';
$_LANG['coupon_type_cue'] = '類型如果是「優惠百分比」就輸入比如10（意思就是優惠10%），如果類型是「優惠金額」就輸入比如20（意思就是優惠20元）';
$_LANG['coupon_condition'] = '滿多少可用';
$_LANG['coupon_condition_cue'] = '購物金額滿多少才能使用，設定為0則表示無限制';
$_LANG['coupon_time'] = '使用期限';
$_LANG['coupon_start_at'] = '開始時間';
$_LANG['coupon_end_at'] = '結束時間';
$_LANG['coupon_brief'] = '簡介';
$_LANG['coupon_image'] = '圖片';
$_LANG['coupon_status'] = '狀態';
$_LANG['coupon_status_0'] = '停用';
$_LANG['coupon_status_1'] = '啟用';
$_LANG['coupon_created_at'] = '新增時間';

// 優惠券領用記錄
$_LANG['coupon_log'] = '優惠券領用記錄';
$_LANG['coupon_log_delete'] = '優惠券領用記錄刪除';
$_LANG['coupon_log_user'] = '會員';
$_LANG['coupon_log_claimed_at'] = '領取時間';
$_LANG['coupon_log_used_at'] = '使用時間';
$_LANG['coupon_log_order_sn'] = '關聯訂單';
$_LANG['coupon_log_status'] = '狀態';
$_LANG['coupon_log_status_0'] = '未使用';
$_LANG['coupon_log_status_1'] = '已使用';

// 進階規則
$_LANG['coupon_max_discount'] = '百分比券封頂金額';
$_LANG['coupon_max_discount_cue'] = '僅百分比券生效，0 表示不封頂';
$_LANG['coupon_limit_per_user'] = '每人限領';
$_LANG['coupon_limit_per_user_cue'] = '0 表示不限領';
$_LANG['coupon_total_quantity'] = '發放總量';
$_LANG['coupon_total_quantity_cue'] = '0 表示不限量';
$_LANG['coupon_issued_quantity'] = '已領取';
$_LANG['coupon_is_first_order_only'] = '僅限首單';
$_LANG['coupon_is_first_order_only_cue'] = '僅限尚無已付款/已完成訂單的會員領取使用';
$_LANG['coupon_scope'] = '適用範圍';
$_LANG['coupon_scope_all'] = '全部商品';
$_LANG['coupon_scope_module'] = '指定模組';
$_LANG['coupon_scope_category'] = '指定分類（含子分類）';
$_LANG['coupon_scope_product'] = '指定商品';
$_LANG['coupon_scope_cue'] = '分類範圍對選中分類及其子分類生效';
$_LANG['coupon_scope_selected'] = '已選擇';
$_LANG['coupon_products_search'] = '搜尋商品名稱';
$_LANG['coupon_products_empty'] = '未找到商品';
$_LANG['coupon_search'] = '搜尋';
$_LANG['coupon_unlimited'] = '不限量';
$_LANG['coupon_money_unit'] = '元';
$_LANG['coupon_face_value_invalid'] = '面額必須大於 0，百分比券不能超過 100';
$_LANG['coupon_time_invalid'] = '結束時間必須晚於開始時間';
$_LANG['coupon_scope_invalid'] = '請選擇有效的適用範圍';
$_LANG['coupon_total_quantity_invalid'] = '發放總量不能小於已領取數量';
$_LANG['coupon_log_used_cannot_delete'] = '已核銷的優惠券記錄不能刪除';
