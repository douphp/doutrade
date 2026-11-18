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
 * 忘记密码流程提交（route=user/password_reset_post）。
 *
 * 流程分两段：`act=default` 提交账号（username 必填），`act=password`
 * 提交新密码（password|confirmed）；验证码、账号存在性等业务校验留 controller。
 */
class PasswordResetPostFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $data = $this->validationData();
        $act = isset($data['act']) ? (string) $data['act'] : 'default';

        if ($act === 'password') {
            return array(
                'password' => 'required|password|confirmed',
                'password_confirm' => '',
            );
        }

        return array(
            'username' => 'required',
            'captcha' => '',
        );
    }
}
