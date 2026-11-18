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

use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Service\User\UserLogDetail;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员登录凭据校验（前台会话端 + API 端无状态端共享使用）。
 *
 * 仅承担「校验凭据 → 返回 user 行 / errors」的纯校验职责，**不**写 session、
 * **不**写 cookie；登录态写入由调用方按端形态收尾：
 * - 前台会话端：UserController::loginPost 接 user 行后调 `auth('front')->login()` 写 session；
 * - API 无状态端：UserController::loginPost 接 user 行后调 `UserAuthService::login()` 签发 API 会话 token 返客户端。
 *
 * 拆「校验」与「写状态」两步，是因为 dou.js 的 dual-POST 模式：先 AJAX 预检（仅校验），
 * 校验通过再以同一 CSRF 令牌走原生 submit（真正写登录态）。中间件 AJAX 阶段不消费一次性
 * 令牌（{@see \Dou\Core\Foundation\Middleware\AbstractCsrfMiddleware}），controller 同步通过
 * isAjax 判定提前 JSON 返回，避免 attempt() 在 AJAX 阶段就把会话写满，导致原生 submit 时
 * 既无 token 又重复执行写入逻辑。
 *
 * 历史 md5 散列命中后会即时升级为 bcrypt。
 */
class LoginService extends BaseService
{
    /** @var UserAuthService */
    private $userAuthService;

    /** @var UserContactQuery */
    private $contactQuery;

    /**
     * @param UserAuthService $userAuthService
     * @param UserContactQuery $contactQuery
     */
    public function __construct(UserAuthService $userAuthService, UserContactQuery $contactQuery)
    {
        $this->userAuthService = $userAuthService;
        $this->contactQuery = $contactQuery;
    }

    /**
     * 验证账号密码登录凭据。
     *
     * IP 限流使用入参 $ip；审计日志由 AuditService 自动附带请求 IP，writeUserLog 不必再传 ip。
     *
     * @param string $usernameIn 邮箱或手机号
     * @param string $passwordIn 明文密码
     * @param string $ip 客户端 IP，业务用于 ip 失败计数限流（必传）
     * @return array ok, user, errors['captcha'|'username'|'password'], field
     */
    public function validateLoginCredentials($usernameIn, $passwordIn, $ip = '')
    {
        $errors = array();
        $field = null;
        $user = null;
        $detailTag = '';
        $skipFailIncrement = false;

        if ($this->userAuthService->ipRateLimit((string) $ip)) {
            audit()->writeUserLog(0, UserLogAction::LOGIN_FAIL, 0, UserLogDetail::IP_RATE_LIMITED);
            return array(
                'ok' => false,
                'user' => null,
                'errors' => array('username' => lang('login_ip_locked')),
                'field' => null,
            );
        }

        // 字段类型识别（业务规则）：FormRequest 已通过 required 校验非空，此处只决定查库哪一列。
        // 既不是邮箱也不是手机的兜底交给 detailTag + audit log，业务上当作"找不到账号"对待。
        if (Check::email($usernameIn)) {
            $field = 'email';
        } elseif (Check::mobile($usernameIn)) {
            $field = 'mobile';
        } else {
            $errors['username'] = lang('user_username_cue');
            $detailTag = UserLogDetail::FIELD_INVALID;
        }

        if ($field) {
            $user = DB::table('user')->where($field, $usernameIn)->find();
        }

        if ($user && !isset($errors['username']) && ($lockedSeconds = $this->userAuthService->isLocked($user['id']))) {
            $lockedMinutes = ceil($lockedSeconds / 60);
            $errors['username'] = lang('login_account_locked') . "，请 {$lockedMinutes} 分钟后重试";
            $detailTag = UserLogDetail::ACCOUNT_LOCKED;
            // 锁定期间再失败不能续期，否则攻击者可无限延长锁定
            $skipFailIncrement = true;
        }

        if (!$errors) {
            if (!is_array($user)) {
                $errors['password'] = lang('user_login_wrong');
                $detailTag = UserLogDetail::ACCOUNT_NOT_FOUND;
            } else {
                $storedPwd = $user['password'];
                if (\Dou\Core\Service\User\UserPasswordService::isLegacyGeneratedPassword($user, $passwordIn)) {
                    $errors['password'] = lang('user_login_wrong');
                    $detailTag = UserLogDetail::PASSWORD_WRONG;
                } elseif (strlen($storedPwd) == 32 && ctype_xdigit($storedPwd)) {
                    if (md5($passwordIn) !== $storedPwd) {
                        $errors['password'] = lang('user_login_wrong');
                        $detailTag = UserLogDetail::PASSWORD_WRONG;
                    } else {
                        $newBcrypt = password_hash($passwordIn, PASSWORD_BCRYPT);
                        DB::table('user')->where('id', $user['id'])->data(array('password' => $newBcrypt))->update();
                        $user = DB::table('user')->where('id', $user['id'])->find();
                    }
                } else {
                    if (!password_verify($passwordIn, $storedPwd)) {
                        $errors['password'] = lang('user_login_wrong');
                        $detailTag = UserLogDetail::PASSWORD_WRONG;
                    }
                }
            }
        }

        if (!$errors && is_array($user)) {
            $statusError = $this->checkUserStatusForLogin($user);
            if ($statusError !== '') {
                $errors['username'] = $statusError;
                $detailTag = UserLogDetail::ACCOUNT_LOCKED;
                $skipFailIncrement = true;
            }
        }

        if ($errors) {
            if ($user && !$skipFailIncrement) {
                $this->userAuthService->loginFail($user['id']);
            }
            audit()->writeUserLog(
                $user && $user['id'] ? $user['id'] : 0,
                UserLogAction::LOGIN_FAIL,
                0,
                $detailTag
            );
            return array('ok' => false, 'user' => $user, 'errors' => $errors, 'field' => $field);
        }

        $this->userAuthService->loginSuccess($user['id']);
        audit()->writeUserLog($user['id'], UserLogAction::LOGIN_SUCCESS, 1, '');

        return array('ok' => true, 'user' => $user, 'errors' => array(), 'field' => $field);
    }

    /**
     * 验证手机验证码登录；命中且手机号不存在时自动创建账号。
     *
     * @param string $mobile
     * @param string $verCode 提交的验证码明文
     * @param string $sessionCode $_SESSION[DOU_ID]['verification']['code']
     * @param string $sessionAccount $_SESSION[DOU_ID]['verification']['account']
     * @param bool $captchaOntime 验证码是否未过期（Captcha::ontime()）
     * @param mixed $promotionUserSn 推广候选 user_sn；新建账号时写入 direct_user_id（已有账号忽略）
     * @param string $ip 客户端 IP，业务用于 ip 失败计数限流（必传）
     * @return array ok, user, errors
     */
    public function validatePhoneLogin($mobile, $verCode, $sessionCode, $sessionAccount, $captchaOntime, $promotionUserSn = '', $ip = '')
    {
        $mobile = Str::nullIfEmpty($mobile);

        $errors = array();
        $user = null;
        $userExists = false;
        $detailTag = '';

        if ($this->userAuthService->ipRateLimit((string) $ip)) {
            audit()->writeUserLog(0, UserLogAction::LOGIN_FAIL, 0, UserLogDetail::IP_RATE_LIMITED);
            return array(
                'ok' => false,
                'user' => null,
                'errors' => array('mobile' => lang('login_ip_locked') ?: 'IP登录请求过于频繁，请 10 分钟后重试'),
            );
        }

        // 手机号格式：FormRequest 已校验，service 保留同名兜底作为 api 共享边界的防御层。
        if (!Check::mobile($mobile)) {
            $errors['mobile'] = lang('user_mobile_cue');
            $detailTag = UserLogDetail::FIELD_INVALID;
        } else {
            $userExists = (bool) DB::table('user')->where('mobile', $mobile)->value('id');
            if ($userExists) {
                $user = DB::table('user')->where('mobile', $mobile)->find();
            }
            if ($userExists && $user && ($lockedSeconds = $this->userAuthService->isLocked($user['id']))) {
                $lockedMinutes = ceil($lockedSeconds / 60);
                $errors['mobile'] = (lang('login_account_locked') ?: '账户已被锁定') . "，请 {$lockedMinutes} 分钟后重试";
                $detailTag = UserLogDetail::ACCOUNT_LOCKED;
            }
        }

        if (!hash_equals(md5($verCode . DOU_SHELL), (string) $sessionCode) || $mobile != $sessionAccount) {
            $errors['verification'] = lang('verification_wrong');
            if ($detailTag === '') {
                $detailTag = UserLogDetail::VERCODE_INVALID;
            }
        } elseif (!$captchaOntime) {
            $errors['verification'] = lang('verification_outtime');
            if ($detailTag === '') {
                $detailTag = UserLogDetail::CAPTCHA_EXPIRED;
            }
        }

        if (!$errors && is_array($user)) {
            $statusError = $this->checkUserStatusForLogin($user);
            if ($statusError !== '') {
                $errors['mobile'] = $statusError;
                $detailTag = UserLogDetail::ACCOUNT_LOCKED;
            }
        }

        if ($errors) {
            // 验证码/状态失败不累加账号锁定：否则任意人可用错误验证码锁死已知手机号账号
            audit()->writeUserLog(
                $user && $user['id'] ? $user['id'] : 0,
                UserLogAction::LOGIN_FAIL,
                0,
                $detailTag
            );
            return array('ok' => false, 'user' => $user, 'errors' => $errors);
        }

        if (!$userExists) {
            $lineage = user()->resolvePromotionLineage($promotionUserSn);
            $insertData = array(
                'user_sn' => user()->randUserSn(),
                'mobile' => Str::nullIfEmpty($mobile),
                'email' => null,
                'password' => \Dou\Core\Service\User\UserPasswordService::createGeneratedPassword(),
                'direct_user_id' => $lineage['direct_user_id'],
                'indirect_user_id' => $lineage['indirect_user_id'],
                'created_at' => date('Y-m-d H:i:s'),
            );

            DB::beginTransaction();
            try {
                $insertId = (int) DB::table('user')->data($insertData)->insert();
                if ($insertId <= 0) {
                    throw new \RuntimeException('Phone user insert failed');
                }
                $user = DB::table('user')->where('id', $insertId)->find();
                if (!is_array($user) || empty($user['id'])) {
                    throw new \RuntimeException('Phone user reload failed: ' . $insertId);
                }
                $this->contactQuery->upsertDefaultContact((int) $user['id'], array('phone' => (string) $mobile));

                // 登记分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）
                user()->recordPromotionRelation((int) $user['id'], (int) $lineage['direct_user_id']);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                return array(
                    'ok' => false,
                    'user' => null,
                    'errors' => array('mobile' => lang('operation_failed', '操作失败，请重试')),
                );
            }
        }

        $this->userAuthService->loginSuccess($user['id']);
        audit()->writeUserLog($user['id'], UserLogAction::LOGIN_SUCCESS, 1, '');

        return array('ok' => true, 'user' => $user, 'errors' => array());
    }

    /**
     * 校验会员状态是否允许登录。
     *
     * 历史整数 1/0 与空值经 UserStatus::normalize() 归一；未知脏值保守按 suspended 拒登。
     *
     * @param array $user user 行（含 status 列）
     * @return string '' 表示通过；非空表示拒登提示串
     */
    private function checkUserStatusForLogin(array $user)
    {
        $status = UserStatus::normalize(isset($user['status']) ? $user['status'] : '');
        if ($status === UserStatus::ACTIVE) {
            return '';
        }
        if ($status === UserStatus::DEACTIVATED) {
            return lang('user_login_account_deactivated', '账号已注销，无法登录');
        }

        return lang('user_login_account_suspended', '账号已被停用，请联系管理员');
    }
}
