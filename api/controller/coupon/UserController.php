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

namespace Dou\Api\Controller\Coupon;

use Dou\Api\Controller\BaseController;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Coupon\CouponService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序优惠券会员中心控制器（?route=user/coupon）
 */
class UserController extends BaseController
{
    /** @var CouponService */
    private $couponService;

    /**
     * @param CouponService $couponService
     */
    public function __construct(CouponService $couponService)
    {
        $this->couponService = $couponService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $result = $this->couponService->buildCouponListData(
            auth('api')->id(),
            $request->integer('page', 1),
            route('coupon')
        );
        $couponList = isset($result['coupon_list']) ? (array) $result['coupon_list'] : array();
        $pager = isset($result['pager']) ? (array) $result['pager'] : array();

        return ApiResponse::success(array(
            'title' => lang('coupon'),
            'coupon_list' => $couponList,
            'pager' => $pager,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($couponList),
        ));
    }
}
