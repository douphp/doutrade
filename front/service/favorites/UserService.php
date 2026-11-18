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

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;
use Dou\Front\Model\Favorites\Favorites;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台收藏夹「会员中心」业务（仅服务于 Favorites\UserController）。
 *
 * 与 {@see FavoritesService}（保留跨端共享的只读状态 getFavoritesState）的分工：
 * 本类承担会员中心收藏入口的写操作（{@see addIfNotExists} / {@see deleteByIdAndUser}）
 * 与收藏列表渲染（{@see buildUserListPage}）。
 */
class UserService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param int $userId
     * @param string $module
     * @param int $itemId
     * @return void
     */
    public function addIfNotExists($userId, $module, $itemId)
    {
        $exists = Favorites::where('user_id', (int) $userId)
            ->where('module', $module)
            ->where('item_id', (int) $itemId)
            ->exists();

        if (!$exists) {
            $price = DB::table($module)->where('id', (int) $itemId)->value('price');
            Favorites::create(array(
                'user_id' => (int) $userId,
                'module' => $module,
                'item_id' => (int) $itemId,
                'price' => $price,
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }
    }

    /**
     * @param int $id
     * @param int $userId
     * @return bool
     */
    public function deleteByIdAndUser($id, $userId)
    {
        return (bool) Favorites::where('id', (int) $id)
            ->where('user_id', (int) $userId)
            ->delete();
    }

    /**
     * @param int $userId
     * @param int $page
     * @param string $pageUrl
     * @return array favorites_list、pager
     */
    public function buildUserListPage($userId, $page, $pageUrl)
    {
        $result = Favorites::where('user_id', (int) $userId)
            ->order('id DESC')
            ->paginate(16, $page, $pageUrl);

        $favoritesList = array();
        foreach ($result['list'] as $rowModel) {
            $row = $rowModel->getAttributes();
            $item = DB::table($row['module'])->field('title, id, price, image')->where('id', (int) $row['item_id'])->find();
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['name']) && $item['name'] ? $item['name'] : (isset($item['title']) ? $item['title'] : '');
            $favoritesList[] = array(
                'id' => $row['id'],
                'module_id' => $row['module'],
                'module' => lang($row['module']),
                'item_id' => $row['item_id'],
                'name' => $name,
                'deducted_price' => ($row['price'] - $item['price'] > 0) ? Util::formatPrice($row['price'] - $item['price']) : '',
                'price' => Util::formatPrice($row['price']),
                'image' => $row['module'] === 'product'
                    ? attachment()->url(language()->langValue(isset($item['image']) ? $item['image'] : '', $row['module'], (int) $row['item_id'], 'image'), true)
                    : attachment()->url(language()->langValue(isset($item['image']) ? $item['image'] : '', $row['module'], (int) $row['item_id'], 'image')),
                'url' => route($row['module'] . '.show', array('id' => $row['item_id'])),
                'del_url' => route('favorites.user.destroy', array('id' => $row['id'])),
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            );
        }

        return array(
            'favorites_list' => $favoritesList,
            'pager' => $result['pager'],
        );
    }
}
