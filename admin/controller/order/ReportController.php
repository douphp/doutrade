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
use Dou\Admin\Service\Order\ReportService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台销售统计控制器（route=order/report/...）。
 *
 * 控制器只做取参 + 调 service + assign + view；
 * 所有聚合计算与 charts_json 装配在 ReportService 内。
 */
class ReportController extends BaseController
{
    /** @var ReportService */
    private $reportService;

    /**
     * @param ReportService $reportService
     */
    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * 总览（route=order/report）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $context = $this->reportService->resolveContext($this->collectParams($request));
        $data = $this->reportService->buildOverviewData($context);

        return $this->view('order_report.htm', array(
            'ur_here' => lang('order_report'),
            'page_actions' => array(
                array('href' => route('admin.order'), 'text' => lang('order_list'), 'style' => ''),
            ),
            'rec' => 'overview',
            'ctx' => $context,
            'kpis' => $data['kpis'],
            'trend' => $data['trend'],
            'status_funnel' => $data['status_funnel'],
            'category_breakdown' => $data['category_breakdown'],
            'charts_json' => $data['charts_json'],
        ));
    }

    /**
     * 商品（route=order/report/product）
     *
     * @param Request $request
     * @return Response
     */
    public function product(Request $request)
    {
        $context = $this->reportService->resolveContext($this->collectParams($request));
        $data = $this->reportService->buildProductData($context);

        return $this->view('order_report.htm', array(
            'ur_here' => lang('order_report_product'),
            'page_actions' => array(
                array('href' => route('admin.order'), 'text' => lang('order_list'), 'style' => ''),
            ),
            'rec' => 'product',
            'ctx' => $context,
            'top_products' => $data['top_products'],
            'category_breakdown' => $data['category_breakdown'],
            'charts_json' => $data['charts_json'],
        ));
    }

    /**
     * 客户（route=order/report/customer）
     *
     * @param Request $request
     * @return Response
     */
    public function customer(Request $request)
    {
        $context = $this->reportService->resolveContext($this->collectParams($request));
        $data = $this->reportService->buildCustomerData($context);

        return $this->view('order_report.htm', array(
            'ur_here' => lang('order_report_customer'),
            'page_actions' => array(
                array('href' => route('admin.order'), 'text' => lang('order_list'), 'style' => ''),
            ),
            'rec' => 'customer',
            'ctx' => $context,
            'new_vs_returning' => $data['new_vs_returning'],
            'top_customers' => $data['top_customers'],
            'charts_json' => $data['charts_json'],
        ));
    }

    /**
     * 支付（route=order/report/payment）
     *
     * @param Request $request
     * @return Response
     */
    public function payment(Request $request)
    {
        $context = $this->reportService->resolveContext($this->collectParams($request));
        $data = $this->reportService->buildPaymentData($context);

        return $this->view('order_report.htm', array(
            'ur_here' => lang('order_report_payment'),
            'page_actions' => array(
                array('href' => route('admin.order'), 'text' => lang('order_list'), 'style' => ''),
            ),
            'rec' => 'payment',
            'ctx' => $context,
            'gateways' => $data['gateways'],
            'refund_trend' => $data['refund_trend'],
            'refund_top_orders' => $data['refund_top_orders'],
            'charts_json' => $data['charts_json'],
        ));
    }

    /**
     * CSV 导出（route=order/report/export）。
     *
     * CSRF token 由 admin/middleware/CsrfMiddleware::getTokenRoutes 在 GET 阶段校验过。
     * 直接走 header + 服务流式输出 + exit，绕开 view 渲染以支持大文件下载。
     *
     * @param Request $request
     * @return Response 兜底返回（实际 exit 在 header 后）
     */
    public function export(Request $request)
    {
        $context = $this->reportService->resolveContext($this->collectParams($request));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = 'order-report-' . $context['date_start'] . '_' . $context['date_end'] . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $this->reportService->exportCsv($context);
        exit;
    }

    /**
     * 把 Request 取出的扁平参数喂给 ReportService::resolveContext。
     *
     * @param Request $request
     * @return array
     */
    private function collectParams(Request $request)
    {
        return array(
            'preset' => trim((string) $request->input('preset', ReportService::PRESET_LAST_7)),
            'date_start' => trim((string) $request->input('date_start', '')),
            'date_end' => trim((string) $request->input('date_end', '')),
            'module' => trim((string) $request->input('module', '')),
            'granularity' => trim((string) $request->input('granularity', ReportService::GRANULARITY_DAY)),
        );
    }
}
