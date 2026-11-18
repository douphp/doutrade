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

namespace Dou\Front\Service\User;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\User\UserLevelService;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Service\User\UserLogDetail;
use Dou\Core\Service\User\UserLogQuery;
use Dou\Core\Service\User\UserStatsService;
use Dou\Front\Model\User\User;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员资料业务（front/api 共用）。
 *
 * 负责会员中心首页数据装配、编辑表单数据装配、更新资料、昵称占用检测等。
 * 不触碰 Smarty / Session，由 Controller 负责头像上传与最终页面输出。
 */
class ProfileService extends BaseService
{
    /** @var UserStatsService */
    private $userStats;

    /** @var UserCenterPresenter */
    private $userCenterPresenter;

    /** @var UserLogQuery */
    private $userLogQuery;

    /** @var UserContactQuery */
    private $contactQuery;

    /** @var UserLevelService */
    private $userLevelService;

    /**
     * @param UserStatsService $userStats
     * @param UserCenterPresenter $userCenterPresenter
     * @param UserLogQuery $userLogQuery
     * @param UserContactQuery $contactQuery
     * @param UserLevelService $userLevelService
     */
    public function __construct(UserStatsService $userStats, UserCenterPresenter $userCenterPresenter, UserLogQuery $userLogQuery, UserContactQuery $contactQuery, UserLevelService $userLevelService)
    {
        $this->userStats = $userStats;
        $this->userCenterPresenter = $userCenterPresenter;
        $this->userLogQuery = $userLogQuery;
        $this->contactQuery = $contactQuery;
        $this->userLevelService = $userLevelService;
    }

    /**
     * 更新会员资料（已由控制器校验并处理文件上传）。
     *
     * 收货 10 字段（contact/first_name/last_name/phone/country/province/city/district/address/postcode）会被
     * 拆出写到 dou_user_contact 默认行（is_default=1）；dou_user 自身只保留 sex/nickname/avatar 等。
     *
     * @param int $userId
     * @param array $updateData phone, address, postcode, sex, nickname, contact/first_name/…（按语言）及可选 avatar
     * @return void
     */
    public function updateProfile($userId, array $updateData)
    {
        $contactFields = array('contact', 'first_name', 'last_name', 'phone', 'country', 'province', 'city', 'district', 'address', 'postcode');
        $contactPayload = array();
        foreach ($contactFields as $col) {
            if (array_key_exists($col, $updateData)) {
                $contactPayload[$col] = (string) $updateData[$col];
                unset($updateData[$col]);
            }
        }
        DB::beginTransaction();
        try {
            if (!empty($updateData)) {
                $updated = User::whereKey($userId)->update($updateData);
                if ($updated === false) {
                    throw new DomainException(lang('operation_failed', '操作失败，请重试'));
                }
            }
            if (!empty($contactPayload)) {
                $this->contactQuery->upsertDefaultContact((int) $userId, $contactPayload);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        audit()->writeUserLog($userId, UserLogAction::EDIT_INFO, 1, UserLogDetail::PROFILE_BASIC_UPDATED);
    }

    /**
     * 检查昵称是否被其他用户占用。
     *
     * @param int $userId
     * @param string $nickname
     * @return bool
     */
    public function nicknameExistsForOtherUser($userId, $nickname)
    {
        if ($nickname === '') {
            return false;
        }
        $existsUserId = User::where('nickname', $nickname)->value('id');
        if (!$existsUserId) {
            return false;
        }
        return ((int) $existsUserId !== (int) $userId);
    }

    /**
     * 获取会员中心首页展示数据。
     *
     * @param int $userId
     * @return array|null 找不到会员时返回 null
     */
    public function getUserInfo($userId)
    {
        $userModel = User::find($userId);
        $userInfo = $userModel ? $userModel->getAttributes() : null;
        if (!$userInfo) {
            return null;
        }

        // 凭证类字段不出服务边界：该数组会被 API 端整体序列化返客户端
        unset(
            $userInfo['password'],
            $userInfo['token'],
            $userInfo['token_expires_at'],
            $userInfo['reset_token'],
            $userInfo['reset_token_expires_at'],
            $userInfo['pay_code'],
            $userInfo['pay_code_updated_at']
        );

        $contactSnap = $this->contactQuery->defaultContactSnapshot((int) $userId);
        $userInfo = array_merge($contactSnap, $userInfo);
        $userInfo['avatar'] = $userInfo['avatar']
            ? attachment()->url($userInfo['avatar'])
            : ROOT_URL . 'images/avatar/..empty.png';
        $lastLogin = $this->userLogQuery->latestLoginFor((int) $userId);
        $userInfo['last_login'] = $lastLogin ? date('Y-m-d H:i', strtotime($lastLogin['created_at'])) : '-';
        $userInfo['last_ip'] = $lastLogin ? $lastLogin['ip'] : '-';
        if (Config::get('features.point', false)) {
            $userInfo['point'] = $this->userStats->totalPoint($userId);
        }
        if (Config::get('features.money', false)) {
            $userInfo['money'] = $this->userStats->totalMoney($userId);
            $userInfo['total_promote'] = $this->userStats->totalPromote($userId);
        }
        if (Config::get('features.coupon', false)) {
            $userInfo['coupon_count'] = $this->userStats->totalCoupon($userId);
        }
        if (Config::get('features.favorites', false)) {
            $userInfo['favorites_count'] = $this->userStats->totalFavorites($userId);
        }
        if (Config::get('features.order', false)) {
            $userInfo['total_consumption'] = $this->userStats->totalConsumption($userId);
        }
        $userInfo['promotion_link'] = route(
            'user.register',
            array(),
            array('query' => array('user_sn' => $userInfo['user_sn']))
        );

        // vip/work/distribution 由 Auth::is_xxx 判定、UserService 取详情，不写入 user_info。
        $userInfo['level_name'] = user()->levelName($userInfo['level_id']);
        $growth = $this->userLevelService->buildGrowthProgress(
            (int) $userId,
            isset($userInfo['level_id']) ? (int) $userInfo['level_id'] : 0
        );
        $userInfo = array_merge($userInfo, $growth);
        $userInfo['mobile'] = $userInfo['mobile'] ? $userInfo['mobile'] : '-';
        $userInfo['user_name'] = $userInfo['nickname']
            ? $userInfo['nickname']
            : ($userInfo['mobile'] !== '-' ? $userInfo['mobile'] : $userInfo['email']);

        // 是否生成推广码由 distribution 是否存在决定，不写回 $userInfo['distribution']
        $distribution = user()->distribution($userId);
        if (is_array($distribution) && !empty($distribution)) {
            $userInfo['promotion_qrcode'] = $this->userCenterPresenter->buildPromotionQrcode($userInfo['user_sn']);
        }

        return $userInfo;
    }

    /**
     * 构建会员资料编辑页基础数据（front/api 共用）。
     *
     * @param int $userId
     * @return array|null
     */
    public function buildEditableUserInfo($userId)
    {
        $userModel = User::find((int) $userId);
        $userInfo = $userModel ? $userModel->getAttributes() : null;
        if (!$userInfo) {
            return null;
        }

        // 凭证类字段不出服务边界：该数组会被 API 端整体序列化返客户端
        unset(
            $userInfo['password'],
            $userInfo['token'],
            $userInfo['token_expires_at'],
            $userInfo['reset_token'],
            $userInfo['reset_token_expires_at'],
            $userInfo['pay_code'],
            $userInfo['pay_code_updated_at']
        );

        $contactSnap = $this->contactQuery->defaultContactSnapshot((int) $userId);
        $userInfo = array_merge($contactSnap, $userInfo);
        $userInfo['avatar'] = $userInfo['avatar']
            ? attachment()->url($userInfo['avatar'])
            : ROOT_URL . 'images/avatar/..empty.png';
        $userInfo['phone'] = !empty($userInfo['phone']) ? $userInfo['phone'] : $userInfo['mobile'];

        $userInfo['sex_list'] = array();
        $sexArray = array('1', '0');
        foreach ($sexArray as $v) {
            $userInfo['sex_list'][] = array(
                'value' => $v,
                'lang' => $v ? lang('user_man') : lang('user_woman'),
                'checked' => $userInfo['sex'] == $v ? true : false,
            );
        }

        return $userInfo;
    }
}
