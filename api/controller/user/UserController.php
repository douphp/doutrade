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

namespace Dou\Api\Controller\User;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\DB;
use Dou\Core\Filesystem\Storage;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\User\ApiTokenService;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserPasswordService;
use Dou\Core\Service\User\UserService;
use Dou\Core\Service\User\VerificationTokenService;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Front\Service\Mail\SiteMail;
use Dou\Front\Service\User\LoginService;
use Dou\Front\Service\User\PasswordResetService;
use Dou\Front\Service\User\ProfileService;
use Dou\Front\Service\User\RegistrationService;
use Dou\Front\Service\User\UserCenterPresenter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序用户控制器
 *
 */
class UserController extends BaseController
{
    /** @var RegistrationService */
    private $registrationService;

    /** @var LoginService */
    private $loginService;

    /** @var ProfileService */
    private $profileService;

    /** @var PasswordResetService */
    private $passwordResetService;

    /** @var UserCenterPresenter */
    private $userCenterPresenter;

    /** @var UserAuthService */
    private $userAuthService;

    /** @var UserPasswordService */
    private $userPasswordService;

    /** @var UserService */
    private $userService;

    /** @var ApiTokenService */
    private $apiTokenService;

    /**
     * @param RegistrationService $registrationService
     * @param LoginService $loginService
     * @param ProfileService $profileService
     * @param PasswordResetService $passwordResetService
     * @param UserCenterPresenter $userCenterPresenter
     * @param UserAuthService $userAuthService
     * @param UserPasswordService $userPasswordService
     * @param UserService $userService
     * @param ApiTokenService $apiTokenService
     */
    public function __construct(
        RegistrationService $registrationService,
        LoginService $loginService,
        ProfileService $profileService,
        PasswordResetService $passwordResetService,
        UserCenterPresenter $userCenterPresenter,
        UserAuthService $userAuthService,
        UserPasswordService $userPasswordService,
        UserService $userService,
        ApiTokenService $apiTokenService
    ) {
        $this->registrationService = $registrationService;
        $this->loginService = $loginService;
        $this->profileService = $profileService;
        $this->passwordResetService = $passwordResetService;
        $this->userCenterPresenter = $userCenterPresenter;
        $this->userAuthService = $userAuthService;
        $this->userPasswordService = $userPasswordService;
        $this->userService = $userService;
        $this->apiTokenService = $apiTokenService;
    }

    /**
     * 会员中心首页（route=user）
     *
     * 输出统一命名空间 data.dou.{user,auth,vip,work,distribution}：
     *  - dou.user：基础资料（不含 vip/work/distribution 详情）
     *  - dou.auth：is_login / is_vip / is_work / is_distribution 布尔
     *  - dou.vip / dou.work / dou.distribution：详情数据，未命中时为空数组
     *
     * @return Response
     */
    public function index()
    {
        $userId = (int) auth('api')->id();
        $userInfo = $this->profileService->getUserInfo($userId);

        $vipDetail = user() ? user()->vip($userId) : false;
        $workDetail = user() ? user()->work($userId) : null;
        $distributionDetail = user() ? user()->distribution($userId) : false;

        return ApiResponse::success(array(
            'title' => lang('user'),
            'welcome' => $this->userCenterPresenter->buildWelcomeText(),
            'link_user_center' => $this->buildLinkUserCenter(),
            'if_connect_plugin' => plugin()->hasConnect(),
            'dou' => array(
                'user' => is_array($userInfo) ? $userInfo : array(),
                'auth' => user() ? (new \Dou\Api\Service\View\UserStateBuilder(user()))->build($userId) : array(
                    'is_login' => false,
                    'is_vip' => false,
                    'is_work' => false,
                    'is_distribution' => false,
                ),
                'vip' => is_array($vipDetail) ? $vipDetail : array(),
                'work' => is_array($workDetail) ? $workDetail : array(),
                'distribution' => is_array($distributionDetail) ? $distributionDetail : array(),
            ),
        ));
    }

    /**
     * 注册表单（route=user/register）
     *
     * @param Request $request
     * @return Response
     */
    public function register(Request $request)
    {
        $this->registrationService->ensureAgreementPageExists();

        $userSn = $request->digits('user_sn');

        // 生成 API/小程序端注册验证码下发需要的 token 对
        $pair = $this->registrationService->createApiVerificationToken();

        return ApiResponse::success(array(
            'captcha_token' => $pair['captcha_token'],
            'storage_captcha_token' => $pair['storage_captcha_token'],
            'promotion_user_sn' => $userSn,
            'sns_token' => $request->digits('sns_token'),
            // register.wxml 里分别控制邮箱验证码区、短信验证码区显示
            'login_mode' => Config::get('param.login_mode', 'email'),
            'mail_username' => !empty(Config::get('site.mail_username', false)) ? 1 : 0,
            'sms_accessKeyId' => Config::get('param.sms_accessKeyId') ? 1 : 0,
        ));
    }

    /**
     * 注册提交（route=user/register_post）
     *
     * @param Request $request
     * @return Response
     */
    public function registerPost(Request $request)
    {
        $post = $request->post();
        $checkVerification = false;
        // 与前台 RegisterFormRequest / 模板一致，由 param.login_mode 唯一决定注册通道
        $useEmailMode = Config::get('param.login_mode') !== 'mobile';
        $field = $useEmailMode ? 'email' : 'mobile';

        $rules['password'] = 'required|password|confirmed';
        if ($useEmailMode) {
            $rules['email'] = 'required|email|unique:user,email';
        } else {
            $rules['mobile'] = 'required|phone|unique:user,mobile';
        }

        $errors = array();
        try {
            $request->validate($rules, [], true);
        } catch (DomainException $e) {
            $errors = $e->hasErrors() ? $e->getErrors() : array('_global' => $e->getMessage());
        }

        if ($useEmailMode) {
            if (Config::get('site.mail_username', false)) {
                $checkVerification = true;
            }
        } else {
            if (Config::get('param.sms_accessKeyId')) {
                $checkVerification = true;
            }
        }

        $account = isset($post[$field]) ? (string) $post[$field] : '';
        $phone = ($field === 'mobile') ? $account : '';

        // 验证码凭据只信服务端签名 token；客户端回传的 account/ontime/code 哈希不可信
        if ($checkVerification) {
            $expectedType = $field === 'email' ? 'email' : 'sms';
            $verificationPayload = $this->verifiedVerificationPayload($request, $expectedType, $account);
            $verificationCode = isset($post['verification']) ? (string) $post['verification'] : '';
            if ($verificationPayload === null) {
                $errors['verification'] = lang('verification_wrong');
            } elseif (!hash_equals((string) $verificationPayload['code'], md5($verificationCode . DOU_SHELL))) {
                $this->consumeVerificationCode($request, $account, $expectedType, 'register');
                $errors['verification'] = lang('verification_wrong');
            }
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        // 推广分销：候选 user_sn 由小程序端透传，统一由 helper 解析
        if ($checkVerification && !$this->consumeVerificationCode($request, $account, $expectedType, 'register')) {
            throw new DomainException(lang('verification_wrong'));
        }
        $lineage = user()->resolvePromotionLineage($request->input('promotion_user_sn'));
        $insertData = $this->registrationService->buildRegisterInsertData(
            $field,
            $account,
            isset($post['password']) ? $post['password'] : '',
            $lineage['direct_user_id'],
            $lineage['indirect_user_id'],
            $phone
        );

        // SNS 账号绑定（登录后关联）
        $snsData = null;
        if (!empty($post['sns']) && is_string($post['sns'])) {
            $sns = json_decode(str_replace('\\', '', $post['sns']), true);
            if (is_array($sns)) {
                $snsData = array_merge(array('group' => 'wxlogin', 'apptype' => 'api'), $sns);
            }
        }

        $result = $this->registrationService->createUser($insertData, $field, $snsData);
        if (!$result['ok']) {
            throw new DomainException($result['error']);
        }

        // 登记分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）
        $this->userService->recordPromotionRelation((int) $result['user']['id'], (int) $lineage['direct_user_id']);

        return ApiResponse::success(array(
            'user' => $this->userAuthService->login($result['user'], $field),
            'dou' => array(
                'auth' => array(
                    'is_work' => $this->userService->isWork((int) $result['user']['id']),
                ),
            ),
        ));
    }

    /**
     * 账号密码登录提交（`route=user/login_post`）
     *
     * @param Request $request
     * @return Response
     */
    public function loginPost(Request $request)
    {
        $errors = array();

        // 凭据验证（业务层）
        $result = $this->loginService->validateLoginCredentials(
            (string) $request->input('username', ''),
            (string) $request->post('password', ''),
            (string) $request->ip()
        );
        if (!empty($result['errors'])) {
            $errors = array_merge($errors, $result['errors']);
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        return ApiResponse::success(array(
            'user' => $this->userAuthService->login($result['user'], $result['field']),
            'dou' => array(
                'auth' => array(
                    'is_work' => $this->userService->isWork((int) $result['user']['id']),
                ),
            ),
        ));
    }

    /**
     * 手机验证码登录提交（`route=user/login_phone_post`）
     *
     * @param Request $request
     * @return Response
     */
    public function loginPhonePost(Request $request)
    {
        $mobile = (string) $request->post('mobile', '');
        $payload = $this->verifiedVerificationPayload($request, 'sms', $mobile);
        if ($payload === null) {
            throw new DomainException(lang('verification_wrong'));
        }
        if (!$this->consumeVerificationCode($request, $mobile, 'sms', 'login')) {
            throw new DomainException(lang('verification_wrong'));
        }

        $result = $this->loginService->validatePhoneLogin(
            $mobile,
            (string) $request->post('verification', ''),
            (string) $payload['code'],
            (string) $payload['account'],
            true,
            $request->input('promotion_user_sn'),
            (string) $request->ip()
        );

        $errors = array();
        if (!empty($result['errors'])) {
            $errors = $result['errors'];
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        return ApiResponse::success(array(
            'user' => $this->userAuthService->login($result['user'], 'mobile'),
            'dou' => array(
                'auth' => array(
                    'is_work' => $this->userService->isWork((int) $result['user']['id']),
                ),
            ),
        ));
    }

    /**
     * 忘记密码表单（`route=user/password_reset`）
     *
     * @return Response
     */
    public function passwordReset()
    {
        return ApiResponse::success(array(
            'title' => lang('user_password_reset'),
        ));
    }

    /**
     * 忘记密码提交（`route=user/password_reset_post`）
     *
     * @param Request $request
     * @return Response
     */
    public function passwordResetPost(Request $request)
    {
        $post = $request->post();
        $email = isset($post['email']) ? trim((string) $post['email']) : '';

        if (!Check::email($email)) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('user_email_cue'), array('email' => lang('user_email_cue')), 422);
        }

        $tokenResult = $this->passwordResetService->createPasswordResetTokenForEmail($email);
        if (!$tokenResult['ok']) {
            // 统一成功文案，避免通过 404 枚举邮箱是否存在
            return ApiResponse::success(array(), lang('user_password_mail_success') . $email);
        }

        $user = $tokenResult['user'];
        $code = $tokenResult['token'];
        // API 端 IS_MINIPROGRAM 下 route() 会生成 /pages/...，邮件链接必须显式走 Web 入口
        $base = defined('ROOT_URL') ? ROOT_URL : (defined('HOME_URL') ? HOME_URL : '');
        $resetUrl = rtrim($base, '/') . '/index.php?route=user/password_reset&act=password'
            . '&uid=' . (int) $user['id']
            . '&code=' . urlencode($code);

        if (!SiteMail::sendUserPasswordResetMail($email, lang_all(), $user, $resetUrl)) {
            return ApiResponse::error(ApiCodes::SERVER_ERROR, lang('mail_send_fail'), array(), 500);
        }

        return ApiResponse::success(array(), lang('user_password_mail_success') . $user['email']);
    }

    /**
     * 个人资料编辑表单（`route=user/edit`）
     *
     * @return Response
     */
    public function edit()
    {
        $userInfo = $this->profileService->buildEditableUserInfo(auth('api')->id());
        if (!$userInfo) {
            throw new DomainException(lang('illegal'));
        }

        return ApiResponse::success(array(
            'title' => lang('user_edit'),
            'language' => Config::get('site.language.default', ''),
            'dou' => array(
                'user' => $userInfo,
            ),
        ));
    }

    /**
     * 个人资料保存（`route=user/edit_post`）
     *
     * @param Request $request
     * @return Response
     */
    public function editPost(Request $request)
    {
        $userId = (int) auth('api')->id();
        $post = $request->post();
        $user = DB::table('user')->where('id', $userId)->find();

        $rules = array(
            'nickname' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'first_name' => 'illegal_char',
            'last_name' => 'illegal_char',
            'country' => 'illegal_char',
            'province' => 'illegal_char',
            'address' => 'illegal_char',
            'postcode' => 'illegal_char',
            'sex' => 'in:0,1',
        );
        if (Config::get('site.language.default', '') === 'en_us') {
            $rules['first_name'] = 'required|illegal_char';
            $rules['last_name'] = 'required|illegal_char';
            $rules['contact'] = 'illegal_char';
        } else {
            $rules['contact'] = 'required|illegal_char';
        }
        $request->validate($rules, array(), true);

        $errors = array();
        if (isset($post['nickname']) && $post['nickname'] && $user['nickname'] != $post['nickname']) {
            if ($this->profileService->nicknameExistsForOtherUser($userId, $post['nickname'])) {
                $errors['nickname'] = lang('user_nickname') . lang('value_exists');
            }
        }

        if (!empty($errors)) {
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        // 头像上传在 uploadAvatar() 方法中

        $post = xss()->post($post);

        // 只更新请求中真实提交的字段：缺省不等于清空，避免 address/postcode/sex 被写空
        $updateData = array('nickname' => $post['nickname']);
        foreach (array('phone', 'address', 'postcode', 'sex') as $col) {
            if (array_key_exists($col, $post)) {
                $updateData[$col] = $post[$col];
            }
        }
        // 地区四层与语言无关，任何语言下都接收；first_name/last_name 仍按站点语言分区
        foreach (array('country', 'province', 'city', 'district') as $col) {
            if (array_key_exists($col, $post)) {
                $updateData[$col] = $post[$col];
            }
        }
        if (Config::get('site.language.default', '') === 'en_us') {
            foreach (array('first_name', 'last_name') as $col) {
                if (array_key_exists($col, $post)) {
                    $updateData[$col] = $post[$col];
                }
            }
        } elseif (array_key_exists('contact', $post)) {
            $updateData['contact'] = $post['contact'];
        }

        $this->profileService->updateProfile($user['id'], $updateData);

        return ApiResponse::success(array());
    }

    /**
     * 修改密码表单（`route=user/password`）
     *
     * @return Response
     */
    public function password()
    {
        return ApiResponse::success(array(
            'title' => lang('user_password_edit'),
        ));
    }

    /**
     * 修改密码提交（`route=user/password_post`）
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
            auth('api')->id(),
            (string) $request->post('old_password', ''),
            (string) $request->post('password')
        );

        if (!$result['ok']) {
            $errors = isset($result['errors']) ? (array) $result['errors'] : array();
            throw new DomainException(reset($errors), '', '', '', $errors);
        }

        return ApiResponse::success(array('relogin' => true), lang('user_password_success'));
    }

    /**
     * 退出登录（`route=user/logout`）：吊销当前设备的 API 会话 token。
     *
     * @param Request $request
     * @return Response
     */
    public function logout(Request $request)
    {
        $this->apiTokenService->revoke((string) $request->bearerToken());
        return ApiResponse::success(array());
    }

    /**
     * 第三方账号管理（`route=user/sns`）
     *
     * @param Request $request
     * @return Response
     */
    public function sns(Request $request)
    {
        $userId = (int) auth('api')->id();
        $payload = array();

        $remove = $request->slug('remove');
        if ($remove) {
            if ($this->registrationService->removeSnsBinding($userId, $remove)) {
                $payload['remove'] = true;
            }
        }
        $payload['title'] = lang('user_sns');
        $payload['plugin_list'] = $this->userCenterPresenter->buildConnectPluginList($userId);

        return ApiResponse::success($payload);
    }

    /**
     * 第三方账号快捷登录引导（`route=user/sns_link`）
     *
     * @return Response
     */
    public function snsLink()
    {
        return ApiResponse::success(array(
            'title' => lang('user_sns'),
        ));
    }

    /**
     * 国家/省/市/区数据接口（`route=user/area`）
     *
     * @param Request $request
     * @return Response
     */
    public function area(Request $request)
    {
        $area = Module::make('area');
        if (!$area) {
            return ApiResponse::success(array());
        }

        $payload = array();
        $type = $request->enum('type', array('init', 'root', 'country', 'province', 'city', 'district'), 'province');
        $current = $request->plainText('current');

        if ($type === 'init') {
            $roots = $area->getRoots();
            $payload['contact'] = $area->getContact($request->integer('id', 0), (int) auth('api')->id());
            $payload['roots'] = $this->areaRootItems($roots);
            $payload['multiple'] = count($roots) > 1;

            return ApiResponse::success($payload);
        }

        if ($type === 'root' || $type === 'country') {
            $roots = $area->getRoots();
            $list = $this->areaRootItems($roots);
            foreach ($list as $index => $item) {
                $list[$index]['cur'] = $item['name'] == $current;
            }
            $payload['area_list'] = $list;
            $payload['multiple'] = count($roots) > 1;

            return ApiResponse::success($payload);
        }

        $parentId = $request->integer('parent_id', 0);
        if ($parentId <= 0) {
            $parent = $request->plainText('parent');
            $parentId = $parent === '' ? 0 : $area->findIdByName($parent);
        }
        // 单国家场景下省级的上级就是唯一顶层节点
        if ($parentId <= 0 && $type === 'province') {
            $parentId = $area->getSingleRootId();
        }

        $payload['area_list'] = $area->getAreaList($parentId, $current);

        return ApiResponse::success($payload);
    }

    /**
     * 顶层节点转为接口输出项。
     *
     * @param array $roots
     * @return array
     */
    protected function areaRootItems($roots)
    {
        $list = array();
        foreach ((array) $roots as $root) {
            $list[] = array(
                'id' => (int) $root['id'],
                'name' => $root['name'],
                'cur' => false,
            );
        }

        return $list;
    }

    /**
     * 文件上传（`route=user/filebox`）
     *
     * @param Request $request
     * @return Response
     */
    public function filebox(Request $request)
    {
        $payload = array();
        $module = $request->alpha('module');
        $folder = $request->alpha('folder', 'no');
        $folderPath = ($folder === 'yes') ? date('Ymd') . '/' : '';

        $disk = Storage::build('images/' . $module . '/' . $folderPath);

        // item_id 必须按字符串处理：长 SN（aftersale/order_sn 等 20 位数字）经 integer() 会溢出为 PHP_INT_MAX。
        $itemIdRaw = (string) $request->input('item_id', '');
        $itemId = ($itemIdRaw !== '' && Check::number($itemIdRaw) && strlen($itemIdRaw) <= 20) ? $itemIdRaw : '';
        $type = $request->alpha('type');
        $imgWidth = $request->digits('img_width', Config::get('site.img_width', 0));
        $field = 'boxfield';

        // 创建场景由表单 draft_token 驱动（业务主键尚未生成）；编辑场景带真实 item_id 直接写 owned。
        $draftToken = (string) $request->post('draft_token', '');
        $userId = (int) auth('api')->id();
        $useDraft = ($draftToken !== '' && $itemId === '');

        if ($itemId === '' && !$useDraft) {
            throw new DomainException(lang('file_draft_invalid'));
        }
        if (!$useDraft) {
            // 编辑场景直写 owned 前必须证明业务主键归属当前会员（product 按工作台权限）
            $fileService = new \Dou\Front\Service\File\FileService();
            $fileService->assertOwnedItem($module, $itemId, $userId);
        }

        if ($type === 'content') {
            $total = count($_FILES[$field]['name']);
            $newFiles = array();
            for ($i = 0; $i < $total; $i++) {
                $newFiles[$field . $i] = array(
                    'name' => $_FILES[$field]['name'][$i],
                    'type' => $_FILES[$field]['type'][$i],
                    'tmp_name' => $_FILES[$field]['tmp_name'][$i],
                    'error' => $_FILES[$field]['error'][$i],
                    'size' => $_FILES[$field]['size'][$i],
                );
            }
            $html = '';
            foreach ($newFiles as $fname => $value) {
                $customFilename = $itemId . '_' . $type . '_' . Str::randomByType('number', 6, time());
                $opts = AttachmentUploadOptions::create()->withBasename($customFilename)->withImageWidth($imgWidth)->withWatermark(Config::get('site.watermark', false))->withBusinessField($fname);
                if ($useDraft) {
                    $image = attachment()->storeDraft($module, UploadedFile::fromGlobals($fname, $newFiles), 'user', $userId, $draftToken, $type, $opts);
                } else {
                    $image = attachment()->store($module, $itemId, UploadedFile::fromGlobals($fname, $newFiles), $type, $opts->withUploader('user', $userId));
                }
                $html .= '<img src="' . attachment()->url($image) . '" data-file="' . $image . '" /><br/><br/>';
            }
            $payload['html'] = $html;
        } elseif ($useDraft) {
            $customFilename = $type . '_' . Str::randomByType('number', 6, time());
            attachment()->storeDraft($module, UploadedFile::fromGlobals($field), 'user', $userId, $draftToken, $type, AttachmentUploadOptions::create()->withBasename($customFilename)->withImageWidth($imgWidth)->withWatermark(Config::get('site.watermark', false))->withBusinessField($field));
            $payload['img_list'] = attachment()->galleryByDraft($module, $draftToken, 'user', $userId, $type, true);
        } else {
            $fileNumber = DB::table('file')->where('module', $module)->where('item_id', $itemId)->where('type', $type)->where('status', 'owned')->count();
            if ($fileNumber < 3) {
                $customFilename = $itemId . '_' . $type . '_' . Str::randomByType('number', 6, time());
                attachment()->store($module, $itemId, UploadedFile::fromGlobals($field), $type, AttachmentUploadOptions::create()->withBasename($customFilename)->withImageWidth($imgWidth)->withWatermark(Config::get('site.watermark', false))->withBusinessField($field)->withUploader('user', $userId));
            }
            $payload['img_list'] = attachment()->gallery($module, $itemId, $type, true);
        }

        return ApiResponse::success($payload);
    }

    /**
     * 删除文件（`route=user/filedel`）
     *
     * @param Request $request
     * @return Response
     */
    public function filedel(Request $request)
    {
        $numberRaw = (string) $request->input('number', '');
        $number = preg_match('/^[a-z0-9.]+$/', $numberRaw) ? $numberRaw : '';
        $fileInfo = DB::table('file')
            ->field('module, item_id, type, status, draft_token, uploader_type, uploader_id')
            ->where('number', $number)
            ->find();

        $userId = (int) auth('api')->id();
        if (!is_array($fileInfo)) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('file_not_exist'), array(), 404);
        }
        if ((string) $fileInfo['uploader_type'] !== 'user' || (int) $fileInfo['uploader_id'] !== $userId) {
            return ApiResponse::error(ApiCodes::FORBIDDEN, lang('file_no_permission'), array(), 403);
        }

        $module = (string) $fileInfo['module'];
        $itemId = (string) $fileInfo['item_id'];
        $type = (string) $fileInfo['type'];
        if ((string) $fileInfo['status'] !== 'draft') {
            $fileService = new \Dou\Front\Service\File\FileService();
            $fileService->assertOwnedItem($module, $itemId, $userId);
        }

        attachment()->delete($number);

        $imgList = array();
        if (!empty($fileInfo['draft_token']) && (string) $fileInfo['status'] === 'draft') {
            $imgList = attachment()->galleryByDraft($module, (string) $fileInfo['draft_token'], 'user', $userId, $type, true);
        } else {
            $imgList = attachment()->gallery($module, $itemId, $type, true);
        }

        return ApiResponse::success(array(
            'img_list' => $imgList,
        ));
    }

    /**
     * 校验客户端回传的验证码签名 token，返回服务端可信载荷。
     *
     * @param Request $request
     * @param string $expectedType sms|email
     * @param string $expectedAccount 请求提交的手机号/邮箱
     * @return array|null
     */
    private function verifiedVerificationPayload(Request $request, $expectedType, $expectedAccount)
    {
        $raw = $request->input('verification_data', '');
        if (is_string($raw)) {
            $data = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $data = $raw;
        } else {
            $data = null;
        }

        if (!is_array($data) || empty($data['sig'])) {
            return null;
        }

        $payload = (new VerificationTokenService())->verify((string) $data['sig']);
        if (!is_array($payload)) {
            return null;
        }

        $account = isset($payload['account']) ? (string) $payload['account'] : '';
        $code = isset($payload['code']) ? (string) $payload['code'] : '';
        $type = isset($payload['type']) ? (string) $payload['type'] : '';
        if ($account === '' || $code === '' || $type !== (string) $expectedType) {
            return null;
        }
        if ((string) $expectedAccount !== '' && $account !== (string) $expectedAccount) {
            return null;
        }

        return $payload;
    }

    private function consumeVerificationCode(Request $request, $account, $type, $purpose)
    {
        $data = $request->input('verification_data', '');
        $data = is_string($data) ? json_decode($data, true) : $data;
        return is_array($data) && isset($data['sig'])
            && (new VerificationTokenService())->consumeCode((string) $data['sig'], $account, $type, $purpose,
                (string) $request->post('verification', ''));
    }

    // -------------------------------------------------------------------------
    // API端特有的
    // -------------------------------------------------------------------------

    /**
     * 小程序登录态检查（`route=user/check_login_state`）
     *
     * @return Response
     */
    public function checkLoginState(Request $request)
    {
        $token = (string) $request->bearerToken();
        $state = (auth('api') && method_exists(auth('api'), 'checkLoginState'))
            ? auth('api')->checkLoginState($token)
            : array('login_state' => 'fail');

        return ApiResponse::success(is_array($state) ? $state : array());
    }

    /**
     * 上传头像（`route=user/upload_avatar`）
     *
     * @return Response
     */
    public function uploadAvatar()
    {
        $userId = (int) auth('api')->id();
        $payload = array();

        if (!empty($_FILES['avatar']['name'])) {
            $fileName = FileHelper::filename(DB::table('user')->where('id', $userId)->value('avatar'));
            $avatarFilename = $fileName ? $fileName : user()->createAvatarFilename();
            $avatar = attachment()->store('user', $userId, UploadedFile::fromGlobals('avatar'), 'main', AttachmentUploadOptions::create()->withBasename($avatarFilename)->withImageWidth(200)->withPrimaryKey('id')->withBusinessField('avatar')->withUploader('user', $userId));
            DB::table('user')->where('id', $userId)->data(array('avatar' => $avatar))->update();
            $payload['file_url'] = attachment()->url($avatar);
        }

        return ApiResponse::success($payload);
    }
}
