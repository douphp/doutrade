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

namespace Dou\Api\Controller\Order;

use Dou\Api\Controller\BaseController;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Work\WorkService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Order\WorkOrderService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序订单工作台（route=order/work/...）
 */
class WorkController extends BaseController
{
    /** @var WorkService */
    private $workService;

    /** @var WorkOrderService */
    private $workOrderService;

    /** @var OrderCore */
    private $order;

    /**
     * @param WorkService $workService
     * @param WorkOrderService $workOrderService
     * @param OrderCore $orderCore
     */
    public function __construct(
        WorkService $workService,
        WorkOrderService $workOrderService,
        OrderCore $orderCore
    ) {
        $this->workService = $workService;
        $this->workOrderService = $workOrderService;
        $this->order = $orderCore;
    }

    /**
     * 工作台订单列表
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = $this->mustLoginAndPermission();
        $status = (string) $request->input('status', 'all');
        $page = $request->integer('page', 1);

        $payload = $this->workOrderService->getWorkOrderList($status, $page, $userId, Module::make('aftersale'));
        $payload['title'] = lang('order_my');

        return ApiResponse::success((array) $payload);
    }

    /**
     * 工作台订单详情（GET order/work/{order_sn}）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $userId = $this->mustLoginAndPermission();
        $orderSn = $request->digits('order_sn');
        $order = $this->workOrderService->getWorkOrderDetail($orderSn, $userId, Module::make('aftersale'));
        if (!$order) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('order_not_exist'), array(), 404);
        }

        return ApiResponse::success(array(
            'order_sn' => $orderSn,
            'title' => lang('order_show'),
            'order' => $order,
            'payment' => plugin()->defaultPaymentSlug(currency()->contextForOrder($order)['code']),
            'shipping_list' => $this->order->getShippingList(),
            'need_pay_check' => $order['pay_id'] === 'offlinepay'
                && $order['status'] === OrderStatus::AWAITING_CONFIRMATION
                && $this->workOrderService->hasPendingOfflinePaymentLeg((int) $order['order_id']),
        ));
    }

    /**
     * 取消订单
     *
     * @param Request $request
     * @return Response
     */
    public function orderCancel(Request $request)
    {
        $this->mustLoginAndPermission();
        $orderSn = $request->digits('order_sn');
        $ok = $this->workOrderService->cancelWorkOrder($orderSn);

        if (!$ok) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_not_exist'), array(), 422);
        }

        return ApiResponse::success(array());
    }

    /**
     * 线下支付审核通过
     *
     * @param Request $request
     * @return Response
     */
    public function payCheck(Request $request)
    {
        $this->mustLoginAndPermission();
        $orderId = $request->integer('order_id', 0);
        if (!$orderId) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
        }
        $ok = $this->workOrderService->payCheckWorkOrder($orderId);
        if (!$ok) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('page_wrong'), array(), 422);
        }

        return ApiResponse::success(array());
    }

    /**
     * 发货 / 更新物流
     *
     * @param Request $request
     * @return Response
     */
    public function tracking(Request $request)
    {
        $this->mustLoginAndPermission();
        $orderId = $request->integer('order_id', 0);
        if (!$orderId) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
        }
        $shippingId = $request->post('shipping_id', '');
        $trackingNo = $request->post('tracking_no', '');
        $ok = $this->workOrderService->updateWorkTracking($orderId, $shippingId, $trackingNo);
        if (!$ok) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('page_wrong'), array(), 422);
        }

        return ApiResponse::success(array());
    }

    /**
     * 登录与工作权限校验。
     *
     * features.user 关闭场景已由 Router 的 Module::assertUserAvailable() 闸住，
     * 本方法仅判定登录态与工作端权限。校验失败抛出携带 ApiResponse 的
     * HttpResponseException 短路，由 api 入口统一捕获 send；调用方拿到的恒为合法 int，
     * 杜绝"返回值未消费仍继续执行"的鉴权旁路。
     *
     * @return int
     * @throws \Dou\Core\Web\Http\HttpResponseException 未登录 / 无工作端权限时
     */
    private function mustLoginAndPermission()
    {
        $userId = (int) auth('api')->id();
        if ($userId <= 0) {
            ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
        }

        if (!$this->workService->checkPermission('order', $userId)) {
            ApiResponse::throwError(ApiCodes::FORBIDDEN, lang('work_no_permission'), array(), 403);
        }

        return $userId;
    }
}
