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
use Dou\Admin\Request\Coupon\CouponFormRequest;
use Dou\Admin\Service\Coupon\CouponService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台优惠券控制器（route=coupon/...）
 */
class CouponController extends BaseController
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
        $page = $request->integer('page', 1);
        $bundle = $this->couponService->buildCouponListData($page);

        return $this->view('coupon.htm', [
            'ur_here' => lang('coupon'),
            'page_actions' => array(
                array('href' => route('admin.coupon.create'), 'text' => lang('coupon_create'), 'style' => 'add'),
                array('href' => route('admin.coupon.log'), 'text' => lang('coupon_log'), 'style' => ''),
            ),
            'rec' => 'default',
            'coupon_list' => $bundle['list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @return Response
     */
    public function create()
    {
        $coupon = $this->couponService->buildCouponDefaultData();
        $options = $this->couponService->buildCouponFormOptions($coupon['scope_type'], $coupon['scope_ids']);

        $langFormState = $this->languageDraft()->buildFormState('coupon', '');
        return $this->view('coupon.htm', [
            'ur_here' => lang('coupon_create'),
            'page_actions' => array(
                array('href' => route('admin.coupon'), 'text' => lang('coupon'), 'style' => ''),
            ),
            'rec' => 'create',
            'coupon' => $coupon,
            'module_list' => $options['module_list'],
            'category_list' => $options['category_list'],
            'selected_products' => $options['selected_products'],
            'product_search_url' => route('admin.coupon.products'),
            'btn_lang' => $langFormState['btn_lang'],
            'lang_drafts' => $langFormState['lang_drafts'],
        ]);
    }

    /**
     * @param CouponFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(CouponFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $newId = $this->couponService->insert($data);

        return redirect(route('admin.coupon.edit', array('id' => $newId)))
            ->with('success', lang('coupon_create') . lang('success'), route('admin.coupon'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $id = $request->integer('id', 0);
        $coupon = $this->couponService->buildCouponEditData($id);
        if (!$coupon) {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }
        $options = $this->couponService->buildCouponFormOptions($coupon['scope_type'], $coupon['scope_ids']);

        $langFormState = $this->languageDraft()->buildFormState('coupon', $id);
        return $this->view('coupon.htm', [
            'ur_here' => lang('coupon_edit'),
            'page_actions' => array(
                array('href' => route('admin.coupon'), 'text' => lang('coupon'), 'style' => ''),
            ),
            'rec' => 'edit',
            'coupon' => $coupon,
            'module_list' => $options['module_list'],
            'category_list' => $options['category_list'],
            'selected_products' => $options['selected_products'],
            'product_search_url' => route('admin.coupon.products'),
            'btn_lang' => $langFormState['btn_lang'],
            'lang_drafts' => $langFormState['lang_drafts'],
        ]);
    }

    /**
     * 商品范围搜索（GET coupon/products）。
     *
     * @param Request $request
     * @return void
     */
    public function products(Request $request)
    {
        ApiResponse::throwSuccess($this->couponService->searchProducts(
            (string) $request->get('keyword', ''),
            $request->integer('limit', 20)
        ));
    }

    /**
     * @param CouponFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(CouponFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->couponService->update($data);

        return redirect(route('admin.coupon.edit', array('id' => (int) $data['id'])))
            ->with('success', lang('coupon_edit') . lang('success'), route('admin.coupon'), lang('back_to_list'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }

        $result = $this->couponService->delete($id, $request->post());

        return $this->respondDeleteResult($result);
    }

    /**
     * 列表外框表单占位（无批量操作时返回列表）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->couponService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}
