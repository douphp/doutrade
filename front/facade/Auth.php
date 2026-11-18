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

namespace Dou\Front\Facade;

use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Auth\StatefulGuardContract;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Service\User\UserLogDetail;
use Dou\Core\Service\User\UserService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台会员认证 Guard。
 *
 * 实现 {@see StatefulGuardContract}：作为前台会员身份解析与登录状态写入的
 * 唯一入口；业务调用面统一使用 `auth('front')->method()` 完整写法。
 *
 * 职责边界：
 * - 身份解析：{@see id()} / {@see user()} / {@see check()} / {@see guest()} / {@see workId()} / {@see work()}
 * - 登录写入：{@see attempt()} / {@see attemptByPhone()} / {@see login()} / {@see logout()}
 * - Init 期会话恢复：{@see restoreFromToken()}
 * - 登录前置检测（编排层使用）：{@see ipRateLimited()} / {@see isLocked()} / {@see lockSecondsRemaining()}
 *
 * 业务属性查询（VIP / 工作端 / 分销）由 {@see UserService::isVip()} /
 * {@see UserService::isWork()} / {@see UserService::isDistribution()} 承担；
 * 模板聚合由 {@see \Dou\Front\Service\View\UserStateBuilder} 承担，
 * 本类**不**提供 isVip / isWork / isDistribution / buildAuthState 方法。
 */
class Auth extends BaseService implements StatefulGuardContract
{
    /** @var UserAuthService|null */
    private $userAuthService;

    /** @var UserContactQuery|null */
    private $contactQuery;

    /** @var int 当前会员 ID（未登录为 0） */
    private $userId = 0;

    /** @var array 当前会员档案（未登录为空数组） */
    private $userProfile = array();

    /** @var int 当前工作端 ID（无工作端身份为 0） */
    private $workId = 0;

    /** @var array 当前工作端详情（无工作端身份为空数组） */
    private $workRow = array();

    /**
     * 构造 Guard。
     *
     * {@see UserAuthService} 走懒加载，仅在登录 / 限流 / cookie 操作时
     * 才经 {@see getUserAuthService()} 实例化；构造期不实例化任何子服务，
     * 避免 Init 极早期触发 user 服务装配。
     */
    public function __construct()
    {
        $this->userAuthService = null;
    }

    // ---------------------------------------------------------------------
    // GuardContract / StatefulGuardContract 实现
    // ---------------------------------------------------------------------

    /**
     * 当前会员 ID；未登录返回 0。
     *
     * @return int
     */
    public function id()
    {
        return $this->userId;
    }

    /**
     * 当前会员资料行；未登录返回空数组。
     *
     * @return array
     */
    public function user()
    {
        return $this->userProfile;
    }

    /**
     * 当前请求是否已认证为前台会员。
     *
     * @return bool
     */
    public function check()
    {
        return $this->userId > 0;
    }

    /**
     * 当前请求是否未认证。
     *
     * @return bool
     */
    public function guest()
    {
        return $this->userId <= 0;
    }

    /**
     * 当前工作端 ID（无工作端身份返回 0）。
     *
     * @return int
     */
    public function workId()
    {
        return $this->workId;
    }

    /**
     * 当前工作端详情数组（无工作端身份返回空数组）。
     *
     * @return array
     */
    public function work()
    {
        return $this->workRow;
    }

    /**
     * 凭据登录尝试（账号 / 密码）。
     *
     * 仅承担「校验凭据 → 写入主体登录态」核心动作；不做验证码、SNS 绑定等
     * 编排逻辑（这些由前台 UserController 处理）。
     *
     * IP 限流、账号锁定、用户不存在、密码错误均返回 false；编排层应在调用前
     * 用 {@see ipRateLimited()} / {@see isLocked()} 做预检以输出具体提示。
     *
     * @param array $credentials 必须包含 username（邮箱或手机号）+ password；可选 field
     * @param bool $remember
     * @return bool
     */
    public function attempt(array $credentials, $remember = false)
    {
        $usernameIn = isset($credentials['username']) ? (string) $credentials['username'] : '';
        $passwordIn = isset($credentials['password']) ? (string) $credentials['password'] : '';

        if ($usernameIn === '' || $passwordIn === '') {
            $this->writeLoginFailAudit(0, UserLogDetail::EMPTY_INPUT);
            return false;
        }

        if ($this->ipRateLimited()) {
            $this->writeLoginFailAudit(0, UserLogDetail::IP_RATE_LIMITED);
            return false;
        }

        $field = null;
        if (Check::email($usernameIn)) {
            $field = 'email';
        } elseif (Check::mobile($usernameIn)) {
            $field = 'mobile';
        }
        if (!$field) {
            $this->writeLoginFailAudit(0, UserLogDetail::FIELD_INVALID);
            return false;
        }

        $user = DB::table('user')->where($field, $usernameIn)->find();
        if (!is_array($user)) {
            $this->writeLoginFailAudit(0, UserLogDetail::ACCOUNT_NOT_FOUND);
            return false;
        }

        if ($this->isLocked($user['id'])) {
            $this->writeLoginFailAudit((int) $user['id'], UserLogDetail::ACCOUNT_LOCKED);
            return false;
        }

        if (!$this->verifyPassword($passwordIn, $user)) {
            $this->writeLoginFailAudit((int) $user['id'], UserLogDetail::PASSWORD_WRONG);
            return false;
        }

        $user = DB::table('user')->where('id', $user['id'])->find();
        if (!is_array($user)) {
            $this->writeLoginFailAudit(0, UserLogDetail::ACCOUNT_NOT_FOUND);
            return false;
        }
        if (UserStatus::normalize(isset($user['status']) ? $user['status'] : '') !== UserStatus::ACTIVE) {
            $this->writeLoginFailAudit((int) $user['id'], UserLogDetail::ACCOUNT_LOCKED);
            return false;
        }
        $this->writeLoginState($user, $field, $remember);
        audit()->writeUserLog($user['id'], UserLogAction::LOGIN_SUCCESS, 1, '');

        return true;
    }

    /**
     * 手机验证码登录尝试。
     *
     * 命中后若手机号未对应账号，自动创建新会员。
     *
     * @param array $credentials mobile / verCode / sessionCode / sessionAccount /
     *                           captchaOntime（bool）/ promotionUserSn（可选）
     * @param bool $remember
     * @return bool
     */
    public function attemptByPhone(array $credentials, $remember = false)
    {
        $mobile = isset($credentials['mobile']) ? Str::nullIfEmpty($credentials['mobile']) : '';
        $verCode = isset($credentials['verCode']) ? (string) $credentials['verCode'] : '';
        $sessionCode = isset($credentials['sessionCode']) ? (string) $credentials['sessionCode'] : '';
        $sessionAccount = isset($credentials['sessionAccount']) ? (string) $credentials['sessionAccount'] : '';
        $captchaOntime = isset($credentials['captchaOntime']) ? (bool) $credentials['captchaOntime'] : false;
        $promotionUserSn = isset($credentials['promotionUserSn']) ? $credentials['promotionUserSn'] : '';

        if ($mobile === null || $mobile === '' || !Check::mobile($mobile)) {
            $this->writeLoginFailAudit(0, UserLogDetail::FIELD_INVALID);
            return false;
        }

        if ($this->ipRateLimited()) {
            $this->writeLoginFailAudit(0, UserLogDetail::IP_RATE_LIMITED);
            return false;
        }

        if (!hash_equals(md5($verCode . DOU_SHELL), (string) $sessionCode) || $mobile != $sessionAccount) {
            $this->writeLoginFailAudit(0, UserLogDetail::VERCODE_INVALID);
            return false;
        }
        if (!$captchaOntime) {
            $this->writeLoginFailAudit(0, UserLogDetail::CAPTCHA_EXPIRED);
            return false;
        }

        $userExists = (bool) DB::table('user')->where('mobile', $mobile)->value('id');
        $user = $userExists ? DB::table('user')->where('mobile', $mobile)->find() : null;

        if ($userExists && $user && $this->isLocked($user['id'])) {
            $this->writeLoginFailAudit((int) $user['id'], UserLogDetail::ACCOUNT_LOCKED);
            return false;
        }

        if (!$userExists) {
            $userService = $this->getUserService();
            $lineage = $userService
                ? $userService->resolvePromotionLineage($promotionUserSn)
                : array('direct_user_id' => 0, 'indirect_user_id' => 0);
            $insertData = array(
                'user_sn' => $userService ? $userService->randUserSn() : '',
                'mobile' => Str::nullIfEmpty($mobile),
                'email' => null,
                'password' => \Dou\Core\Service\User\UserPasswordService::createGeneratedPassword(),
                'direct_user_id' => isset($lineage['direct_user_id']) ? $lineage['direct_user_id'] : 0,
                'indirect_user_id' => isset($lineage['indirect_user_id']) ? $lineage['indirect_user_id'] : 0,
                'created_at' => date('Y-m-d H:i:s'),
            );
            DB::table('user')->data($insertData)->insert();
            $user = DB::table('user')->where('mobile', $insertData['mobile'])->find();
            if (is_array($user) && (string) $mobile !== '') {
                $this->getContactQuery()->upsertDefaultContact((int) $user['id'], array('phone' => (string) $mobile));
            }

            // 登记分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）
            if ($userService && is_array($user)) {
                $userService->recordPromotionRelation((int) $user['id'], (int) $insertData['direct_user_id']);
            }
        }

        if (!is_array($user)) {
            return false;
        }

        $this->writeLoginState($user, 'mobile', $remember);
        audit()->writeUserLog($user['id'], UserLogAction::LOGIN_SUCCESS, 1, '');
        return true;
    }

    /**
     * 已知主体行直接登录（注册后立刻登录 / 三方登录回调）。
     *
     * @param array $user 至少含 user_id / password 及 $field 字段的会员行
     * @param bool $remember
     * @return void
     */
    public function login(array $user, $remember = false)
    {
        $field = $this->resolveFieldFromUser($user);
        $this->writeLoginState($user, $field, $remember);
    }

    /**
     * 登出当前会员：清 session、清 remember 凭证、重置实例状态。
     *
     * @return void
     */
    public function logout()
    {
        $userId = (int) $this->id();
        $userAuthService = $this->getUserAuthService();
        if ($userAuthService) {
            $userAuthService->deleteCookie(DOU_TOKEN);
            if ($userId > 0) {
                $userAuthService->clearRememberToken($userId);
            }
        } else {
            setcookie(DOU_TOKEN, '', time() - 3600, '/', '', IS_HTTPS, true);
        }
        Session::clear();
        $this->reset();
    }

    // ---------------------------------------------------------------------
    // Init 期会话恢复
    // ---------------------------------------------------------------------

    /**
     * 注入身份上下文。
     *
     * - 未传 $context 时自行调用 {@see resolveUserContext()} 解析。
     * - $context['ok'] === false 视为未登录，等价于 {@see reset()}。
     *
     * @param array|null $context
     * @return void
     */
    public function hydrate($context = null)
    {
        // 隐式 nullable 兼容（5.6 无 ?Type、8.4 弃用隐式 nullable）：去 array 提示，非数组一律自行解析上下文。
        if (!is_array($context)) {
            $context = $this->resolveUserContext();
        }

        $ok = isset($context['ok']) ? (bool) $context['ok'] : false;
        if (!$ok) {
            $this->reset();
            return;
        }

        $this->userId = isset($context['userId']) ? (int) $context['userId'] : 0;
        $this->userProfile = (isset($context['userProfile']) && is_array($context['userProfile']))
            ? $context['userProfile']
            : array();
        $this->workRow = (isset($context['work']) && is_array($context['work']))
            ? $context['work']
            : array();
        $this->workId = isset($context['workId'])
            ? (int) $context['workId']
            : (isset($this->workRow['id']) ? (int) $this->workRow['id'] : 0);
    }

    /**
     * 重置身份缓存（登出 / 未通过鉴权时使用）。
     *
     * @return void
     */
    public function reset()
    {
        $this->userId = 0;
        $this->userProfile = array();
        $this->workId = 0;
        $this->workRow = array();
    }

    /**
     * 通过 remember_token 恢复登录态。
     *
     * @return bool
     */
    public function restoreFromToken()
    {
        if (!isset($_COOKIE[DOU_TOKEN])) {
            return false;
        }

        $tokenHash = hash('sha256', $_COOKIE[DOU_TOKEN]);
        $user = DB::table('user')->where('token', $tokenHash)->find();
        $tokenExpiresAt = ($user && $user['token_expires_at']) ? strtotime((string) $user['token_expires_at']) : false;
        if ($user && $tokenExpiresAt !== false && $tokenExpiresAt > time()) {
            if (UserStatus::normalize(isset($user['status']) ? $user['status'] : '') !== UserStatus::ACTIVE) {
                $this->deleteRememberCookie();
                DB::table('user')->where('id', $user['id'])->data(array(
                    'token' => '',
                    'token_expires_at' => null,
                ))->update();
                return false;
            }

            $field = $this->resolveFieldFromUser($user);
            $this->writeLoginState($user, $field, true);
            return true;
        }

        $this->deleteRememberCookie();
        if ($user) {
            DB::table('user')->where('id', $user['id'])->data(array(
                'token' => '',
                'token_expires_at' => null,
            ))->update();
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // 登录前置检测（供编排层调用，避免在 attempt 失败后无法定位原因）
    // ---------------------------------------------------------------------

    /**
     * 当前请求 IP 是否处于登录失败限流期。
     *
     * @param int $limit
     * @param int $window
     * @return bool
     */
    public function ipRateLimited($limit = 10, $window = 600)
    {
        $userAuthService = $this->getUserAuthService();
        if (!$userAuthService) {
            return false;
        }
        return $userAuthService->ipRateLimit((string) request()->ip(), $limit, $window);
    }

    /**
     * 指定会员是否处于账号锁定期。
     *
     * @param int $userId
     * @return bool
     */
    public function isLocked($userId)
    {
        $userAuthService = $this->getUserAuthService();
        if (!$userAuthService) {
            return false;
        }
        return $userAuthService->isLocked((int) $userId) > 0;
    }

    /**
     * 指定会员账号锁定剩余秒数（未锁定返回 0）。
     *
     * @param int $userId
     * @return int
     */
    public function lockSecondsRemaining($userId)
    {
        $userAuthService = $this->getUserAuthService();
        if (!$userAuthService) {
            return 0;
        }
        return (int) $userAuthService->isLocked((int) $userId);
    }

    // ---------------------------------------------------------------------
    // 内部解析
    // ---------------------------------------------------------------------

    /**
     * 解析当前请求的前台会员上下文。
     *
     * 返回结构（统一供 {@see hydrate()} 与中间件使用）：
     * - ok          bool   是否解析到合法会员
     * - row         array|null  数据库原始 user 记录
     * - field       string 登录字段（email/mobile）
     * - userProfile array  会员展示资料
     * - userId      int    会员 ID
     * - work        array  工作端详情（无身份为空数组）
     * - workId      int    工作端 ID（无身份为 0）
     *
     * @return array
     */
    public function resolveUserContext()
    {
        $userService = $this->getUserService();
        $userId = (int) Session::get('user_id', 0);
        $shell = (string) Session::get('shell', '');
        $ok = true;

        if ($userId <= 0 || $shell === '') {
            $ok = $this->restoreFromToken();
        }

        if (!$ok) {
            return $this->emptyContext();
        }

        $userId = (int) Session::get('user_id', 0);
        $shell = (string) Session::get('shell', '');
        $row = $this->verifySession($userId, $shell);
        if (!is_array($row)) {
            return $this->emptyContext();
        }

        $field = $this->resolveLoginField();
        $userProfile = $userService ? $userService->buildUserProfile($row, $field) : array();
        if (!is_array($userProfile)) {
            $userProfile = array();
        }

        $userId = isset($userProfile['user_id']) ? (int) $userProfile['user_id'] : 0;
        $work = $this->fetchWorkRow($userId);

        return array(
            'ok' => true,
            'row' => $row,
            'field' => $field,
            'userProfile' => $userProfile,
            'userId' => $userId,
            'work' => $work,
            'workId' => isset($work['id']) ? (int) $work['id'] : 0,
        );
    }

    /**
     * 校验 session 是否有效。
     *
     * @param int $userId
     * @param string $shell
     * @return array|false
     */
    public function verifySession($userId, $shell)
    {
        $row = $this->matchSession($userId, $shell);
        if (is_array($row)) {
            $this->touchSession();
            return $row;
        }

        return false;
    }

    /**
     * 比对 session 凭证与数据库会员状态。
     *
     * @param int $userId
     * @param string $shell
     * @return array|false
     */
    public function matchSession($userId, $shell)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || $shell === '') {
            return false;
        }

        $user = DB::table('user')->where('id', $userId)->find();
        if (!is_array($user)) {
            return false;
        }

        if (UserStatus::normalize(isset($user['status']) ? $user['status'] : '') !== UserStatus::ACTIVE) {
            Session::clear();
            return false;
        }

        $field = $this->resolveLoginField();
        $checkShell = hash_equals(md5($user[$field] . $user['password'] . DOU_SHELL), (string) $shell);
        return $checkShell ? $user : false;
    }

    /**
     * 刷新会员会话心跳。
     *
     * @param int $timeout
     * @return void
     */
    public function touchSession($timeout = 86400)
    {
        $onTime = (int) Session::get('ontime', 0);
        $now = time();
        if ($onTime <= 0 || $now - $onTime > $timeout) {
            Session::clear();
            return;
        }

        Session::set('ontime', $now);
    }

    // ---------------------------------------------------------------------
    // 内部辅助
    // ---------------------------------------------------------------------

    /**
     * 写入登录态（session / token / login_count）。
     *
     * 「最近登录时间 / IP」由 `audit()->writeUserLog(UserLogAction::LOGIN_SUCCESS, 1)` 写入 user_log；
     * 同时更新当前实例为已登录态，避免后续请求逻辑还要重新走 hydrate。
     *
     * @param array $user
     * @param string $field
     * @param bool $remember
     * @return void
     */
    private function writeLoginState(array $user, $field, $remember)
    {
        $userAuthService = $this->getUserAuthService();
        if ($userAuthService) {
            $userAuthService->loginSuccess($user['id']);
            $userAuthService->login($user, $field, $remember);
        }

        $userService = $this->getUserService();
        $userProfile = $userService ? $userService->buildUserProfile($user, $field) : array();
        if (!is_array($userProfile)) {
            $userProfile = array();
        }
        $work = $this->fetchWorkRow((int) $user['id']);
        $this->hydrate(array(
            'ok' => true,
            'row' => $user,
            'field' => $field,
            'userProfile' => $userProfile,
            'userId' => (int) $user['id'],
            'work' => $work,
            'workId' => isset($work['id']) ? (int) $work['id'] : 0,
        ));
    }

    /**
     * 校验输入密码；若库内为历史 md5 散列则即时升级为 bcrypt。
     *
     * @param string $passwordIn
     * @param array $user
     * @return bool
     */
    private function verifyPassword($passwordIn, array $user)
    {
        if (\Dou\Core\Service\User\UserPasswordService::isLegacyGeneratedPassword($user, $passwordIn)) {
            return false;
        }
        $stored = isset($user['password']) ? (string) $user['password'] : '';

        if (strlen($stored) == 32 && ctype_xdigit($stored)) {
            if (md5($passwordIn) !== $stored) {
                return false;
            }
            $newBcrypt = password_hash($passwordIn, PASSWORD_BCRYPT);
            DB::table('user')->where('id', $user['id'])->data(array('password' => $newBcrypt))->update();
            return true;
        }

        return password_verify($passwordIn, $stored);
    }

    /**
     * 记录登录失败：计数 + 审计写入。
     *
     * $userId > 0 时同步 {@see UserAuthService::loginFail} 累加按账号失败计数；
     * $userId = 0 时（账号未匹配、字段非法等）仅写审计，避免对不存在账号计数。
     * 审计写入由 {@see UserAuthService::ipRateLimit} 反查 `user_log` 表用作 IP 限流统计，
     * 所有失败分支应一致调用本方法，避免漏算。
     *
     * @param int $userId
     * @param string $detailTag 来自 {@see UserLogDetail} 的字典常量
     * @return void
     */
    private function writeLoginFailAudit($userId, $detailTag)
    {
        $userId = (int) $userId;
        if ($userId > 0) {
            $userAuthService = $this->getUserAuthService();
            if ($userAuthService) {
                $userAuthService->loginFail($userId);
            }
        }
        audit()->writeUserLog($userId, UserLogAction::LOGIN_FAIL, 0, $detailTag);
    }

    /**
     * 根据会员行解析登录字段（email 优先，否则 mobile）。
     *
     * @param array $user
     * @return string
     */
    private function resolveFieldFromUser(array $user)
    {
        $mode = Config::get('param.login_mode', '');
        if ($mode === 'mobile' || $mode === 'email') {
            return $mode;
        }
        if (!empty($user['email'])) {
            return 'email';
        }
        if (!empty($user['mobile'])) {
            return 'mobile';
        }
        return 'email';
    }

    /**
     * 删除 remember-me cookie（封装 UserAuthService 兜底）。
     *
     * @return void
     */
    private function deleteRememberCookie()
    {
        $userAuthService = $this->getUserAuthService();
        if ($userAuthService) {
            $userAuthService->deleteCookie(DOU_TOKEN);
        } else {
            setcookie(DOU_TOKEN, '', time() - 3600, '/', '', IS_HTTPS, true);
        }
    }

    /**
     * 读取指定会员的工作端详情（无身份返回空数组）。
     *
     * @param int $userId
     * @return array
     */
    private function fetchWorkRow($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return array();
        }

        $service = $this->getUserService();
        if (!$service) {
            return array();
        }

        $row = $service->work($userId);
        return is_array($row) ? $row : array();
    }

    /**
     * 解析当前登录字段：优先 session 中已写入的 field，否则回退到 `param.login_mode`。
     *
     * `param.login_mode === 'mobile'` 返回 `mobile`，其它一律 `email`。
     *
     * @return string 'email' 或 'mobile'
     */
    private function resolveLoginField()
    {
        $field = (string) Session::get('field', '');
        if ($field !== '') {
            return $field;
        }

        return Config::get('param.login_mode') == 'mobile' ? 'mobile' : 'email';
    }

    /**
     * 构造「未登录」的标准上下文骨架（与 {@see resolveUserContext()} 返回结构对齐）。
     *
     * @return array
     */
    private function emptyContext()
    {
        return array(
            'ok' => false,
            'row' => null,
            'field' => '',
            'userProfile' => array(),
            'userId' => 0,
            'work' => array(),
            'workId' => 0,
        );
    }

    /**
     * 始终从容器读取最新 user 服务，避免构造阶段快照过早。
     *
     * @return UserService|null
     */
    private function getUserService()
    {
        return user();
    }

    /**
     * 懒加载 UserContactQuery（手机号自动注册后写默认收货联系人）。
     *
     * @return UserContactQuery
     */
    private function getContactQuery()
    {
        if (!$this->contactQuery) {
            $this->contactQuery = new UserContactQuery();
        }
        return $this->contactQuery;
    }

    /**
     * 懒加载 UserAuthService。
     *
     * @return UserAuthService|null
     */
    private function getUserAuthService()
    {
        if ($this->userAuthService) {
            return $this->userAuthService;
        }
        if (!class_exists('Dou\\Core\\Service\\User\\UserAuthService')) {
            return null;
        }
        $this->userAuthService = new UserAuthService();
        return $this->userAuthService;
    }
}
