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

namespace Dou\Front\Service\User;

use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Front\Model\User\User;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 邮箱找回密码 token 生成服务（不负责发信）。
 *
 * 调用方（Controller / API）拿到 token 与 user 行后，自行通过邮件服务下发链接。
 */
class PasswordResetService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 生成并写入邮箱找回密码 token（仅生成 token，不发信）。
     *
     * @param string $email
     * @param int $expireSeconds
     * @return array ok, user, token, error
     */
    public function createPasswordResetTokenForEmail($email, $expireSeconds = 3600)
    {
        $email = (string) $email;
        if (!Check::email($email)) {
            return array('ok' => false, 'user' => null, 'token' => '', 'error' => lang('user_email_cue'));
        }

        $userModel = User::where('email', $email)->first();
        $user = $userModel ? $userModel->getAttributes() : null;
        if (!$user) {
            return array('ok' => false, 'user' => null, 'token' => '', 'error' => lang('user_email_no_exist'));
        }

        $time = time();
        $token = bin2hex(openssl_random_pseudo_bytes(16));
        $tokenHash = hash('sha256', $token);
        $userModel->fill(array(
            'reset_token' => $tokenHash,
            'reset_token_expires_at' => date('Y-m-d H:i:s', $time + (int) $expireSeconds),
        ), 'update')->save();

        return array('ok' => true, 'user' => $user, 'token' => $token, 'error' => '');
    }
}
