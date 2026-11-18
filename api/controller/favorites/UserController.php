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
use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Service\Favorites\FavoritesService;
use Dou\Front\Service\Favorites\UserService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序收藏夹会员中心控制器（?route=favorites/user/*）
 */
class UserController extends BaseController
{
    /** @var UserService */
    private $userService;

    /** @var FavoritesService */
    private $favoritesService;

    /**
     * @param UserService $userService
     * @param FavoritesService $favoritesService
     */
    public function __construct(UserService $userService, FavoritesService $favoritesService)
    {
        $this->userService = $userService;
        $this->favoritesService = $favoritesService;
    }

    public function store(Request $request)
    {
        $userId = auth('api')->id();
        $module = $request->extendId('module', 'product');
        $item_id = $request->integer('item_id', 0);
        $before = $this->favoritesService->getFavoritesState($module, $item_id, $userId);
        $this->userService->addIfNotExists($userId, $module, $item_id);
        $after = $this->favoritesService->getFavoritesState($module, $item_id, $userId);
        $data = array('favorites' => $after);
        $message = 'OK';
        if (!$before && $after) {
            $message = lang('favorites_create_success');
            $data['text'] = lang('favorites_ed');
        }
        return ApiResponse::success($data, $message);
    }

    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        $deleted = $this->userService->deleteByIdAndUser($id, (int) auth('api')->id());
        if (!$deleted) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, 'not_found', array(), 404);
        }
        return ApiResponse::success(array(), 'success');
    }

    public function index(Request $request)
    {
        $result = $this->userService->buildUserListPage(
            auth('api')->id(),
            $request->integer('page', 1),
            route('favorites.user'),
            ''
        );
        $favorites_list = isset($result['favorites_list']) ? (array) $result['favorites_list'] : array();
        foreach ($favorites_list as $k => $row) {
            $moduleId = isset($row['module_id']) ? $row['module_id'] : 'product';
            $favorites_list[$k]['url'] = Url::urlMini($moduleId, isset($row['item_id']) ? $row['item_id'] : 0);
            unset($favorites_list[$k]['del_url']);
        }
        $pager = isset($result['pager']) ? (array) $result['pager'] : array();
        return ApiResponse::success(array(
            'title' => lang('favorites'),
            'favorites_list' => $favorites_list,
            'pager' => $pager,
            'total' => isset($pager['record_count']) ? (int) $pager['record_count'] : count($favorites_list),
        ));
    }
}
