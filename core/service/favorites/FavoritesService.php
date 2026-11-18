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

namespace Dou\Core\Service\Favorites;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 收藏夹只读读模型（可选模块，跨模块只读使用）。
 *
 * 调用方经 `Module::make('favorites')` 取实例（收藏模块未安装时返回 null）：
 * 短名按 {@see \Dou\Core\Foundation\Extension\Module} 默认规律探到本类
 * `Dou\Core\Service\Favorites\FavoritesService`（core 优先于 front 同名实现）。
 *
 * 与 {@see \Dou\Front\Service\Favorites\FavoritesService}（前台收藏夹增删/会员中心列表）边界：
 * - core 层：仅承担「按 user_id + module + item_id 的命中查询」类只读能力，
 *   供商品详情等跨模块场景拿当前会员的收藏标记。
 * - front 层：承担收藏入口控制器调用的增删与「我的收藏」列表渲染等端侧业务。
 */
class FavoritesService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 在候选 id 集合中筛出指定会员已收藏的那一部分。
     *
     * 仅在「收藏」特性开启、user_id > 0、候选非空时查询；返回 [item_id => true] 的查找表，
     * 调用方可用 `isset($map[$id])` 做 O(1) 判定。
     *
     * @param string $module 收藏模块（favorites.module）。
     * @param array $ids 候选 item_id 集合。
     * @param int $userId 显式入参；<=0 返回空数组
     * @return array [item_id => true]，未命中或未启用时为空数组。
     */
    public function mapFavoritedIds($module, $ids, $userId = 0)
    {
        $map = array();
        $userId = (int) $userId;
        if (Config::get('features.favorites', false) && $userId > 0 && !empty($ids)) {
            $rows = DB::table('favorites')
                ->field('item_id')
                ->where('module', $module)
                ->where('user_id', $userId)
                ->where('item_id', 'IN', $ids)
                ->select();
            foreach ((array) $rows as $row) {
                $map[$row['item_id']] = true;
            }
        }
        return $map;
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

        $exists = DB::table('favorites')
            ->where('item_id', (int) $itemId)
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
