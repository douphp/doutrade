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

namespace Dou\Api\Controller\Point;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\Url;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Service\Point\PointService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序积分商城控制器
 */
class PointController extends BaseController
{
    /** @var PointService */
    private $pointService;

    /**
     * @param PointService $pointService
     */
    public function __construct(PointService $pointService)
    {
        $this->pointService = $pointService;
    }

    public function index(Request $request)
    {
        $result = $this->pointService->buildPointListData($request->integer('page', 1), route('point'));
        $product_list = isset($result['product_list']) ? (array) $result['product_list'] : array();
        foreach ($product_list as $idx => $row) {
            $product_list[$idx]['url'] = Url::urlMini('product', $row['id']) . '&mode=point';
        }

        $pager = isset($result['pager']) ? (array) $result['pager'] : array();
        return ApiResponse::success(array(
            'title' => lang('point'),
            'product_list' => $product_list,
            'pager' => $pager,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($product_list),
        ));
    }
}
