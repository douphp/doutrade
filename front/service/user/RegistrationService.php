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
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 注册流程业务服务（front/api 共用）。
 *
 * 负责注册参数校验、入库数据组装、新会员事务创建、SNS 账号绑定 / 解绑、
 * 协议单页就位、API 端验证码 token 生成等；不触碰 Smarty 与 Session，
 * 由 Controller 负责会话与跳转。
 */
class RegistrationService extends BaseService
{
    /** @var UserContactQuery */
    private $contactQuery;

    /**
     * @param UserContactQuery $contactQuery
     */
    public function __construct(UserContactQuery $contactQuery)
    {
        $this->contactQuery = $contactQuery;
    }

    /**
     * 确保存在注册流程所需的「用户协议」单页（page.slug = agreement）。
     *
     * @return bool 是否新插入了 agreement 单页（已存在则返回 false）
     */
    public function ensureAgreementPageExists()
    {
        if (DB::table('page')->where('slug', 'agreement')->exists()) {
            return false;
        }
        DB::table('page')->data(array('slug' => 'agreement', 'name' => '用户协议'))->insert();

        return true;
    }

    /**
     * 生成 API/小程序端注册验证码下发需要的 token 对（无 session）。
     * 表单 token（captcha_token）随 UI 下发，哈希 token（storage_captcha_token）由客户端缓存，
     * 下一次调用验证码下发接口时回传，服务端通过 md5(token . DOU_SHELL) 比对。
     *
     * @return array { captcha_token, storage_captcha_token }
     */
    public function createApiVerificationToken()
    {
        $token = strtoupper(Str::randomByType('letter.number', 8));

        return array(
            'captcha_token' => $token,
            'storage_captcha_token' => md5($token . DOU_SHELL),
        );
    }

    /**
     * 组装注册入库数据（front/api 共用）。
     *
     * @param string $field email|mobile
     * @param string $account
     * @param string $password
     * @param int $directUserId
     * @param int $indirectUserId
     * @param string $phone
     * @return array
     */
    public function buildRegisterInsertData($field, $account, $password, $directUserId = 0, $indirectUserId = 0, $phone = '')
    {
        $resolvedPhone = (string) $phone;
        if ($field !== 'email' && $resolvedPhone === '') {
            $resolvedPhone = (string) $account;
        }
        $insertData = array(
            'user_sn' => user()->randUserSn(),
            'direct_user_id' => (int) $directUserId,
            'indirect_user_id' => (int) $indirectUserId,
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'created_at' => date('Y-m-d H:i:s'),
            'email' => null,
            'mobile' => null,
            // 仅在事务里作为「默认收货行 phone」的临时入参；createUser 会拆出写到 dou_user_contact
            '_default_contact_phone' => $resolvedPhone,
        );

        if ($field === 'email') {
            $insertData['email'] = Str::nullIfEmpty($account);
        } else {
            $insertData['mobile'] = Str::nullIfEmpty($account);
        }

        return $insertData;
    }

    /**
     * 创建新会员账号（含事务）。
     *
     * @param array $insertData
     * @param string $field 'email' 或 'mobile'
     * @param array|null $snsData SNS 账号信息（来自 Session），null 表示无绑定
     * @return array ok, user, error
     */
    public function createUser(array $insertData, $field, $snsData = null)
    {
        $field = (string) $field;
        if (!in_array($field, array('email', 'mobile'), true)) {
            return array('ok' => false, 'user' => null, 'error' => lang('user_insert_fail'));
        }
        if (array_key_exists('email', $insertData)) {
            $insertData['email'] = Str::nullIfEmpty($insertData['email']);
        }
        if (array_key_exists('mobile', $insertData)) {
            $insertData['mobile'] = Str::nullIfEmpty($insertData['mobile']);
        }
        $account = isset($insertData[$field]) ? trim((string) $insertData[$field]) : '';
        if ($account === '') {
            return array('ok' => false, 'user' => null, 'error' => lang('user_insert_fail'));
        }
        $contactPhone = isset($insertData['_default_contact_phone']) ? (string) $insertData['_default_contact_phone'] : '';
        unset($insertData['_default_contact_phone']);

        DB::beginTransaction();
        try {
            // 必须用 insert 返回的主键取新用户：唯一冲突/空账号时回查业务字段可能命中既有用户
            $insertId = (int) DB::table('user')->data($insertData)->insert();
            if ($insertId <= 0) {
                throw new \RuntimeException('User insert failed: empty insert id');
            }
            $user = DB::table('user')->where('id', $insertId)->find();
            if (!is_array($user) || empty($user['id'])) {
                throw new \RuntimeException('User reload failed: ' . $insertId);
            }

            if (is_array($snsData)) {
                $snsInserted = DB::table('user_sns')->data(array(
                    'user_id' => $user['id'],
                    'group' => $snsData['group'],
                    'openid' => $snsData['openid'],
                    'unionid' => $snsData['unionid'],
                    'apptype' => $snsData['apptype'],
                    'created_at' => $insertData['created_at'],
                ))->insert();
                if (!$snsInserted) {
                    throw new \RuntimeException('SNS bind failed for user ' . $user['id']);
                }
                DB::table('user')->where('id', $user['id'])->data(array(
                    'nickname' => $snsData['nickname'],
                    'avatar' => $snsData['avatar'],
                    'sex' => $snsData['sex'],
                ))->update();
            }

            if ($contactPhone !== '') {
                $this->contactQuery->upsertDefaultContact((int) $user['id'], array('phone' => $contactPhone));
            }

            DB::commit();
            return array('ok' => true, 'user' => $user, 'error' => '');
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('User create failed: ' . $e->getMessage(), array(
                'channel' => 'user',
                'module' => 'user',
                'payload' => array(
                    'field' => (string) $field,
                    'email' => isset($insertData['email']) ? (string) $insertData['email'] : '',
                    'mobile' => isset($insertData['mobile']) ? (string) $insertData['mobile'] : '',
                    'has_sns' => is_array($snsData),
                ),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ));

            return array('ok' => false, 'user' => null, 'error' => lang('user_insert_fail'));
        }
    }

    /**
     * 绑定第三方 SNS 账号（登录后关联）。
     *
     * @param int $userId
     * @param array $sns
     * @param string $addTime datetime 字符串（Y-m-d H:i:s）
     * @return void
     */
    public function bindSns($userId, array $sns, $addTime)
    {
        DB::table('user_sns')->data(array(
            'user_id' => $userId,
            'group' => $sns['group'],
            'openid' => $sns['openid'],
            'unionid' => $sns['unionid'],
            'apptype' => $sns['apptype'],
            'created_at' => $addTime,
        ))->insert();
    }

    /**
     * 解绑用户 SNS 账号（按分组）。
     *
     * @param int $userId
     * @param string $group
     * @return bool
     */
    public function removeSnsBinding($userId, $group)
    {
        if ($group === '') {
            return false;
        }
        return DB::table('user_sns')
            ->where('group', $group)
            ->where('user_id', (int) $userId)
            ->delete() ? true : false;
    }
}
