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

// 會員中心
$_LANG['user'] = '會員中心';
$_LANG['user_list'] = '會員資料';
$_LANG['user_excel'] = '匯出所選會員';
$_LANG['user_excel_all'] = '匯出全部';
$_LANG['user_create'] = '新增會員';
$_LANG['user_edit'] = '會員管理';
$_LANG['user_account'] = '會員帳號';
$_LANG['user_password_confirm'] = '確認登入密碼';
$_LANG['user_password_new'] = '新登入密碼';
$_LANG['user_password_new_cue'] = '留空表示不修改密碼';
$_LANG['user_password_new_confirm'] = '確認新登入密碼';
$_LANG['user_man'] = '男';
$_LANG['user_woman'] = '女';
$_LANG['user_delete'] = '會員刪除';
$_LANG['user_avatar_upload'] = '上傳頭像';
$_LANG['user_point'] = '點數';
$_LANG['user_money'] = '餘額';
$_LANG['user_account'] = '會員帳號';
$_LANG['user_total_consumption'] = '累計消費';
$_LANG['user_total_promote'] = '推廣金';

// 會員資料
$_LANG['user_user_sn'] = '會員編號';
$_LANG['user_level'] = '會員等級';
$_LANG['user_level_no'] = '普通會員';
$_LANG['user_username'] = '會員帳號';
$_LANG['user_mobile'] = '手機號';
$_LANG['user_email'] = '信箱';
$_LANG['user_password'] = '登入密碼';
$_LANG['user_contact'] = '聯絡人 ';
$_LANG['user_first_name'] = 'First Name';
$_LANG['user_last_name'] = 'Last Name';
$_LANG['user_phone'] = '聯絡電話';
$_LANG['user_country'] = '國家';
$_LANG['user_province'] = '省份';
$_LANG['user_address'] = '地址';
$_LANG['user_postcode'] = '郵遞區號';
$_LANG['user_sex'] = '性別';
$_LANG['user_nickname'] = '暱稱';
$_LANG['user_avatar'] = '頭像';
$_LANG['user_login_count'] = '登入次數';
$_LANG['user_last_login'] = '最後登入時間';
$_LANG['user_last_ip'] = '最後登入IP';
$_LANG['user_created_at'] = '註冊時間';

// 會員狀態
$_LANG['user_status'] = '狀態';
$_LANG['user_status_all'] = '全部狀態';
$_LANG['user_status_active'] = '正常';
$_LANG['user_status_suspended'] = '已封禁';
$_LANG['user_status_deactivated'] = '已註銷';
$_LANG['user_status_action_active'] = '解禁';
$_LANG['user_status_action_suspended'] = '封禁';
$_LANG['user_status_action_deactivated'] = '註銷';
$_LANG['user_status_confirm'] = '確定變更該會員狀態？';
$_LANG['user_status_change_success'] = '會員狀態已變更';
$_LANG['user_status_transit_wrong'] = '目前狀態不允許該變更';

// 分銷等級
$_LANG['user_distribution_level'] = '分銷等級';
$_LANG['user_distribution_level_no'] = '無分銷等級';

// 佔位符
$_LANG['user_search_placeholder'] = '手機/信箱/會員編號/姓名';

// 驗證資訊
$_LANG['user_username_cue'] = '手機號和信箱至少需要新增一種作為帳號';
$_LANG['user_mobile_cue'] = '手機號不能為空，且注意正確輸入';
$_LANG['user_mobile_exists'] = '該手機號已經存在，請更換其它手機號';
$_LANG['user_mobile_no_exist'] = '您輸入的手機號不存在，請核對後重新輸入。';
$_LANG['user_email_cue'] = '信箱不能為空，且注意正確輸入';
$_LANG['user_email_exists'] = '該信箱已經存在，請更換其它信箱';
$_LANG['user_email_no_exist'] = '您輸入的E-MAIL不存在，請核對後重新輸入。';
$_LANG['user_email_check'] = '信箱驗證';
$_LANG['user_password_cue'] = '密碼至少包含6個字元。只能使用字母，數字和符號';
$_LANG['user_verification_send'] = '發送驗證碼';
$_LANG['user_verification_wrong'] = '請輸入正確的驗證碼';
$_LANG['user_verification_outtime'] = '驗證逾時';
$_LANG['user_verification_body'] = '尊敬的 u%，您好:<br><br>您的信箱驗證碼是：c%<br>請勿將驗證碼透露給其他人。<br><br>本郵件由系統自動發送，請勿直接回覆！';

// 會員等級
$_LANG['user_level'] = '會員等級';
$_LANG['user_level_list'] = '等級列表';
$_LANG['user_level_create'] = '新增會員等級';
$_LANG['user_level_edit'] = '編輯會員等級';
$_LANG['user_level_delete'] = '刪除會員等級';

$_LANG['user_level_name'] = '等級名稱';
$_LANG['user_level_upgrade_type'] = '升級方式';
$_LANG['user_level_upgrade_type_noauto'] = '不自動升級';
$_LANG['user_level_upgrade_type_shopping'] = '消費金額滿';
$_LANG['user_level_upgrade_type_promote'] = '推廣金額滿';
$_LANG['user_level_upgrade_type_cue'] = '消費金額滿（自己購物消費的金額總額）；推廣金額滿（成為分銷員後推廣商品獲得的佣金總額），滿足條件將升級到該等級';
$_LANG['user_level_upgrade_condition'] = '升級條件';
$_LANG['user_level_good_discount'] = '商品折扣';
$_LANG['user_level_good_discount_cue'] = '購買商品可以享受的折扣，100%表示不打折';
$_LANG['user_level_direct_reward'] = '商品佣金';
$_LANG['user_level_direct_reward_cue'] = 'A成為分銷員直接推薦給B顧客下單消費，則A獲得是銷售佣金（商品佣金）';
$_LANG['user_level_indirect_reward'] = '邀請佣金';
$_LANG['user_level_indirect_reward_cue'] = '如果B這時候註冊成為分銷員，推薦C顧客下單消費，則B獲得銷售佣金（商品佣金），則A獲得邀請佣金';
$_LANG['user_level_icon'] = '等級圖示';

// 聯絡人
$_LANG['user_contact_manager'] = '聯絡人管理';
$_LANG['user_contact_create'] = '新增聯絡人';
$_LANG['user_contact_edit'] = '編輯聯絡人';
$_LANG['user_contact_deleteete'] = '刪除聯絡人';
$_LANG['user_contact_name'] = '姓名';
$_LANG['user_contact_phone'] = '手機號';
$_LANG['user_contact_id_card'] = '身分證';
$_LANG['user_contact_area'] = '所在地區';
$_LANG['user_contact_province'] = '省';
$_LANG['user_contact_city'] = '市';
$_LANG['user_contact_district'] = '區';
$_LANG['user_contact_address'] = '地址';
$_LANG['user_contact_tag'] = '標籤';
$_LANG['user_contact_is_default'] = '預設';
$_LANG['user_contact_is_defaulted'] = '已預設';
$_LANG['user_contact_is_default_set'] = '設為預設';

// 操作日誌
$_LANG['user_log'] = '操作日誌';
$_LANG['user_log_manager'] = '操作日誌';
$_LANG['user_log_action'] = '操作類型';
$_LANG['user_log_ip'] = 'IP';
$_LANG['user_log_time'] = '時間';
$_LANG['user_log_result'] = '結果';
$_LANG['user_log_result_success'] = '成功';
$_LANG['user_log_result_fail'] = '失敗';
$_LANG['user_log_details'] = '備註';
$_LANG['user_log_view'] = '檢視日誌';

// 提示訊息
$_LANG['user_select_empty'] = '沒有選擇會員';
$_LANG['user_edit_success'] = '會員編輯成功！';
$_LANG['user_create_success'] = '會員新增成功！';
$_LANG['user_password_success'] = '登入密碼修改成功！';

// 參數設定項
$_LANG['user_login_mode_lang'] = '登入模式';
$_LANG['user_login_mode_value'] = 'email';
$_LANG['user_login_mode_cue'] = '輸入email使用信箱作為帳號；輸入mobile使用手機號作為帳號（配套簡訊模組可以進行簡訊驗證，請從模块扩展安裝）；輸入weixin使用微信登入則無需註冊（需配套微信登入外掛或含微信登入功能的小程式）';
$_LANG['user_loginid_mode_lang'] = '微信登入唯一ID';
$_LANG['user_loginid_mode_value'] = 'unionid';
$_LANG['user_loginid_mode_cue'] = '如果輸入openid則登入帳號只在目前終端有效（這裡的終端比如微信公眾號、微信小程式、PC網頁端），如果要各終端統一則輸入unionid，這樣各終端登入都作為同一個帳號';
$_LANG['user_login_phone_lang'] = '取得微信手機號';
$_LANG['user_login_phone_value'] = '1';
$_LANG['user_login_phone_cue'] = '微信登入後需要取得手機號請輸入1，不需要強制取得手機號輸入0';
$_LANG['user_delete_balance_exists'] = '該會員仍有餘額、凍結金額或積分，無法刪除';
$_LANG['user_delete_withdraw_exists'] = '該會員仍有未結清提現，無法刪除';
$_LANG['user_level_in_use'] = '該等級下仍有會員，無法刪除';
