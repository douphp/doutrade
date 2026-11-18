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

namespace Dou\Api\Controller\Point;

use Dou\Api\Controller\BaseController;
use Dou\Core\Service\User\UserStatsService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Point\PointService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序积分会员中心控制器（?route=point/user）
 */
class UserController extends BaseController
{
    /** @var PointService */
    private $pointService;

    /** @var UserStatsService */
    private $userStats;

    /**
     * @param PointService $pointService
     * @param UserStatsService $userStats
     */
    public function __construct(PointService $pointService, UserStatsService $userStats)
    {
        $this->pointService = $pointService;
        $this->userStats = $userStats;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = auth('api')->id();
        $result = $this->pointService->buildPointLogListData($userId, $request->integer('page', 1), route('point.user'));
        return ApiResponse::success(array(
            'title' => lang('point_log'),
            'log_list' => isset($result['point_log_list']) ? (array) $result['point_log_list'] : array(),
            'total' => $this->userStats->totalPoint($userId),
            'pager' => isset($result['pager']) ? $result['pager'] : array(),
        ));
    }
}
