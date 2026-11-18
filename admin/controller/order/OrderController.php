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

namespace Dou\Admin\Controller\Order;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Order\OrderActionFormRequest;
use Dou\Admin\Request\Order\OrderTrackingFormRequest;
use Dou\Admin\Service\Order\OrderService;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Service\Order\OrderScheduledTasks;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订单控制器（route=order/...）
 *
 * 仅做路由调度、取参、assign；
 * 列表与详情数据来自 OrderService::build*Data；高风险 POST 通过 *FormRequest 校验。
 */
class OrderController extends BaseController
{
    /** @var OrderService */
    private $orderService;

    /** @var OrderScheduledTasks */
    private $scheduledTasks;

    /**
     * @param OrderService $orderService
     * @param OrderScheduledTasks $scheduledTasks
     */
    public function __construct(OrderService $orderService, OrderScheduledTasks $scheduledTasks)
    {
        $this->orderService = $orderService;
        $this->scheduledTasks = $scheduledTasks;
    }

    /**
     * 订单列表（route=order）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $usernameRaw = trim((string) $request->input('username'));
        $statusRaw = trim((string) $request->input('status', ''));
        $orderSnRaw = trim((string) $request->input('order_sn'));
        $orderConsigneeRaw = trim((string) $request->input('order_consignee'));
        $timeStartRaw = trim((string) $request->input('time_start', ''));
        $timeEndRaw = trim((string) $request->input('time_end', ''));

        $username = Check::username($usernameRaw) ? $usernameRaw : '';
        $status = OrderStatus::isValid($statusRaw) ? $statusRaw : '';
        $orderSn = Check::number($orderSnRaw) ? $orderSnRaw : '';
        $orderConsignee = Check::username($orderConsigneeRaw) ? $orderConsigneeRaw : '';
        $timeStart = ($timeStartRaw !== '' && strtotime($timeStartRaw) !== false) ? $timeStartRaw : '';
        $timeEnd = ($timeEndRaw !== '' && strtotime($timeEndRaw) !== false) ? $timeEndRaw : '';

        $rejectFilter = array(
            'username' => $usernameRaw !== '' && !Check::username($usernameRaw),
            'status' => $statusRaw !== '' && !OrderStatus::isValid($statusRaw),
            'order_sn' => $orderSnRaw !== '' && !Check::number($orderSnRaw),
            'order_consignee' => $orderConsigneeRaw !== '' && !Check::username($orderConsigneeRaw),
            'time_start' => $timeStartRaw !== '' && strtotime($timeStartRaw) === false,
            'time_end' => $timeEndRaw !== '' && strtotime($timeEndRaw) === false,
        );

        $bundle = $this->orderService->buildOrderListData(array(
            'username' => $username,
            'status' => $status,
            'order_sn' => $orderSn,
            'order_consignee' => $orderConsignee,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'page' => $page,
            'reject_filter' => $rejectFilter,
        ));

        return $this->view('order.htm', [
            'ur_here' => lang('order'),
            'page_actions' => array(
                array('href' => route('admin.order.reconcile'), 'text' => lang('order_reconcile'), 'style' => '', 'post' => true),
                array('href' => route('admin.order.report'), 'text' => lang('order_report'), 'style' => ''),
                array('href' => route('admin.order.set'), 'text' => lang('set'), 'style' => ''),
            ),
            'rec' => 'default',
            'req' => array(
                'username' => $usernameRaw,
                'status' => $statusRaw,
                'order_sn' => $orderSnRaw,
                'order_consignee' => $orderConsigneeRaw,
                'time_start' => $timeStartRaw,
                'time_end' => $timeEndRaw,
            ),
            'status_filter' => $bundle['status_filter'],
            'status_options' => $bundle['status_options'],
            'order_list' => $bundle['order_list'],
            'pager' => $bundle['pager'],
            // 未随包下发 excel 库时不展示导出入口
            'excel' => file_exists(LIBRARY_PATH . 'excel/Excel.php'),
        ]);
    }

    /**
     * 订单详情（route=order/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $orderId = $this->orderService->resolveViewOrderId(
            $request->input('order_sn'),
            $request->input('order_id')
        );
        $order = $this->orderService->buildOrderViewData($orderId);
        if (!$order) {
            return redirect(route('admin.order'));
        }

        return $this->view('order.htm', [
            'ur_here' => lang('order_show'),
            'page_actions' => array(
                array('href' => route('admin.order'), 'text' => lang('order_list'), 'style' => ''),
            ),
            'rec' => 'show',
            'order' => $order,
            'shipping_list' => $this->orderService->getShippingListForAdmin(),
        ]);
    }

    /**
     * 线下付款审核通过（route=order/pay_check）
     *
     * @param Request $request
     * @return Response
     */
    public function payCheck(Request $request)
    {
        $orderId = $request->integer('order_id', 0);
        return redirect($this->orderService->payCheck($orderId));
    }

    /**
     * 线下付款驳回（route=order/pay_reject）
     *
     * 把当前 pending payment 标记为 FAILED，订单回退到 PENDING，用户可重新上传凭证。
     *
     * @param Request $request
     * @return Response
     */
    public function payReject(Request $request)
    {
        $orderId = $request->integer('order_id', 0);
        $reason = trim((string) $request->input('reason', ''));
        return redirect($this->orderService->payReject($orderId, $reason));
    }

    /**
     * 标记订单级退款失败（route=order/fail_refund）。
     *
     * 仅处理非售后退款（aftersale_id=0）；售后退款走售后详情页。
     *
     * @param Request $request
     * @return Response
     */
    public function failRefund(Request $request)
    {
        $refundSn = (string) $request->post('refund_sn', '');
        return redirect($this->orderService->failOrderRefund($refundSn));
    }

    /**
     * 重新执行付款后联动（route=order/retry_paid）。
     *
     * 仅对 PAID / 已完成订单生效，重跑积分 / 分销 / 会员升级（均幂等，不会重复发放）。
     *
     * @param Request $request
     * @return Response
     */
    public function retryPaid(Request $request)
    {
        $orderId = $request->integer('order_id', 0);
        $result = $this->orderService->retryPaidEffects($orderId);
        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * 保存物流（route=order/tracking）。
     *
     * 字段白名单与校验规则定义在 OrderTrackingFormRequest::rules()。
     *
     * @param OrderTrackingFormRequest $formRequest
     * @return Response
     */
    public function tracking(OrderTrackingFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        return redirect($this->orderService->tracking($data));
    }

    /**
     * 批量删除 / 取消（route=order/action）。
     *
     * 字段白名单与校验规则定义在 OrderActionFormRequest::rules()。
     *
     * @param OrderActionFormRequest $formRequest
     * @return Response
     */
    public function action(OrderActionFormRequest $formRequest)
    {
        $data = $formRequest->validated();

        $result = $this->orderService->action($data);
        if (!is_array($result)) {
            // excel/excel_all 分支已由 Excel::export 直接发送二进制响应
            return null;
        }

        return redirect($result['back_url'])->with('success', $result['message']);
    }

    /**
     * 初始化订单参数（route=order/set）
     *
     * @return Response
     */
    public function set()
    {
        return redirect($this->orderService->set());
    }

    /**
     * 立即对账（route=order/reconcile）。
     *
     * 给运营手动入口：扫描 pending payment，按支付插件查询第三方网关状态。
     * 与 PaymentReconciliationLottery 兜底并行（不替代），返回扫描统计。
     *
     * @return Response
     */
    public function reconcile()
    {
        $stats = $this->scheduledTasks->reconcilePendingPayments();
        $closed = $this->scheduledTasks->expirePastDuePayments();

        $scanned = isset($stats['scanned']) ? (int) $stats['scanned'] : 0;
        $succeeded = isset($stats['succeeded']) ? (int) $stats['succeeded'] : 0;
        $message = sprintf('%s: scanned=%d, succeeded=%d, expired=%d', lang('order_reconcile'), $scanned, $succeeded, (int) $closed);

        return redirect(route('admin.order'))->with('success', $message);
    }
}
