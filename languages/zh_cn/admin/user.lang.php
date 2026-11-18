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

// 会员中心
$_LANG['user'] = '会员';
$_LANG['user_list'] = '会员资料';
$_LANG['user_excel'] = '导出所选会员';
$_LANG['user_excel_all'] = '导出所有';
$_LANG['user_create'] = '添加会员';
$_LANG['user_edit'] = '会员管理';
$_LANG['user_account'] = '会员账号';
$_LANG['user_password_confirm'] = '确认登录密码';
$_LANG['user_password_new'] = '新登录密码';
$_LANG['user_password_new_cue'] = '留空表示不修改密码';
$_LANG['user_password_new_confirm'] = '确认新登录密码';
$_LANG['user_man'] = '男';
$_LANG['user_woman'] = '女';
$_LANG['user_delete'] = '会员删除';
$_LANG['user_avatar_upload'] = '上传头像';
$_LANG['user_point'] = '积分';
$_LANG['user_money'] = '余额';
$_LANG['user_account'] = '会员账号';
$_LANG['user_total_consumption'] = '累计消费';
$_LANG['user_total_promote'] = '推广金';

// 会员数据
$_LANG['user_user_sn'] = '会员编号';
$_LANG['user_level'] = '会员等级';
$_LANG['user_level_no'] = '普通会员';
$_LANG['user_username'] = '会员账号';
$_LANG['user_mobile'] = '手机号';
$_LANG['user_email'] = '邮箱';
$_LANG['user_password'] = '登录密码';
$_LANG['user_contact'] = '联系人 ';
$_LANG['user_first_name'] = 'First Name';
$_LANG['user_last_name'] = 'Last Name';
$_LANG['user_phone'] = '联系电话';
$_LANG['user_country'] = '国家';
$_LANG['user_province'] = '省份';
$_LANG['user_address'] = '地址';
$_LANG['user_postcode'] = '邮政编码';
$_LANG['user_sex'] = '性别';
$_LANG['user_nickname'] = '昵称';
$_LANG['user_avatar'] = '头像';
$_LANG['user_login_count'] = '登录次数';
$_LANG['user_last_login'] = '最后登录时间';
$_LANG['user_last_ip'] = '最后登录IP';
$_LANG['user_created_at'] = '注册时间';

// 会员状态
$_LANG['user_status'] = '状态';
$_LANG['user_status_all'] = '全部状态';
$_LANG['user_status_active'] = '正常';
$_LANG['user_status_suspended'] = '已封禁';
$_LANG['user_status_deactivated'] = '已注销';
$_LANG['user_status_action_active'] = '解禁';
$_LANG['user_status_action_suspended'] = '封禁';
$_LANG['user_status_action_deactivated'] = '注销';
$_LANG['user_status_confirm'] = '确定变更该会员状态？';
$_LANG['user_status_change_success'] = '会员状态已变更';
$_LANG['user_status_transit_wrong'] = '当前状态不允许该变更';

// 分销等级
$_LANG['user_distribution_level'] = '分销等级';
$_LANG['user_distribution_level_no'] = '无分销等级';

// 占位符
$_LANG['user_search_placeholder'] = '手机/邮箱/会员编号/姓名';

// 验证信息
$_LANG['user_username_cue'] = '手机号和邮箱至少需要添加一种作为账号';
$_LANG['user_mobile_cue'] = '手机号不能为空，且注意正确输入';
$_LANG['user_mobile_exists'] = '该手机号已经存在，请更换其它手机号';
$_LANG['user_mobile_no_exist'] = '您输入的手机号不存在，请核对后重新输入。';
$_LANG['user_email_cue'] = '邮箱不能为空，且注意正确输入';
$_LANG['user_email_exists'] = '该邮箱已经存在，请更换其它邮箱';
$_LANG['user_email_no_exist'] = '您输入的E-MAIL不存在，请核对后重新输入。';
$_LANG['user_email_check'] = '邮箱验证';
$_LANG['user_password_cue'] = '密码至少包含6个字符。只能使用字母，数字和符号';
$_LANG['user_verification_send'] = '发送验证码';
$_LANG['user_verification_wrong'] = '请输入正确的验证码';
$_LANG['user_verification_outtime'] = '验证超时';
$_LANG['user_verification_body'] = '尊敬的 u%，您好:<br><br>您的邮箱验证码是：c%<br>请勿将验证码透露给其他人。<br><br>本邮件由系统自动发送，请勿直接回复！';

// 会员等级
$_LANG['user_level'] = '会员等级';
$_LANG['user_level_list'] = '等级列表';
$_LANG['user_level_create'] = '添加会员等级';
$_LANG['user_level_edit'] = '编辑会员等级';
$_LANG['user_level_delete'] = '删除会员等级';

$_LANG['user_level_name'] = '等级名称';
$_LANG['user_level_upgrade_type'] = '升级方式';
$_LANG['user_level_upgrade_type_noauto'] = '不自动升级';
$_LANG['user_level_upgrade_type_shopping'] = '消费金额满';
$_LANG['user_level_upgrade_type_promote'] = '推广金额满';
$_LANG['user_level_upgrade_type_cue'] = '消费金额满（自己购物消费的金额总额）；推广金额满（成为分销员后推广商品获得的佣金总额），满足条件将升级到该等级';
$_LANG['user_level_upgrade_condition'] = '升级条件';
$_LANG['user_level_good_discount'] = '商品折扣';
$_LANG['user_level_good_discount_cue'] = '购买商品可以享受的折扣，100%表示不打折';
$_LANG['user_level_direct_reward'] = '商品佣金';
$_LANG['user_level_direct_reward_cue'] = 'A成为分销员直接推荐给B顾客下单消费，则A获得是销售佣金（商品佣金）';
$_LANG['user_level_indirect_reward'] = '邀请佣金';
$_LANG['user_level_indirect_reward_cue'] = '如果B这时候注册成为分销员，推荐C顾客下单消费，则B获得销售佣金（商品佣金），则A获得邀请佣金';
$_LANG['user_level_icon'] = '等级图标';
$_LANG['user_level_upgrade_type_admin'] = '后台调整';
$_LANG['user_level_log'] = '等级变动记录';
$_LANG['user_level_log_manager'] = '等级变动记录';
$_LANG['user_level_log_level_change'] = '等级变更';
$_LANG['user_level_log_upgrade_type'] = '变动方式';
$_LANG['user_level_log_order_sn'] = '触发订单';
$_LANG['user_level_log_time'] = '时间';
$_LANG['user_level_log_view'] = '查看变动';
$_LANG['user_level_log_table_missing'] = '会员等级变动日志表 user_level_log 不存在，请先执行数据库升级后再查看记录。';

// 联系人
$_LANG['user_contact_manager'] = '联系人管理';
$_LANG['user_contact_create'] = '添加联系人';
$_LANG['user_contact_edit'] = '编辑联系人';
$_LANG['user_contact_deleteete'] = '删除联系人';
$_LANG['user_contact_name'] = '姓名';
$_LANG['user_contact_phone'] = '手机号';
$_LANG['user_contact_id_card'] = '身份证';
$_LANG['user_contact_area'] = '所在地区';
$_LANG['user_contact_province'] = '省';
$_LANG['user_contact_city'] = '市';
$_LANG['user_contact_district'] = '区';
$_LANG['user_contact_address'] = '地址';
$_LANG['user_contact_tag'] = '标签';
$_LANG['user_contact_is_default'] = '默认';
$_LANG['user_contact_is_defaulted'] = '已默认';
$_LANG['user_contact_is_default_set'] = '设为默认';

// 操作日志
$_LANG['user_log'] = '操作日志';
$_LANG['user_log_manager'] = '操作日志';
$_LANG['user_log_action'] = '操作类型';
$_LANG['user_log_ip'] = 'IP';
$_LANG['user_log_time'] = '时间';
$_LANG['user_log_result'] = '结果';
$_LANG['user_log_result_success'] = '成功';
$_LANG['user_log_result_fail'] = '失败';
$_LANG['user_log_details'] = '备注';
$_LANG['user_log_view'] = '查看日志';

// 提示信息
$_LANG['user_select_empty'] = '没有选择会员';
$_LANG['user_edit_success'] = '会员编辑成功！';
$_LANG['user_create_success'] = '会员添加成功！';
$_LANG['user_password_success'] = '登录密码修改成功！';

// 参数设置项
$_LANG['user_login_mode_lang'] = '登录模式';
$_LANG['user_login_mode_value'] = 'email';
$_LANG['user_login_mode_cue'] = '输入email使用邮箱作为账号；输入mobile使用手机号作为账号（配套短信模块可以进行短信验证，请从模块扩展安装）；输入weixin使用微信登录则无需注册（需配套微信登录插件或含微信登录功能的小程序）';
$_LANG['user_loginid_mode_lang'] = '微信登录唯一ID';
$_LANG['user_loginid_mode_value'] = 'unionid';
$_LANG['user_loginid_mode_cue'] = '如果输入openid则登录账号只在当前终端有效（这里的终端比如微信公众号、微信小程序、PC网页端），如果要各终端统一则输入unionid，这样各终端登录都作为同一个账号';
$_LANG['user_login_phone_lang'] = '获取微信手机号';
$_LANG['user_login_phone_value'] = '1';
$_LANG['user_login_phone_cue'] = '微信登录后需要获取手机号请输入1，不需要强制获取手机号输入0';
$_LANG['user_delete_balance_exists'] = '该会员仍有余额、冻结金额或积分，无法删除';
$_LANG['user_delete_withdraw_exists'] = '该会员仍有未结清提现，无法删除';
$_LANG['user_level_in_use'] = '该等级下仍有会员，无法删除';
