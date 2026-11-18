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
use Dou\Core\Facade\DB;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Comment\CommentService as CommentCore;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Service\Comment\CommentService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序评论会员中心控制器（?route=comment/user/*）。
 */
class UserController extends BaseController
{
    /** @var CommentService */
    private $commentService;

    /** @var CommentCore */
    private $comment;

    /** @var \Dou\Core\Filesystem\Disk */
    private $commentDisk;

    /**
     * @param CommentService $commentService
     * @param CommentCore $comment
     */
    public function __construct(CommentService $commentService, CommentCore $comment)
    {
        $this->commentService = $commentService;
        $this->comment = $comment;
        $this->commentDisk = Storage::disk('comment');
    }

    /**
     * 我的评论列表（?route=comment/user）。
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = $this->requireLoginUserId();
        $page = $request->integer('page', 1);
        $listData = $this->commentService->buildUserCommentList($userId, $page, '');
        $pager = isset($listData['pager']) && is_array($listData['pager']) ? $listData['pager'] : array();

        return ApiResponse::success(array(
            'title' => lang('comment'),
            'comment_list' => $listData['comment_list'],
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($listData['comment_list']),
            'page' => isset($pager['page']) ? (int) $pager['page'] : (int) $page,
            'page_count' => isset($pager['page_count']) ? (int) $pager['page_count'] : 1,
        ));
    }

    /**
     * 发表评论表单数据（?route=comment/user/add）。
     *
     * @param Request $request
     * @return Response
     */
    public function add(Request $request)
    {
        $userId = $this->requireLoginUserId();
        $orderItemId = $request->integer('order_item_id', 0);
        $item = DB::table('order_item')->where('id', $orderItemId)->find();
        if (!is_array($item)) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
        }
        $msg = $this->comment->checkPermission($item['module'], $item['item_id'], $orderItemId, $userId);
        if ($msg) {
            return ApiResponse::error(ApiCodes::FORBIDDEN, $msg, array(), 403);
        }

        $form = $this->commentService->getPostFormData($orderItemId, $this->comment, '', $userId);
        if ($form === null || !isset($form['item'])) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('page_wrong'), array(), 404);
        }

        return ApiResponse::success(array(
            'title' => lang('comment_post'),
            'item' => $form['item'],
            'order_item_id' => $orderItemId,
            'html_file_list' => attachment()->gallery('comment', $orderItemId, 'gallery', true),
        ));
    }

    /**
     * 提交评论（POST comment/user）。
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $userId = $this->requireLoginUserId();
        $orderItemId = $request->integer('order_item_id', 0);
        $result = $this->commentService->processStore($request->post(), $orderItemId, (string) $request->ip(), $userId, false);

        if ($result['type'] === 'done') {
            return ApiResponse::success(array('success' => $result['message']), $result['message']);
        }
        if ($result['type'] === 'error_msg') {
            return ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, strip_tags($result['text']), array(), 422);
        }

        return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
    }

    /**
     * 评论图片上传。
     *
     * @param Request $request
     * @return Response
     */
    public function filebox(Request $request)
    {
        $userId = $this->requireLoginUserId();
        // item_id 在这里是订单项 ID：评论图片按订单项归档
        $orderItemId = $request->integer('item_id', 0);
        $orderItem = DB::table('order_item')
            ->field('id, user_id')
            ->where('id', $orderItemId)
            ->find();
        if (!is_array($orderItem) || (int) $orderItem['user_id'] !== $userId) {
            return ApiResponse::error(ApiCodes::FORBIDDEN, lang('comment_no_allow_item'), array(), 403);
        }

        $this->commentService->ensureCommentImageDir();
        $disk = $this->commentDisk->withSubdirectory(date('Ymd'));

        $fileNumber = DB::table('file')
            ->where('module', 'comment')
            ->where('item_id', $orderItemId)
            ->where('type', 'gallery')
            ->count();

        if ($fileNumber < 3) {
            $customFilename = $orderItemId . '_' . Str::randomByType('letter', 6, date('Ymd'));
            attachment()->store('comment', $orderItemId, UploadedFile::fromGlobals('gallery'), 'gallery', AttachmentUploadOptions::create()->withBasename($customFilename)->withUploader('user', $userId));
        }

        return ApiResponse::success(array(
            'html_file_list' => attachment()->gallery('comment', $orderItemId, 'gallery', true),
        ));
    }

    /**
     * 评论图片删除。
     *
     * @param Request $request
     * @return Response
     */
    public function filedel(Request $request)
    {
        $userId = $this->requireLoginUserId();
        $number = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $request->input('number'));
        if ($number === '') {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('file_not_exist'), array(), 400);
        }

        $fileInfo = DB::table('file')
            ->field('module, item_id, type, uploader_type, uploader_id')
            ->where('number', $number)
            ->find();
        if (!is_array($fileInfo)
            || $fileInfo['module'] !== 'comment'
            || $fileInfo['type'] !== 'gallery'
            || $fileInfo['uploader_type'] !== 'user'
            || (int) $fileInfo['uploader_id'] !== $userId
        ) {
            return ApiResponse::error(ApiCodes::FORBIDDEN, lang('file_no_permission'), array(), 403);
        }

        attachment()->delete($number);

        return ApiResponse::success(array(
            'html_file_list' => attachment()->gallery($fileInfo['module'], $fileInfo['item_id'], $fileInfo['type'], true),
        ));
    }

    /**
     * 校验登录态并返回当前会员 ID。
     *
     * @return int
     */
    private function requireLoginUserId()
    {
        $userId = (int) auth('api')->id();
        if ($userId <= 0) {
            ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
        }

        return $userId;
    }
}
