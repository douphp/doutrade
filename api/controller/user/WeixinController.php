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
use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\User\ApiTokenService;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Service\User\UserLogDetail;
use Dou\Core\Service\User\UserService;
use Dou\Core\Service\User\VerificationTokenService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Client;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序（API）微信能力子控制器。
 *
 * 仅用于 `api` 端：`jscode2session`、手机号解密等。PC 端微信登录走独立插件与 OAuth 流程，
 * 与本类无关，勿将两端混用或抽成同一套「通用微信登录」。
 */
class WeixinController extends BaseController
{
    /** @var UserAuthService */
    private $userAuthService;

    /** @var UserService */
    private $userService;

    /** @var ApiTokenService */
    private $apiTokenService;

    /** @var UserContactQuery */
    private $contactQuery;

    /**
     * @param UserAuthService $userAuthService
     * @param UserService $userService
     * @param ApiTokenService $apiTokenService
     * @param UserContactQuery $contactQuery
     */
    public function __construct(UserAuthService $userAuthService, UserService $userService, ApiTokenService $apiTokenService, UserContactQuery $contactQuery)
    {
        $this->userAuthService = $userAuthService;
        $this->userService = $userService;
        $this->apiTokenService = $apiTokenService;
        $this->contactQuery = $contactQuery;
    }

    /**
     * 微信小程序获取手机号（服务端解密），仅 API 使用。
     *
     * @param Request $request
     * @return Response
     */
    public function getPhone(Request $request)
    {
        $appid = Config::get('param.miniprogram_appid', '');
        $appsecret = Config::get('param.miniprogram_appsecret', '');
        $code = $request->input('code');

        $tokenUrl = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=$appid&secret=$appsecret";
        $tokenData = json_decode(file_get_contents($tokenUrl, true), true);
        $accessToken = isset($tokenData['access_token']) ? $tokenData['access_token'] : '';

        $callback = json_decode(Client::post("https://api.weixin.qq.com/wxa/business/getuserphonenumber?access_token=$accessToken", array('code' => $code)), true);
        if (!is_array($callback)) {
            $callback = array();
        }

        $phone = isset($callback['phone_info']['phoneNumber']) ? (string) $callback['phone_info']['phoneNumber'] : '';
        $phoneToken = '';
        if ($phone !== '') {
            $phoneToken = (new VerificationTokenService())->issue(array(
                'phone' => $phone,
                'type' => 'weixin_phone',
            ), 300);
        }

        return ApiResponse::success(array(
            'phone_token' => $phoneToken,
        ));
    }

    /**
     * 微信小程序登录：code 换 session，绑定 `user_sns`（group=wxlogin, apptype=api）。
     * 与 PC 端插件微信登录不是同一路径。
     *
     * @param Request $request
     * @return Response
     */
    public function login(Request $request)
    {
        $group = 'wxlogin';
        $apptype = 'api';
        $nobind = true;
        $loginidMode = (Config::get('param.loginid_mode') === 'unionid') ? 'unionid' : 'openid';
        $act = $request->rec('act', 'default');

        if ($this->userAuthService->ipRateLimit((string) $request->ip())) {
            throw new DomainException(lang('login_ip_limit') ?: '操作过于频繁，请稍后再试');
        }

        $appid = Config::get('param.miniprogram_appid', '');
        $appsecret = Config::get('param.miniprogram_appsecret', '');
        if (empty($request->input('code'))) {
            throw new DomainException('Code获取失败');
        }

        $code = $request->input('code');
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid=" . $appid . "&secret=" . $appsecret . "&js_code=" . $code . "&grant_type=authorization_code";
        $weixinInfo = Client::get($url);
        $weixinInfo = json_decode($weixinInfo, true);

        if (empty($weixinInfo) || empty($weixinInfo['openid']) || empty($weixinInfo['session_key'])) {
            audit()->writeUserLog(0, UserLogAction::LOGIN_FAIL, 0, UserLogDetail::WX_CODE_INVALID);
            throw new DomainException('请求微信接口失败,appid或私钥不匹配！');
        }

        $openid = $weixinInfo['openid'];
        $unionid = isset($weixinInfo['unionid']) ? $weixinInfo['unionid'] : '';

        if (!$unionid && $loginidMode === 'unionid') {
            audit()->writeUserLog(0, UserLogAction::LOGIN_FAIL, 0, UserLogDetail::WX_UNIONID_MISSING);
            throw new DomainException('无法获取"unionid"，请联系管理员');
        }

        // 只信 getPhone 服务端签发的短时 phone_token；裸 phone 参数一律忽略
        $phoneToken = (string) $request->input('phone_token', '');
        $phone = null;
        if ($phoneToken !== '') {
            $phonePayload = (new VerificationTokenService())->verify($phoneToken);
            if (is_array($phonePayload)
                && isset($phonePayload['type']) && (string) $phonePayload['type'] === 'weixin_phone'
                && isset($phonePayload['phone']) && Check::number($phonePayload['phone'])
                && (new VerificationTokenService())->consume($phoneToken, 'weixin_phone')
            ) {
                $phone = Str::nullIfEmpty($phonePayload['phone']);
            }
        }
        $sns = array('openid' => $openid, 'unionid' => $unionid, 'nickname' => '微信用户');

        $wxUserId = DB::table('user_sns')->where('group', $group)->where('apptype', $apptype)->where('openid', $openid)->value('user_id');
        $user = DB::table('user')->where('id', $wxUserId)->find();

        if ($user) {
            $this->assertLoginAllowed($user);

            if ($user['mobile'] && $act === 'preload') {
                return ApiResponse::success(array(
                    'phone_exist' => 'yes',
                ));
            }

            DB::beginTransaction();
            try {
                $payload = array();
                $payload['user']['user_id'] = $user['id'];
                $payload['user']['token'] = $this->apiTokenService->issue((int) $user['id'], (string) $request->ip());
                $payload['dou']['auth']['is_work'] = $this->userService->isWork((int) $user['id']);

                $loginCount = $user['login_count'] + 1;
                DB::table('user')->where('id', $wxUserId)->data(array(
                    'login_count' => $loginCount,
                ))->update();

                if (!$user['mobile'] && $phone) {
                    DB::table('user')->where('id', $wxUserId)->data(array('mobile' => $phone))->update();
                    $this->contactQuery->upsertDefaultContact((int) $wxUserId, array('phone' => (string) $phone));
                }

                DB::commit();
                $this->userAuthService->loginSuccess($user['id']);
                audit()->writeUserLog($user['id'], UserLogAction::LOGIN_SUCCESS, 1, UserLogDetail::WX_LOGIN);
                return ApiResponse::success($payload);
            } catch (\Dou\Core\Web\Http\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                DB::rollback();
                audit()->writeUserLog($user['id'], UserLogAction::LOGIN_FAIL, 0, UserLogDetail::WX_LOGIN_EXCEPTION);
                throw new DomainException('登录失败，请重试');
            }
        }

        if ($act === 'preload') {
            return ApiResponse::success(array('phone_exist' => 'no'));
        }

        if ($loginidMode === 'unionid') {
            $unionUserId = DB::table('user_sns')->where('group', $group)->where('unionid', $unionid)->value('user_id');
            $unionUser = DB::table('user')->where('id', $unionUserId)->find();
        } else {
            $unionUser = null;
            $unionUserId = null;
        }

        if ($unionUser) {
            $this->assertLoginAllowed($unionUser);

            DB::beginTransaction();
            try {
                $payload = array();
                $addTime = date('Y-m-d H:i:s');
                if (DB::table('user_sns')->data(array(
                    'user_id' => $unionUserId,
                    'group' => $group,
                    'openid' => $sns['openid'],
                    'unionid' => $sns['unionid'],
                    'apptype' => $apptype,
                    'created_at' => $addTime,
                ))->insert()) {
                    $payload['user']['user_id'] = $unionUser['id'];
                    $payload['user']['token'] = $this->apiTokenService->issue((int) $unionUser['id'], (string) $request->ip());
                    $payload['dou']['auth']['is_work'] = $this->userService->isWork((int) $unionUser['id']);

                    $loginCount = $unionUser['login_count'] + 1;
                    DB::table('user')->where('id', $unionUserId)->data(array(
                        'login_count' => $loginCount,
                    ))->update();

                    if (!$unionUser['mobile'] && $phone) {
                        DB::table('user')->where('id', $unionUserId)->data(array('mobile' => $phone))->update();
                        $this->contactQuery->upsertDefaultContact((int) $unionUserId, array('phone' => (string) $phone));
                    }

                    DB::commit();
                    $this->userAuthService->loginSuccess($unionUser['id']);
                    audit()->writeUserLog($unionUser['id'], UserLogAction::LOGIN_SUCCESS, 1, UserLogDetail::WX_LOGIN_UNIONID);
                }
                return ApiResponse::success($payload);
            } catch (\Dou\Core\Web\Http\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                DB::rollback();
                audit()->writeUserLog($unionUser['id'], UserLogAction::LOGIN_FAIL, 0, UserLogDetail::WX_BIND_UNIONID_EXCEPTION);
                throw new DomainException('绑定微信失败，请重试');
            }
        }

        // 仅当客户端确实带上了有效手机号时，才尝试按手机号关联到既有账号；
        // 否则会因 `where('mobile', null)` 被 parseWhere 改写为 `WHERE mobile IS NULL`，
        // 命中历史上以 NULL 手机号注册的第一条用户，把不同 openid 全绑到同一个 user_id。
        $phoneUser = null;
        $phoneUserId = null;
        if ($phone !== null && $phone !== '') {
            $phoneUserId = DB::table('user')->where('mobile', $phone)->value('id');
            if ($phoneUserId) {
                $phoneUser = DB::table('user')->where('id', $phoneUserId)->find();
            }
        }

        if ($phoneUser) {
            $this->assertLoginAllowed($phoneUser);

            DB::beginTransaction();
            try {
                $payload = array();
                $addTime = date('Y-m-d H:i:s');
                if (DB::table('user_sns')->data(array(
                    'user_id' => $phoneUserId,
                    'group' => $group,
                    'openid' => $sns['openid'],
                    'unionid' => $sns['unionid'],
                    'apptype' => $apptype,
                    'created_at' => $addTime,
                ))->insert()) {
                    $payload['user']['user_id'] = $phoneUser['id'];
                    $payload['user']['token'] = $this->apiTokenService->issue((int) $phoneUser['id'], (string) $request->ip());
                    $payload['dou']['auth']['is_work'] = $this->userService->isWork((int) $phoneUser['id']);

                    $loginCount = $phoneUser['login_count'] + 1;
                    DB::table('user')->where('id', $phoneUserId)->data(array(
                        'login_count' => $loginCount,
                    ))->update();

                    DB::commit();
                    $this->userAuthService->loginSuccess($phoneUser['id']);
                    audit()->writeUserLog($phoneUser['id'], UserLogAction::LOGIN_SUCCESS, 1, UserLogDetail::WX_LOGIN_PHONE);
                }
                return ApiResponse::success($payload);
            } catch (\Dou\Core\Web\Http\HttpResponseException $e) {
                throw $e;
            } catch (\Exception $e) {
                DB::rollback();
                audit()->writeUserLog($phoneUser['id'], UserLogAction::LOGIN_FAIL, 0, UserLogDetail::WX_BIND_PHONE_EXCEPTION);
                throw new DomainException('绑定微信失败，请重试');
            }
        }

        if ($nobind) {
            DB::beginTransaction();
            try {
                $userSn = user() ? user()->randUserSn() : '';
                $password = \Dou\Core\Service\User\UserPasswordService::createGeneratedPassword();
                $addTime = date('Y-m-d H:i:s');
                $email = $userSn . '@weixin.login';

                // 推广分销：扫码进入 login_weixin 自动建号时，
                // 写入由小程序透传的 promotion_user_sn 对应的 direct_user_id / indirect_user_id
                $lineage = user()
                    ? user()->resolvePromotionLineage($request->input('promotion_user_sn'))
                    : array('direct_user_id' => 0, 'indirect_user_id' => 0);

                if (DB::table('user')->data(array(
                    'user_sn' => $userSn,
                    'mobile' => Str::nullIfEmpty($phone),
                    'email' => Str::nullIfEmpty($email),
                    'password' => $password,
                    'nickname' => $sns['nickname'],
                    'direct_user_id' => $lineage['direct_user_id'],
                    'indirect_user_id' => $lineage['indirect_user_id'],
                    'created_at' => $addTime,
                ))->insert()) {
                    $newUser = DB::table('user')->where('email', $email)->find();
                    if (is_array($newUser) && $phone !== null && (string) $phone !== '') {
                        $this->contactQuery->upsertDefaultContact((int) $newUser['id'], array('phone' => (string) $phone));
                    }
                    if (DB::table('user_sns')->data(array(
                        'user_id' => $newUser['id'],
                        'group' => $group,
                        'openid' => $sns['openid'],
                        'unionid' => $sns['unionid'],
                        'apptype' => $apptype,
                        'created_at' => $addTime,
                    ))->insert()) {
                        $loginCount = $newUser['login_count'] + 1;
                        DB::table('user')->where('id', $newUser['id'])->data(array(
                            'login_count' => $loginCount,
                        ))->update();

                        DB::commit();

                        // 登记分销关系树（直推及其上级链，无限级；返利仍硬封顶 2 级）
                        $this->userService->recordPromotionRelation((int) $newUser['id'], (int) $lineage['direct_user_id']);

                        $payload = array();
                        $payload['user']['user_id'] = $newUser['id'];
                        $payload['user']['token'] = $this->apiTokenService->issue((int) $newUser['id'], (string) $request->ip());
                        $payload['dou']['auth']['is_work'] = $this->userService->isWork((int) $newUser['id']);

                        audit()->writeUserLog($newUser['id'], UserLogAction::LOGIN_SUCCESS, 1, UserLogDetail::WX_REGISTER);
                        return ApiResponse::success($payload);
                    }

                    DB::rollback();
                    throw new DomainException('无法完成账号与小程序绑定，请联系管理员');
                }

                DB::rollback();
                throw new DomainException('无法生成账号信息，请联系管理员');
            } catch (\Dou\Core\Web\Http\HttpResponseException $e) {
                throw $e;
            } catch (DomainException $e) {
                throw $e;
            } catch (\Exception $e) {
                DB::rollback();
                throw new DomainException('注册失败，请重试');
            }
        }

        return ApiResponse::success(array('sns' => $sns));
    }

    /**
     * 微信登录状态校验：停用/注销/锁定一律拒绝签发 token。
     *
     * @param array $user
     * @return void
     * @throws DomainException
     */
    private function assertLoginAllowed(array $user)
    {
        $status = UserStatus::normalize(isset($user['status']) ? $user['status'] : '');
        if ($status === UserStatus::DEACTIVATED) {
            throw new DomainException(lang('user_login_account_deactivated', '账号已注销，无法登录'));
        }
        if ($status !== UserStatus::ACTIVE) {
            throw new DomainException(lang('user_login_account_suspended', '账号已被停用，请联系管理员'));
        }

        $lockedSeconds = $this->userAuthService->isLocked((int) $user['id']);
        if ($lockedSeconds > 0) {
            $lockedMinutes = (int) ceil($lockedSeconds / 60);
            throw new DomainException((lang('login_account_locked') ?: '账户已被锁定') . "，请 {$lockedMinutes} 分钟后重试");
        }
    }
}
