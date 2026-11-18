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

namespace Dou\Api\Controller\Aftersale;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Aftersale\AftersaleService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 售后服务会员中心控制器（?route=aftersale/user/*，复用 front 端 AftersaleService）
 * 工作人员侧见 WorkController。
 */
class UserController extends BaseController
{
    /** @var AftersaleService */
    private $aftersaleService;

    /**
     * @param AftersaleService $aftersaleService
     */
    public function __construct(AftersaleService $aftersaleService)
    {
        $this->aftersaleService = $aftersaleService;
    }

    /**
     * 售后服务列表（会员）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = auth('api')->id();
        $page = $request->integer('page', 1);
        $result = $this->aftersaleService->buildAftersaleUserListData($userId, $page, '');

        return ApiResponse::success(array(
            'title' => lang('aftersale_list'),
            'aftersale_list' => $result['list'],
            'pager' => $result['pager'],
        ));
    }

    /**
     * 售后申请表单数据
     *
     * @param Request $request
     * @return Response
     */
    public function apply(Request $request)
    {
        $userId = (int) auth('api')->id();
        $orderSn = $request->digits('order_sn');
        $result = $this->aftersaleService->buildAftersaleApplyData($orderSn, $userId);

        if (!$result['can_apply']) {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('aftersale_apply_wrong'), array(), 422);
        }

        // 创建场景：清掉旧草稿、发新 draft_token，并按草稿身份回显当前会员的 draft 缩略图列表。
        attachment()->cleanupUserDrafts('user', $userId, 'aftersale');
        $draftToken = attachment()->newDraftToken('user', $userId);

        return ApiResponse::success(array(
            'title' => lang('aftersale_apply'),
            'order' => $result['order'],
            'item_list' => $result['item_list'],
            'img_list' => attachment()->galleryByDraft('aftersale', $draftToken, 'user', $userId, 'gallery', true),
            'draft_token' => $draftToken,
        ));
    }

    /**
     * 售后申请提交（Ajax）
     *
     * @param Request $request
     * @return Response
     */
    public function applyPost(Request $request)
    {
        $userId = (int) auth('api')->id();
        $orderSn = $request->digits('order_sn', '', 'post');
        $reason = $request->post('reason', '');
        $type = (string) $request->post('type', '');
        $draftToken = (string) $request->post('draft_token', '');
        if (!$reason) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('aftersale_reason') . lang('is_empty'), array(), 422);
        }
        if (Check::illegalChar($reason)) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('aftersale_reason') . lang('illegal_char'), array(), 422);
        }

        $submittedItems = array();
        $itemList = $request->post('item_list');
        if (is_array($itemList)) {
            $submittedItems = array_map('intval', $itemList);
        } elseif (!empty($itemList)) {
            $submitted = explode(',', $itemList);
            $submittedItems = array_map('intval', $submitted);
        }

        $result = $this->aftersaleService->submitAftersaleApply($orderSn, $userId, $reason, $submittedItems, $type, $draftToken);

        if ($result['success']) {
            return ApiResponse::success(array(), lang('aftersale_apply_success'));
        } elseif ($result['error'] === 'items_required') {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('aftersale_items_required'), array(), 422);
        } elseif ($result['error'] === 'invalid_items') {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('aftersale_invalid_items'), array(), 422);
        } elseif ($result['error'] === 'save_failed') {
            return ApiResponse::error(ApiCodes::SERVER_ERROR, lang('aftersale_apply_failed'), array(), 500);
        } else {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, lang('aftersale_apply_wrong'), array(), 422);
        }
    }
}
