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

use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台评论入口（/comment）。
 *
 * 会员侧（列表 / 发表 / 提交）位于 {@see UserController}（/user/comment/*）；
 * 本控制器为 /comment 约定式入口，登录后跳转到 /user/comment，未登录跳登录页。
 */
class CommentController extends BaseController
{
    /** @var NavigationBuilder */
    private $nav;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param NavigationBuilder $nav
     * @param SeoResolver $seo
     */
    public function __construct(NavigationBuilder $nav, SeoResolver $seo)
    {
        $this->nav = $nav;
        $this->seo = $seo;
    }

    /**
     * 默认入口：根据登录态分发到登录页或我的评论
     *
     * @return Response
     */
    public function index()
    {
        if (!auth('front') || !auth('front')->check()) {
            return redirect(route('user.login'));
        }
        return redirect(route('comment.user'));
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
