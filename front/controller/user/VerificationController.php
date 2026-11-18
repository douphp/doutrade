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
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员验证码核验 / 改手机 / 改邮箱子控制器（route=user/verify/...）
 *
 * URL 映射（由 FrontResolver 子控制器探测自动解析，无需硬编码）：
 *   route=user/verify/verification       → verification()       验证码核验页
 *   route=user/verify/verification_post  → verificationPost()   验证码核验提交
 *   route=user/verify/mobile           → mobile()           修改手机号表单
 *   route=user/verify/mobile_post      → mobilePost()       修改手机号提交
 *   route=user/verify/email              → email()              修改邮箱表单
 *   route=user/verify/email_post         → emailPost()          修改邮箱提交
 */
class VerificationController extends BaseController
{
    /** @var Captcha */
    private $captcha;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param Captcha $captcha
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        Captcha $captcha,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->captcha = $captcha;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 验证码核验页（route=user/verify/verification）
     *
     * @param Request $request
     * @return Response
     */
    public function verification(Request $request)
    {
        $type = $request->rec('type', 'sms');
        $authUser = auth('front')->user();
        $checkAccount = (!empty($authUser['account']))
            ? $authUser['account']
            : (string) Session::get('action_account');

        $verificationNextUrl = Session::get('verification_next_url');
        if (!$verificationNextUrl || !$checkAccount) {
            return redirect(ROOT_URL);
        } else {
            $userInfo = Check::email($checkAccount)
                ? DB::table('user')->where('email', $checkAccount)->find()
                : DB::table('user')->where('mobile', $checkAccount)->find();
            $field = $type === 'sms' ? 'mobile' : 'email';
            if (is_array($userInfo) && isset($userInfo[$field])) {
                Session::set('check_account', $userInfo[$field]);
            }
            Session::del('verid');
        }

        $ver = array(
            'value' => $type,
            'sms' => route('user.verify.verification', [], ['query' => ['type' => 'sms']]),
            'email' => route('user.verify.verification', [], ['query' => ['type' => 'email']]),
        );

        if ($type === 'sms' && empty($userInfo['mobile'])) {
            return redirect($ver['email']);
        }

        $urHere = $this->breadcrumb->build('user', 'user_verification');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_verification'),

            // 页面骨架
            'rec' => 'verification',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'captcha_token' => $this->captcha->tokenSet(),
            'ver' => $ver,
            'verification_check_cue' => preg_replace('/d%/Ums', $checkAccount, lang('verification_check_cue')),
            'user_info' => isset($userInfo) ? $userInfo : null,
        ]);
    }

    /**
     * 验证码核验提交（route=user/verify/verification_post）
     *
     * @param Request $request
     * @return Response
     */
    public function verificationPost(Request $request)
    {
        $type = $request->rec('type', 'sms');
        $checkAccount = (string) Session::get('check_account');
        $verificationData = Session::arr('verification');
        $verificationNextUrl = Session::get('verification_next_url');

        if (!$verificationNextUrl || !$checkAccount) {
            return redirect(ROOT_URL);
        }

        if (
            !hash_equals(md5($request->post('verification', '') . DOU_SHELL), (string) Arr::get($verificationData, 'code', ''))
            || $checkAccount != Arr::get($verificationData, 'account', '')
        ) {
            $this->recordVerificationFailure('verify_verification_attempts', 'verification');
            throw new DomainException(
                lang('verification_wrong'),
                route('user.verify.verification', [], ['query' => ['type' => $type]])
            );
        } elseif (!$this->captcha->ontime()) {
            throw new DomainException(
                lang('verification_outtime'),
                route('user.verify.verification', [], ['query' => ['type' => $type]])
            );
        }

        // 找回密码 / 换绑账号都必须先完成一次真实验证码核验，写入一次性标记后才可继续
        $purpose = (string) Session::get('verification_purpose');
        if ($purpose === 'password_reset') {
            $field = Check::email($checkAccount) ? 'email' : 'mobile';
            $userId = (int) DB::table('user')->where($field, $checkAccount)->value('id');
            if ($userId <= 0) {
                throw new DomainException(lang('verification_wrong'), route('user.password_reset'));
            }
            Session::set('password_reset_verified', array(
                'user_id' => $userId,
                'account' => $checkAccount,
                'at' => time(),
                'source' => 'code',
            ));
            Session::set('check_account', $checkAccount);
        } else {
            Session::del('password_reset_verified');
            $authUserId = (int) auth('front')->id();
            if ($purpose === 'account_change' && $authUserId > 0) {
                Session::set('account_change_verified', array(
                    'user_id' => $authUserId,
                    'account' => $checkAccount,
                    'at' => time(),
                ));
            }
        }
        Session::del('verification_purpose');
        Session::del('verify_verification_attempts');

        return redirect($verificationNextUrl);
    }

    /**
     * 修改手机号表单（route=user/verify/mobile）
     *
     * @return Response
     */
    public function mobile()
    {
        Session::set('verification_next_url', route('user.verify.mobile'));
        Session::set('verification_purpose', 'account_change');
        Session::del('password_reset_verified');
        Session::del('ver_mobile_attempts');
        $checkAccount = (string) Session::get('check_account');
        $verificationData = Session::arr('verification');
        $accountChangeFlag = Session::arr('account_change_verified');
        if (!$this->isValidAccountChangeFlag($accountChangeFlag, (int) auth('front')->id(), $checkAccount)
            || $checkAccount != Arr::get($verificationData, 'account', '')
            || !$this->captcha->ontime()
        ) {
            return redirect(route('user.verify.verification'));
        } else {
            Session::set('verid', 'ver_mobile');
        }

        $urHere = $this->breadcrumb->build('user', 'user_modify_mobile');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_modify_mobile'),

            // 页面骨架
            'rec' => 'mobile',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'captcha_token' => $this->captcha->tokenSet(),
        ]);
    }

    /**
     * 修改手机号提交（route=user/verify/mobile_post）
     *
     * @param Request $request
     * @return Response
     */
    public function mobilePost(Request $request)
    {
        Session::set('verification_next_url', route('user.verify.mobile'));
        $checkAccount = (string) Session::get('check_account');
        $verificationData = Session::arr('verification');
        $accountChangeFlag = Session::arr('account_change_verified');
        if (!$this->isValidAccountChangeFlag($accountChangeFlag, (int) auth('front')->id(), $checkAccount)
            || $checkAccount != Arr::get($verificationData, 'account', '')
        ) {
            return redirect(route('user.verify.verification'));
        } elseif (!$this->captcha->ontime()) {
            throw new DomainException(lang('verification_outtime'), route('user.verify.verification'));
        }

        $request->validate(
            array('mobile' => 'required|phone')
        );

        $verificationMobileData = Session::arr('ver_mobile');
        if (
            !hash_equals(md5($request->post('verification', '') . DOU_SHELL), (string) Arr::get($verificationMobileData, 'code', ''))
            || $request->post('mobile') != Arr::get($verificationMobileData, 'account', '')
        ) {
            $this->recordVerificationFailure('ver_mobile_attempts', 'ver_mobile');
            throw new DomainException(lang('verification_wrong'));
        } elseif (!$this->captcha->ontime('front', 'ver_mobile')) {
            throw new DomainException(lang('verification_outtime'));
        } else {
            $userId = (int) auth('front')->id();
            $newMobile = (string) $request->post('mobile');
            $exists = DB::table('user')->where('mobile', $newMobile)->where('id', '<>', $userId)->value('id');
            if ($exists) {
                throw new DomainException(lang('user_mobile_exist'), route('user.verify.mobile'));
            }

            Session::del('ver_mobile_attempts');
            $updated = DB::table('user')->where('id', $userId)->data(array('mobile' => $newMobile))->update();
            if ($updated === false) {
                throw new DomainException(lang('operation_failed', '操作失败，请重试'));
            }
            $authService = new UserAuthService();
            $authService->revokeAllSessions($userId);
            $authService->deleteCookie(DOU_TOKEN);
            Session::clear();
            return redirect(route('user.login'));
        }
    }

    /**
     * 修改邮箱表单（route=user/verify/email）
     *
     * @return Response
     */
    public function email()
    {
        Session::set('verification_next_url', route('user.verify.email'));
        Session::set('verification_purpose', 'account_change');
        Session::del('password_reset_verified');
        Session::del('ver_email_attempts');
        $checkAccount = (string) Session::get('check_account');
        $verificationData = Session::arr('verification');
        $accountChangeFlag = Session::arr('account_change_verified');
        if (!$this->isValidAccountChangeFlag($accountChangeFlag, (int) auth('front')->id(), $checkAccount)
            || $checkAccount != Arr::get($verificationData, 'account', '')
            || !$this->captcha->ontime()
        ) {
            return redirect(route('user.verify.verification'));
        } else {
            Session::set('verid', 'ver_email');
        }

        $urHere = $this->breadcrumb->build('user', 'user_modify_email');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_modify_email'),

            // 页面骨架
            'rec' => 'email',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'captcha_token' => $this->captcha->tokenSet(),
        ]);
    }

    /**
     * 修改邮箱提交（route=user/verify/email_post）
     *
     * @param Request $request
     * @return Response
     */
    public function emailPost(Request $request)
    {
        Session::set('verification_next_url', route('user.verify.email'));
        $checkAccount = (string) Session::get('check_account');
        $verificationData = Session::arr('verification');
        $accountChangeFlag = Session::arr('account_change_verified');
        if (!$this->isValidAccountChangeFlag($accountChangeFlag, (int) auth('front')->id(), $checkAccount)
            || $checkAccount != Arr::get($verificationData, 'account', '')
        ) {
            return redirect(route('user.verify.verification'));
        } elseif (!$this->captcha->ontime()) {
            throw new DomainException(lang('verification_outtime'), route('user.verify.verification'));
        }

        $request->validate(
            array('email' => 'required|email')
        );

        $verificationEmailData = Session::arr('ver_email');
        if (
            !hash_equals(md5($request->post('verification', '') . DOU_SHELL), (string) Arr::get($verificationEmailData, 'code', ''))
            || $request->post('email') != Arr::get($verificationEmailData, 'account', '')
        ) {
            $this->recordVerificationFailure('ver_email_attempts', 'ver_email');
            throw new DomainException(lang('verification_wrong'));
        } elseif (!$this->captcha->ontime('front', 'ver_email')) {
            throw new DomainException(lang('verification_outtime'));
        } else {
            $userId = (int) auth('front')->id();
            $newEmail = (string) $request->post('email');
            $exists = DB::table('user')->where('email', $newEmail)->where('id', '<>', $userId)->value('id');
            if ($exists) {
                throw new DomainException(lang('user_email_exist'), route('user.verify.email'));
            }

            Session::del('ver_email_attempts');
            $updated = DB::table('user')->where('id', $userId)->data(array('email' => $newEmail))->update();
            if ($updated === false) {
                throw new DomainException(lang('operation_failed', '操作失败，请重试'));
            }
            $authService = new UserAuthService();
            $authService->revokeAllSessions($userId);
            $authService->deleteCookie(DOU_TOKEN);
            Session::clear();
            return redirect(route('user.login'));
        }
    }

    /**
     * 校验换绑手机/邮箱前的“当前账号已验证”一次性标记。
     *
     * @param mixed $flag
     * @param int $userId
     * @param string $account
     * @return bool
     */
    private function isValidAccountChangeFlag($flag, $userId, $account)
    {
        if (!is_array($flag)) {
            return false;
        }
        $flagUserId = isset($flag['user_id']) ? (int) $flag['user_id'] : 0;
        $flagAccount = isset($flag['account']) ? trim((string) $flag['account']) : '';
        $at = isset($flag['at']) ? (int) $flag['at'] : 0;

        return $flagUserId > 0
            && $flagUserId === (int) $userId
            && (string) $account !== ''
            && $flagAccount === (string) $account
            && $at > 0
            && (time() - $at) <= 600;
    }

    /**
     * 记录验证码失败次数；达到 5 次清空对应验证码会话，强制重新发码。
     *
     * @param string $attemptKey 计数 session key
     * @param string $clearKey 验证码 session key
     * @return void
     */
    private function recordVerificationFailure($attemptKey, $clearKey)
    {
        $attempts = (int) Session::get($attemptKey, 0) + 1;
        Session::set($attemptKey, $attempts);
        if ($attempts >= 5) {
            Session::del($clearKey);
            Session::del($attemptKey);
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
