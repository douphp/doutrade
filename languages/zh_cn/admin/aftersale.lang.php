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
$_LANG['aftersale'] = '售后服务';
$_LANG['aftersale_list'] = '退换货列表';
$_LANG['aftersale_apply'] = '售后服务申请';
$_LANG['aftersale_item_list'] = '选择售后商品';
$_LANG['aftersale_btn'] = '申请售后';
$_LANG['aftersale_picksite'] = '自提点';

// 字段
$_LANG['aftersale_aftersale_sn'] = '售后编号';
$_LANG['aftersale_user'] = '客户信息';
$_LANG['aftersale_aftersale_money'] = '退款金额';
$_LANG['aftersale_item_list'] = '售后商品';
$_LANG['aftersale_reason'] = '售后原因';
$_LANG['aftersale_handle_record'] = '处理说明';
$_LANG['aftersale_image'] = '商品照片';
$_LANG['aftersale_status'] = '状态';
$_LANG['aftersale_status_all'] = '全部';
$_LANG['aftersale_status_0'] = '处理中';
$_LANG['aftersale_status_1'] = '处理完成';
$_LANG['aftersale_created_at'] = '申请时间';

// 字符串状态机文案
$_LANG['aftersale_status_pending'] = '申请中';
$_LANG['aftersale_status_approved'] = '已同意';
$_LANG['aftersale_status_rejected'] = '已驳回';
$_LANG['aftersale_status_cancelled'] = '已撤销';
$_LANG['aftersale_status_waiting_return'] = '待买家寄回';
$_LANG['aftersale_status_return_shipped'] = '买家已寄出';
$_LANG['aftersale_status_received'] = '卖家已收货';
$_LANG['aftersale_status_refunding'] = '退款中';
$_LANG['aftersale_status_refunded'] = '已退款';
$_LANG['aftersale_status_refund_failed'] = '退款失败';

// 售后类型
$_LANG['aftersale_type'] = '售后类型';
$_LANG['aftersale_type_refund_only'] = '仅退款';
$_LANG['aftersale_type_refund_with_return'] = '退货退款';
$_LANG['aftersale_type_exchange'] = '换货';
$_LANG['aftersale_type_repair'] = '维修';

// 金额明细
$_LANG['aftersale_amount_detail'] = '退款金额';
$_LANG['aftersale_apply_amount'] = '申请金额';
$_LANG['aftersale_approved_amount'] = '审核金额';
$_LANG['aftersale_refunded_amount'] = '已退金额';

// 买家寄回物流
$_LANG['aftersale_return_info'] = '买家寄回物流';
$_LANG['aftersale_return_company'] = '物流公司';
$_LANG['aftersale_return_sn'] = '运单号';
$_LANG['aftersale_return_time'] = '寄出时间';

// 状态机操作
$_LANG['aftersale_operation'] = '售后操作';
$_LANG['aftersale_approve'] = '审核同意';
$_LANG['aftersale_receive'] = '确认收货';
$_LANG['aftersale_receive_confirm'] = '确认已收到买家寄回的商品？';
$_LANG['aftersale_dispatch_refund'] = '派发退款';
$_LANG['aftersale_dispatch_confirm'] = '确认派发退款？钱包腿将自动到账，网关腿落待确认。';
$_LANG['aftersale_reject'] = '驳回';
$_LANG['aftersale_reject_confirm'] = '确认驳回该售后申请？';
$_LANG['aftersale_reject_reason'] = '驳回理由';
$_LANG['aftersale_close_refund_failed'] = '关闭售后（不退款）';
$_LANG['aftersale_close_refund_failed_confirm'] = '确认关闭该售后？将释放待确认退款并把订单恢复到退款前状态，且不再退款。';

// 待确认网关退款
$_LANG['aftersale_pending_gateway_refund'] = '待确认网关退款';
$_LANG['aftersale_refund_sn'] = '退款单号';
$_LANG['aftersale_refund_channel'] = '退款渠道';
$_LANG['aftersale_confirm_gateway_refund'] = '确认网关退款已完成';
$_LANG['aftersale_confirm_gateway_confirm'] = '确认该笔网关退款已在支付平台后台完成？';
$_LANG['aftersale_fail_gateway_refund'] = '标记失败';
$_LANG['aftersale_fail_gateway_confirm'] = '确认该笔网关退款未成功？标记失败后可重新派发退款。';

// 协商时间线
$_LANG['aftersale_timeline'] = '协商时间线';

// 提示信息
$_LANG['aftersale_handle_wrong'] = '操作失败';
$_LANG['order_aftersale_not_supported'] = '充值套餐属于现金等价物，不支持售后退款';
$_LANG['aftersale_money_invalid'] = '退款金额无效';
$_LANG['aftersale_money_over'] = '退款金额大于订单总金额';
$_LANG['aftersale_money_over_available'] = '退款金额大于订单当前可退金额';

// 参数设置
$_LANG['core_allow_aftersale_time'] = '允许申请售后期限';
$_LANG['core_allow_aftersale_time_cue'] = '从用户提货后开始计算，单位为小时';
$_LANG['core_pay_timeout'] = '付款超时时间';
$_LANG['core_pay_timeout_cue'] = '订单提交后超过时间将自动取消订单，并释放库存，单位为分钟';
