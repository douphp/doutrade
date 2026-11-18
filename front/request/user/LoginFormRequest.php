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

namespace Dou\Front\Request\User;

use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 账号密码登录提交（route=user/login_post）。
 *
 * 用户名同时支持邮箱与手机号两种形态，FormRequest 只校验是否填写；
 * 字段类型（email|mobile）与 IP 限流、账号锁定等业务规则由
 * LoginService::validateLoginCredentials 处理。captcha session 比对留在 controller。
 */
class LoginFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'username' => 'required',
            'password' => 'required',
            'remember' => 'boolean',
            'captcha' => '',
        );
    }
}
