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

namespace Dou\Api\Controller\Favorites;

use Dou\Api\Controller\BaseController;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序收藏夹模块入口控制器。
 *
 * 会员收藏的列表 / 加入 / 取消逻辑位于 {@see UserController}（?route=favorites/user/*）；
 * 本控制器仅保留模块入口（供 app.json 收录与 ?route=favorites 兜底）。
 */
class FavoritesController extends BaseController
{
    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        return ApiResponse::success(array());
    }
}
