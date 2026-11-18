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

namespace Dou\Api\Controller\Comment;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Comment\CommentService as CommentCore;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序评论模块入口控制器。
 *
 * 会员侧（列表 / 表单 / 提交 / 上传）位于 {@see UserController}（?route=comment/user/*）；
 * 本控制器持有面向商品详情等公共调用的评论列表入口（?route=comment/list，MethodResolver 别名映射到 listing()）。
 */
class CommentController extends BaseController
{
    /** @var CommentCore */
    private $comment;

    /**
     * @param CommentCore $comment
     */
    public function __construct(CommentCore $comment)
    {
        $this->comment = $comment;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return ApiResponse::success(array());
    }

    /**
     * 模块内容评论列表（小程序 route=comment/list，公开；MethodResolver 别名映射为 listing()）。
     *
     * @param Request $request
     * @return Response
     */
    public function listing(Request $request)
    {
        $module = $request->alpha('module');
        $itemId = $request->integer('item_id', 0);
        $page = $request->integer('page', 1);
        if ($module === '' || $itemId <= 0) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
        }

        $pagination = Config::get('pagination', array());
        $pageSize = isset($pagination['comment']) ? (int) $pagination['comment'] : 10;
        if ($pageSize <= 0) {
            $pageSize = 10;
        }

        $data = $this->comment->data($module, $itemId, $pageSize, $page);
        $commentList = isset($data['list']) && is_array($data['list']) ? $data['list'] : array();
        $pager = isset($data['pager']) && is_array($data['pager']) ? $data['pager'] : array();

        return ApiResponse::success(array(
            'comment_list' => $commentList,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($commentList),
            'page' => isset($pager['page']) ? (int) $pager['page'] : (int) $page,
            'page_count' => isset($pager['page_count']) ? (int) $pager['page_count'] : 1,
        ));
    }
}
