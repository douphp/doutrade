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

namespace Dou\Admin\Controller\Area;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Area\AreaFormRequest;
use Dou\Admin\Service\Area\AreaService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台地区管理（route=area/...）
 */
class AreaController extends BaseController
{
    /** @var AreaService */
    private $areaService;

    /**
     * @param AreaService $areaService
     */
    public function __construct(AreaService $areaService)
    {
        $this->areaService = $areaService;
    }

    /**
     * 地区列表（route=area）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        // 分区标识形如 zh_cn / en_us，含下划线，不能用 alpha（^[a-z]+$）
        $group = $request->slug('lang');
        $data = $this->areaService->buildAreaListData($group);

        return $this->view('area.htm', [
            'ur_here' => lang('area_list'),
            'page_actions' => array(
                array('href' => route('admin.area.create', array('lang' => $data['area_group'])), 'text' => lang('area_create'), 'style' => ''),
            ),
            'rec' => 'default',
            'area_list' => $data['area_list'],
            'area_group' => $data['area_group'],
            'area_group_name' => $data['area_group_name'],
            'single_root' => $data['single_root'],
            'area_tabs' => $data['area_tabs'],
        ]);
    }

    /**
     * 添加地区表单（route=area/create）
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $data = $this->areaService->buildAreaDefaultData($request->slug('lang'));

        $languageForm = $this->languageDraft()->buildFormState('area', '');
        return $this->view('area.htm', [
            'ur_here' => lang('area_create'),
            'page_actions' => array(
                array('href' => route('admin.area', array('lang' => $data['area_group'])), 'text' => lang('area_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'area' => $data['area'],
            'area_list' => $data['area_list'],
            'area_group' => $data['area_group'],
            'area_group_name' => $data['area_group_name'],
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        ]);
    }

    /**
     * 添加提交（route=area/store）
     *
     * @param AreaFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(AreaFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newId = $this->areaService->insert($data);
        return redirect(route('admin.area.edit', array('id' => $newId)))
            ->with('success', lang('area_add_succes'), route('admin.area'), lang('back_to_list'));
    }

    /**
     * 编辑地区（route=area/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        $data = $this->areaService->buildAreaEditData($id);
        if (!$data) {
            throw new DomainException(lang('illegal'), route('admin.area'));
        }

        $languageForm = $this->languageDraft()->buildFormState('area', $id);
        return $this->view('area.htm', [
            'ur_here' => lang('area_edit'),
            'page_actions' => array(
                array('href' => route('admin.area.destroy', array('id' => $data['area']['id'])), 'text' => lang('area_delete'), 'style' => '', 'delete' => true),
                array('href' => route('admin.area', array('lang' => $data['area_group'])), 'text' => lang('area_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'area_list' => $data['area_list'],
            'area' => $data['area'],
            'area_group' => $data['area_group'],
            'area_group_name' => $data['area_group_name'],
            'btn_lang' => $languageForm['btn_lang'],
            'lang_drafts' => $languageForm['lang_drafts'],
        ]);
    }

    /**
     * 更新提交（route=area/update）
     *
     * @param AreaFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(AreaFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->areaService->update($data);
        return redirect(route('admin.area.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('area_edit_succes'), route('admin.area'), lang('back_to_list'));
    }

    /**
     * 删除地区（route=area/del）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.area'));
        }
        $result = $this->areaService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }
}
