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
 * Release Date: 2026-09-26
 */

/**
 * user 模块后台子菜单族声明（族名 = 文件名）
 *
 * 随模块安装 / 卸载生命周期（installed 清单 file_list 维护本文件），
 * 由 {@see \Dou\Admin\Service\Menu\AdminMenuRegistry::submenus()} 装配。
 * 字段语义见 AdminMenuRegistry docblock；匹配裁决与渲染由 AdminNavResolver 完成。
 *
 * 本族是会员中心相关模块的唯一信源：钱包、积分等模块自身不注册侧栏节点，
 * 在此按路由别名显式列出即为会员中心子菜单的一项，其页面侧栏由族名回落到「会员」。
 * 可装配模块（vip / point / money 等）无需判断安装状态：声明保留，未安装时其路由
 * 不在路由清单中，该项自然不渲染也不参与匹配。
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return array(
    'title' => 'user',
    'icon' => 'bi-people',
    'items' => array(
        // 会员等级（user.level）等无独立项的下级域统一归「会员」入口项
        'user',
        // 会员中心入口（这些模块在 module.no_show_menu 中，不占侧栏一行）
        'vip',
        'point',
        'money',
        'withdraw',
        'share',
        'favorites',
        // 分销入口首段为已存在族名，命中时渲染分销族自身子菜单（文案取 distribution_manager）
        'distribution',
        'user.contact',
        'user.log',
        'user.level_log',
    ),
    // 项图标（键为路由别名或项 key）：与 items 分离书写，缺项不渲染图标
    'icons' => array(
        'user' => 'bi-people',
        'vip' => 'bi-gem',
        'point' => 'bi-coin',
        'money' => 'bi-wallet2',
        'withdraw' => 'bi-cash-stack',
        'share' => 'bi-share',
        'favorites' => 'bi-heart',
        'distribution' => 'bi-diagram-3',
        'user.contact' => 'bi-person-lines-fill',
        'user.log' => 'bi-clock-history',
        'user.level_log' => 'bi-list-check',
    ),
);
