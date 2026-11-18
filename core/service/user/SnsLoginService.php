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

use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 第三方登录公共流程服务（QQ / 微信登录共用）。
 *
 * 给定 SNS 用户身份，按下列优先级返回业务跳转 URL：
 *   1. openid / unionid 已与某 user 关联 -> 写登录态 -> 跳转用户中心
 *   2. 未关联，但 userProfile 已登录      -> 在 user_sns 写绑定记录 -> 跳转 user/sns
 *   3. nobind=true 且未登录                -> 自动注册新会员 + 写 user_sns -> 跳转用户中心
 *   4. 其余情况                            -> 把 sns 数据写 session -> 跳转 user/sns_link?sns_token=...
 */
class SnsLoginService
{
    /** @var UserAuthService */
    private $userAuthService;

    /**
     * @param UserAuthService $userAuthService
     */
    public function __construct(UserAuthService $userAuthService)
    {
        $this->userAuthService = $userAuthService;
    }

    /**
     * 解析当前 SNS 身份的最终落地 URL。
     *
     * @param array $sns 必含 group/apptype/openid/unionid/nickname/avatar/sex
     * @param array|null $userProfile 当前已登录会员（未登录传 null）
     * @param string $loginIdMode 'openid' | 'unionid'：决定按哪个字段查找已存在用户
     * @param bool $nobind true 时未登录用户也直接注册（不走绑定）
     * @return string 跳转 URL
     */
    public function resolve(array $sns, $userProfile, $loginIdMode = 'openid', $nobind = false)
    {
        $sns += array(
            'group' => '',
            'apptype' => '',
            'openid' => '',
            'unionid' => '',
            'nickname' => '',
            'avatar' => '',
            'sex' => 0,
        );

        $loginMode = Config::get('param.login_mode', '') === 'mobile' ? 'mobile' : 'email';
        $loginField = $loginMode === 'mobile' ? 'mobile' : 'email';

        $linkedUserId = (int) DB::table('user_sns')
            ->where('group', (string) $sns['group'])
            ->where('apptype', (string) $sns['apptype'])
            ->where('openid', (string) $sns['openid'])
            ->value('user_id');
        if ($linkedUserId === 0 && $loginIdMode === 'unionid' && $sns['unionid'] !== '') {
            $linkedUserId = (int) DB::table('user_sns')
                ->where('group', (string) $sns['group'])
                ->where('unionid', (string) $sns['unionid'])
                ->value('user_id');
        }

        if ($linkedUserId > 0) {
            $userRow = DB::table('user')->where('id', $linkedUserId)->find();
            if ($userRow) {
                $status = UserStatus::normalize(isset($userRow['status']) ? $userRow['status'] : '');
                if ($status !== UserStatus::ACTIVE || $this->userAuthService->isLocked((int) $userRow['id']) > 0) {
                    return route('user.login');
                }
                if (user()) {
                    $this->userAuthService->login($userRow, $loginField);
                    Session::del('promotion_user_sn');
                    return route('user');
                }
            }
        }

        if (is_array($userProfile) && !empty($userProfile['user_id'])) {
            $bindUid = (int) $userProfile['user_id'];
            $alreadyBound = DB::table('user_sns')
                ->where('user_id', $bindUid)
                ->where('group', (string) $sns['group'])
                ->where('apptype', (string) $sns['apptype'])
                ->exists();

            if ($alreadyBound) {
                $update = array('openid' => (string) $sns['openid']);
                if ($sns['unionid'] !== '') {
                    $update['unionid'] = (string) $sns['unionid'];
                }
                $updated = DB::table('user_sns')
                    ->where('user_id', $bindUid)
                    ->where('group', (string) $sns['group'])
                    ->where('apptype', (string) $sns['apptype'])
                    ->data($update)
                    ->update();
                if ($updated === false) {
                    throw new DomainException('第三方账号绑定失败，请重试', route('user.profile.sns'));
                }
            } else {
                if (!$this->insertUserSns($bindUid, $sns)) {
                    throw new DomainException('第三方账号绑定失败，请重试', route('user.profile.sns'));
                }
            }

            return route('user.profile.sns');
        }

        if ($nobind) {
            $newUid = $this->autoRegister($sns);
            if ($newUid > 0 && user()) {
                $userRow = DB::table('user')->where('id', $newUid)->find();
                if ($userRow) {
                    $this->userAuthService->login($userRow, 'email');
                    return route('user');
                }
            }
            return route('user');
        }

        $snsToken = Str::randomByType();
        Session::set('sns', $sns, $snsToken);

        return route('user.profile.sns_link', array(), array('query' => array('sns_token' => $snsToken)));
    }

    /**
     * @param int $userId
     * @param array $sns
     * @return bool
     */
    private function insertUserSns($userId, array $sns)
    {
        $row = array(
            'user_id' => $userId,
            'group' => (string) $sns['group'],
            'openid' => (string) $sns['openid'],
            'apptype' => (string) $sns['apptype'],
            'created_at' => date('Y-m-d H:i:s'),
        );
        if ($sns['unionid'] !== '') {
            $row['unionid'] = (string) $sns['unionid'];
        }
        return DB::table('user_sns')->data($row)->insert() !== false;
    }

    /**
     * 自动以 SNS 资料注册新会员，并写入 user_sns 关联。
     *
     * @param array $sns
     * @return int 新会员 ID（0 表示失败）
     */
    private function autoRegister(array $sns)
    {
        if (user() === null) {
            return 0;
        }

        $lineage = user()->resolvePromotionLineage(Session::get('promotion_user_sn'));

        $userSn = user()->randUserSn();
        $email = $userSn . '@weixin.login';
        $row = array(
            'user_sn' => $userSn,
            'mobile' => null,
            'email' => $email,
            'password' => UserPasswordService::createGeneratedPassword(),
            'nickname' => (string) $sns['nickname'],
            'avatar' => (string) $sns['avatar'],
            'sex' => (int) $sns['sex'],
            'direct_user_id' => $lineage['direct_user_id'],
            'indirect_user_id' => $lineage['indirect_user_id'],
            'created_at' => date('Y-m-d H:i:s'),
        );

        $newUid = DB::transaction(function () use ($row, $lineage, $sns) {
            $newUid = (int) DB::table('user')->data($row)->insert();
            if ($newUid <= 0) {
                throw new DomainException('第三方账号注册失败，请重试', route('user.login'));
            }

            // 会员、推广关系与第三方身份绑定共同提交，失败时不留下孤儿会员。
            if (user()->recordPromotionRelation($newUid, (int) $lineage['direct_user_id']) === false
                || !$this->insertUserSns($newUid, $sns)) {
                throw new DomainException('第三方账号注册失败，请重试', route('user.login'));
            }
            return $newUid;
        });
        Session::del('promotion_user_sn');
        return $newUid;
    }
}
