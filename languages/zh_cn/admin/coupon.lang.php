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
$_LANG['coupon'] = '优惠券';
$_LANG['coupon_list'] = '优惠券列表';
$_LANG['coupon_create'] = '添加优惠券';
$_LANG['coupon_edit'] = '编辑优惠券';
$_LANG['coupon_delete'] = '删除优惠券';
$_LANG['coupon_del_have_log'] = '"d%"已经有用户领取，您不能删除';
$_LANG['coupon_add_succes'] = '添加优惠券成功';
$_LANG['coupon_edit_succes'] = '编辑优惠券成功';
$_LANG['coupon_amount'] = '优惠金额';
$_LANG['coupon_condition_text'] = '满d%可用';
$_LANG['coupon_got'] = '已领';
$_LANG['coupon_used'] = '已使用';

// 优惠券
$_LANG['coupon_id'] = '优惠券编号';
$_LANG['coupon_name'] = '优惠券名称';
$_LANG['coupon_coupon_sn'] = '优惠券码';
$_LANG['coupon_type'] = '类型';
$_LANG['coupon_type_money'] = '优惠金额';
$_LANG['coupon_type_percent'] = '优惠百分比';
$_LANG['coupon_face_value'] = '面值';
$_LANG['coupon_money'] = '面值';
$_LANG['coupon_type_cue'] = '类型如果是“优惠百分比”就输入比如10（意思就是优惠10%），如果类型是“优惠金额”就输入比如20（意思就是优惠20元）';
$_LANG['coupon_condition'] = '满多少可用';
$_LANG['coupon_condition_cue'] = '购物金额满多少才能使用，设置为0则表示无限制';
$_LANG['coupon_time'] = '使用期限';
$_LANG['coupon_start_at'] = '开始时间';
$_LANG['coupon_end_at'] = '结束时间';
$_LANG['coupon_brief'] = '简介';
$_LANG['coupon_image'] = '图片';
$_LANG['coupon_status'] = '状态';
$_LANG['coupon_status_0'] = '停用';
$_LANG['coupon_status_1'] = '启用';
$_LANG['coupon_created_at'] = '添加时间';

// 优惠券领用记录
$_LANG['coupon_log'] = '优惠券领用记录';
$_LANG['coupon_log_delete'] = '优惠券领用记录删除';
$_LANG['coupon_log_user'] = '会员';
$_LANG['coupon_log_claimed_at'] = '领取时间';
$_LANG['coupon_log_used_at'] = '使用时间';
$_LANG['coupon_log_order_sn'] = '关联订单';
$_LANG['coupon_log_status'] = '状态';
$_LANG['coupon_log_status_0'] = '未使用';
$_LANG['coupon_log_status_1'] = '已使用';

// 高级规则
$_LANG['coupon_max_discount'] = '百分比券封顶金额';
$_LANG['coupon_max_discount_cue'] = '仅百分比券生效，0 表示不封顶';
$_LANG['coupon_limit_per_user'] = '每人限领';
$_LANG['coupon_limit_per_user_cue'] = '0 表示不限领';
$_LANG['coupon_total_quantity'] = '发放总量';
$_LANG['coupon_total_quantity_cue'] = '0 表示不限量';
$_LANG['coupon_issued_quantity'] = '已领取';
$_LANG['coupon_is_first_order_only'] = '仅限首单';
$_LANG['coupon_is_first_order_only_cue'] = '仅限尚无已付款/已完成订单的会员领取使用';
$_LANG['coupon_scope'] = '适用范围';
$_LANG['coupon_scope_all'] = '全部商品';
$_LANG['coupon_scope_module'] = '指定模块';
$_LANG['coupon_scope_category'] = '指定分类（含子分类）';
$_LANG['coupon_scope_product'] = '指定商品';
$_LANG['coupon_scope_cue'] = '分类范围对选中分类及其子分类生效';
$_LANG['coupon_scope_selected'] = '已选择';
$_LANG['coupon_products_search'] = '搜索商品名称';
$_LANG['coupon_products_empty'] = '未找到商品';
$_LANG['coupon_search'] = '搜索';
$_LANG['coupon_unlimited'] = '不限量';
$_LANG['coupon_money_unit'] = '元';
$_LANG['coupon_face_value_invalid'] = '面值必须大于 0，百分比券不能超过 100';
$_LANG['coupon_time_invalid'] = '结束时间必须晚于开始时间';
$_LANG['coupon_scope_invalid'] = '请选择有效的适用范围';
$_LANG['coupon_total_quantity_invalid'] = '发放总量不能小于已领取数量';
$_LANG['coupon_log_used_cannot_delete'] = '已核销的优惠券记录不能删除';
