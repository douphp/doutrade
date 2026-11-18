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
 * 会员审计日志 `details` 列标签字典（dou_user_log.details）。
 *
 * 与 {@see \Dou\Core\Service\Audit\AuditService::writeUserLog} 的 `$details` 入参对应；
 * 字典统一收敛为大写下划线标签，便于审计可读且消除 PII 落库。
 *
 * 与 {@see UserLogAction} 配合：`action` 列分类、`details` 列细分原因。
 */
class UserLogDetail
{
    /** 用户名或密码字段为空（前置必填校验） */
    const EMPTY_INPUT = 'EMPTY_INPUT';

    /** 字段格式非法或合并校验失败（含手机号 / 邮箱格式不符） */
    const FIELD_INVALID = 'FIELD_INVALID';

    /** 同 IP 登录失败次数已超阈值，命中 IP 限流 */
    const IP_RATE_LIMITED = 'IP_RATE_LIMITED';

    /** 账号查无此人（用户不存在） */
    const ACCOUNT_NOT_FOUND = 'ACCOUNT_NOT_FOUND';

    /** 账号已被锁定（连续失败次数过多） */
    const ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';

    /** 密码错误 */
    const PASSWORD_WRONG = 'PASSWORD_WRONG';

    /** 短信 / 邮件验证码不匹配 */
    const VERCODE_INVALID = 'VERCODE_INVALID';

    /** 图形验证码已过期 */
    const CAPTCHA_EXPIRED = 'CAPTCHA_EXPIRED';

    /** 微信小程序：openid 路径登录成功 */
    const WX_LOGIN = 'WX_LOGIN';

    /** 微信小程序：unionid 路径绑定成功并登录 */
    const WX_LOGIN_UNIONID = 'WX_LOGIN_UNIONID';

    /** 微信小程序：手机号匹配既有账号并登录 */
    const WX_LOGIN_PHONE = 'WX_LOGIN_PHONE';

    /** 微信小程序：自动建号成功并登录 */
    const WX_REGISTER = 'WX_REGISTER';

    /** 微信小程序：jscode2session 返回数据缺失 */
    const WX_CODE_INVALID = 'WX_CODE_INVALID';

    /** 微信小程序：loginid_mode=unionid 但微信返回缺 unionid */
    const WX_UNIONID_MISSING = 'WX_UNIONID_MISSING';

    /** 微信小程序：openid 路径事务异常回滚 */
    const WX_LOGIN_EXCEPTION = 'WX_LOGIN_EXCEPTION';

    /** 微信小程序：unionid 路径绑定事务异常回滚 */
    const WX_BIND_UNIONID_EXCEPTION = 'WX_BIND_UNIONID_EXCEPTION';

    /** 微信小程序：手机号路径绑定事务异常回滚 */
    const WX_BIND_PHONE_EXCEPTION = 'WX_BIND_PHONE_EXCEPTION';

    /** 个人资料基础编辑（联系方式 / 头像 / 昵称等） */
    const PROFILE_BASIC_UPDATED = 'PROFILE_BASIC_UPDATED';
}
