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

namespace Dou\Admin\Controller\Attribute;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Attribute\AttributeFormRequest;
use Dou\Admin\Service\Attribute\AttributeService;
use Dou\Admin\Service\Menu\AdminNavResolver;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\RouteEntry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台款式属性控制器（route=attribute/...）
 */
class AttributeController extends BaseController
{
    /** @var AttributeService */
    private $attributeService;

    /**
     * @param AttributeService $attributeService
     */
    public function __construct(AttributeService $attributeService)
    {
        $this->attributeService = $attributeService;
    }

    /**
     * 页面级导航覆盖：属性在 module.no_show_menu 中（不占侧栏一行），也无同名子菜单族，
     * 其路由命名空间 admin.attribute.* 无节点可归，因此显式指定侧栏归「商品」。
     *
     * @return array
     */
    private function navVars()
    {
        $entry = request()->routeEntry();
        $routeName = $entry instanceof RouteEntry ? (string) $entry->name : '';

        return array(
            'nav' => AdminNavResolver::resolve($routeName, array('sidebar' => 'product')),
        );
    }

    /**
     * 属性列表（route=attribute）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $catId = $request->integer('id', 0);
        $data = $this->attributeService->buildAttributeListData($catId);

        return $this->view('attribute.htm', $this->navVars() + [
            'ur_here' => lang('attribute'),
            'page_actions' => array(
                array('href' => route('admin.attribute.create'), 'text' => lang('attribute_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'attribute_list' => $data['attribute_list'],
        ]);
    }

    /**
     * 添加属性（route=attribute/create）
     *
     * @return Response
     */
    public function create()
    {
        $data = $this->attributeService->buildAttributeDefaultData();

        $languageForm = $this->languageDraft()->buildFormState('attribute', '');
        return $this->view('attribute.htm', $this->navVars() + [
            'ur_here' => lang('attribute_create'),
            'page_actions' => array(
                array('href' => route('admin.attribute'), 'text' => lang('attribute'), 'style' => ''),
            ),
            'rec' => 'create',
            'product_category' => $data['product_category'],
            'attribute' => $data['attribute'],
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        ]);
    }

    /**
     * 添加提交（route=attribute/store）
     *
     * @param AttributeFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(AttributeFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $newId = $this->attributeService->insert($data);
        return redirect(route('admin.attribute.edit', array('id' => $newId)))
            ->with('success', lang('attribute_add_succes'), route('admin.attribute'), lang('back_to_list'));
    }

    /**
     * 编辑属性（route=attribute/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $attId = $request->integer('id', 0);
        $data = $this->attributeService->buildAttributeEditData($attId);
        if (!$data) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }

        $languageForm = $this->languageDraft()->buildFormState('attribute', $attId);
        return $this->view('attribute.htm', $this->navVars() + [
            'ur_here' => lang('attribute_edit'),
            'page_actions' => array(
                array('href' => route('admin.attribute'), 'text' => lang('attribute'), 'style' => ''),
            ),
            'rec' => 'edit',
            'product_category' => $data['product_category'],
            'attribute' => $data['attribute'],
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        ]);
    }

    /**
     * 更新提交（route=attribute/update）
     *
     * @param AttributeFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(AttributeFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->attributeService->update($data);
        return redirect(route('admin.attribute.edit', array('id' => (int) $data['att_id'])))
            ->with('success', lang('attribute_edit_succes'), route('admin.attribute'), lang('back_to_list'));
    }

    /**
     * 删除属性（DELETE route=attribute/{att_id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $attId = $request->integer('id', 0);
        if ($attId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }
        $result = $this->attributeService->delete($attId, $request->post());

        return $this->respondDeleteResult($result);
    }

    /**
     * 属性值 AJAX 操作（route=attribute/value）
     *
     * @param Request $request
     * @return void
     */
    public function value(Request $request)
    {
        $act = $request->rec('act', 'add');
        $this->attributeService->processValueAjax($act, $request->all());
    }

    /**
     * 新增页分类切换：返回该分类可用的属性区 HTML（GET route=attribute/box）
     *
     * @param Request $request
     * @return void
     */
    public function box(Request $request)
    {
        $this->attributeService->processBoxAjax($request->all());
    }
}
