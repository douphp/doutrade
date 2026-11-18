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

namespace Dou\Front\Controller\Aftersale;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\Aftersale\AftersaleApplyFormRequest;
use Dou\Front\Request\Aftersale\ReturnShipFormRequest;
use Dou\Front\Service\Aftersale\AftersaleService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后服务会员中心控制器（/user/aftersale/*）
 */
class UserController extends BaseController
{
    /** @var AftersaleService */
    private $aftersaleService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param AftersaleService $aftersaleService
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
         */
    public function __construct(
        AftersaleService $aftersaleService,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->aftersaleService = $aftersaleService;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 售后服务.会员中心（列表，/user/aftersale）
     *
     * @param Request $request
     */
    public function index(Request $request)
    {
        $page = $request->route('page', 1);
        $result = $this->aftersaleService->buildAftersaleUserListData(auth('front')->id(), $page, route('aftersale.user'));

        $urHere = $this->breadcrumb->build('user', 'aftersale_list');

        return $this->view('aftersale.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'aftersale_list'),

            // 页面骨架
            'rec' => 'user',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('aftersale_list'),
            'aftersale_list' => $result['list'],
            'pager' => $result['pager'],
            'back_url' => route('aftersale.user'),
        ]);
    }

    /**
     * 售后服务.申请表单（route=user/aftersale/create，GET）
     *
     * @param Request $request
     */
    public function create(Request $request)
    {
        $rawOrderSn = $request->input('order_sn');
        $orderSn = Check::number($rawOrderSn) ? $rawOrderSn : '';
        $userId = (int) auth('front')->id();
        $data = $this->aftersaleService->buildAftersaleApplyData($orderSn, $userId);

        if (!$data['order']) {
            return redirect(route('order.user'));
        }
        if (!$data['can_apply']) {
            return message()->respond(lang('aftersale_apply_wrong'), route('order.user'), 3);
        }

        // 创建场景：业务主键提交后才生成，上传走 draft_token；清掉历史草稿后发一枚新令牌。
        attachment()->cleanupUserDrafts('user', $userId, 'aftersale');
        $draftToken = attachment()->newDraftToken('user', $userId);

        $urHere = $this->breadcrumb->build('aftersale', 'aftersale_apply');

        return $this->view('aftersale.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('aftersale', 'aftersale_apply'),

            // 页面骨架
            'rec' => 'apply',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('aftersale_apply'),
            'item_list' => $data['item_list'],
            'order' => $data['order'],
            'draft_token' => $draftToken,
            'img_list_html' => attachment()->galleryByDraft('aftersale', $draftToken, 'user', $userId, 'gallery'),
        ]);
    }

    /**
     * 售后服务.提交（route=user/aftersale，POST）
     *
     * @param AftersaleApplyFormRequest $formRequest
     * @param Request $request
     */
    public function store(AftersaleApplyFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $rawOrderSn = $request->post('order_sn');
        $orderSn = Check::number($rawOrderSn) ? $rawOrderSn : '';
        $reason = $request->post('reason');
        $type = (string) $request->post('type', '');
        $draftToken = (string) $request->post('draft_token', '');

        $submittedItems = is_array($request->post('item_list')) ? (array) $request->post('item_list') : array();
        $result = $this->aftersaleService->submitAftersaleApply(
            $orderSn,
            (int) auth('front')->id(),
            $reason,
            $submittedItems,
            $type,
            $draftToken
        );

        if ($result['success']) {
            return $this->respond($request, route('aftersale.user'), array(), lang('aftersale_apply_success'));
        }
        if ($result['error'] === 'items_required') {
            throw new DomainException(lang('aftersale_items_required'), route('aftersale.user'));
        }

        if ($result['error'] === 'invalid_items') {
            throw new DomainException(lang('aftersale_invalid_items'), route('aftersale.user'));
        }
        if ($result['error'] === 'save_failed') {
            throw new DomainException(lang('aftersale_apply_failed'), route('order.user'));
        }
        throw new DomainException(lang('aftersale_apply_wrong'), route('order.user'));
    }

    /**
     * 售后服务.详情（协商时间线 + 寄回物流 + 买家可执行动作，route=user/aftersale/{id}，GET）
     *
     * @param Request $request
     */
    public function show(Request $request)
    {
        $aftersaleId = $request->integer('id', 0);
        $userId = (int) auth('front')->id();
        $aftersale = $this->aftersaleService->buildAftersaleShowData($aftersaleId, $userId);

        if (!$aftersale) {
            return message()->respond(lang('page_wrong'), route('aftersale.user'), 3);
        }

        $urHere = $this->breadcrumb->build('aftersale', 'aftersale_detail');

        return $this->view('aftersale.dwt', [
            'page_title' => $this->seo->pageTitle('aftersale', 'aftersale_detail'),

            'rec' => 'detail',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            'head' => lang('aftersale_detail'),
            'aftersale' => $aftersale,
            'back_url' => route('aftersale.user'),
        ]);
    }

    /**
     * 售后服务.买家填寄回运单（WAITING_RETURN → RETURN_SHIPPED）
     *
     * @param ReturnShipFormRequest $formRequest
     * @param Request $request
     */
    public function returnShip(ReturnShipFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $result = $this->aftersaleService->submitReturnShipping(
            (int) $data['id'],
            (int) auth('front')->id(),
            (string) $data['shipping_company'],
            (string) $data['tracking_no'],
            (string) $request->ip()
        );

        if (!$result['success']) {
            throw new DomainException($result['error'], route('aftersale.user'));
        }

        return $this->respond(
            $request,
            route('aftersale.user.show', array('id' => (int) $data['id'])),
            array(),
            lang('aftersale_return_success')
        );
    }

    /**
     * 售后服务.买家撤销（PENDING / WAITING_RETURN → CANCELLED）
     *
     * @param Request $request
     */
    public function cancel(Request $request)
    {
        $aftersaleId = $request->integer('id', 0);

        $result = $this->aftersaleService->cancelAftersale(
            $aftersaleId,
            (int) auth('front')->id(),
            (string) $request->ip()
        );

        if (!$result['success']) {
            throw new DomainException($result['error'], route('aftersale.user'));
        }

        return $this->respond(
            $request,
            route('aftersale.user'),
            array(),
            lang('aftersale_cancel_success')
        );
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
            'nav_middle_list' => $this->nav->middle(0, 'user', 0),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('aftersale'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
