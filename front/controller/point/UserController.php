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

namespace Dou\Front\Controller\Point;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\User\UserStatsService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Point\PointService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台积分会员中心控制器（/user/point）
 */
class UserController extends BaseController
{
    /** @var PointService */
    private $pointService;

    /** @var UserStatsService */
    private $userStats;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param PointService $pointService
     * @param UserStatsService $userStats
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        PointService $pointService,
        UserStatsService $userStats,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->pointService = $pointService;
        $this->userStats = $userStats;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = (int) auth('front')->id();
        $data = $this->pointService->buildPointLogListData($userId, $request->route('page', 1), route('point.user'));

        $urHere = $this->breadcrumb->build('user', 'point_log');

        return $this->view('point_user.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'point_log'),

            // 页面骨架
            'rec' => 'user',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('point_log'),
            'point_log_list' => $data['point_log_list'],
            'total' => $this->userStats->totalPoint($userId),
            'pager' => $data['pager'],
        ]);
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
            'link_user_center' => $this->buildLinkUserCenter('point'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
