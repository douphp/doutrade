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

namespace Dou\Admin\Controller\Brand;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Brand\BrandFormRequest;
use Dou\Admin\Service\Brand\BrandService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台品牌控制器
 */
class BrandController extends BaseController
{
    /** @var BrandService */
    private $brandService;

    /**
     * @param BrandService $brandService
     */
    public function __construct(BrandService $brandService)
    {
        $this->brandService = $brandService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $keyword = $request->input('keyword') ? trim($request->input('keyword')) : '';
        $page = $request->integer('page', 1);
        $data = $this->brandService->buildBrandListData($keyword, $page);

        return $this->view('brand.htm', [
            'ur_here' => lang('brand'),
            'page_actions' => array(
                array('href' => route('admin.brand.create'), 'text' => lang('brand_create'), 'style' => 'add'),
            ),
            'rec' => 'default',
            'keyword' => $data['keyword'],
            'brand_list' => $data['brand_list'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        $adminId = (int) auth('admin')->id();
        attachment()->cleanupUserDrafts('admin', $adminId, 'brand');
        $draftToken = attachment()->newDraftToken('admin', $adminId);

        $form = $this->brandService->buildBrandDefaultData();
        $langFormState = $this->languageDraft()->buildFormState('brand', '');
        return $this->view('brand.htm', [
            'ur_here' => lang('brand_create'),
            'page_actions' => array(
                array('href' => route('admin.brand'), 'text' => lang('brand'), 'style' => ''),
            ),
            'rec' => 'create',
            'item_id' => 0,
            'draft_token' => $draftToken,
            'brand' => $form['brand'],
            'item_content' => '',
            'btn_lang' => $langFormState['btn_lang'],
            'lang_drafts' => $langFormState['lang_drafts'],
        ]);
    }

    /**
     * @param BrandFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(BrandFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $draftToken = (string) $request->post('draft_token', '');
        $adminId = (int) auth('admin')->id();

        $newId = $this->brandService->insert($data, $draftToken, $adminId);
        return redirect(route('admin.brand.edit', array('id' => $newId)))
            ->with('success', lang('brand_add_succes'), route('admin.brand'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.brand'));
        }

        $form = $this->brandService->buildBrandEditData($id);
        if ($form === null) {
            throw new DomainException(lang('item_not_exist'), route('admin.brand'));
        }

        $langFormState = $this->languageDraft()->buildFormState('brand', $id);
        return $this->view('brand.htm', [
            'ur_here' => lang('brand_edit'),
            'page_actions' => array(
                array('href' => route('admin.brand'), 'text' => lang('brand'), 'style' => ''),
            ),
            'rec' => 'edit',
            'item_id' => $form['item_id'],
            'draft_token' => '',
            'item_content' => $form['item_content'],
            'brand' => $form['brand'],
            'btn_lang' => $langFormState['btn_lang'],
            'lang_drafts' => $langFormState['lang_drafts'],
        ]);
    }

    /**
     * @param BrandFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(BrandFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->brandService->update($data, (int) auth('admin')->id());
        return redirect(route('admin.brand.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('brand_edit_succes'), route('admin.brand'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.brand'));
        }
        $result = $this->brandService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->brandService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
