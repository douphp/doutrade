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
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserPasswordService;
use Dou\Core\Support\FileHelper;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Controller\Asset\LangController;
use Dou\Front\Controller\BaseController;
use Dou\Front\Request\User\UserEditFormRequest;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;
use Dou\Front\Service\User\ProfileService;
use Dou\Front\Service\User\RegistrationService;
use Dou\Front\Service\User\UserCenterPresenter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员资料 / 密码 / 第三方账号子控制器（route=user/profile/...）
 *
 * URL 映射（由 FrontResolver 子控制器探测自动解析，无需硬编码）：
 *   route=user/profile/edit          → edit()        个人资料编辑表单
 *   route=user/profile/edit_post     → editPost()     个人资料保存
 *   route=user/profile/password      → password()    修改密码表单
 *   route=user/profile/password_post → passwordPost()  修改密码提交
 *   route=user/profile/sns           → sns()         第三方账号管理
 *   route=user/profile/sns_link      → snsLink()      第三方账号快捷登录引导
 */
class ProfileController extends BaseController
{
    /** @var ProfileService */
    private $profileService;

    /** @var UserPasswordService */
    private $userPasswordService;

    /** @var RegistrationService */
    private $registrationService;

    /** @var UserCenterPresenter */
    private $userCenterPresenter;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    /**
     * @param ProfileService $profileService
     * @param UserPasswordService $userPasswordService
     * @param RegistrationService $registrationService
     * @param UserCenterPresenter $userCenterPresenter
     * @param NavigationBuilder $nav
     * @param BreadcrumbBuilder $breadcrumb
     * @param SeoResolver $seo
     */
    public function __construct(
        ProfileService $profileService,
        UserPasswordService $userPasswordService,
        RegistrationService $registrationService,
        UserCenterPresenter $userCenterPresenter,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->profileService = $profileService;
        $this->userPasswordService = $userPasswordService;
        $this->registrationService = $registrationService;
        $this->userCenterPresenter = $userCenterPresenter;

        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 个人资料编辑表单（route=user/profile/edit）
     *
     * @return Response
     */
    public function edit()
    {
        $userInfo = $this->profileService->buildEditableUserInfo(auth('front')->id());
        if (!$userInfo) {
            return redirect(route('user.login'));
        }

        $urHere = $this->breadcrumb->build('user', 'user_edit');

        return $this->view('user.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_edit'),

            // 页面骨架
            'rec' => 'edit',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_edit'),
            'user_info' => $userInfo,
            'wrong' => array('sex' => ''),
        ]);
    }

    /**
     * 个人资料保存（route=user/profile/edit_post）
     *
     * @param UserEditFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function editPost(UserEditFormRequest $formRequest, Request $request)
    {
        $formRequest->validated();

        $post = $request->post();
        $userId = auth('front')->id();
        $user = DB::table('user')->where('id', $userId)->find();

        $errors = array();
        if (isset($post['nickname']) && $post['nickname'] && $user['nickname'] != $post['nickname']) {
            if ($this->profileService->nicknameExistsForOtherUser($userId, $post['nickname'])) {
                $errors['nickname'] = lang('user_nickname') . lang('value_exists');
            }
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        // 头像上传
        $avatar = '';
        if (isset($_FILES['avatar']['name']) && $_FILES['avatar']['name'] != '') {
            $fileName = FileHelper::filename(DB::table('user')->where('id', $userId)->value('avatar'));
            $avatarFilename = $fileName ? $fileName : user()->createAvatarFilename();
            $avatar = attachment()->store('user', $userId, UploadedFile::fromGlobals('avatar'), 'main', AttachmentUploadOptions::create()->withBasename($avatarFilename)->withImageWidth(200)->withPrimaryKey('id')->withBusinessField('avatar')->withUploader('user', $userId));
        }

        $post = xss()->post($post);

        // 只更新真实提交的字段：缺省不等于清空，避免 address/postcode/sex 被写空
        $updateData = array('nickname' => $post['nickname']);
        foreach (array('phone', 'address', 'postcode', 'sex') as $col) {
            if (array_key_exists($col, $post)) {
                $updateData[$col] = $post[$col];
            }
        }
        // 地区四层与语言无关，任何语言下都接收；first_name/last_name 按有效语言分区
        foreach (array('country', 'province', 'city', 'district') as $col) {
            if (array_key_exists($col, $post)) {
                $updateData[$col] = $post[$col];
            }
        }
        if (LangController::currentPack() === 'en_us') {
            foreach (array('first_name', 'last_name') as $col) {
                if (array_key_exists($col, $post)) {
                    $updateData[$col] = $post[$col];
                }
            }
        } elseif (array_key_exists('contact', $post)) {
            $updateData['contact'] = $post['contact'];
        }
        if ($avatar) {
            $updateData['avatar'] = $avatar;
        }

        $this->profileService->updateProfile($userId, $updateData);
        return $this->respond($request, route('user.profile.edit'), array(), lang('user_edit_success'));
    }

    /**
     * 修改密码表单（route=user/profile/password）
     *
     * @return Response
     */
    public function password()
    {
        $urHere = $this->breadcrumb->build('user', 'user_password_edit');

        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_password_edit'),

            // 页面骨架
            'rec' => 'password',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_password_edit'),
        ]);
    }

    /**
     * 修改密码提交（route=user/profile/password_post）
     *
     * @param Request $request
     * @return Response
     */
    public function passwordPost(Request $request)
    {
        $rules = array(
            'password' => 'required|password|confirmed',
        );
        $request->validate($rules, [], true);

        $result = $this->userPasswordService->verifyAndChangePassword(
            auth('front')->id(),
            (string) $request->post('old_password', ''),
            (string) $request->post('password')
        );

        if (!$result['ok']) {
            $errors = isset($result['errors']) ? (array) $result['errors'] : array(lang('illegal'));
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        // 刷新 Session shell 以使旧 cookie 失效；当前浏览器清掉 remember-me cookie（本机 session 保留）
        $shellField = Session::get('field');
        if ($shellField !== null && $shellField !== '' && isset($result['user'][$shellField])) {
            Session::set('shell', md5($result['user'][$shellField] . $result['user']['password'] . DOU_SHELL));
        }
        (new UserAuthService())->deleteCookie(DOU_TOKEN);
        return message()->respond(lang('user_password_success'), route('user.profile.edit'));
    }

    /**
     * 第三方账号管理（route=user/profile/sns）
     *
     * @param Request $request
     * @return Response
     */
    public function sns(Request $request)
    {
        $remove = $request->slug('remove');
        if ($remove) {
            $this->registrationService->removeSnsBinding(auth('front')->id(), $remove);
        }

        $link = $request->rec('link');
        if ($link) {
            return redirect(ROOT_URL . 'index.php?route=plugin/' . $link . '/start');
        }

        $urHere = $this->breadcrumb->build('user', 'user_sns');

        return $this->view('user.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_sns'),

            // 页面骨架
            'rec' => 'sns',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_sns'),
            'plugin_list' => $this->userCenterPresenter->buildConnectPluginList(auth('front')->id()),
        ]);
    }

    /**
     * 第三方账号快捷登录引导（route=user/profile/sns_link）
     *
     * @param Request $request
     * @return Response
     */
    public function snsLink(Request $request)
    {
        $redirect = $this->redirectIfLoggedIn();
        if ($redirect) {
            return $redirect;
        }

        $snsToken = $request->digits('sns_token');
        $snsSessionMap = Session::arr('sns');
        if ($snsToken === '' || !isset($snsSessionMap[$snsToken]) || !is_array($snsSessionMap[$snsToken])) {
            return redirect(route('user'));
        }

        $urHere = $this->breadcrumb->build('user', 'user_sns');

        // 推广分销：sns_link 上的 register/login 链接需透传 user_sn，
        // 让用户在第三方授权后选择「新账号 / 已有账号」时不丢失推荐人。
        $registerQuery = array('sns_token' => $snsToken);
        $loginQuery = array('sns_token' => $snsToken);
        $promotionUserSn = (string) Session::get('promotion_user_sn');
        if ($promotionUserSn !== '') {
            $registerQuery['user_sn'] = $promotionUserSn;
            $loginQuery['user_sn'] = $promotionUserSn;
        }
        return $this->view('user_passport.dwt', [
            // 页面头信息
            'page_title' => $this->seo->pageTitle('user', 'user_sns'),

            // 页面骨架
            'rec' => 'sns_link',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),

            // 数据
            'head' => lang('user_sns'),
            'sns' => $snsSessionMap[$snsToken],
            'url_register' => route('user.register', [], ['query' => $registerQuery]),
            'url_login' => route('user.login', [], ['query' => $loginQuery]),
        ]);
    }

    /**
     * 若当前请求来自已登录会员则重定向至会员中心
     *
     * @return Response|null
     */
    private function redirectIfLoggedIn()
    {
        if (auth('front') && auth('front')->check()) {
            return redirect(route('user'));
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
