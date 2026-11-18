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
 * 手机验证码登录提交（route=user/login_phone_post）。
 *
 * 仅校验手机号格式与验证码必填；IP 限流、验证码比对、自动建号等业务规则由
 * LoginService::validatePhoneLogin 处理；captcha 过期等业务校验留 controller。
 */
class LoginPhoneFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'mobile' => 'required|phone',
            'verification' => 'required',
        );
    }
}
