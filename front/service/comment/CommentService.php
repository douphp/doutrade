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

namespace Dou\Front\Service\Comment;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Comment\CommentService as CommentCore;
use Dou\Core\Support\Check;
use Dou\Core\Support\Honeypot;
use Dou\Front\Model\Comment\Comment;

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

    /** @var Captcha */
    private $captcha;

    /**
     * @param CommentCore $comment
     * @param Captcha $captcha
     */
    public function __construct(CommentCore $comment, Captcha $captcha)
    {
        $this->comment = $comment;
        $this->captcha = $captcha;
    }

    /**
     *
     *
     * @return void
     */
    public function ensureCommentImageDir()
    {
        $path = ROOT_PATH . 'images/comment/';
        if (!file_exists($path)) {
            mkdir($path, 0777);
        }
    }

    /**
     * @param int $userId
     * @param int $page
     * @param string $pageUrl
     * @return array comment_list??pager
     */
    public function buildUserCommentList($userId, $page, $pageUrl)
    {
        $lib = $this->comment;
        // AR：casts(created_at/数值字段) 接管基础格式化
        $result = Comment::filterByUserId($userId)
            ->where('reply_id', 0)
            ->applyDefaultOrder()
            ->paginate(15, (int) $page, $pageUrl);

        $rows = array();
        foreach ($result['list'] as $model) {
            $rows[] = $model->toArray();
        }

        if (!$rows) {
            return array('comment_list' => array(), 'pager' => $result['pager']);
        }

        // 批量预取昵称 / 回复 / 内容项名 / 图片，避免逐行查询
        $userIds = array();
        $rootIds = array();
        $orderItemIds = array();
        $moduleIds = array();
        foreach ($rows as $row) {
            $userIds[] = (int) $row['user_id'];
            $rootIds[] = (int) $row['id'];
            $orderItemIds[] = (int) $row['order_item_id'];
            $rowModule = isset($row['module']) ? $row['module'] : '';
            $moduleIds[$rowModule][] = (int) $row['item_id'];
        }

        $nicknames = $lib->nicknameMap($userIds);
        $replies = $lib->replyMap($rootIds);
        $itemNames = $lib->itemNameMap($moduleIds);
        $galleries = attachment()->galleryBatch('comment', $orderItemIds, 'gallery');

        $commentList = array();
        foreach ($rows as $row) {
            $ifShowFlag = isset($row['if_show']) ? (int) $row['if_show'] : 0;
            $ifShow = $ifShowFlag
                ? (lang('display'))
                : (lang('hidden'));
            $addTime = $row['created_at'];

            $rowModule = isset($row['module']) ? $row['module'] : '';
            $rowItemId = (int) $row['item_id'];
            $itemName = isset($itemNames[$rowModule][$rowItemId]) ? $itemNames[$rowModule][$rowItemId] : '';
            $nickname = isset($nicknames[(int) $row['user_id']]) ? $nicknames[(int) $row['user_id']] : '';
            $reply = isset($replies[(int) $row['id']]) ? $replies[(int) $row['id']] : false;
            $orderItemId = (int) $row['order_item_id'];

            $moduleKey = 'top_add_' . $rowModule;
            $moduleLabel = ($moduleKey !== 'top_add_' && lang($moduleKey) !== '')
                ? lang($moduleKey)
                : $rowModule;

            $commentList[] = array(
                'id' => $row['id'],
                'module' => $moduleLabel,
                'item_name' => $itemName,
                'item_url' => route($rowModule . '.show', array('id' => $rowItemId)),
                'nickname' => !$row['anonymous'] && $nickname ? $nickname : lang('comment_anonymous'),
                'content' => $row['content'],
                'img_list' => isset($galleries[$orderItemId]) ? $galleries[$orderItemId] : array(),
                'star_box' => $lib->starBox($row['star']),
                'if_show' => $ifShow,
                'if_read' => $row['if_read'],
                'ip' => $row['ip'],
                'created_at' => $addTime,
                'reply' => $reply,
            );
        }

        return array(
            'comment_list' => $commentList,
            'pager' => $result['pager'],
        );
    }

    /**
     * @param string $orderItemId
     * @param \Dou\Core\Service\Comment\CommentService $comment
     * @param string $userSubUrl
     * @param int $userId 会员 ID，由调用端显式传入（前台 / API 共用）
     * @return array|null
     */
    public function getPostFormData($orderItemId, CommentCore $comment, $userSubUrl, $userId = 0)
    {
        $item = Comment::findOrderItem($orderItemId);
        if (!$item) {
            return null;
        }

        if ($this->comment->checkPermission($item['module'], $item['item_id'], $orderItemId, (int) $userId)) {
            return array('redirect' => $userSubUrl);
        }

        $item['order'] = DB::table('order')->where('id', (int) $item['order_id'])->find();
        $orderCreatedAt = isset($item['order']['created_at']) ? $item['order']['created_at'] : '';
        $item['order']['created_at'] = $orderCreatedAt
            ? date('Y-m-d H:i', is_numeric($orderCreatedAt) ? (int) $orderCreatedAt : strtotime($orderCreatedAt))
            : '';
        $item['order']['order_link'] = route(
            'user.order',
            [],
            ['query' => ['order_sn' => $item['order']['order_sn']]]
        );

        $item['product'] = DB::table($item['module'])->where('id', (int) $item['item_id'])->find();
        if (!is_array($item['product'])) {
            $item['product'] = array();
        }
        if (!isset($item['product']['name'])) {
            $item['product']['name'] = isset($item['product']['title']) ? $item['product']['title'] : '';
        }
        $item['product']['image'] = $item['module'] === 'product'
            ? attachment()->url(language()->langValue(isset($item['product']['image']) ? $item['product']['image'] : '', $item['module'], (int) $item['item_id'], 'image'), true)
            : attachment()->url(language()->langValue(isset($item['product']['image']) ? $item['product']['image'] : '', $item['module'], (int) $item['item_id'], 'image'));
        $item['product']['url'] = route($item['module'] . '.show', array('id' => $item['item_id']));

        return array('item' => $item);
    }

    /**
     * 处理评论提交：登录与发表资格校验、内容合规、可选验证码，校验通过后写库。
     *
     * @param array $post
     * @param string $orderItemId
     * @param string $ip 客户端 IP，入库 comment.ip 防垃圾
     * @param int $userId 会员 ID，由调用端显式传入（前台 / API 共用）
     * @param bool $verifySpam 是否校验浏览器表单蜜罐（API 为 Bearer 鉴权，不校验）
     * @return array type=error_msg|done
     */
    public function processStore(array $post, $orderItemId, $ip = '', $userId = 0, $verifySpam = true)
    {
        $userId = (int) $userId;
        $orderItemId = (int) $orderItemId;

        if ($verifySpam
            && (Honeypot::isFilled(isset($post[Honeypot::FIELD_NAME]) ? $post[Honeypot::FIELD_NAME] : '')
                || Honeypot::failsTiming(isset($post[Honeypot::TIMESTAMP_FIELD]) ? $post[Honeypot::TIMESTAMP_FIELD] : '', time(), Honeypot::MIN_SECONDS, Honeypot::MAX_SECONDS))
        ) {
            return array('type' => 'error_msg', 'text' => lang('illegal'));
        }

        $captchaRaw = isset($post['captcha']) ? (string) $post['captcha'] : '';
        $module = isset($post['module']) && Check::extendId($post['module']) ? $post['module'] : '';
        $itemId = isset($post['item_id']) && Check::number($post['item_id']) ? $post['item_id'] : '';

        // 订单评论以订单项为唯一事实源，忽略客户端提交的 module/item_id
        $orderItem = null;
        if ($orderItemId > 0) {
            $orderItem = DB::table('order_item')
                ->field('id, module, item_id')
                ->where('id', $orderItemId)
                ->find();
            if (!is_array($orderItem)) {
                return array('type' => 'error_msg', 'text' => lang('comment_no_allow_item'));
            }
            $module = (string) $orderItem['module'];
            $itemId = (int) $orderItem['item_id'];
        }

        $wrong = array();

        if ($userId <= 0) {
            $wrong['msg'] = lang('comment_comment_need_login');
        } else {
            if ($msg = $this->comment->checkPermission($module, $itemId, $orderItemId, $userId)) {
                $wrong['msg'] = $msg;
            } else {
                $includeChinese = Config::get('site.input_must_include_chinese', false) ? lang('input_must_include_chinese') : '';

                if ($this->comment->hasHtmlChar(isset($post['content']) ? $post['content'] : '')) {
                    $wrong['content'] = lang('comment_content') . lang('illegal_char');
                } elseif (!$this->comment->checkComment(isset($post['content']) ? $post['content'] : '', 300)) {
                    $wrong['content'] = preg_replace('/d%/Ums', $includeChinese, lang('comment_content_wrong'));
                }

                // 验证码只用于无订单评论，与前台内嵌表单一致
                if ($orderItemId <= 0 && Config::get('site.captcha', false) && !$this->captcha->verify($captchaRaw)) {
                    $wrong['captcha'] = lang('captcha_wrong');
                }
            }
        }

        if ($wrong) {
            $wrongFormat = '';
            foreach ($wrong as $value) {
                $wrongFormat .= $value . '<br>';
            }
            return array('type' => 'error_msg', 'text' => $wrongFormat);
        }

        if (!$module || !$itemId) {
            return array('type' => 'error_msg', 'text' => lang('page_wrong'));
        }

        // 无订单评论必须指向真实存在的内容行
        if ($orderItemId <= 0
            && (!DB::tableExist($module) || !DB::table($module)->where('id', (int) $itemId)->exists())
        ) {
            return array('type' => 'error_msg', 'text' => lang('page_wrong'));
        }

        $content = isset($post['content']) ? xss()->comment($post['content']) : '';
        $ip = (string) $ip;
        $addTime = date('Y-m-d H:i:s');
        $star = isset($post['star']) ? (int) $post['star'] : 5;
        if ($star < 1 || $star > 5) {
            $star = 5;
        }
        $anonymous = !empty($post['anonymous']) ? 1 : 0;

        $inserted = DB::table('comment')->insert(array(
            'user_id' => $userId,
            'order_item_id' => $orderItemId,
            'module' => $module,
            'item_id' => (int) $itemId,
            'content' => $content,
            'star' => $star,
            'anonymous' => $anonymous,
            'ip' => $ip,
            'created_at' => $addTime,
        ));
        if (!$inserted) {
            return array('type' => 'error_msg', 'text' => lang('page_wrong'));
        }

        if ($orderItemId > 0) {
            DB::table('order_item')->where('id', $orderItemId)->update(array('comment' => '1'));
        }

        return array('type' => 'done', 'message' => lang('comment_success'));
    }
}
