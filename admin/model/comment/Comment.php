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

namespace Dou\Admin\Model\Comment;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台评论数据模型
 */
class Comment extends Model
{
    /** @var string */
    protected $table = 'comment';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'reply_id' => 'int',
        'user_id' => 'int',
        'item_id' => 'int',
        'order_item_id' => 'int',
        'if_show' => 'int',
        'if_read' => 'int',
        'anonymous' => 'int',
        'star' => 'int',
        'created_at' => 'datetime:Y-m-d H:i',
    );

    /** @var array */
    protected $fillable = array(
        'reply_id',
        'from',
        'user_id',
        'order_item_id',
        'module',
        'item_id',
        'content',
        'star',
        'anonymous',
        'ip',
        'if_show',
        'if_read',
        'created_at',
    );

    /**
     * 仅取主贴（reply_id = 0）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWhereRoot(Builder $query)
    {
        return $query->where('reply_id', 0);
    }

    /**
     * @param int $id
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findById($id)
    {
        return static::where('id', (int) $id)->first();
    }

    /**
     * @param int $replyId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findReplyByReplyId($replyId)
    {
        return static::field('content, created_at')
            ->where('reply_id', (int) $replyId)
            ->first();
    }

    /**
     * @param string $module
     * @param int $itemId
     * @return string
     */
    public static function findItemName($module, $itemId)
    {
        $field = DB::fieldExist($module, 'title') ? 'title' : 'name';
        $name = DB::table($module)->where('id', (int) $itemId)->value($field);

        return $name ? $name : '';
    }

    /**
     * @param int $userId
     * @return string
     */
    public static function findNicknameByUserId($userId)
    {
        $nickname = DB::table('user')->where('id', (int) $userId)->value('nickname');
        return $nickname ? $nickname : '';
    }

    /**
     * @param int $id
     * @return void
     */
    public static function markRead($id)
    {
        static::where('id', (int) $id)->update(array('if_read' => 1));
    }

    /**
     * @param int $id
     * @return int
     */
    public static function toggleShow($id)
    {
        $ifShow = (int) DB::table(static::tableName())->where('id', (int) $id)->value('if_show');
        $ifShow = $ifShow ? 0 : 1;
        static::where('id', (int) $id)->update(array('if_show' => $ifShow));

        return $ifShow;
    }

    /**
     * @param array $data
     * @return void
     */
    public static function insertReply(array $data)
    {
        static::create($data);
    }

    /**
     * @param array $ids
     * @return void
     */
    public static function deleteByIds(array $ids)
    {
        DB::table(static::tableName())->where('id', 'IN', $ids)->delete();
    }
}
