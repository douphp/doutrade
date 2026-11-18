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

namespace Dou\Api\Controller\User;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\User\ContactService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序用户联系人子控制器（`route=user/contact/...`）
 */
class ContactController extends BaseController
{
    /** @var ContactService */
    private $contactService;

    /**
     */
    public function __construct(ContactService $contactService)
    {
        $this->contactService = $contactService;
    }

    /**
     * 地址簿列表（route=user/contact）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $result = $this->contactService->buildContactListData(auth('api')->id(), $page, '');

        return ApiResponse::success(array(
            'title' => lang('user_contact_manager'),
            'contact_list' => $result['list'],
            'pager' => $result['pager'],
        ));
    }

    /**
     * 新增地址表单（route=user/contact/create）
     *
     * @return Response
     */
    public function create()
    {
        return ApiResponse::success(array(
            'title' => lang('user_contact_create'),
        ));
    }

    /**
     * 新增地址提交（route=user/contact/store）
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $rules = array(
            'name' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'address' => 'required|illegal_char',
        );
        if (Config::get('site.language.default', '') === 'zh_cn') {
            $rules['phone'] = 'required|phone';
        }
        if (Config::get('features.area', false)) {
            $rules['province'] = 'required|illegal_char';
            $rules['city'] = 'required|illegal_char';
        }

        $errors = array();
        try {
            $request->validate($rules, array(), true);
        } catch (DomainException $e) {
            $errors = $e->hasErrors() ? $e->getErrors() : array('_global' => $e->getMessage());
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        $newId = $this->contactService->insertContact(auth('api')->id(), $request->post());

        return ApiResponse::success(
            array('contact_id' => $newId),
            lang('user_contact_create') . lang('success')
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id');
        $contact = DB::table('user_contact')->where('id', $id)->where('user_id', auth('api')->id())->find();

        return ApiResponse::success(array(
            'title' => lang('user_contact_edit'),
            'contact' => $contact ? $contact : array(),
        ));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function update(Request $request)
    {
        $id = $request->integer('id');
        $rules = array(
            'name' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'address' => 'required|illegal_char',
        );
        if (Config::get('site.language.default', '') === 'zh_cn') {
            $rules['phone'] = 'required|phone';
        }
        if (Config::get('features.area', false)) {
            $rules['province'] = 'required|illegal_char';
            $rules['city'] = 'required|illegal_char';
        }

        $errors = array();
        try {
            $request->validate($rules, array(), true);
        } catch (DomainException $e) {
            $errors = $e->hasErrors() ? $e->getErrors() : array('_global' => $e->getMessage());
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        $this->contactService->updateContact(auth('api')->id(), $id, $request->post());

        return ApiResponse::success(
            array('contact_id' => $id),
            lang('user_contact_edit') . lang('success')
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id');
        if (!$this->contactService->delete(auth('api')->id(), $id)) {
            throw new DomainException(lang('del_fail'));
        }

        return ApiResponse::success(
            array(),
            lang('user_contact_del') . lang('success')
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function set(Request $request)
    {
        $id = $request->integer('id');
        $this->contactService->setDefaultContact(auth('api')->id(), $id);

        return ApiResponse::success(
            array(),
            lang('user_contact_is_default_set') . lang('success')
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function listJson(Request $request)
    {
        $userId = auth('api')->id();
        $contactId = $this->contactService->resolveContactId(
            $userId,
            $request->integer('contact_id')
        );

        return ApiResponse::success(array(
            'contact_list' => $this->contactService->getJsonList($userId, $contactId),
        ));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function info(Request $request)
    {
        $userId = auth('api')->id();
        $contactId = $this->contactService->resolveContactId(
            $userId,
            $request->integer('contact_id')
        );

        return ApiResponse::success(array(
            'contact' => $this->contactService->getJsonInfo($userId, $contactId),
        ));
    }
}
