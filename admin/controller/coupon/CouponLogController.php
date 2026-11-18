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

namespace Dou\Admin\Controller\Coupon;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Coupon\CouponLogBatchActionFormRequest;
use Dou\Admin\Service\Coupon\CouponLogService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Arr;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台优惠券领取记录控制器（route=coupon_log/...）
 */
class CouponLogController extends BaseController
{
    /** @var CouponLogService */
    private $couponLogService;

    /**
     * @param CouponLogService $couponLogService
     */
    public function __construct(CouponLogService $couponLogService)
    {
        $this->couponLogService = $couponLogService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $filters = array(
            'coupon_id' => $request->integer('coupon_id', 0),
            'time_start' => trim((string) $request->input('time_start', '')),
            'time_end' => trim((string) $request->input('time_end', '')),
            'page' => $request->integer('page', 1),
            'key' => trim((string) $request->input('key', '')),
            'menu' => trim((string) $request->input('menu', '')),
        );
        $mergedFilters = $this->couponLogService->mergeSessionFilter($filters);
        $bundle = $this->couponLogService->buildCouponLogListData(
            Arr::get($mergedFilters, 'coupon_id', ''),
            Arr::get($mergedFilters, 'time_start', ''),
            Arr::get($mergedFilters, 'time_end', ''),
            (int) Arr::get($mergedFilters, 'page', 1)
        );

        return $this->view('coupon_log.htm', [
            'ur_here' => lang('coupon_log'),
            'page_actions' => array(
                array('href' => route('admin.coupon'), 'text' => lang('coupon_list'), 'style' => ''),
            ),
            'rec' => 'default',
            'req' => $mergedFilters,
            'log_list' => $bundle['list'],
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
            throw new DomainException(lang('illegal'), route('admin.coupon.log'));
        }

        $result = $this->couponLogService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * @param CouponLogBatchActionFormRequest $formRequest
     * @return Response
     */
    public function action(CouponLogBatchActionFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $this->couponLogService->action($data);
        return redirect(route('admin.coupon.log'))->with('success', lang('del_succes'));
    }
}
