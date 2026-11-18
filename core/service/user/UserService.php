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

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员核心服务（薄编排门面）。
 *
 * 按变化原因把会员只读 / 工具能力拆到下列主题域子服务，本类只做组合与对外稳定签名：
 * - {@see UserProfileQuery}     资料读模型、等级名称、当前等级、按字段查 user_id
 * - {@see UserMembershipQuery}  扩展身份（VIP / 工作端 / 分销）按 features 闸读取
 * - {@see UserContactQuery}     会员收货联系人列表与地址拼装
 * - {@see UserPromotionService} 推广关系解析、user_sn / 头像文件名随机生成
 *
 * 调用方以 `user()->xxx()` 访问（features.user 未装载时返回 null）；
 * 子服务可单独由容器注入或经 `Module::make('user.xxx')` 取实例
 * （如等级升级 → `Module::make('user.user_level')` → {@see \Dou\Core\Service\User\UserLevelService}）。
 *
 * 展示型能力（会员中心导航等）由 shell 层承载：前台由
 * `Front\Service\User\UserCenterNavBuilder`，后台导航（含会员中心项）由
 * `Admin\Service\Menu\AdminNavResolver` 统一解析。
 */
class UserService extends BaseService
{
    /** @var UserProfileQuery */
    private $profileQuery;

    /** @var UserMembershipQuery */
    private $membershipQuery;

    /** @var UserContactQuery */
    private $contactQuery;

    /** @var UserPromotionService */
    private $promotionService;

    /**
     * @param UserProfileQuery $profileQuery
     * @param UserMembershipQuery $membershipQuery
     * @param UserContactQuery $contactQuery
     * @param UserPromotionService $promotionService
     */
    public function __construct(
        UserProfileQuery $profileQuery,
        UserMembershipQuery $membershipQuery,
        UserContactQuery $contactQuery,
        UserPromotionService $promotionService
    ) {
        $this->profileQuery = $profileQuery;
        $this->membershipQuery = $membershipQuery;
        $this->contactQuery = $contactQuery;
        $this->promotionService = $promotionService;
    }

    // -----------------------------------------------------------------------
    // 资料读模型（UserProfileQuery）
    // -----------------------------------------------------------------------

    /**
     * 构建当前会员的基础资料（仅展示型字段，不含扩展身份）。
     *
     * @param array $row
     * @param string $field
     * @return array
     */
    public function buildUserProfile($row, $field)
    {
        return $this->profileQuery->buildUserProfile($row, $field);
    }

    /**
     * 会员等级名称（未启用 user_level / 表不存在时返回空串）。
     *
     * @param string $level_id
     * @return string
     */
    public function levelName($level_id = '')
    {
        return $this->profileQuery->levelName($level_id);
    }

    /**
     * 按 mobile / email / user_sn / contact 精确匹配 user_id。
     *
     * @param string $keyword
     * @return mixed|null
     */
    public function findUserId($keyword)
    {
        return $this->profileQuery->findUserId($keyword);
    }

    /**
     * 指定会员的等级（level_id、good_discount）；未启用或未登录返回 null。
     *
     * @param int $userId 显式入参；<=0 返回 null
     * @return array|null
     */
    public function currentLevel($userId = 0)
    {
        return $this->profileQuery->currentLevel($userId);
    }

    /**
     * 标准化会员行：按 user_id 查库或对已查询行补齐 username / avatar。
     *
     * @param array|int|string $userOrId
     * @return array|false
     */
    public function format($userOrId)
    {
        return $this->profileQuery->format($userOrId);
    }

    // -----------------------------------------------------------------------
    // 扩展身份（UserMembershipQuery）
    // -----------------------------------------------------------------------

    /**
     * 工作端身份详情；未启用或无记录返回 null。
     *
     * @param mixed $user_id
     * @return array|null
     */
    public function work($user_id)
    {
        return $this->membershipQuery->work($user_id);
    }

    /**
     * VIP 状态详情；未启用 vip 模块或会员无记录返回 false / 空数组。
     *
     * @param mixed $user_id
     * @return array|bool
     */
    public function vip($user_id)
    {
        return $this->membershipQuery->vip($user_id);
    }

    /**
     * 分销身份详情；未启用或会员无记录返回 false。
     *
     * @param mixed $user_id
     * @return array|bool
     */
    public function distribution($user_id)
    {
        return $this->membershipQuery->distribution($user_id);
    }

    /**
     * 指定会员是否处于有效 VIP 期。
     *
     * @param int $userId
     * @return bool
     */
    public function isVip($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        $row = $this->membershipQuery->vip($userId);
        return is_array($row) && isset($row['status']) && $row['status'] === 'yes';
    }

    /**
     * 指定会员是否具备工作端身份。
     *
     * @param int $userId
     * @return bool
     */
    public function isWork($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        $row = $this->membershipQuery->work($userId);
        return is_array($row) && !empty($row);
    }

    /**
     * 指定会员是否具备分销身份。
     *
     * @param int $userId
     * @return bool
     */
    public function isDistribution($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        $row = $this->membershipQuery->distribution($userId);
        return is_array($row) && !empty($row);
    }

    // -----------------------------------------------------------------------
    // 收货联系人（UserContactQuery）
    // -----------------------------------------------------------------------

    /**
     * 会员收货联系人列表（含 address_full、cur 标记）。
     *
     * @param mixed $user_id
     * @param string $current_contact_id
     * @return array
     */
    public function contactList($user_id, $current_contact_id = '')
    {
        return $this->contactQuery->contactList($user_id, $current_contact_id);
    }

    /**
     * 拼装完整收货地址字符串。
     *
     * @param array $contact
     * @return string
     */
    public function addressFull($contact)
    {
        return $this->contactQuery->addressFull($contact);
    }

    // -----------------------------------------------------------------------
    // 推广关系（UserPromotionService）
    // -----------------------------------------------------------------------

    /**
     * 解析候选 user_sn 的直推/间推 user_id。
     *
     * @param mixed $userSn
     * @param int $selfUserId
     * @return array ['direct_user_id' => int, 'indirect_user_id' => int]
     */
    public function resolvePromotionLineage($userSn, $selfUserId = 0)
    {
        return $this->promotionService->resolvePromotionLineage($userSn, $selfUserId);
    }

    /**
     * 登记新会员的分销关系树（直推及其上级链，无限级）。
     *
     * @param int $newUserId 刚创建的会员 ID
     * @param int $directUserId 直推上级 ID（0 表示无推荐人）
     * @return bool
     */
    public function recordPromotionRelation($newUserId, $directUserId)
    {
        return $this->promotionService->recordPromotionRelation($newUserId, $directUserId);
    }

    /**
     * 生成不重复的 6 位会员编号。
     *
     * @return string
     */
    public function randUserSn()
    {
        return $this->promotionService->randUserSn();
    }

    /**
     * 生成不重复的头像文件名。
     *
     * @return string
     */
    public function createAvatarFilename()
    {
        return $this->promotionService->createAvatarFilename();
    }
}
