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

namespace Dou\Admin\Controller\Aftersale;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Aftersale\ApproveFormRequest;
use Dou\Admin\Request\Aftersale\RejectFormRequest;
use Dou\Admin\Service\Aftersale\AftersaleService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台售后管理（route=aftersale/...）
 */
class AftersaleController extends BaseController
{
    /** @var AftersaleService */
    private $aftersaleService;

    /**
     * @param AftersaleService $aftersaleService
     */
    public function __construct(AftersaleService $aftersaleService)
    {
        $this->aftersaleService = $aftersaleService;
    }

    /**
     * 售后列表（route=aftersale）
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $bundle = $this->aftersaleService->buildAftersaleListData($request->all());

        return $this->view('aftersale.htm', [
            'ur_here' => lang('aftersale'),
            'rec' => 'default',
            'req' => $bundle['req'],
            'aftersale_list' => $bundle['aftersale_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * 售后详情（route=aftersale/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $aftersale_id = $request->integer('id', 0);
        $unlock = $request->alpha('unlock');

        $bundle = $this->aftersaleService->buildAftersaleViewData($aftersale_id, $unlock);
        if (!$bundle) {
            return redirect(route('admin.aftersale'));
        }

        return $this->view('aftersale.htm', [
            'ur_here' => lang('aftersale'),
            'page_actions' => array(
                array('href' => route('admin.aftersale'), 'text' => lang('aftersale_list'), 'style' => ''),
            ),
            'page_cue_inline' => $bundle['unlock'] ? '<em class="c-red">解锁状态</em>' : '',
            'rec' => 'show',
            'aftersale' => $bundle['aftersale'],
            'unlock' => $bundle['unlock'],
        ]);
    }

    /**
     * 审核同意（route=aftersale/approve）
     *
     * @param ApproveFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function approve(ApproveFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->aftersaleService->approve(
            (int) $data['id'],
            (float) $data['approved_amount'],
            (string) $data['note'],
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }

    /**
     * 驳回售后（route=aftersale/reject）
     *
     * @param RejectFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function reject(RejectFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $this->aftersaleService->reject(
            (int) $data['id'],
            (string) $data['note'],
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }

    /**
     * 确认收货（route=aftersale/receive）
     *
     * @param Request $request
     * @return void
     */
    public function receive(Request $request)
    {
        $this->aftersaleService->receive(
            $request->integer('id', 0),
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }

    /**
     * 派发退款（route=aftersale/dispatch_refund）
     *
     * @param Request $request
     * @return void
     */
    public function dispatchRefund(Request $request)
    {
        $this->aftersaleService->dispatchRefund(
            $request->integer('id', 0),
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }

    /**
     * 确认网关退款已完成（route=aftersale/confirm_gateway_refund）
     *
     * @param Request $request
     * @return void
     */
    public function confirmGatewayRefund(Request $request)
    {
        $this->aftersaleService->confirmGatewayRefund(
            (string) $request->post('refund_sn', ''),
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }

    /**
     * 标记网关退款失败（route=aftersale/fail_gateway_refund）
     *
     * @param Request $request
     * @return void
     */
    public function failGatewayRefund(Request $request)
    {
        $this->aftersaleService->failGatewayRefund(
            $request->integer('id', 0),
            (int) auth('admin')->id(),
            (string) $request->ip()
        );
    }
}
