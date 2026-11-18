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

namespace Dou\Api\Controller\Aftersale;

use Dou\Api\Controller\BaseController;
use Dou\Core\Domain\Aftersale\AftersaleStatus;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Work\WorkService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 售后服务控制器 - 工作人员侧（route=aftersale/work/...）
 * 复用 front AftersaleService 的 work 侧方法
 */
class WorkController extends BaseController
{
    /** @var WorkService */
    private $workService;

    /**
     * @param WorkService $workService
     */
    public function __construct(WorkService $workService)
    {
        $this->workService = $workService;
    }

    /**
     * 工作人员权限检查（公共逻辑），并按需解析 aftersale 模块
     *
     * @return \Dou\Front\Service\Aftersale\AftersaleService 正常返回服务实例；无权限或 aftersale 未启用时抛 HttpResponseException 短路
     */
    private function checkPermission()
    {
        $userId = auth('api')->id();
        if (!$this->workService->checkPermission('aftersale', $userId)) {
            ApiResponse::throwError(ApiCodes::FORBIDDEN, lang('work_no_permission'), array(), 403);
        }
        $aftersaleService = Module::make('aftersale');
        if (!$aftersaleService) {
            ApiResponse::throwError(ApiCodes::FORBIDDEN, lang('work_no_permission'), array(), 403);
        }
        return $aftersaleService;
    }

    /**
     * 工作人员售后列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $aftersaleService = $this->checkPermission();

        $status = (string) $request->input('status', 'all');
        if ($status !== 'all' && !AftersaleStatus::isValid($status)) {
            $status = 'all';
        }
        $page = $request->integer('page', 1);
        $result = $aftersaleService->buildAftersaleWorkListData($status, $page);

        return ApiResponse::success(array(
            'title' => lang('aftersale_list'),
            'aftersale_list' => $result['list'],
            'status_list' => $result['status_list'],
            'pager' => $result['pager'],
        ));
    }

    /**
     * 工作人员售后单详情（GET aftersale/work/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $aftersaleService = $this->checkPermission();

        $aftersaleId = $request->integer('id', 0);
        $aftersale = $aftersaleService->buildAftersaleWorkShowData($aftersaleId);
        if ($aftersale === null) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('page_wrong'), array(), 404);
        }

        return ApiResponse::success(array(
            'title' => lang('aftersale_detail'),
            'aftersale' => $aftersale,
        ));
    }

    /**
     * 工作人员处理售后单（Ajax）
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request)
    {
        $aftersaleService = $this->checkPermission();

        $aftersaleId = $request->integer('id', 0);
        $aftersaleMoney = $request->price('aftersale_money');
        if ($aftersaleMoney === '') {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('price_wrong'), array(), 422);
        }
        $post = $request->post();
        $handleRecord = isset($post['handle_record']) ? $post['handle_record'] : '';
        $itemListRaw = isset($post['item_list']) ? $post['item_list'] : null;
        $itemList = is_array($itemListRaw)
            ? array_map('intval', $itemListRaw)
            : array();

        $result = $aftersaleService->handleAftersaleWorkOrder(
            $aftersaleId,
            $aftersaleMoney,
            $handleRecord,
            $itemList,
            (int) auth('api')->id(),
            (string) $request->ip()
        );

        if ($result['success']) {
            return ApiResponse::success(array(), lang('success'));
        } else {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $result['error'], array(), 422);
        }
        return ApiResponse::success(array());
    }
}
