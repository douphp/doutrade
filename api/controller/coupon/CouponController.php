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
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Coupon\CouponService as CouponCore;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Coupon\CouponService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序优惠券控制器
 */
class CouponController extends BaseController
{
    /** @var CouponService */
    private $couponService;

    /** @var CouponCore */
    private $coupon;

    /**
     * @param CouponService $couponService
     * @param CouponCore $coupon
     */
    public function __construct(CouponService $couponService, CouponCore $coupon)
    {
        $this->couponService = $couponService;
        $this->coupon = $coupon;
    }

    /**
     * 券中心。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = (int) auth('api')->id();
        $data = $this->coupon->getCouponCenterPage(
            $userId,
            $request->integer('page', 1),
            route('coupon')
        );
        $pager = isset($data['pager']) ? (array) $data['pager'] : array();

        return ApiResponse::success(array(
            'title' => lang('coupon_center'),
            'coupon_list' => $data['coupon_list'],
            'pager' => $pager,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($data['coupon_list']),
        ));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function claim(Request $request)
    {
        $userId = (int) auth('api')->id();
        $id = $request->integer('id', 0);

        $result = $this->couponService->claimCoupon($userId, $id, (string) $request->ip());
        if (empty($result['ok'])) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                lang('coupon_reason_' . $result['reason'], lang('coupon_reason_failed'))
            );
        }

        $payload = array();
        if ($id) {
            $couponList = $this->coupon->getCouponList($userId);
            $payload['coupon_list'] = $couponList;
            $payload['claimable'] = false;
            foreach ($couponList as $item) {
                if ((int) $item['id'] === (int) $id) {
                    $payload['claimable'] = !empty($item['claimable']);
                    break;
                }
            }
        }

        return ApiResponse::success($payload, lang('coupon_get_success'));
    }
}
