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

use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Point\PointService;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SchemaService;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台积分商城控制器
 */
class PointController extends BaseController
{
    /** @var PointService */
    private $pointService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /** @var SchemaService */
    private $schema;

    /**
     * @param PointService $pointService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     * @param SchemaService $schema
     */
    public function __construct(
        PointService $pointService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo,
        SchemaService $schema
    ) {
        $this->pointService = $pointService;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
        $this->schema = $schema;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $data = $this->pointService->buildPointListData($request->route('page', 1), route('point'));

        $urHere = $this->breadcrumb->build('point');

        return $this->view('point.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('point'),

            // 页面骨架
            'rec' => 'default',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'product_list' => $data['product_list'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::single('product', $request->route('id'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'), HOME_URL);
        }

        $product = $this->pointService->buildPointShowData($id);
        if (!$product) {
            throw new DomainException(lang('page_wrong'), HOME_URL);
        }

        $codeHead = Config::get('site.code_head', '');
        $schema = $this->schema->generic('product', $product);
        if ($schema) {
            $codeHead = $schema . $codeHead;
        }

        $urHere = $this->breadcrumb->build('point', 'point_detail');

        return $this->view('point.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('point', 'point_detail'),

            // 页面骨架
            'rec' => 'detail',
            'code_head' => $codeHead,
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'product' => $product,
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
