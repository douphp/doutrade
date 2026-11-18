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

namespace Dou\Core\Service\User;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员审计日志 `action` 列常量集合（dou_user_log.action）。
 *
 * 与 {@see \Dou\Core\Service\Audit\AuditService::writeUserLog} 的 `$action` 入参一一对应；
 * 后台筛选下拉 {@see \Dou\Admin\Service\User\LogService::actionOptions} 与
 * 最近登录查询 {@see \Dou\Core\Service\User\UserLogQuery} 均以此为单一字典源。
 *
 * 常量值由历史落库格式保留方括号包裹（`[LOGIN_SUCCESS]` 等），便于与已落库数据兼容；
 * 新增 action 仅在末尾追加常量并同步加入 {@see all()}。
 */
class UserLogAction
{
    /** 会员登录成功 */
    const LOGIN_SUCCESS = '[LOGIN_SUCCESS]';

    /** 会员登录失败（任意失败原因） */
    const LOGIN_FAIL = '[LOGIN_FAIL]';

    /** 会员基础资料编辑（联系方式 / 头像 / 昵称等） */
    const EDIT_INFO = '[EDIT_INFO]';

    /** 会员主动修改密码 */
    const PASSWORD_CHANGE = '[PASSWORD_CHANGE]';

    /** 管理员变更会员状态（封禁 / 解禁 / 注销） */
    const SET_STATUS = '[SET_STATUS]';

    /**
     * 所有 action 候选（按定义顺序），供后台筛选下拉与白名单校验使用。
     *
     * @return array<int, string>
     */
    public static function all()
    {
        return array(
            self::LOGIN_SUCCESS,
            self::LOGIN_FAIL,
            self::EDIT_INFO,
            self::PASSWORD_CHANGE,
            self::SET_STATUS,
        );
    }
}
