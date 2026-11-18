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

namespace Dou\Front\Service\Email;

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Front\Model\Email\Email;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台邮件订阅（Ajax JSON）
 */
class EmailService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * @param string $ip
     * @return bool
     */
    public function isWaterByIp($ip)
    {
        return Email::countUnreadByIp($ip) >= 1;
    }

    /**
     * 尝试写入订阅，业务阻断统一抛出 DomainException。
     *
     * @param string $email
     * @param int $userId
     * @param string $ip
     * @param string $addTime datetime 字符串（Y-m-d H:i:s）
     * @return void
     */
    public function subscribeOrMessage($email, $userId, $ip, $addTime)
    {
        if (!Check::email($email)) {
            throw new DomainException(lang('email_email') . lang('is_wrong'));
        }
        if (Email::existsByEmail($email)) {
            throw new DomainException(lang('email_exist'));
        }
        if ($this->isWaterByIp($ip)) {
            throw new DomainException(lang('email_is_water'));
        }
        Email::insertRow(array(
            'user_id' => (int) $userId,
            'email' => $email,
            'ip' => $ip,
            'created_at' => $addTime,
        ));
    }

    /**
     * @param string $email
     * @param int $userId
     * @param string $ip
     * @param string $addTime datetime 字符串（Y-m-d H:i:s）
     * @return string
     */
    public function subscribe($email, $userId, $ip, $addTime)
    {
        $this->subscribeOrMessage($email, $userId, $ip, $addTime);
        return lang('email_subscribe_success');
    }
}
