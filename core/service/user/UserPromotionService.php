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
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员推广关系解析与编号工具。
 *
 * 包含「候选 user_sn 解析直推/间推关系」「随机 6 位会员编号」「随机头像文件名」等工具方法，
 * 不涉及 Smarty 与展示副作用，可被三端 Registration / SNS 一键注册等流程共用。
 */
class UserPromotionService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 解析推广关系：将候选 user_sn 解析为新会员的直推/间推 user_id。
     *
     * 用于所有「自动建会员」入口（注册、SNS 一键注册、手机号验证码登录新建账号、
     * 微信小程序一键登录新建账号），统一从候选 user_sn 推导出
     * `direct_user_id` / `indirect_user_id`，避免在多处重复查表与防御逻辑。
     *
     * 拒绝策略：
     *  - 候选 user_sn 不是数字串、查不到对应会员：返回 [0, 0]
     *  - 候选 user_sn 命中的就是当前会员自身（自荐）：返回 [0, 0]
     *
     * @param mixed $userSn 候选推广人的 user_sn（来源：URL/Session/storage/接口入参）
     * @param int $selfUserId 当前操作者自身 user_id（注册场景通常为 0；用于绑定时排除自身）
     * @return array ['direct_user_id' => int, 'indirect_user_id' => int]
     */
    public function resolvePromotionLineage($userSn, $selfUserId = 0)
    {
        $userSn = trim((string) $userSn);
        if ($userSn === '' || !preg_match('/^\d+$/', $userSn)) {
            return array('direct_user_id' => 0, 'indirect_user_id' => 0);
        }

        $directUserId = (int) DB::table('user')->where('user_sn', $userSn)->value('id');
        $selfUserId = (int) $selfUserId;
        if ($directUserId <= 0 || ($selfUserId > 0 && $directUserId === $selfUserId)) {
            return array('direct_user_id' => 0, 'indirect_user_id' => 0);
        }

        $indirectUserId = (int) DB::table('user')->where('id', $directUserId)->value('direct_user_id');
        if ($selfUserId > 0 && $indirectUserId === $selfUserId) {
            $indirectUserId = 0;
        }

        return array(
            'direct_user_id' => $directUserId,
            'indirect_user_id' => $indirectUserId,
        );
    }

    /**
     * 登记新会员的分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）。
     *
     * 所有「自动建会员」入口在写入 dou_user 后统一调用，把 direct_user_id 起的整条
     * 上级链写入 dou_distribution_relation。分销模块未安装（核心服务恒存在，但
     * distribution_relation 表缺失）时由 {@see \Dou\Core\Service\Distribution\DistributionService::recordRelation}
     * 内部判表跳过，不影响注册主流程。
     *
     * @param int $newUserId 刚创建的会员 ID
     * @param int $directUserId 直推上级 ID（0 表示无推荐人）
     * @return bool 正常跳过或写入成功为 true，写入失败为 false
     */
    public function recordPromotionRelation($newUserId, $directUserId)
    {
        $newUserId = (int) $newUserId;
        $directUserId = (int) $directUserId;
        if ($newUserId <= 0 || $directUserId <= 0) {
            return true;
        }

        $distribution = Module::make('distribution');
        if ($distribution) {
            return $distribution->recordRelation($newUserId, $directUserId);
        }
        return true;
    }

    /**
     * 生成不重复的 6 位会员编号；冲突时递归重试。
     *
     * @return string
     */
    public function randUserSn()
    {
        $user_sn = Str::randomByType('number', 6);
        if (DB::table('user')->where('user_sn', $user_sn)->value('user_sn')) {
            return $this->randUserSn();
        }

        return $user_sn;
    }

    /**
     * 生成不重复的头像文件名（用于上传时占位）；冲突时递归重试。
     *
     * @return string
     */
    public function createAvatarFilename()
    {
        $avatar_filename = 'a_' . date('ymdHis') . str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);

        if (DB::table('file')->where('file', $avatar_filename)->value('file')) {
            return $this->createAvatarFilename();
        }

        return $avatar_filename;
    }
}
