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

// 規格屬性
$_LANG['attribute'] = '規格屬性';
$_LANG['attribute_manage'] = '管理';
$_LANG['attribute_create'] = '新增';
$_LANG['attribute_edit'] = '編輯規格屬性';
$_LANG['attribute_delete'] = '刪除規格屬性';
$_LANG['attribute_del_is_parent'] = '還存在使用「d%」規格屬性的商品，您不能刪除';
$_LANG['attribute_add_succes'] = '新增規格屬性成功';
$_LANG['attribute_edit_succes'] = '編輯規格屬性成功';

// 欄位
$_LANG['attribute_category'] = '所屬分類';
$_LANG['attribute_category_all'] = '通用';
$_LANG['attribute_name'] = '名稱';
$_LANG['attribute_type'] = '類型';
$_LANG['attribute_type_text'] = '文字';
$_LANG['attribute_type_image'] = '圖片';

// 資料
$_LANG['attribute_value'] = '規格屬性列表';
$_LANG['attribute_value_value'] = '選項';
$_LANG['attribute_value_type'] = '類型';
$_LANG['attribute_value_image'] = '圖片';
$_LANG['attribute_value_remark'] = '備註(可選)';
$_LANG['attribute_value_price_change'] = '加價';

// 提示訊息
$_LANG['attribute_value_must_edit_mode'] = '只有在編輯模式下才可以新增規格屬性';
$_LANG['attribute_value_price_cue'] = '每個規格屬性的「第一個值」必須作為預設屬性並匹配預設的價格，因此不能設定價格浮動，如果已經設定，將會被自動清零';
$_LANG['attribute_value_create_cue'] = '可直接新增規格選項，保存商品時一併寫入；切換商品分類會刷新屬性區並清空已填選項；每個規格的第一個選項不能設定加價；圖片型規格需保存後在編輯頁上傳圖片';
$_LANG['attribute_value_category_mismatch'] = '存在不屬於當前商品分類的規格選項，請切換分類或移除對應選項';
$_LANG['attribute_value_category_switch_confirm'] = '切換分類將清空已填規格選項，是否繼續？';
