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

namespace Dou\Front\Controller\Comment;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Comment\CommentService as CommentCore;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\Comment\CommentAddFormRequest;
use Dou\Front\Service\Comment\CommentService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台评论会员中心控制器（/user/comment/*）
 */
class UserController extends BaseController
{
    /** @var CommentService */
    private $commentService;

    /** @var CommentCore */
    private $comment;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param CommentService $commentService
     * @param CommentCore $comment
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
         */
    public function __construct(
        CommentService $commentService, CommentCore $comment,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->commentService = $commentService;
        $this->comment = $comment;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 我的评论
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        if (!auth('front') || !auth('front')->check()) {
            return redirect(route('user.login'));
        }

        $this->commentService->ensureCommentImageDir();

        $page = $request->route('page', 1);
        $data = $this->commentService->buildUserCommentList(
            auth('front')->id(),
            $page,
            route('comment.user')
        );

        $urHere = $this->breadcrumb->build('comment', 'comment_list');

        return $this->view('comment.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('comment', 'comment_list'),

            // 页面骨架
            'rec' => 'user',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('comment_list'),
            'comment_list' => $data['comment_list'],
            'pager' => $data['pager'],
        ]);
    }

    /**
     * 发表评论表单（route=user/comment/create，GET）
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        if (!auth('front') || !auth('front')->check()) {
            return redirect(route('user.login'));
        }

        $this->commentService->ensureCommentImageDir();

        $orderItemId = $request->integer('order_item_id', 0);
        $data = $this->commentService->getPostFormData($orderItemId, $this->comment, route('comment.user'), (int) auth('front')->id());

        if ($data === null) {
            throw new DomainException('page_wrong', HOME_URL);
        }
        if (isset($data['redirect'])) {
            return redirect($data['redirect']);
        }

        $urHere = $this->breadcrumb->build('comment', 'comment_post');

        return $this->view('comment.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('comment', 'comment_post'),

            // 页面骨架
            'rec' => 'post',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('comment_post'),
            'item' => $data['item'],
            'order_item_id' => $orderItemId,
            'img_list_html' => attachment()->gallery('comment', $orderItemId, 'gallery'),
        ]);
    }

    /**
     * 提交评论（route=user/comment，POST）
     *
     * @param CommentAddFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(CommentAddFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $this->commentService->ensureCommentImageDir();

        $orderItemId = $request->integer('order_item_id', 0);

        $result = $this->commentService->processStore($request->post(), $orderItemId, (string) $request->ip(), (int) auth('front')->id());

        $backUrl = (string) $request->header('Referer', HOME_URL);

        if (isset($result['type']) && $result['type'] === 'error_msg') {
            throw new DomainException($result['text'], $backUrl);
        }

        $message = isset($result['message']) ? $result['message'] : lang('comment_success');

        return $this->respond($request, $backUrl, array(), $message);
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('comment'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
