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

namespace Dou\Front\Service\Favorites;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Front\Model\Favorites\Favorites;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台收藏夹只读状态业务（跨端共享）。
 *
 * 与会员中心写入 / 列表渲染（{@see UserService}）分工：本类只保留 {@see getFavoritesState}，
 * 供商品列表 / 详情拿当前会员收藏状态文案与样式，当前由
 * {@see \Dou\Api\Controller\Favorites\UserController} 经构造注入调用。
 */
class FavoritesService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 获取收藏状态文案与样式。
     *
     * @param string $module
     * @param int $itemId
     * @param int $userId
     * @return array|null
     */
    public function getFavoritesState($module, $itemId, $userId = 0)
    {
        $userId = (int) $userId;
        if (!Config::get('features.favorites', false) || $userId <= 0) {
            return null;
        }

        $exists = Favorites::where('item_id', (int) $itemId)
            ->where('module', $module)
            ->where('user_id', $userId)
            ->exists();

        if ($exists) {
            return array(
                'class' => ' ed',
                'text' => lang('favorites_ed'),
            );
        }

        return array(
            'class' => '',
            'text' => lang('favorites_btn'),
        );
    }
}
