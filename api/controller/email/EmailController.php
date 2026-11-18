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

namespace Dou\Api\Controller\Email;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Email\EmailService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 邮件订阅控制器（复用 Front EmailService）。
 */
class EmailController extends BaseController
{
    /** @var EmailService */
    private $emailService;

    /**
     * @param EmailService $emailService
     */
    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * @return Response
     */
    public function index()
    {
        return ApiResponse::success(array(
            'title' => lang('email'),
        ));
    }

    /**
     * 邮件订阅（POST email）
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $email = trim((string) $request->post('email', ''));
        if (!Check::email($email)) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('email_cue'), array(), 422);
        }

        $ip = $request->ip();
        $addTime = date('Y-m-d H:i:s');
        $userId = (int) (auth('api') ? auth('api')->id() : 0);
        $message = '';

        try {
            $message = $this->emailService->subscribe($email, $userId, $ip, $addTime);
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return ApiResponse::success(array(), $message);
    }
}
