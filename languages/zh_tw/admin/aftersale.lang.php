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
$_LANG['aftersale'] = '售後服務';
$_LANG['aftersale_list'] = '退換貨列表';
$_LANG['aftersale_apply'] = '售後服務申請';
$_LANG['aftersale_item_list'] = '選擇售後商品';
$_LANG['aftersale_btn'] = '申請售後';
$_LANG['aftersale_picksite'] = '自提點';

// 欄位
$_LANG['aftersale_aftersale_sn'] = '售後編號';
$_LANG['aftersale_user'] = '客戶資訊';
$_LANG['aftersale_aftersale_money'] = '退款金額';
$_LANG['aftersale_item_list'] = '售後商品';
$_LANG['aftersale_reason'] = '售後原因';
$_LANG['aftersale_handle_record'] = '處理說明';
$_LANG['aftersale_image'] = '商品照片';
$_LANG['aftersale_status'] = '狀態';
$_LANG['aftersale_status_all'] = '全部';
$_LANG['aftersale_status_0'] = '處理中';
$_LANG['aftersale_status_1'] = '處理完成';
$_LANG['aftersale_created_at'] = '申請時間';

// 字串狀態機文案
$_LANG['aftersale_status_pending'] = '申請中';
$_LANG['aftersale_status_approved'] = '已同意';
$_LANG['aftersale_status_rejected'] = '已駁回';
$_LANG['aftersale_status_cancelled'] = '已撤銷';
$_LANG['aftersale_status_waiting_return'] = '待買家寄回';
$_LANG['aftersale_status_return_shipped'] = '買家已寄出';
$_LANG['aftersale_status_received'] = '賣家已收貨';
$_LANG['aftersale_status_refunding'] = '退款中';
$_LANG['aftersale_status_refunded'] = '已退款';
$_LANG['aftersale_status_refund_failed'] = '退款失敗';

// 售後類型
$_LANG['aftersale_type'] = '售後類型';
$_LANG['aftersale_type_refund_only'] = '僅退款';
$_LANG['aftersale_type_refund_with_return'] = '退貨退款';
$_LANG['aftersale_type_exchange'] = '換貨';
$_LANG['aftersale_type_repair'] = '維修';

// 金額明細
$_LANG['aftersale_amount_detail'] = '退款金額';
$_LANG['aftersale_apply_amount'] = '申請金額';
$_LANG['aftersale_approved_amount'] = '審核金額';
$_LANG['aftersale_refunded_amount'] = '已退金額';

// 買家寄回物流
$_LANG['aftersale_return_info'] = '買家寄回物流';
$_LANG['aftersale_return_company'] = '物流公司';
$_LANG['aftersale_return_sn'] = '運單號';
$_LANG['aftersale_return_time'] = '寄出時間';

// 狀態機操作
$_LANG['aftersale_operation'] = '售後操作';
$_LANG['aftersale_approve'] = '審核同意';
$_LANG['aftersale_receive'] = '確認收貨';
$_LANG['aftersale_receive_confirm'] = '確認已收到買家寄回的商品？';
$_LANG['aftersale_dispatch_refund'] = '派發退款';
$_LANG['aftersale_dispatch_confirm'] = '確認派發退款？錢包退款將自動到帳，網關退款待確認。';
$_LANG['aftersale_reject'] = '駁回';
$_LANG['aftersale_reject_confirm'] = '確認駁回該售後申請？';
$_LANG['aftersale_reject_reason'] = '駁回理由';
$_LANG['aftersale_close_refund_failed'] = '關閉售後（不退款）';
$_LANG['aftersale_close_refund_failed_confirm'] = '確認關閉該售後？將釋放待確認退款並把訂單恢復到退款前狀態，且不再退款。';

// 待確認網關退款
$_LANG['aftersale_pending_gateway_refund'] = '待確認網關退款';
$_LANG['aftersale_refund_sn'] = '退款單號';
$_LANG['aftersale_refund_channel'] = '退款渠道';
$_LANG['aftersale_confirm_gateway_refund'] = '確認網關退款已完成';
$_LANG['aftersale_confirm_gateway_confirm'] = '確認該筆網關退款已在支付平台後台完成？';
$_LANG['aftersale_fail_gateway_refund'] = '標記失敗';
$_LANG['aftersale_fail_gateway_confirm'] = '確認該筆網關退款未成功？標記失敗後可重新派發退款。';

// 協商時間線
$_LANG['aftersale_timeline'] = '協商時間線';

// 提示訊息
$_LANG['aftersale_handle_wrong'] = '操作失敗';
$_LANG['order_aftersale_not_supported'] = '儲值套餐屬於現金等價物，不支援售後退款';
$_LANG['aftersale_money_invalid'] = '退款金額無效';
$_LANG['aftersale_money_over'] = '退款金額大於訂單總金額';
$_LANG['aftersale_money_over_available'] = '退款金額大於訂單當前可退金額';

// 參數設定
$_LANG['core_allow_aftersale_time'] = '允許申請售後期限';
$_LANG['core_allow_aftersale_time_cue'] = '從使用者提貨後開始計算，單位為小時';
$_LANG['core_pay_timeout'] = '付款逾時時間';
$_LANG['core_pay_timeout_cue'] = '訂單提交後超過時間將自動取消訂單，並釋放庫存，單位為分鐘';
