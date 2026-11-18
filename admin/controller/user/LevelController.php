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
use Dou\Admin\Request\User\LevelFormRequest;
use Dou\Admin\Service\User\LevelService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员等级（route=user/level/...）。
 *
 * 控制器只做路由调度、取参、assign；
 * 列表与表单数据来自 LevelService::build*Data；新增 / 更新通过 LevelFormRequest 校验。
 */
class LevelController extends BaseController
{
    /** @var LevelService */
    private $levelService;

    /**
     * @param LevelService $levelService
     */
    public function __construct(LevelService $levelService)
    {
        $this->levelService = $levelService;
    }

    /**
     * 等级列表（route=user/level）
     *
     * @return Response
     */
    public function index()
    {
        $levelList = $this->levelService->buildLevelListData();
        return $this->view('user_level.htm', [
            'ur_here' => lang('user_level'),
            'page_actions' => array(
                array('href' => route('admin.user.level.create'), 'text' => lang('user_level_create'), 'style' => 'add'),
                array('href' => route('admin.user.level_log'), 'text' => lang('user_level_log'), 'style' => ''),
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'default',
            'level_list' => $levelList,
            'pager' => $this->levelService->buildLevelListPager(count($levelList)),
        ]);
    }

    /**
     * 添加表单（route=user/level/create）
     *
     * @return Response
     */
    public function create()
    {
        return $this->view('user_level.htm', [
            'ur_here' => lang('user_level_create'),
            'page_actions' => array(
                array('href' => route('admin.user.level'), 'text' => lang('user_level_list'), 'style' => ''),
            ),
            'rec' => 'create',
            'user_level' => $this->levelService->buildLevelDefaultData(),
        ]);
    }

    /**
     * 添加提交（route=user/level/store）。
     *
     * 字段白名单与校验规则定义在 LevelFormRequest::rules()。
     *
     * @param LevelFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(LevelFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newId = $this->levelService->insert($data);
        return redirect(route('admin.user.level.edit', array('id' => $newId)))
            ->with('success', lang('user_level_create') . lang('success'), route('admin.user.level'), lang('back_to_list'));
    }

    /**
     * 编辑表单（route=user/level/edit）
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $levelId = $request->integer('id', 0);
        if ($levelId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        $userLevel = $this->levelService->buildLevelEditData($levelId);
        if (!$userLevel) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        return $this->view('user_level.htm', [
            'ur_here' => lang('user_level_edit'),
            'page_actions' => array(
                array('href' => route('admin.user.level'), 'text' => lang('user_level_list'), 'style' => ''),
            ),
            'rec' => 'edit',
            'user_level' => $userLevel,
        ]);
    }

    /**
     * 编辑提交（route=user/level/update）。
     *
     * 字段白名单与校验规则定义在 LevelFormRequest::rules()。
     *
     * @param LevelFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(LevelFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->levelService->update($data);
        return redirect(route('admin.user.level.edit', array('id' => (int) $data['level_id'])))
            ->with('success', lang('user_level_edit') . lang('success'), route('admin.user.level'), lang('back_to_list'));
    }

    /**
     * 删除（route=user/level/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $levelId = $request->integer('id', 0);
        if ($levelId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        $result = $this->levelService->delete($levelId, $request->post());
        return $this->respondDeleteResult($result);
    }
}
