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

namespace Dou\Admin\Controller\User;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\User\UserContactFormRequest;
use Dou\Admin\Service\User\ContactService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员联系方式（route=user/contact/...）。
 *
 * 控制器只做路由调度、取参、assign；
 * 列表数据来自 ContactService::buildContactListData；更新动作走 UserContactFormRequest。
 */
class ContactController extends BaseController
{
    /** @var ContactService */
    private $contactService;

    /**
     * @param ContactService $contactService
     */
    public function __construct(ContactService $contactService)
    {
        $this->contactService = $contactService;
    }

    /**
     * 联系方式列表（route=user/contact）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $usernameRaw = trim((string) $request->input('username'));
        $nameRaw = trim((string) $request->input('name'));
        $phoneRaw = trim((string) $request->input('phone'));

        $username = Check::username($usernameRaw) ? $usernameRaw : '';
        $name = ($nameRaw !== '' && !Check::illegalChar($nameRaw)) ? $nameRaw : '';
        $phone = Check::mobile($phoneRaw) ? $phoneRaw : '';

        $rejectFilter = array(
            'username' => $usernameRaw !== '' && !Check::username($usernameRaw),
            'name' => $nameRaw !== '' && Check::illegalChar($nameRaw),
            'phone' => $phoneRaw !== '' && !Check::mobile($phoneRaw),
        );

        $pageUrl = route('admin.user.contact');
        if ($usernameRaw !== '') {
            $pageUrl .= '&username=' . rawurlencode($usernameRaw);
        }
        if ($nameRaw !== '') {
            $pageUrl .= '&name=' . rawurlencode($nameRaw);
        }
        if ($phoneRaw !== '') {
            $pageUrl .= '&phone=' . rawurlencode($phoneRaw);
        }

        $page = $request->integer('page', 1);

        $result = $this->contactService->buildContactListData($username, $name, $phone, $pageUrl, $page, $rejectFilter);

        return $this->view('user_contact.htm', [
            'ur_here' => lang('user_contact'),
            'page_actions' => array(
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'default',
            'req' => array(
                'username' => $usernameRaw,
                'name' => $nameRaw,
                'phone' => $phoneRaw,
            ),
            'contact_list' => $result['list'],
            'pager' => $result['pager'],
        ]);
    }

    /**
     * 编辑联系方式表单（route=user/contact/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        $contact = $this->contactService->buildContactEditData($id);
        if (!$contact) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        return $this->view('user_contact.htm', [
            'ur_here' => lang('user_contact_edit'),
            'page_actions' => array(
                array('href' => route('admin.user.contact'), 'text' => lang('user_contact'), 'style' => ''),
            ),
            'rec' => 'edit',
            'contact' => $contact,
        ]);
    }

    /**
     * 更新联系方式（route=user/contact/update）。
     *
     * 字段白名单与校验规则定义在 UserContactFormRequest::rules()。
     *
     * @param UserContactFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(UserContactFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->contactService->update($data);
        return redirect(route('admin.user.contact.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('user_contact_edit') . lang('success'), route('admin.user.contact'), lang('back_to_list'));
    }

    /**
     * 删除联系方式（route=user/contact/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        $result = $this->contactService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }
}
