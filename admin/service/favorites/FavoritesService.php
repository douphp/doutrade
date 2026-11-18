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

namespace Dou\Admin\Service\Favorites;

use Dou\Admin\Model\Favorites\Favorites;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台收藏服务（route=favorites/...）
 */
class FavoritesService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * @param string $keyField item_id|user_id
     * @param string $keyword
     * @param int|string $page
     * @return array key_list, keyword, favorites_list, pager
     */
    public function buildFavoritesListData($keyField, $keyword, $page)
    {
        $field = array('item_id', 'user_id');
        $key_list = array();
        foreach ($field as $value) {
            $v = array(
                'value' => $value,
                'name' => lang('favorites_' . $value),
                'cur' => ($value == $keyField),
            );
            $key_list[] = $v;
        }

        $resolvedKw = $keyword;
        if ($keyword !== '' && $keyField !== '') {
            $kw = $keyword;
            if ($keyField === 'item_id') {
                $kw = DB::table('product')->where('title', 'LIKE', '%' . $kw . '%')->value('id');
            }
            if ($keyField === 'user_id') {
                $kw = DB::table('user')->whereRaw('(email LIKE ? OR mobile LIKE ?)', array('%' . $keyword . '%', '%' . $keyword . '%'))->value('user_id');
            }
            $resolvedKw = $kw;
        }

        $page_url = route('admin.favorites');
        if ($keyField) {
            $page_url .= '&key=' . $keyField;
        }
        if ($keyword !== '') {
            $page_url .= '&keyword=' . rawurlencode($keyword);
        }

        $p = Check::number((string) $page) ? (int) $page : 1;
        // AR：casts 接管 created_at；跨模块 item enrich（dynamic table）仍走 DB::
        $result = Favorites::filterByKeyField($keyField, $keyword, $resolvedKw)
            ->order('id DESC')
            ->paginate(15, $p, Util::normalizeQueryString($page_url));

        $favorites_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $item = DB::table($row['module'])->field('title, id, price, image')->where('id', (int) $row['item_id'])->find();
            if (!is_array($item)) {
                $item = array('title' => '', 'id' => (int) $row['item_id'], 'price' => 0, 'image' => '');
            }

            $rawPrice = (float) $model->getRawAttribute('price');
            $itemPrice = (float) (isset($item['price']) ? $item['price'] : 0);
            $favorites_list[] = array(
                'id' => $row['id'],
                'user' => (user() !== null ? user()->format($row['user_id']) : false),
                'module' => lang($row['module']),
                'item_id' => $row['item_id'],
                'name' => isset($item['title']) ? $item['title'] : '',
                'deducted_price' => ($rawPrice - $itemPrice) > 0 ? Util::formatPrice($rawPrice - $itemPrice) : '',
                'price' => Util::formatPrice($rawPrice),
                'image' => $row['module'] == 'product' ? attachment()->url($item['image'], true) : attachment()->url($item['image']),
                'url' => route($row['module'] . '.show', array('id' => $row['item_id'])),
                'del_url' => route('admin.favorites.destroy', array('id' => $row['id'])),
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'key_list' => $key_list,
            'keyword' => $keyword,
            'favorites_list' => $favorites_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * @param string $id
     * @param array $data 来自 Request::post()
     * @return array { message, back_url, timeout, confirm_url, redirect }
     */
    public function delete($id, array $data)
    {
        $favoritesModel = Favorites::find((int) $id);
        if (!$favoritesModel) {
            throw new DomainException(lang('illegal'), route('admin.favorites'));
        }
        $favorites = $favoritesModel->getAttributes();
        $user = (user() !== null ? user()->format($favorites['user_id']) : false);
        $item = DB::table($favorites['module'])->where('id', (int) $favorites['item_id'])->find();
        $name = !empty($item['name']) ? $item['name'] : (Arr::get($item, 'title', ''));

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) ($user['username'] . ':' . $name));
            Favorites::destroy($id);
            return array(
                'message' => '',
                'back_url' => route('admin.favorites'),
                'redirect' => true,
            );
        }

        $msg = preg_replace('/d%/Ums', $user['username'] . lang('favorites') . '-' . $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.favorites'),
            'timeout' => '30',
            'confirm_url' => route('admin.favorites.destroy', array('id' => $id)),
            'redirect' => false,
        );
    }

    /**
     * 批量操作（删除选中项）。
     *
     * @param array $data 来自 FavoritesActionFormRequest::validated()
     * @return array { message, back_url }
     */
    public function action(array $data)
    {
        if (!isset($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('select_empty'));
        }
        if (!isset($data['action']) || $data['action'] !== 'del_all') {
            throw new DomainException(lang('select_empty'));
        }

        $safe_ids = array();
        foreach ($data['checkbox'] as $rid) {
            if (Check::number((string) $rid)) {
                $safe_ids[] = (int) $rid;
            }
        }
        if (empty($safe_ids)) {
            throw new DomainException(lang('select_empty'));
        }

        Favorites::deleteWhereIdIn($safe_ids);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:FAVORITES (' . count($safe_ids) . ' items)');
        return array(
            'message' => lang('del_succes'),
            'back_url' => route('admin.favorites'),
        );
    }
}
