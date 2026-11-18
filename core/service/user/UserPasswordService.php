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

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员密码维护服务（用户中心修改密码）。
 *
 * 让 front / api UserController 共用；仅承担「校验原密码 → 更新新密码哈希」的领域动作，
 * 不触碰 auth / session 状态，因此放在 core 不违反「auth 只属于 shell 层」原则。
 *
 * 历史 md5 散列：若密码字段为 32 位十六进制 md5，按 md5 校验通过后即时升级为 bcrypt；
 * 新写入统一为 bcrypt。
 */
class UserPasswordService extends BaseService
{
    /** 自动建号只写入不可猜测的随机密码；随机源不可用时拒绝建号。 */
    public static function createGeneratedPassword()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(32);
        } else {
            $strong = false;
            $bytes = function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(32, $strong) : false;
            if (!$strong || !is_string($bytes) || strlen($bytes) !== 32) {
                throw new \RuntimeException('Secure password generation failed');
            }
        }
        return password_hash(bin2hex($bytes), PASSWORD_BCRYPT);
    }

    /** 历史自动建号密码与创建时间同源，禁止继续作为密码凭据使用。 */
    public static function isLegacyGeneratedPassword(array $user, $input)
    {
        if (!preg_match('/^rand([0-9]{10,11})$/', (string) $input, $match)) {
            return false;
        }
        $created = !empty($user['created_at']) ? strtotime((string) $user['created_at']) : false;
        return $created !== false && abs((int) $match[1] - $created) <= 3;
    }

    /**
     * 验证原密码并修改为新密码。
     *
     * @param int $userId
     * @param string $oldPasswordInput
     * @param string $newPassword
     * @return array{ok:bool,user:?array,errors:array}
     */
    public function verifyAndChangePassword($userId, $oldPasswordInput, $newPassword)
    {
        $user = DB::table('user')->where('id', $userId)->find();
        $oldStored = is_array($user) ? (string) $user['password'] : '';
        $errors = array();

        if (!$user || self::isLegacyGeneratedPassword($user, $oldPasswordInput)) {
            return array('ok' => false, 'user' => null, 'errors' => array('old_password' => lang('user_old_password_cue')));
        }

        if (strlen($oldStored) == 32 && ctype_xdigit($oldStored)) {
            if (md5($oldPasswordInput) !== $oldStored) {
                $errors['old_password'] = lang('user_old_password_cue');
            }
        } else {
            if (!password_verify($oldPasswordInput, $oldStored)) {
                $errors['old_password'] = lang('user_old_password_cue');
            }
        }

        if ($errors) {
            return array('ok' => false, 'user' => null, 'errors' => $errors);
        }

        DB::table('user')->where('id', $userId)->data(array(
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
        ))->update();

        // 改密后吊销该会员全部会话凭证：Web remember-me + API token（均不随密码派生）
        (new UserAuthService())->revokeAllSessions((int) $userId);

        $user = DB::table('user')->where('id', $userId)->find();
        audit()->writeUserLog($userId, UserLogAction::PASSWORD_CHANGE, 1);

        return array('ok' => true, 'user' => $user, 'errors' => array());
    }
}
