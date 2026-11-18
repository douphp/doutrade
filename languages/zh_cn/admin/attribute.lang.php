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

// 规格属性
$_LANG['attribute'] = '规格属性';
$_LANG['attribute_manage'] = '管理';
$_LANG['attribute_create'] = '添加';
$_LANG['attribute_edit'] = '编辑规格属性';
$_LANG['attribute_delete'] = '删除规格属性';
$_LANG['attribute_del_is_parent'] = '还存在使用"d%"规格属性的商品，您不能删除';
$_LANG['attribute_add_succes'] = '添加规格属性成功';
$_LANG['attribute_edit_succes'] = '编辑规格属性成功';

// 字段
$_LANG['attribute_category'] = '所属分类';
$_LANG['attribute_category_all'] = '通用';
$_LANG['attribute_name'] = '名称';
$_LANG['attribute_type'] = '类型';
$_LANG['attribute_type_text'] = '文本';
$_LANG['attribute_type_image'] = '图片';

// 数据
$_LANG['attribute_value'] = '规格属性列表';
$_LANG['attribute_value_value'] = '选项';
$_LANG['attribute_value_type'] = '类型';
$_LANG['attribute_value_image'] = '图片';
$_LANG['attribute_value_remark'] = '备注(可选)';
$_LANG['attribute_value_price_change'] = '加价';

// 提示信息
$_LANG['attribute_value_must_edit_mode'] = '只有在编辑模式下才可以添加规格属性';
$_LANG['attribute_value_price_cue'] = '每个规格属性的“第一个值”必须作为默认属性并匹配默认的价格，因此不能设置价格浮动，如果已经设置，将会被自动清零';
$_LANG['attribute_value_create_cue'] = '可直接添加规格选项，保存商品时一并写入；切换商品分类会刷新属性区并清空已填选项；每个规格的第一个选项不能设置加价；图片型规格需保存后在编辑页上传图片';
$_LANG['attribute_value_category_mismatch'] = '存在不属于当前商品分类的规格选项，请切换分类或移除对应选项';
$_LANG['attribute_value_category_switch_confirm'] = '切换分类将清空已填规格选项，是否继续？';
