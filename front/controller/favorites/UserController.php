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

namespace Dou\Front\Controller\Favorites;

use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Favorites\UserService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 收藏夹会员中心控制器（/user/favorites/*）
 */
class UserController extends BaseController
{
    /** @var UserService */
    private $userService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param UserService $userService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
         */
    public function __construct(
        UserService $userService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->userService = $userService;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 我的收藏（/user/favorites）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = (int) auth('front')->id();

        $data = $this->userService->buildUserListPage($userId, $request->route('page', 1), route('favorites.user'));

        $urHere = $this->breadcrumb->build('user', 'favorites');

        return $this->view('favorites.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'favorites'),

            // 页面骨架
            'rec' => 'user',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('favorites'),
            'favorites_list' => $data['favorites_list'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function store(Request $request)
    {
        $userId = (int) auth('front')->id();
        $module = $request->extendId('module', 'product', 'post');
        $itemId = $request->integer('item_id', 0);

        $this->userService->addIfNotExists($userId, $module, $itemId);

        ApiResponse::throwSuccess(array(
            'text' => lang('favorites_ed'),
        ), lang('favorites_create_success'));
    }

    /**
     * 取消收藏（/user/favorites/{id}，DELETE）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $userId = (int) auth('front')->id();
        $id = $request->integer('id', 0);

        $this->userService->deleteByIdAndUser($id, $userId);
        $page = Session::get('page', '');
        return redirect(route('favorites.user', [], ['query' => ['page' => $page]]));
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
            'link_user_center' => $this->buildLinkUserCenter('favorites'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
