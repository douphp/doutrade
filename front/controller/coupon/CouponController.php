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

namespace Dou\Front\Controller\Coupon;

use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Coupon\CouponService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 优惠券控制器（会员）
 */
class CouponController extends BaseController
{
    /** @var CouponService */
    private $couponService;

    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param CouponService $couponService
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
         */
    public function __construct(
        CouponService $couponService,
        NavigationBuilder $nav,
        SeoResolver $seo
    ) {
        $this->couponService = $couponService;

        $this->nav = $nav;
        $this->seo = $seo;
    }

    /**
     * 券中心。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\Response
     */
    public function index(Request $request)
    {
        $userId = (int) auth('front')->id();
        $data = $this->couponService->getCouponCenterPage(
            $userId,
            $request->route('page', 1),
            route('coupon')
        );

        return $this->view('coupon_center.dwt', array(
            'page_title' => $this->seo->pageTitle('coupon', 0, lang('coupon_center')),
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'rec' => 'coupon_center',
            'coupon_list' => $data['coupon_list'],
            'pager' => $data['pager'],
        ));
    }

    /**
     * 券详情暂不单独成页，回到券中心。
     *
     * @return \Dou\Core\Web\Http\Response
     */
    public function show()
    {
        return redirect(route('coupon'));
    }

    /**
     * @param Request $request
     * @return void
     */
    public function claim(Request $request)
    {
        $userId = (int) auth('front')->id();
        $id = $request->integer('id', 0);

        $result = $this->couponService->claimCoupon($userId, $id, (string) $request->ip());
        if (empty($result['ok'])) {
            $reason = (string) $result['reason'];
            $terminal = in_array($reason, array(
                'not_found', 'disabled', 'not_started', 'expired',
                'sold_out', 'limit_reached', 'first_order_only',
            ), true);

            ApiResponse::throwError(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                lang('coupon_reason_' . $reason, lang('coupon_reason_failed')),
                array(),
                422,
                $terminal ? array('claimable' => false, 'text' => lang('coupon_status_got')) : array()
            );
        }

        $state = $this->couponService->getCouponState($userId, $id);
        $claimable = !empty($state['claimable']);

        ApiResponse::throwSuccess(
            array(
                'text' => lang('coupon_status_got'),
                'claimable' => $claimable,
            ),
            lang('coupon_get_success')
        );
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
