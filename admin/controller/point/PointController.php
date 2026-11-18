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

namespace Dou\Admin\Controller\Point;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Point\PointLogBatchActionFormRequest;
use Dou\Admin\Service\Point\PointService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台积分流水控制器（route=point/...）
 */
class PointController extends BaseController
{
    /** @var PointService */
    private $pointService;

    /**
     * @param PointService $pointService
     */
    public function __construct(PointService $pointService)
    {
        $this->pointService = $pointService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $username = $request->username('username');
        $time_end = $request->input('time_end') ? trim($request->input('time_end')) : '';
        $time_start = $request->input('time_start') ? trim($request->input('time_start')) : '';
        $page = $request->integer('page', 1);

        $bundle = $this->pointService->buildPointListData($username, $time_start, $time_end, $page);

        return $this->view('point.htm', [
            'ur_here' => lang('point_log'),
            'page_actions' => array(
                array('href' => route('admin.point.set'), 'text' => lang('set'), 'style' => ''),
            ),
            'rec' => 'default',
            'filter_username' => $username,
            'filter_time_start' => $time_start,
            'filter_time_end' => $time_end,
            'point_log_list' => $bundle['list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.point'));
        }
        $result = $this->pointService->deleteByIdOrConfirm($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * @param PointLogBatchActionFormRequest $formRequest
     * @return Response
     */
    public function action(PointLogBatchActionFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $result = $this->pointService->action($data);
        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * @return Response
     */
    public function set()
    {
        return redirect($this->pointService->seedPointScaleParameter());
    }
}
