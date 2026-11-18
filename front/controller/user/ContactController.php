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

namespace Dou\Front\Controller\User;

use Dou\Core\Facade\DB;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\User\ContactFormRequest;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;
use Dou\Front\Service\User\ContactService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 地址簿控制器（route=user/contact/...）
 *
 * URL 映射（由 front/route/user.php 的 Route::resource(...)->sub('contact') 显式声明）：
 *   route=user/contact            (GET)    → index()    地址簿列表
 *   route=user/contact/create     (GET)    → create()   新增表单
 *   route=user/contact            (POST)   → store()    新增提交
 *   route=user/contact/{id}/edit  (GET)    → edit()     编辑表单
 *   route=user/contact/{id}       (PUT)    → update()   编辑保存
 *   route=user/contact/{id}       (DELETE) → destroy()  删除
 *   route=user/contact/set        (GET)    → set()      设为默认
 *   route=user/contact/list_json → listJson() JSON 列表（snake → camel 自动映射；同义 ?route=user/contact/listJson）
 *   route=user/contact/info    → info()     JSON 单条
 */
class ContactController extends BaseController
{
    /** @var ContactService */
    private $contactService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param ContactService $contactService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
         */
    public function __construct(
        ContactService $contactService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->contactService = $contactService;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 地址簿列表（route=user/contact）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->route('page', 1);
        $result = $this->contactService->buildContactListData(auth('front')->id(), $page, route('user.contact'));

        foreach ($result['list'] as &$item) {
            $item['url_default'] = route('user.contact.set', [], ['query' => ['id' => $item['id']]]);
            $item['url_edit'] = route('user.contact.edit', ['id' => $item['id']]);
            $item['url_delete'] = route('user.contact.destroy', ['id' => $item['id']]);
        }
        unset($item);

        $urHere = $this->breadcrumb->build('user', 'user_contact_manager');

        return $this->view('user_contact.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_contact_manager'),

            // 页面骨架
            'rec' => 'contact',
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'pager' => $result['pager'],
            'head' => lang('user_contact_manager'),
            'contact_list' => $result['list'],
        ]);
    }

    /**
     * 新增地址表单（route=user/contact/create）
     *
     * @return Response
     */
    public function create()
    {
        $urHere = $this->breadcrumb->build('user', 'user_contact_create');

        return $this->view('user_contact.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_contact_create'),

            // 页面骨架
            'rec' => 'create',
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_contact_create'),
            'contact' => $this->emptyContactFormRow(),
        ]);
    }

    /**
     * 新增地址提交（route=user/contact/store）
     *
     * @param Request $request
     * @return Response
     */
    public function store(ContactFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $newId = $this->contactService->insertContact(auth('front')->id(), $request->post());

        if ($request->input('do') === 'vue') {
            ApiResponse::throwSuccess(
                array('contact_id' => $newId),
                lang('user_contact_create') . lang('success')
            );
        }

        return $this->respond($request, route('user.contact'), array(), lang('user_contact_create') . lang('success'));
    }

    /**
     * 编辑地址表单（route=user/contact/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id');
        $row = DB::table('user_contact')->where('id', $id)->where('user_id', auth('front')->id())->find();
        if (!is_array($row)) {
            return redirect(route('user.contact'));
        }

        $contact = array_merge($this->emptyContactFormRow(), $row);
        // 历史英文记录 name 可能为空，中文表单预填归一展示名
        $contact['name'] = UserContactQuery::displayName($contact);
        $contact['is_default'] = !empty($contact['is_default']) ? '1' : '0';

        $urHere = $this->breadcrumb->build('user', 'user_contact_edit');

        return $this->view('user_contact.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_contact_edit'),

            // 页面骨架
            'rec' => 'edit',
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_contact_edit'),
            'contact' => $contact,
        ]);
    }

    /**
     * 编辑地址保存（route=user/contact/update）
     *
     * @param Request $request
     * @return Response
     */
    public function update(ContactFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $id = $request->integer('id');

        $this->contactService->updateContact(auth('front')->id(), $id, $request->post());

        if ($request->input('do') === 'vue') {
            ApiResponse::throwSuccess(
                array('contact_id' => $id),
                lang('user_contact_edit') . lang('success')
            );
        }

        return $this->respond($request, route('user.contact'), array(), lang('user_contact_edit') . lang('success'));
    }

    /**
     * 删除地址（route=user/contact/{id}，DELETE）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id');
        return $this->contactService->delete(auth('front')->id(), $id)
            ? message()->respond(lang('del_success'), route('user.contact'))
            : message()->respond(lang('del_fail'));
    }

    /**
     * 设为默认地址（route=user/contact/set）
     *
     * @param Request $request
     * @return Response
     */
    public function set(Request $request)
    {
        $id = $request->integer('id');
        $this->contactService->setDefaultContact(auth('front')->id(), $id);
        return redirect(route('user.contact'));
    }

    /**
     * 地址列表 JSON（route=user/contact/list_json）
     *
     * @param Request $request
     * @return void
     */
    public function listJson(Request $request)
    {
        $userId = auth('front')->id();
        $contactId = $this->contactService->resolveContactId(
            $userId,
            $request->integer('contact_id')
        );
        ApiResponse::throwSuccess(array(
            'contact_list' => $this->contactService->getJsonList($userId, $contactId),
        ));
    }

    /**
     * 单条地址 JSON（route=user/contact/info）
     *
     * @param Request $request
     * @return void
     */
    public function info(Request $request)
    {
        $userId = auth('front')->id();
        $contactId = $this->contactService->resolveContactId(
            $userId,
            $request->integer('contact_id')
        );
        ApiResponse::throwSuccess(array(
            'contact' => $this->contactService->getJsonInfo($userId, $contactId),
        ));
    }

    /**
     * 新增联系人表单用的空数据行（与 user_contact.dwt 字段一致）
     *
     * @return array
     */
    private function emptyContactFormRow()
    {
        return array(
            'id' => '',
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'id_card' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'address' => '',
            'tag' => '',
            'is_default' => '0',
        );
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('user'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
