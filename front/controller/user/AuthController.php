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

namespace Dou\Front\Controller\User;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Security\Captcha;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Honeypot;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\User\LoginFormRequest;
use Dou\Front\Request\User\LoginPhoneFormRequest;
use Dou\Front\Request\User\PasswordResetPostFormRequest;
use Dou\Front\Request\User\RegisterFormRequest;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;
use Dou\Front\Service\User\LoginService;
use Dou\Front\Service\User\RegistrationService;
use Dou\Front\Service\User\UserCenterPresenter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员登录 / 注册 / 找回密码 / 退出子控制器（route=user/...）
 *
 * URL 映射（由 front/route/user.php 子控制器 group 声明，sub='auth'）：
 *   route=user/register             → register()           注册表单
 *   route=user/register_post        → registerPost()       注册提交
 *   route=user/login                → login()              账号密码登录表单
 *   route=user/login_post           → loginPost()          账号密码登录提交
 *   route=user/login_phone          → loginPhone()         手机验证码登录表单
 *   route=user/login_phone_post     → loginPhonePost()     手机验证码登录提交
 *   route=user/password_reset       → passwordReset()      找回密码流程
 *   route=user/password_reset_post  → passwordResetPost()  找回密码流程提交
 *   route=user/logout               → logout()             退出登录
 */
class AuthController extends BaseController
{
    /** @var RegistrationService */
    private $registrationService;

    /** @var LoginService */
    private $loginService;

    /** @var UserCenterPresenter */
    private $userCenterPresenter;

    /** @var Captcha */
    private $captcha;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param RegistrationService $registrationService
     * @param LoginService $loginService
     * @param UserCenterPresenter $userCenterPresenter
     * @param Captcha $captcha
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        RegistrationService $registrationService,
        LoginService $loginService,
        UserCenterPresenter $userCenterPresenter,
        Captcha $captcha,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->registrationService = $registrationService;
        $this->loginService = $loginService;
        $this->userCenterPresenter = $userCenterPresenter;
        $this->captcha = $captcha;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 注册表单（route=user/register）
     *
     * @param Request $request
     * @return Response
     */
    public function register(Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        if ($this->registrationService->ensureAgreementPageExists()) {
            return redirect(route('user.register'));
        }

        $userSn = $request->integer('user_sn', 0);
        if ($userSn > 0) {
            Session::set('promotion_user_sn', $userSn);
        }

        $urHere = $this->breadcrumb->build('user', 'user_register');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_register'),

            // 页面骨架
            'rec' => 'register',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'token' => csrf()->generate('user_register'),
            'captcha_token' => $this->captcha->tokenSet(),
            'head' => lang('user_register'),
            'sns_token' => $request->digits('sns_token'),
            'user_register_cue_0' => preg_replace('/d%/Ums', (Config::get('param.login_mode') === 'mobile' ? lang('user_mobile') : lang('user_email')), lang('user_register_cue_0')),
        ]);
    }

    /**
     * 注册提交（route=user/register_post）
     *
     * @param RegisterFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function registerPost(RegisterFormRequest $formRequest, Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        $formRequest->validated();

        if (Honeypot::isFilled($request->post(Honeypot::FIELD_NAME, ''))
            || Honeypot::failsTiming($request->post(Honeypot::TIMESTAMP_FIELD, ''), time(), Honeypot::MIN_SECONDS, Honeypot::MAX_SECONDS)
        ) {
            throw new DomainException(lang('illegal'), route('user.register'));
        }

        $post = $request->post();
        // 注册模式必须与 RegisterFormRequest / 模板保持同一信源，禁止用请求是否带 email 推断
        $useEmailMode = Config::get('param.login_mode') !== 'mobile';
        $field = $useEmailMode ? 'email' : 'mobile';

        $checkVerification = $useEmailMode
            ? (bool) Config::get('site.mail_username', '')
            : (bool) Config::get('param.sms_accessKeyId');

        // 验证码必须放在 FormRequest 校验之后做 session 比对：发码时机、过期判定与 captcha session 强耦合
        if ($checkVerification) {
            $verificationInput = isset($post['verification']) ? (string) $post['verification'] : '';
            $accountInput = isset($post[$field]) ? (string) $post[$field] : '';
            $verificationData = Session::arr('verification');
            $errors = array();
            if (!hash_equals(md5($verificationInput . DOU_SHELL), (string) Arr::get($verificationData, 'code', '')) || $accountInput != Arr::get($verificationData, 'account', '')) {
                $errors['verification'] = lang('verification_wrong');
            } elseif (!$this->captcha->ontime()) {
                $errors['verification'] = lang('verification_outtime');
            }
            if (!empty($errors)) {
                throw new DomainException(reset($errors), '', '', '', $errors);
            }
        }

        // 推广分销：候选 user_sn 由 register / loginPhone GET 写入 Session，统一由 helper 解析
        $lineage = user()->resolvePromotionLineage(Session::get('promotion_user_sn'));

        $account = ($field === 'email') ? (isset($post['email']) ? $post['email'] : '') : (isset($post['mobile']) ? $post['mobile'] : '');
        $phone = ($field === 'mobile') ? $account : '';
        $insertData = $this->registrationService->buildRegisterInsertData(
            $field,
            $account,
            isset($post['password']) ? $post['password'] : '',
            $lineage['direct_user_id'],
            $lineage['indirect_user_id'],
            $phone
        );

        // SNS 账号绑定（登录后关联）
        $snsToken = $request->digits('sns_token');
        $snsSessionMap = Session::arr('sns');
        $snsData = ($snsToken !== '' && isset($snsSessionMap[$snsToken]) && is_array($snsSessionMap[$snsToken]))
            ? $snsSessionMap[$snsToken]
            : null;

        $result = $this->registrationService->createUser($insertData, $field, $snsData);
        if (!$result['ok']) {
            throw new DomainException($result['error'], route('user'));
        }

        // 登记分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）
        user()->recordPromotionRelation((int) $result['user']['id'], (int) $lineage['direct_user_id']);

        // 推广关系候选已落入新会员，清空 Session 候选位防止陈旧值影响后续注册/绑定
        Session::del('promotion_user_sn');

        auth('front')->login($result['user']);
        return $this->respond($request, route('user'), array(), lang('user_insert_success'));
    }

    /**
     * 账号密码登录表单（route=user/login）
     *
     * @param Request $request
     * @return Response
     */
    public function login(Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        // 推广分销：登录页若引导到注册或随后触发自动建号，需保留 URL 上的 user_sn 至 Session
        $userSn = $request->integer('user_sn', 0);
        if ($userSn > 0) {
            Session::set('promotion_user_sn', $userSn);
        }

        $sns = $request->rec('sns');
        if ($sns) {
            return redirect(ROOT_URL . 'index.php?route=plugin/' . $sns . '/start');
        }

        $returnUrl = $request->input('return_url') ? $request->input('return_url') : (isset($_SERVER['HTTP_REFERER']) ? urlencode($_SERVER['HTTP_REFERER']) : HOME_URL);
        $returnUrl = $this->safeReturnUrl($returnUrl, HOME_URL);

        $urHere = $this->breadcrumb->build('user', 'user_login');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_login'),

            // 页面骨架
            'rec' => 'login',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'token' => csrf()->generate('user_login'),
            'head' => lang('user_login'),
            'return_url' => $returnUrl,
            'sns_token' => $request->digits('sns_token'),
            'connect_plugin_list' => $this->userCenterPresenter->buildConnectPluginList(),
            'login_phone_url' => route('user.login_phone', [], ['query' => ['return_url' => $returnUrl]]),
        ]);
    }

    /**
     * 账号密码登录提交（route=user/login_post）
     *
     * @param LoginFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function loginPost(LoginFormRequest $formRequest, Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        $formRequest->validated();

        $errors = array();

        if (Honeypot::isFilled($request->post(Honeypot::FIELD_NAME, ''))
            || Honeypot::failsTiming($request->post(Honeypot::TIMESTAMP_FIELD, ''), time(), 0, Honeypot::MAX_SECONDS)
        ) {
            throw new DomainException(lang('illegal'), route('user.login'));
        }

        if (Config::get('site.captcha', false)) {
            $captchaValue = $request->captcha('captcha');
            if (!$this->captcha->verify($captchaValue)) {
                $errors['captcha'] = lang('captcha_wrong');
            }
        }

        $username = (string) $request->input('username', '');
        // 密码不做 trim：注册/重置按原文入库，登录必须使用同一原文
        $password = (string) $request->post('password', '');
        $remember = (bool) $request->post('remember');

        // 凭据验证（业务层）：仅校验，不写登录态。
        $result = $this->loginService->validateLoginCredentials($username, $password, (string) $request->ip());
        if (!empty($result['errors'])) {
            $errors = array_merge($errors, $result['errors']);
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        auth('front')->login($result['user'], $remember);

        $userId = (int) $result['user']['id'];

        $snsToken = $request->digits('sns_token');
        $snsSessionMap = Session::arr('sns');
        if ($snsToken !== '' && isset($snsSessionMap[$snsToken]) && is_array($snsSessionMap[$snsToken])) {
            $sns = $snsSessionMap[$snsToken];
            $this->registrationService->bindSns($userId, $sns, date('Y-m-d H:i:s'));
        }

        Session::del('promotion_user_sn');

        $returnUrl = $this->safeReturnUrl((string) $request->post('return_url', ''), HOME_URL);
        $redirectUrl = $returnUrl;
        if (Config::get('features.work', false)
            && DB::table('work')->where('user_id', $userId)->where('status', 1)->value('user_id')) {
            $redirectUrl = route('book.work');
        }
        return $this->respond($request, $redirectUrl);
    }

    /**
     * 手机验证码登录表单（route=user/login_phone）
     *
     * @param Request $request
     * @return Response
     */
    public function loginPhone(Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        // 推广分销：手机号登录页若新建账号，需保留 URL 上的 user_sn 至 Session，
        // 等到 validatePhoneLogin 自动建号时再写入 direct_user_id。
        $userSn = $request->integer('user_sn', 0);
        if ($userSn > 0) {
            Session::set('promotion_user_sn', $userSn);
        }

        $returnUrl = $request->input('return_url') ? $request->input('return_url') : (isset($_SERVER['HTTP_REFERER']) ? urlencode($_SERVER['HTTP_REFERER']) : HOME_URL);

        $urHere = $this->breadcrumb->build('user', 'user_login_phone');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_login_phone'),

            // 页面骨架
            'rec' => 'login_phone',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'token' => csrf()->generate('user_login_phone'),
            'captcha_token' => $this->captcha->tokenSet(),
            'head' => lang('user_login_phone'),
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * 手机验证码登录提交（route=user/login_phone_post）
     *
     * @param LoginPhoneFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function loginPhonePost(LoginPhoneFormRequest $formRequest, Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        $formRequest->validated();

        $verificationData = Session::arr('verification');
        $errors = array();

        // 凭据验证（业务层）：仅校验，不写登录态。手机号未对应账号时由 service 自动创建。
        $result = $this->loginService->validatePhoneLogin(
            (string) $request->post('mobile', ''),
            (string) $request->post('verification', ''),
            Arr::get($verificationData, 'code', ''),
            Arr::get($verificationData, 'account', ''),
            $this->captcha->ontime(),
            Session::get('promotion_user_sn'),
            (string) $request->ip()
        );
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
            // 验证码错误累计 3 次清空 verification session（防爆破）
            if (isset($errors['verification'])) {
                $attempts = intval(Session::get('verification_attempts', 0)) + 1;
                Session::set('verification_attempts', $attempts);
                if ($attempts >= 3) {
                    Session::del('verification');
                    Session::del('verification_attempts');
                }
            }
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        Session::del('verification');
        Session::del('verification_attempts');
        Session::del('promotion_user_sn');

        auth('front')->login($result['user']);

        $userId = (int) $result['user']['id'];

        $redirectUrl = route('user');
        if (Config::get('features.work', false)
            && DB::table('work')->where('user_id', $userId)->value('user_id')) {
            $redirectUrl = route('work');
        }
        return $this->respond($request, $redirectUrl);
    }

    /**
     * 忘记密码流程（route=user/password_reset，act=default|password）
     *
     * @param Request $request
     * @return Response
     */
    public function passwordReset(Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        $act = $request->rec('act', 'default');
        $userInfo = null;
        $passwordResetCheckCue = '';
        $token = '';

        if ($act === 'password') {
            // 只认一次性验证标记：验证码路径由 verificationPost 写入，邮件链接路径在此校验 token 后写入
            $reset = $this->resolvePasswordResetVerification($request);
            if ($reset === null) {
                throw new DomainException(lang('verification_wrong'), route('user.password_reset'));
            }
            $userInfo = DB::table('user')->where('id', (int) $reset['user_id'])->find();
            if (!is_array($userInfo)) {
                throw new DomainException(lang('illegal'), route('user.password_reset'));
            }
            $token = csrf()->generate('user_password_reset');
            $passwordResetCheckCue = preg_replace('/d%/Ums', (string) $reset['account'], lang('user_password_reset_check_cue'));
        } else {
            $token = csrf()->generate('user_password_reset');
        }

        $urHere = $this->breadcrumb->build('user', 'user_password_reset');
        $viewData = array(
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_password_reset'),

            // 页面骨架
            'rec' => 'password_reset',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'act' => $act,
            'token' => $token,
        );
        if ($act === 'password') {
            $viewData['user_password_reset_check_cue'] = $passwordResetCheckCue;
            $viewData['user_info'] = $userInfo;
        }

        return $this->view('user_passport.dwt', $viewData);
    }

    /**
     * 忘记密码流程提交（route=user/password_reset_post，act=default|password）
     *
     * @param PasswordResetPostFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function passwordResetPost(PasswordResetPostFormRequest $formRequest, Request $request)
    {
        $redirect = $this->redirectIfLoggedIn($request);
        if ($redirect) {
            return $redirect;
        }

        $formRequest->validated();

        $act = $request->rec('act', 'default');
        if ($act !== 'password') {
            $act = 'default';
        }

        if ($act === 'default') {
            if (Honeypot::isFilled($request->post(Honeypot::FIELD_NAME, ''))
                || Honeypot::failsTiming($request->post(Honeypot::TIMESTAMP_FIELD, ''), time(), Honeypot::MIN_SECONDS, Honeypot::MAX_SECONDS)
            ) {
                throw new DomainException(lang('illegal'), route('user.password_reset'));
            }

            $username = (string) $request->post('username', '');
            if (Check::email($username)) {
                if (!DB::table('user')->where('email', $username)->value('id')) {
                    throw new DomainException(lang('user_password_reset_email_no_exist'), route('user.password_reset'));
                }
            } else {
                if (!DB::table('user')->where('mobile', $username)->value('id')) {
                    throw new DomainException(lang('user_password_reset_mobile_no_exist'), route('user.password_reset'));
                }
            }

            $captchaVal = $request->captcha('captcha');
            if (Config::get('site.captcha', false) && !$this->captcha->verify($captchaVal)) {
                throw new DomainException(lang('captcha_wrong'), route('user.password_reset'));
            }

            // 新一轮找回必须清掉上一轮验证状态，避免旧标记复用
            Session::del('password_reset_verified');
            Session::del('check_account');
            Session::del('verification');
            Session::del('verification_attempts');
            Session::del('verify_verification_attempts');
            Session::set('verification_purpose', 'password_reset');
            Session::set('action_account', $username);
            Session::set('verification_next_url', route('user.password_reset', [], ['query' => ['act' => 'password']]));
            return redirect(route('user.verify.verification'));
        }

        $reset = $this->resolvePasswordResetVerification($request);
        if ($reset === null) {
            throw new DomainException(lang('verification_wrong'), route('user.password_reset'));
        }

        $userId = (int) $reset['user_id'];
        $user = DB::table('user')->where('id', $userId)->find();
        if (!is_array($user)) {
            throw new DomainException(lang('illegal'), route('user.password_reset'));
        }

        $newPassword = (string) $request->post('password', '');
        $updateData = array(
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
            'reset_token' => '',
            'reset_token_expires_at' => null,
        );
        $affected = DB::table('user')->where('id', $userId)->data($updateData)->update();
        if ($affected === false) {
            throw new DomainException(lang('operation_failed', '操作失败，请重试'), route('user.password_reset'));
        }

        // 重置后吊销全部会话凭证；当前浏览器也清掉 remember-me cookie
        $authService = new UserAuthService();
        $authService->revokeAllSessions($userId);
        $authService->deleteCookie(DOU_TOKEN);
        audit()->writeUserLog($userId, UserLogAction::PASSWORD_CHANGE, 1);
        $this->clearPasswordResetState();

        return message()->respond(lang('user_password_reset_success'), route('user.login'), 15);
    }

    /**
     * 解析找回密码的“已验证”状态。
     *
     * 优先校验邮件链接中的 uid/code；否则读取 verificationPost 写入的一次性 session 标记。
     *
     * @param Request $request
     * @return array|null {user_id, account, at, source}
     */
    private function resolvePasswordResetVerification(Request $request)
    {
        $uid = $request->integer('uid', 0);
        $code = (string) $request->input('code', '');

        if ($uid > 0 || $code !== '') {
            if ($uid <= 0 || $code === '') {
                return null;
            }
            $user = DB::table('user')->where('id', $uid)->find();
            if (!is_array($user) || empty($user['reset_token']) || empty($user['reset_token_expires_at'])) {
                return null;
            }
            $expires = strtotime((string) $user['reset_token_expires_at']);
            if ($expires === false || $expires <= time()) {
                return null;
            }
            if (!hash_equals((string) $user['reset_token'], hash('sha256', $code))) {
                return null;
            }

            $account = !empty($user['email']) ? (string) $user['email'] : (string) $user['mobile'];
            if ($account === '') {
                return null;
            }
            $flag = array(
                'user_id' => (int) $user['id'],
                'account' => $account,
                'at' => time(),
                'source' => 'email_token',
            );
            Session::set('password_reset_verified', $flag);
            Session::set('check_account', $account);

            return $flag;
        }

        $flag = Session::arr('password_reset_verified');
        if ($this->isValidPasswordResetFlag($flag)) {
            return $flag;
        }

        return null;
    }

    /**
     * @param mixed $flag
     * @return bool
     */
    private function isValidPasswordResetFlag($flag)
    {
        if (!is_array($flag)) {
            return false;
        }
        $userId = isset($flag['user_id']) ? (int) $flag['user_id'] : 0;
        $account = isset($flag['account']) ? trim((string) $flag['account']) : '';
        $at = isset($flag['at']) ? (int) $flag['at'] : 0;

        return $userId > 0 && $account !== '' && $at > 0 && (time() - $at) <= 600;
    }

    /**
     * 清理找回密码相关 session，避免旧标记复用。
     *
     * @return void
     */
    private function clearPasswordResetState()
    {
        Session::del('password_reset_verified');
        Session::del('check_account');
        Session::del('verification');
        Session::del('verification_attempts');
        Session::del('verify_verification_attempts');
        Session::del('action_account');
        Session::del('verification_purpose');
        Session::del('verification_next_url');
        Session::del('verid');
    }

    /**
     * 退出登录（route=user/logout）
     *
     * @return Response
     */
    public function logout()
    {
        auth('front')->logout();
        return redirect(HOME_URL);
    }

    /**
     * 校验登录后的 return_url：只允许站内单斜杠相对路径或同 host 的 http(s) URL。
     *
     * @param mixed $url
     * @param string $default
     * @return string
     */
    private function safeReturnUrl($url, $default)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return $default;
        }

        $decoded = urldecode($url);
        if (preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            return $default;
        }

        $parts = parse_url($decoded);
        if (is_array($parts) && isset($parts['host'])) {
            $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
            $homeHost = parse_url($default, PHP_URL_HOST);
            if (($scheme === 'http' || $scheme === 'https') && $homeHost && strcasecmp((string) $parts['host'], (string) $homeHost) === 0) {
                return $decoded;
            }
            return $default;
        }

        if (isset($decoded[0]) && $decoded[0] === '/'
            && (!isset($decoded[1]) || ($decoded[1] !== '/' && $decoded[1] !== '\\'))
        ) {
            return $decoded;
        }

        return $default;
    }

    /**
     * 若当前请求来自已登录会员则重定向至会员中心
     *
     * @param Request $request
     * @return Response|null
     */
    private function redirectIfLoggedIn(Request $request)
    {
        if (auth('front') && auth('front')->check()) {
            $url = route('user');
            if ($request->wantsJson()) {
                return $this->respond($request, $url);
            }
            return redirect($url);
        }
    }

    /**
     * 构建公共布局变量
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'post' => array(
                'mobile' => '',
                'email' => '',
            ),
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('user'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}
