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

namespace Dou\Front\Controller\Brand;

use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Brand\BrandService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台品牌控制器（列表 index、详情 show 分别走独立 action）。
 */
class BrandController extends BaseController
{
    /** @var BrandService */
    private $brandService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param BrandService $brandService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
         */
    public function __construct(
        BrandService $brandService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->brandService = $brandService;

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
        $class = '';
        $classReq = $request->route('class');
        if ($classReq !== null && $classReq !== '') {
            if (Check::illegalChar($classReq)) {
                return message()->respond(lang('illegal'), ROOT_URL);
            }
            $class = trim($classReq);
        }

        $data = $this->brandService->buildBrandListData($request->route('page', 1), $class);

        $urHere = $this->breadcrumb->build('brand');

        return $this->view('brand.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('brand'),

            // 页面骨架
            'rec' => 'default',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'brand_list' => $data['brand_list'],
            'class' => $class,
            'screen' => $data['screen'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $id = RouteId::single('brand', $request->route('id'));
        if ($id == -1) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $detail = $this->brandService->buildBrandShowData($id);
        if (!$detail) {
            throw new DomainException('page_wrong', HOME_URL);
        }

        $brandRow = $detail['brand'];

        $urHere = $this->breadcrumb->build('brand', '', $brandRow['name']);

        return $this->view('brand.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('brand', '', $brandRow['name']),

            // 页面骨架
            'rec' => 'detail',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'brand' => $brandRow,
        ]);
    }

    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
        );
    }
}
