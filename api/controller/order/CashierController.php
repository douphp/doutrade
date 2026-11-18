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
use Dou\Api\Service\Payment\MiniProgramPaymentService;
use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Service\Order\CashierService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序收银台子控制器（route=order/cashier/*）
 */
class CashierController extends BaseController
{
    /** @var CashierService */
    private $cashierService;

    /** @var OrderCore */
    private $order;

    /** @var PaymentService */
    private $paymentService;

    /** @var MiniProgramPaymentService */
    private $miniProgramPaymentService;

    /**
     * @param CashierService $cashierService 订单支付业务
     * @param OrderCore $orderCore
     * @param PaymentService $paymentService
     * @param MiniProgramPaymentService $miniProgramPaymentService
     */
    public function __construct(
        CashierService $cashierService,
        OrderCore $orderCore,
        PaymentService $paymentService,
        MiniProgramPaymentService $miniProgramPaymentService
    ) {
        $this->cashierService = $cashierService;
        $this->order = $orderCore;
        $this->paymentService = $paymentService;
        $this->miniProgramPaymentService = $miniProgramPaymentService;
    }

    /**
     * 收银台（route=order/cashier）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn');
        $order = $this->cashierService->getCashierOrder($userId, $orderSn);
        if (!$order) {
            $existing = DB::table('order')
                ->where('order_sn', $orderSn)
                ->where('user_id', $userId)
                ->field('status')
                ->find();
            if (!is_array($existing)) {
                return ApiResponse::error(ApiCodes::NOT_FOUND, lang('order_not_exist'), array(), 404);
            }

            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_status_cannot_pay'), array(), 422);
        }

        $remaining = $order ? $this->cashierService->remainingAmount($order) : 0.0;
        $orderCtx = $order ? currency()->contextForOrder($order) : null;
        $paymentList = $order ? $this->miniProgramPaymentService->buildPaymentList($order) : array();
        $defaultPayment = '';
        foreach ($paymentList as $item) {
            if (!empty($item['default'])) {
                $defaultPayment = (string) $item['slug'];
                break;
            }
        }
        if ($defaultPayment === '' && !empty($paymentList)) {
            $defaultPayment = (string) $paymentList[0]['slug'];
        }

        return ApiResponse::success(array(
            'title' => lang('order_cashier'),
            'order' => $order ? $order : array(),
            'payable_amount' => $remaining,
            'payable_amount_format' => $orderCtx ? Util::formatCurrency($remaining, $orderCtx) : '',
            'payment' => $defaultPayment,
            'payment_list' => $paymentList,
        ));
    }

    /**
     * 订单支付状态（route=order/cashier/status）
     *
     * 轻量只读接口：供小程序支付回调后轮询，不触发自动任务 / 对账。
     *
     * @param Request $request
     * @return Response
     */
    public function status(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn');
        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', $userId)
            ->field('order_sn, status')
            ->find();

        if (!is_array($order)) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('order_not_exist'), array(), 404);
        }

        $status = (string) $order['status'];
        $paidStatuses = array(
            OrderStatus::PAID,
            OrderStatus::AWAITING_CONFIRMATION,
            OrderStatus::COMPLETED,
            OrderStatus::REFUNDING,
            OrderStatus::REFUNDED,
            OrderStatus::PARTIAL_REFUNDED,
        );

        return ApiResponse::success(array(
            'order_sn' => $orderSn,
            'status' => $status,
            'paid' => in_array($status, $paidStatuses, true),
            'finished' => in_array($status, array(OrderStatus::CANCELLED), true),
        ));
    }

    /**
     * 发起小程序支付（route=order/cashier/payment，POST）
     *
     * 统一入口：按插件开关 + 小程序能力校验后，由 Provider 返回原生动作描述。
     *
     * @param Request $request
     * @return Response
     */
    public function payment(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn');
        $payId = (string) $request->input('pay_id', '');

        if ((string) $request->input('use_wallet', '') === '1') {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_payment_invalid', lang('illegal')), array(), 422);
        }

        try {
            $action = $this->miniProgramPaymentService->start($userId, $orderSn, $payId);
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        return ApiResponse::success($action);
    }

    /**
     * 离线付款页数据（route=order/cashier/offlinepay，POST）
     *
     * 等价于 payment + pay_id=offlinepay，并返回离线付款页所需的订单与提示信息；
     * payment_sn 由离线付款 Provider 下发，页面随后用它上传付款凭证。
     *
     * @param Request $request
     * @return Response
     */
    public function offlinepay(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn');

        if ((string) $request->input('use_wallet', '') === '1') {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_payment_invalid', lang('illegal')), array(), 422);
        }

        try {
            $action = $this->miniProgramPaymentService->start($userId, $orderSn, 'offlinepay');
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), array(), 422);
        }

        if (isset($action['type']) && $action['type'] === 'paid') {
            return ApiResponse::success(array(
                'title' => '离线付款',
                'paid' => true,
                'order' => array(),
            ));
        }

        $order = $this->cashierService->getPayOrder($userId, $orderSn);
        if (!is_array($order)) {
            return ApiResponse::success(array(
                'title' => '离线付款',
                'order' => array(),
            ));
        }

        $remaining = $this->cashierService->remainingAmount($order);
        $order['payment_sn'] = isset($action['payment_sn']) ? (string) $action['payment_sn'] : '';
        $order['pay_amount'] = $remaining;
        $order['pay_amount_format'] = Util::formatCurrency($remaining, currency()->contextForOrder($order));

        return ApiResponse::success(array(
            'title' => '离线付款',
            'order' => $order,
            'tips' => isset($action['tips']) ? (string) $action['tips'] : '',
        ));
    }

    /**
     * 上传付款凭证（route=order/cashier/pay_evidence）
     *
     * @param Request $request
     * @return Response
     */
    public function payEvidence(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $orderSn = $request->digits('order_sn', '', 'post');
        $paymentSn = (string) $request->post('payment_sn', '');
        $order = DB::table('order')->where('order_sn', $orderSn)->where('user_id', $userId)->find();
        if (!$order) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('order_not_exist'), array(), 404);
        }
        $order['order_id'] = (int) $order['id'];
        if (!plugin()->existsBySlug('offlinepay')) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_payment_invalid', lang('illegal')), array(), 422);
        }
        if (empty($_FILES['pay_evidence']['name'])) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('order_pay_evidence_cue'), array(), 400);
        }

        if ($paymentSn === '') {
            $existing = $this->paymentService->findActivePending((int) $order['order_id']);
            if (is_array($existing) && isset($existing['gateway']) && $existing['gateway'] === 'offlinepay') {
                $paymentSn = (string) $existing['payment_sn'];
            }
        }
        if ($paymentSn === '') {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_pay_evidence_cue'), array(), 422);
        }

        $walletCheck = $this->cashierService->assertPaymentReady($userId, $orderSn, $paymentSn);
        if (empty($walletCheck['ok'])) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $walletCheck['msg'], array(), 422);
        }

        $payEvidence = attachment()->store('order', $orderSn, UploadedFile::fromGlobals('pay_evidence'), 'main', AttachmentUploadOptions::create()->withImageWidth(800)->withPrimaryKey('order_sn')->withBusinessField('pay_evidence')->withUploader('user', (int) $userId));
        if (!$this->cashierService->savePayEvidence($userId, $orderSn, $paymentSn, $payEvidence)) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('order_payment_invalid', lang('order_pay_evidence_cue')), array(), 422);
        }

        return ApiResponse::success(array());
    }

    /**
     * 登录校验并返回 user_id。
     *
     * @return int
     */
    private function mustLoginUserId()
    {
        $userId = auth('api') ? (int) auth('api')->id() : 0;
        if ($userId > 0) {
            return $userId;
        }
        ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
    }
}
