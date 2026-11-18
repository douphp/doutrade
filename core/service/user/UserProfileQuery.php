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
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员资料读模型 / 格式化。
 *
 * 负责会员展示型字段拼装、按字段查找 user_id、当前会员等级解析、
 * 会员等级名称读取等只读能力；不写库、不依赖 Smarty。
 */
class UserProfileQuery extends BaseService
{
    /** @var UserContactQuery */
    private $contactQuery;

    /**
     * @param UserContactQuery $contactQuery
     */
    public function __construct(UserContactQuery $contactQuery)
    {
        $this->contactQuery = $contactQuery;
    }

    /**
     * 构建当前会员的基础资料。
     *
     * 仅返回会员展示所需的基础字段；`vip / work / distribution` 等身份数据
     * 由 `Auth` 门面（is_vip/is_work/is_distribution）和对应方法按需获取。
     *
     * @param array $row
     * @param string $field
     * @return array
     */
    public function buildUserProfile($row, $field)
    {
        if (!is_array($row) || empty($row['id'])) {
            return array();
        }

        $account = isset($row[$field]) ? $row[$field] : '';
        $displayName = !empty($row['nickname']) ? $row['nickname'] : $account;
        $userName = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
        $avatar = !empty($row['avatar']) ? attachment()->url($row['avatar']) : ROOT_URL . 'images/avatar/..empty.png';
        $userId = (int) $row['id'];

        return array(
            'user_id' => $userId,
            'user_sn' => isset($row['user_sn']) ? $row['user_sn'] : '',
            'user_name' => $userName,
            'account' => $account,
            'avatar' => $avatar,
            'level_id' => isset($row['level_id']) ? $row['level_id'] : 0,
            'level_name' => isset($row['level_id']) ? $this->levelName($row['level_id']) : '',
        );
    }

    /**
     * 会员等级名称（未启用 user 模块 / 表不存在时返回空串）。
     *
     * @param string $level_id
     * @return string
     */
    public function levelName($level_id = '')
    {
        if (Config::get('features.user', false) && DB::table('user_level')->exists()) {
            $level_name = DB::table('user_level')->where('id', $level_id)->value('name');
            $level_name = $level_name ? $level_name : lang('user_level_no');

            return $level_name;
        }

        return '';
    }

    /**
     * 按会员联系方式等字段精确匹配解析 `user_id`。
     *
     * 在 `user` 表上对 `mobile`、`email`、`user_sn`、`contact` 做全等 OR 匹配，
     * 非模糊搜索；后台筛选「会员关键字」语义与此一致。
     *
     * @param string $keyword
     * @return mixed|null 命中则返回 user_id，未命中或未启用会员模块返回 null
     */
    public function findUserId($keyword)
    {
        if (!Config::get('features.user', false) || !DB::tableExist('user')) {
            return null;
        }

        $userId = DB::table('user')
            ->where('mobile', $keyword)
            ->whereOr('email', $keyword)
            ->whereOr('user_sn', $keyword)
            ->value('id');
        if ($userId) {
            return $userId;
        }
        if (DB::tableExist('user_contact')) {
            return DB::table('user_contact')
                ->where('name', $keyword)
                ->whereOr('phone', $keyword)
                ->value('user_id');
        }

        return null;
    }

    /**
     * 指定会员的等级（level_id、good_discount）；未开启会员模块、未登录或未设等级返回 null。
     *
     * @param int $userId 显式入参；<=0 返回 null
     * @return array|null
     */
    public function currentLevel($userId = 0)
    {
        if (!Config::get('features.user', false)) {
            return null;
        }
        $userId = (int) $userId;
        if ($userId <= 0) {
            return null;
        }
        $levelId = DB::table('user')->where('id', $userId)->value('level_id');
        if (!$levelId) {
            return null;
        }

        $level = DB::table('user_level')
            ->field('id, good_discount')
            ->where('id', $levelId)
            ->find();

        return is_array($level) ? $level : null;
    }

    /**
     * 标准化会员行：按 user_id 查库或直接传入已查询的 user 行；
     * 统一补齐 username（按 param.login_mode 选 mobile/email）与 avatar URL。
     *
     * @param array|int|string $userOrId 传 user_id 时查库；传数组时仅做字段补齐
     * @return array|false 不存在或无数据时返回 false
     */
    public function format($userOrId)
    {
        if (!is_array($userOrId)) {
            $userBasic = DB::table('user')
                ->field('id, email, mobile, nickname, user_sn, avatar')
                ->where('id', $userOrId)
                ->find();
            $fromQuery = true;
        } else {
            $userBasic = $userOrId;
            $fromQuery = false;
        }

        if (!$userBasic) {
            return $userBasic;
        }

        $mobile = isset($userBasic['mobile']) ? $userBasic['mobile'] : '';
        $email = isset($userBasic['email']) ? $userBasic['email'] : '';
        if (Config::get('param.login_mode') == 'mobile') {
            $userBasic['username'] = $mobile ? $mobile : $email;
        } else {
            $userBasic['username'] = $email ? $email : $mobile;
        }

        if ($fromQuery) {
            $userBasic['avatar'] = attachment()->url(isset($userBasic['avatar']) ? $userBasic['avatar'] : '');
        }

        // 把 dou_user_contact 的 is_default=1 行字段 merge 进 user 行，
        // 让模板 / API 继续以 {$user.contact} / {$user.phone} 等键读取。
        $contactSnap = $this->contactQuery->defaultContactSnapshot((int) (isset($userBasic['id']) ? $userBasic['id'] : 0));
        $userBasic = array_merge($contactSnap, $userBasic);

        if (!empty($userBasic['id'])) {
            $userBasic['user_id'] = (int) $userBasic['id'];
        }

        return $userBasic;
    }
}
