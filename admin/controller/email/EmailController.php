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

namespace Dou\Admin\Controller\Email;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Email\EmailService;
use Dou\Core\Facade\Session;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订阅邮箱控制器
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
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        Session::set('page', $page);
        $list = $this->emailService->buildEmailListData($page);
        return $this->view('email.htm', [
            'ur_here' => lang('email'),
            'rec' => 'default',
            'email_list' => $list['email_list'],
            'pager' => $list['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function check(Request $request)
    {
        $id = $request->integer('id', 0);
        $sessionPage = Session::get('page', 1);
        $page = Check::number($sessionPage) ? $sessionPage : 1;
        $this->emailService->markCheckedAndRedirect($id, $page);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function delAll(Request $request)
    {
        $checkbox = $request->input('checkbox', array());
        $checkbox = is_array($checkbox) ? $checkbox : array();
        $this->emailService->deleteSelected($checkbox);
        return redirect(route('admin.email'))->with('success', lang('del_succes'));
    }
}
