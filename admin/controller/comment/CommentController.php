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

namespace Dou\Admin\Controller\Comment;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Comment\CommentBatchActionFormRequest;
use Dou\Admin\Request\Comment\CommentReplyFormRequest;
use Dou\Admin\Service\Comment\CommentService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台评论（route=comment/...）
 */
class CommentController extends BaseController
{
    /** @var CommentService */
    private $commentService;

    /**
     * @param CommentService $commentService
     */
    public function __construct(CommentService $commentService)
    {
        $this->commentService = $commentService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $page = $request->integer('page', 1);
        $bundle = $this->commentService->buildCommentListData($page);
        return $this->view('comment.htm', [
            'ur_here' => lang('comment'),
            'page_actions' => array(
                array('href' => route('admin.comment.set'), 'text' => lang('set'), 'style' => ''),
            ),
            'rec' => 'default',
            'comment_list' => $bundle['comment_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function read(Request $request)
    {
        $bundle = $this->commentService->buildCommentReadData($request->input('id'));
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.comment'));
        }

        return $this->view('comment.htm', [
            'ur_here' => lang('comment_read'),
            'page_actions' => array(
                array('href' => route('admin.comment'), 'text' => lang('comment_list'), 'style' => ''),
            ),
            'rec' => 'read',
            'comment' => $bundle['comment'],
            'reply' => $bundle['reply'],
        ]);
    }

    /**
     * @param CommentReplyFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function reply(CommentReplyFormRequest $formRequest, Request $request)
    {
        $validatedData = $formRequest->validated();
        $adminId = auth('admin')->id();
        $this->commentService->reply(
            $validatedData['id'],
            $validatedData['content'],
            $adminId,
            (string) $request->ip()
        );
        return redirect(route('admin.comment'))->with('success', lang('comment_insert_success'));
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function showHidden(Request $request)
    {
        return $this->response($this->commentService->toggleShowHtml($request->input('id')));
    }

    /**
     * @param CommentBatchActionFormRequest $formRequest
     * @return Response
     */
    public function delAll(CommentBatchActionFormRequest $formRequest)
    {
        $this->commentService->deleteBatch($formRequest->validated());
        return redirect(route('admin.comment'))->with('success', lang('del_succes'));
    }

    /**
     * @return void
     */
    public function set()
    {
        $this->commentService->redirectToCommentParameterGroup();
    }
}
