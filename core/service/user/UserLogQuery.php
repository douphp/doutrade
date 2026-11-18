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

namespace Dou\Core\Service\User;

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员操作日志读模型。
 *
 * 数据源为 `dou_user_log`（由 `audit()->writeUserLog()` 写入），作为
 * 「最近登录时间 / IP」等只读查询的统一入口；写入仍走 AuditService。
 */
class UserLogQuery extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 单个会员最近一次成功登录记录。
     *
     * @param int $userId
     * @return array|null `['created_at' => 'Y-m-d H:i:s', 'ip' => string]`；无记录返回 null
     */
    public function latestLoginFor($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return null;
        }

        $row = DB::table('user_log')
            ->field('created_at, ip')
            ->where('user_id', $userId)
            ->where('action', UserLogAction::LOGIN_SUCCESS)
            ->where('result', 1)
            ->order('created_at DESC')
            ->find();

        if (!$row) {
            return null;
        }

        return array(
            'created_at' => isset($row['created_at']) && $row['created_at'] !== '' ? (string) $row['created_at'] : '',
            'ip' => isset($row['ip']) ? (string) $row['ip'] : '',
        );
    }

    /**
     * 批量会员最近一次成功登录记录（避免列表 N+1）。
     *
     * 单条 SQL：按 `(user_id, UserLogAction::LOGIN_SUCCESS, result=1)` 过滤 + `created_at DESC`，
     * 命中 `KEY user_id` / `KEY created_at` 索引；在 PHP 端按 user_id 取首条即可。
     *
     * @param int[] $userIds
     * @return array<int, array{created_at:string, ip:string}>
     */
    public function latestLoginMap(array $userIds)
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            function ($v) {
                return $v > 0;
            }
        )));
        if (empty($userIds)) {
            return array();
        }

        $rows = DB::table('user_log')
            ->field('user_id, created_at, ip')
            ->whereIn('user_id', $userIds)
            ->where('action', UserLogAction::LOGIN_SUCCESS)
            ->where('result', 1)
            ->order('created_at DESC')
            ->select();

        $map = array();
        foreach ((array) $rows as $row) {
            $uid = (int) $row['user_id'];
            if ($uid <= 0 || isset($map[$uid])) {
                continue;
            }
            $map[$uid] = array(
                'created_at' => isset($row['created_at']) && $row['created_at'] !== '' ? (string) $row['created_at'] : '',
                'ip' => isset($row['ip']) ? (string) $row['ip'] : '',
            );
        }

        return $map;
    }
}
