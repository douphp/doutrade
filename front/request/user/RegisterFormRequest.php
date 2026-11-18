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

use Dou\Core\Foundation\Configuration\Config;
use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员注册提交（route=user/register_post）。
 *
 * 注册模式与模板渲染保持一致：由全局配置 `param.login_mode` 决定走邮箱模式还是手机号模式，
 * 两种模式分别约束唯一性。密码格式与确认一致由 `password|confirmed` 覆盖。
 * 验证码字段只在所选通道（site.mail_username / param.sms_accessKeyId）启用时才必填。
 */
class RegisterFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $data = $this->validationData();

        // 注册模式由全局配置 `param.login_mode` 决定，与模板渲染保持一致；
        // 避免用「post 是否含 email 字段」推断模式，防止空提交时 rule 走到模板上不可见的字段
        // （例如 email 模式模板下，email 为空 → mobile 规则报错 → UI 无对应错误位）。
        $useEmailMode = Config::get('param.login_mode') !== 'mobile';

        // 验证码字段只在对应通道启用时才必填：邮箱模式看 site.mail_username，
        // 手机号模式看 param.sms_accessKeyId。
        $verificationRequired = $useEmailMode
            ? (bool) Config::get('site.mail_username', '')
            : (bool) Config::get('param.sms_accessKeyId', '');

        $rules = array(
            'password' => 'required|password|confirmed',
            'password_confirm' => '',
            'verification' => $verificationRequired ? 'required' : '',
        );
        if ($useEmailMode) {
            $rules['email'] = 'required|email|unique:user,email';
        } else {
            $rules['mobile'] = 'required|phone|unique:user,mobile';
        }

        return $rules;
    }
}
