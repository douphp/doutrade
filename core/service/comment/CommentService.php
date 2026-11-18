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

namespace Dou\Core\Service\Comment;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 评论服务（前/api 文章详情下使用）。
 */
class CommentService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 执行data操作。
     *
     * @param mixed $module 参数module。
     * @param mixed $item_id 参数item_id。
     * @param int $page_size 参数page_size。
     * @param int $page 参数page。
     * @return mixed 返回结果。
     */
    public function data($module, $item_id, $page_size = 10, $page = 1)
    {
        $result = $this->getCommentList($module, $item_id, $page_size, $page);

        return array(
            'module' => $module,
            'item_id' => $item_id,
            'list' => $result['list'],
            'pager' => $result['pager'],
        );
    }

    /**
     * 执行getCommentList操作。
     *
     * 昵称、管理员回复、评论图片均按本页批量预取，避免逐行查询。
     *
     * @param mixed $module 参数module。
     * @param mixed $item_id 参数item_id。
     * @param int $page_size 参数page_size。
     * @param int $page 参数page。
     * @return array list, pager
     */
    public function getCommentList($module, $item_id, $page_size = 10, $page = 1)
    {
        // 链式查询带分页
        $query = DB::table('comment')
            ->where('module', $module)
            ->where('item_id', intval($item_id))
            ->where('if_show', '1')
            ->where('reply_id', 0)
            ->order('id DESC');

        $result = $query->paginate($page_size, $page, route($module . '.show', ['id' => $item_id]));

        $rows = (array) $result['list'];
        if (empty($rows)) {
            return array('list' => array(), 'pager' => $result['pager']);
        }

        $rootIds = array();
        $userIds = array();
        $orderItemIds = array();
        foreach ($rows as $row) {
            $rootIds[] = (int) $row['id'];
            $userIds[] = (int) $row['user_id'];
            $orderItemIds[] = (int) $row['order_item_id'];
        }

        $nicknames = $this->nicknameMap($userIds);
        $replies = $this->replyMap($rootIds);
        $galleries = attachment()->galleryBatch('comment', $orderItemIds, 'gallery');

        $comment_list = array();
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $nickname = isset($nicknames[$userId]) ? $nicknames[$userId] : '';
            $orderItemId = (int) $row['order_item_id'];

            $comment_list[] = array(
                "id" => $row['id'],
                "nickname" => !$row['anonymous'] && $nickname ? $nickname : lang('comment_anonymous'),
                "content" => $row['content'],
                "img_list" => isset($galleries[$orderItemId]) ? $galleries[$orderItemId] : array(),
                "star_box" => $this->starBox($row['star']),
                "created_at" => date("Y-m-d H:i", strtotime($row['created_at'])),
                "reply" => isset($replies[(int) $row['id']]) ? $replies[(int) $row['id']] : false
            );
        }

        return array('list' => $comment_list, 'pager' => $result['pager']);
    }

    /**
     * 批量取会员昵称。
     *
     * @param array $userIds
     * @return array<int, string> 会员 ID => 昵称
     */
    public function nicknameMap(array $userIds)
    {
        $map = array();

        $ids = array();
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId > 0) {
                $ids[$userId] = $userId;
            }
        }
        if (!$ids) {
            return $map;
        }

        $rows = DB::table('user')->field('id, nickname')->where('id', 'IN', array_values($ids))->select();
        foreach ((array) $rows as $row) {
            $map[(int) $row['id']] = $row['nickname'];
        }

        return $map;
    }

    /**
     * 批量取根评论的管理员回复（一贴一回复，取最早一条）。
     *
     * @param array $rootIds
     * @return array<int, array> 根评论 ID => array(content, created_at)
     */
    public function replyMap(array $rootIds)
    {
        $map = array();

        $ids = array();
        foreach ($rootIds as $rootId) {
            $rootId = (int) $rootId;
            if ($rootId > 0) {
                $ids[$rootId] = $rootId;
            }
        }
        if (!$ids) {
            return $map;
        }

        $rows = DB::table('comment')
            ->field('reply_id, content, created_at')
            ->where('reply_id', 'IN', array_values($ids))
            ->order('id ASC')
            ->select();
        foreach ((array) $rows as $row) {
            $rootId = (int) $row['reply_id'];
            if (!isset($map[$rootId])) {
                $map[$rootId] = array(
                    'content' => $row['content'],
                    'created_at' => date('Y-m-d H:i', strtotime($row['created_at'])),
                );
            }
        }

        return $map;
    }

    /**
     * 批量取内容项名称：按模块分组一次查询，名称字段优先 title、回落 name。
     *
     * @param array $moduleIds array(模块名 => array(内容 ID...))
     * @return array<string, array<int, string>> 模块名 => array(内容 ID => 名称)
     */
    public function itemNameMap(array $moduleIds)
    {
        $map = array();

        foreach ($moduleIds as $module => $ids) {
            $module = (string) $module;
            if ($module === '' || !Check::tableName($module) || !DB::tableExist($module)) {
                continue;
            }

            $clean = array();
            foreach ((array) $ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $clean[$id] = $id;
                }
            }
            if (!$clean) {
                continue;
            }

            $field = DB::fieldExist($module, 'title') ? 'title' : 'name';
            if (!DB::fieldExist($module, $field)) {
                continue;
            }

            $rows = DB::table($module)->field('id, ' . $field)->where('id', 'IN', array_values($clean))->select();
            foreach ((array) $rows as $row) {
                $map[$module][(int) $row['id']] = $row[$field];
            }
        }

        return $map;
    }

    /**
     * 执行starBox操作。
     *
     * @param mixed $star 参数star。
     * @return mixed 返回结果。
     */
    public function starBox($star)
    {
        $list = array();
        for ($i = 1; $i <= $star; $i++) {
            $list[] = $i;
        }

        $star_box['list'] = $list;
        $star_box['number'] = $star;

        return $star_box;
    }

    /**
     * 评论是否含 HTML 结构字符。
     *
     * 评论按纯文本存储与展示，只需拒绝可构成标签的尖括号；百分号、斜杠、邮箱等
     * 常规符号不再当作非法字符（旧的 Check::illegalChar 口径过严）。
     *
     * @param mixed $value
     * @return bool
     */
    public function hasHtmlChar($value)
    {
        if ($value === null || !is_string($value)) {
            return false;
        }

        return (bool) preg_match('/[<>]/', $value);
    }

    /**
     * 待处理（管理员未读）根评论数量，供后台侧栏角标使用。
     *
     * @return int
     */
    public function unreadCount()
    {
        return (int) DB::table('comment')
            ->where('reply_id', 0)
            ->where('if_read', 0)
            ->count();
    }

    /**
     * 执行checkComment操作。
     *
     * 长度按字符计数，与表单校验和前端输入上限口径一致。
     *
     * @param mixed $value 参数value。
     * @param mixed $length 参数length。
     * @return bool 返回结果。
     */
    public function checkComment($value, $length)
    {
        $value = (string) $value;
        $check_chinese = Config::get('site.input_must_include_chinese', false) ? Check::chinese($value) : true;

        if ($check_chinese && $value !== '' && mb_strlen($value) <= (int) $length) {
            return true;
        }
    }

    /**
     * 执行checkPermission操作。
     *
     * @param mixed $module 参数module。
     * @param mixed $item_id 参数item_id。
     * @param string $order_item_id 参数order_item_id。
     * @param string $user_id 参数user_id。
     * @return mixed 返回结果。
     */
    public function checkPermission($module, $item_id, $order_item_id = '', $user_id = 0)
    {
        $user_id = (int) $user_id;
        $order_item_id = (int) $order_item_id;

        // 判断当前会员是否评论过该项目（按会员维度，管理员回复行不计入）
        if ($user_id > 0 && DB::table('comment')
            ->where('item_id', $item_id)
            ->whereRaw('module = ?', array($module))
            ->where('user_id', $user_id)
            ->where('reply_id', 0)
            ->exists()
        ) {
            return lang('comment_exist_item');
        }

        if ($order_item_id > 0) {
            $orderItem = DB::table('order_item')
                ->field('id, order_id, user_id, comment')
                ->where('id', $order_item_id)
                ->find();

            // 订单项不存在或不属于当前会员
            if (!is_array($orderItem) || (int) $orderItem['user_id'] !== $user_id) {
                return lang('comment_no_allow_item');
            }

            // 订单项已被评价
            if ((int) $orderItem['comment'] === 1) {
                return lang('comment_no_allow_item');
            }
            if (DB::table('comment')->where('order_item_id', $order_item_id)->exists()) {
                return lang('comment_exist_item');
            }

            // 仅已完成且允许评价的订单可评论
            $order = DB::table('order')
                ->field('status, allow_comment')
                ->where('id', $orderItem['order_id'])
                ->where('user_id', $user_id)
                ->find();
            if (!is_array($order)
                || (string) $order['status'] !== OrderStatus::COMPLETED
                || (int) $order['allow_comment'] !== 1
            ) {
                return lang('comment_no_allow_item');
            }

            return null;
        }

        // 无订单评论：仅当站点开启 comment_allow_no_order 时允许
        if (!Config::get('param.comment_allow_no_order')) {
            return lang('comment_no_allow_item');
        }
    }
}
