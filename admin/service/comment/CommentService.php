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

namespace Dou\Admin\Service\Comment;

use Dou\Admin\Model\Comment\Comment;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Comment\CommentService as CommentCore;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 *
 */
class CommentService extends BaseService
{
    /** @var CommentCore */
    private $comment;

    /**
     */
    public function __construct(CommentCore $comment)
    {
        $this->comment = $comment;
    }

    /**
     * @param int $page
     * @return array comment_list, pager
     */
    public function buildCommentListData($page)
    {
        // AR：casts(created_at/数值字段) 接管基础格式化；本页内容项名 / 昵称 / 回复 / 图片 / 会员行批量预取
        $result = Comment::whereRoot()
            ->order('id DESC')
            ->paginate(15, (int) $page, route('admin.comment'));

        $rows = array();
        foreach ($result['list'] as $model) {
            $rows[] = $model->toArray();
        }

        if (!$rows) {
            return array('comment_list' => array(), 'pager' => $result['pager']);
        }

        $userIds = array();
        $rootIds = array();
        $orderItemIds = array();
        $moduleIds = array();
        foreach ($rows as $row) {
            $userIds[] = (int) $row['user_id'];
            $rootIds[] = (int) $row['id'];
            $orderItemIds[] = (int) $row['order_item_id'];
            $moduleIds[$row['module']][] = (int) $row['item_id'];
        }

        $itemNames = $this->comment->itemNameMap($moduleIds);
        $nicknames = $this->comment->nicknameMap($userIds);
        $replies = $this->comment->replyMap($rootIds);
        $galleries = attachment()->galleryBatch('comment', $orderItemIds, 'gallery');
        $userRows = $this->userRowMap($userIds);

        $comment_list = array();
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $itemId = (int) $row['item_id'];
            $orderItemId = (int) $row['order_item_id'];

            $if_show = $row['if_show'] ? lang('display') : lang('hidden');
            $item_name = isset($itemNames[$row['module']][$itemId]) ? $itemNames[$row['module']][$itemId] : '';
            $nickname = isset($nicknames[$userId]) ? $nicknames[$userId] : '';

            if (isset($replies[(int) $row['id']])) {
                $reply = $replies[(int) $row['id']];
            } else {
                $reply = array(
                    'content' => '',
                    'created_at' => '',
                );
            }

            if ($userId && user() !== null) {
                $user = user()->format(isset($userRows[$userId]) ? $userRows[$userId] : $userId);
            } else {
                $user = '';
            }

            $comment_list[] = array(
                'id' => $row['id'],
                'user' => $user,
                'module_name' => lang('top_add_' . $row['module']),
                'item_name' => $item_name,
                'item_url' => route($row['module'] . '.show', array('id' => $itemId)),
                'nickname' => !$row['anonymous'] && $nickname ? $nickname : lang('comment_anonymous'),
                'content' => $row['content'],
                'img_list' => isset($galleries[$orderItemId]) ? $galleries[$orderItemId] : array(),
                'star_box' => $this->comment->starBox($row['star']),
                'if_show' => $if_show,
                'if_read' => $row['if_read'],
                'ip' => $row['ip'],
                'created_at' => $row['created_at'],
                'reply' => $reply,
            );
        }

        return array(
            'comment_list' => $comment_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 批量预取会员行，供账号列复用 UserProfileQuery::format()。
     *
     * @param array $userIds
     * @return array<int, array>
     */
    private function userRowMap(array $userIds)
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

        $rows = DB::table('user')
            ->field('id, email, mobile, nickname, user_sn, avatar')
            ->where('id', 'IN', array_values($ids))
            ->select();
        foreach ((array) $rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /**
     * @param mixed $id
     * @return array|null comment, reply
     */
    public function buildCommentReadData($id)
    {
        $comment = Comment::findById($id);
        if (!$comment) {
            return null;
        }

        $commentCreatedAt = Util::toTimestamp($comment->getRawAttribute('created_at'));
        $comment['created_at'] = $commentCreatedAt !== null ? date('Y-m-d', $commentCreatedAt) : '';

        $item_name = Comment::findItemName($comment['module'], $comment['item_id']);
        $comment['user'] = $comment['user_id'] ? (user() !== null ? user()->format($comment['user_id']) : false) : '';
        $comment['module_name'] = lang('top_add_' . $comment['module']);
        $comment['item_name'] = $item_name;
        $comment['item_url'] = route($comment['module'] . '.show', array('id' => $comment['item_id']));
        $comment['img_list'] = attachment()->gallery('comment', $comment['order_item_id'], 'gallery', true);
        $comment['star_box'] = $this->comment->starBox($comment['star']);

        $nickname = Comment::findNicknameByUserId($comment['user_id']);
        $comment['nickname'] = !$comment['anonymous'] && $nickname ? $nickname : lang('comment_anonymous');

        $reply = Comment::findReplyByReplyId($id);
        if ($reply) {
            $reply = $reply->getAttributes();
            $reply['created_at'] = date('Y-m-d H:i', strtotime($reply['created_at']));
            if (!isset($reply['content'])) {
                $reply['content'] = '';
            }
        } else {
            $reply = array(
                'content' => '',
                'created_at' => '',
            );
        }

        Comment::markRead($id);

        return array(
            'comment' => $comment,
            'reply' => $reply,
        );
    }

    /**
     * @param mixed $id 回复目标评论 id
     * @param string $content 回复内容
     * @param int $adminId 当前管理员 id
     * @param string $ip 客户端 IP，由 controller 显式传入（入库 reply.ip 字段）
     * @return void
     */
    public function reply($id, $content, $adminId, $ip = '')
    {
        $id = (int) $id;
        $comment = Comment::findById($id);
        if (!$comment || (int) $comment['reply_id'] !== 0) {
            throw new DomainException(lang('illegal'), route('admin.comment'));
        }
        if (Comment::findReplyByReplyId($id)) {
            throw new DomainException(lang('illegal'), route('admin.comment'));
        }

        $itemName = Comment::findItemName($comment['module'], $comment['item_id']);

        Comment::insertReply(array(
            'reply_id' => $id,
            'from' => 'admin',
            'user_id' => (int) $adminId,
            'order_item_id' => (int) $comment['order_item_id'],
            'module' => (string) $comment['module'],
            'item_id' => (int) $comment['item_id'],
            'content' => xss()->comment((string) $content),
            'ip' => (string) $ip,
            'created_at' => date('Y-m-d H:i:s'),
        ));
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::REPLY, 1, (string) $itemName);
    }

    /**
     * @param mixed $id
     * @return string
     */
    public function toggleShowHtml($id)
    {
        if (!Check::number($id)) {
            throw new DomainException(lang('illegal'), route('admin.comment'));
        }
        $if_show = Comment::toggleShow($id);

        $cls = $if_show ? 'd' : 'h';
        $b = lang('display');
        $s = lang('hidden');
        return '<em class="' . $cls . '"><b>' . $b . '</b><s>' . $s . '</s></em>';
    }

    /**
     * @param array $post
     * @return void
     */
    public function deleteBatch(array $validatedData)
    {
        if (empty($validatedData['checkbox']) || !is_array($validatedData['checkbox'])) {
            throw new DomainException(lang('comment_select_empty'), route('admin.comment'));
        }

        $ids = array_map('intval', $validatedData['checkbox']);
        Comment::deleteByIds($ids);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'comment:' . implode(',', $ids));
    }

    /**
     *
     *
     * @return void
     */
    public function redirectToCommentParameterGroup()
    {
        $set_list = array('comment_allow_no_order');
        foreach ($set_list as $name) {
            if (!DB::table('parameter')->where('name', $name)->find()) {
                DB::table('parameter')->insert(array(
                    'name' => $name,
                    'lang' => lang($name . '_lang'),
                    'value' => lang($name . '_value'),
                    'cue' => lang($name . '_cue'),
                    'group' => 'comment',
                ));
            }
        }
        throw new RedirectException(route('admin.parameter.set', ['group' => 'comment']));
    }
}
