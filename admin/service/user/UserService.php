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

namespace Dou\Admin\Service\User;

use Dou\Admin\Model\User\User;
use Dou\Core\Domain\User\UserStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserAuthService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Service\User\UserLogQuery;
use Dou\Core\Service\User\UserMembershipQuery;
use Dou\Core\Service\User\UserStatsService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;
use Dou\Core\Web\Http\UploadedFile;
use Dou\Vendor\Excel\Excel;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员业务服务（route=user/...）。
 *
 * `insert` / `update` / `delete` 与控制器同名；
 * 列表 / 编辑 / 默认表单数据用 `build*Data`；
 * 输入格式与唯一性由 UserFormRequest 承担；业务阻断在此抛 DomainException。
 */
class UserService extends BaseService
{
    /** @var UserStatsService */
    private $userStats;

    /** @var UserLogQuery */
    private $userLogQuery;

    /** @var UserMembershipQuery */
    private $membershipQuery;

    /** @var UserContactQuery */
    private $contactQuery;

    /**
     * @param UserStatsService $userStats
     * @param UserLogQuery $userLogQuery
     * @param UserMembershipQuery $membershipQuery
     * @param UserContactQuery $contactQuery
     */
    public function __construct(UserStatsService $userStats, UserLogQuery $userLogQuery, UserMembershipQuery $membershipQuery, UserContactQuery $contactQuery)
    {
        $this->userStats = $userStats;
        $this->userLogQuery = $userLogQuery;
        $this->membershipQuery = $membershipQuery;
        $this->contactQuery = $contactQuery;
    }

    /**
     * 后台会员列表。
     *
     * @param string $username 会员关键字（手机/邮箱/user_sn/联系人，Core\UserService::findUserId）；空则不过滤
     * @param int $page
     * @param array $rejectFilter 已输入但未通过校验时为 true（`username` 等），统一按无结果处理
     * @return array user_list, pager, filter_username
     */
    public function buildUserListData($username = '', $page = 1, array $rejectFilter = array(), $statusFilter = '')
    {
        $username = (string) $username;
        $page = (int) $page > 0 ? (int) $page : 1;
        $statusFilter = UserStatus::isValid($statusFilter) ? (string) $statusFilter : '';

        $userIdFilter = 0;
        $forceEmpty = !empty($rejectFilter['username']);
        if (!$forceEmpty && $username !== '') {
            $resolvedUserId = user() !== null ? user()->findUserId($username) : null;
            if ($resolvedUserId) {
                $userIdFilter = (int) $resolvedUserId;
            } else {
                $forceEmpty = true;
            }
        }
        if ($forceEmpty) {
            $userIdFilter = -1;
        }

        // 分页 URL 必须带筛选参数，否则第 2 页会退回未过滤全量列表
        $pageUrl = route('admin.user');
        $glue = strpos($pageUrl, '?') !== false ? '&' : '?';
        if ($username !== '') {
            $pageUrl .= $glue . 'username=' . rawurlencode($username);
            $glue = '&';
        }
        if ($statusFilter !== '') {
            $pageUrl .= $glue . 'status=' . rawurlencode($statusFilter);
        }

        $result = User::withLevelIfAvailable()
            ->filterByUserId($userIdFilter)
            ->filterByStatus($statusFilter)
            ->applyDefaultOrder()
            ->paginate(15, $page, $pageUrl);
        $models = $result['list'];

        $userIds = array();
        foreach ($models as $model) {
            $userIds[] = (int) $model->getRawAttribute('id');
        }

        $lastLoginMap = $this->userLogQuery->latestLoginMap($userIds);
        $pointMap = $this->userStats->pointMap($userIds);
        $moneyMap = $this->userStats->moneyMap($userIds);
        $consumptionMap = $this->userStats->consumptionMap($userIds);
        $promoteMap = $this->userStats->promoteMap($userIds);
        $vipMap = $this->membershipQuery->vipMap($userIds);
        $levelFeature = Config::get('features.user', false) && DB::table('user_level')->exists();

        $userList = array();
        foreach ($models as $model) {
            $row = $model->toArray();
            $userId = (int) $row['id'];
            $last = isset($lastLoginMap[$userId]) ? $lastLoginMap[$userId] : null;
            $lastLogin = $last ? date('Y-m-d H:i:s', strtotime($last['created_at'])) : '-';

            $levelName = '';
            if ($levelFeature) {
                $relName = isset($row['level']['name']) ? $row['level']['name'] : '';
                $levelName = $relName !== '' ? $relName : lang('user_level_no');
            }

            $contactSnap = $this->contactQuery->defaultContactSnapshot($userId);
            $statusRaw = UserStatus::normalize(isset($row['status']) ? $row['status'] : '');
            $userList[] = array(
                'id' => $userId,
                'user_id' => $userId,
                'status' => $statusRaw,
                'status_format' => lang('user_status_' . $statusRaw),
                'level_name' => $levelName,
                'vip' => $this->buildAdminVipListCells(isset($vipMap[$userId]) ? $vipMap[$userId] : array()),
                'user' => (user() !== null ? user()->format(array_merge($model->getAttributes(), $contactSnap)) : false),
                'mobile' => $row['mobile'],
                'email' => $row['email'],
                'user_sn' => $row['user_sn'],
                'contact' => $contactSnap['contact'],
                'phone' => $contactSnap['phone'],
                'point' => $pointMap === false ? false : (isset($pointMap[$userId]) ? $pointMap[$userId] : 0),
                'money_total' => $moneyMap === false ? false : (isset($moneyMap[$userId]) ? $moneyMap[$userId] : 0),
                'total_consumption' => isset($consumptionMap[$userId]) ? $consumptionMap[$userId] : 0,
                'total_promote' => isset($promoteMap[$userId]) ? $promoteMap[$userId] : 0,
                'sex' => $row['sex'],
                'login_count' => $row['login_count'],
                'last_login' => $lastLogin,
                'last_login_log_url' => route('admin.user.log', array('user_id' => $userId)),
                'level_log_url' => route('admin.user.level_log', array('user_id' => $userId)),
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'user_list' => $userList,
            'pager' => $result['pager'],
            'filter_username' => $username,
            'force_empty' => $forceEmpty,
            'has_user_level_data' => $levelFeature,
            'status_filter' => $statusFilter,
            'status_options' => $this->statusOptions(),
        );
    }

    /**
     * 会员状态选项（值 + 文案），供列表筛选下拉与编辑页状态展示复用。
     *
     * @return array
     */
    public function statusOptions()
    {
        $options = array();
        foreach (UserStatus::all() as $status) {
            $options[] = array('value' => $status, 'format' => lang('user_status_' . $status));
        }

        return $options;
    }

    /**
     * 变更会员状态（封禁 / 解禁 / 注销），按 UserStatus::canTransit 校验合法迁移，并写审计日志。
     *
     * @param int $userId
     * @param string $newStatus
     * @param int $adminId 操作管理员（审计）
     * @return void
     * @throws DomainException 非法会员 / 非法状态 / 非法迁移
     */
    public function setStatus($userId, $newStatus, $adminId = 0)
    {
        $userId = (int) $userId;
        $newStatus = (string) $newStatus;

        if ($userId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }
        if (!UserStatus::isValid($newStatus)) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $current = UserStatus::normalize(DB::table('user')->where('id', $userId)->value('status'));
        if ($current === $newStatus) {
            return;
        }
        if (!UserStatus::canTransit($current, $newStatus)) {
            throw new DomainException(lang('user_status_transit_wrong'), route('admin.user.edit', array('id' => $userId)));
        }

        User::whereKey($userId)->update(array('status' => $newStatus));

        // 停用/注销后立即吊销 Web remember-me 与 API token，已登录会话在下一次请求失效
        if ($newStatus !== UserStatus::ACTIVE) {
            (new UserAuthService())->revokeAllSessions($userId);
        }

        audit()->writeUserLog($userId, UserLogAction::SET_STATUS, 1, $current . '->' . $newStatus);
    }

    /**
     * 后台会员列表「VIP 记录」列：模板固定读取 text / start_at / end_at。
     *
     * Core `UserService::vip()` 在无记录或功能关闭时返回空数组或 false，此处统一为带键的空串，
     * 避免 PHP 8+ 与 Smarty 编译模板出现 Undefined array key。
     *
     * @param array|false $vip `Dou\Core\Service\User\UserService::vip` 返回值
     * @return array 含 text、start_at、end_at
     */
    private function buildAdminVipListCells($vip)
    {
        if (!is_array($vip) || empty($vip)) {
            return array(
                'text' => '',
                'start_at' => '',
                'end_at' => '',
            );
        }

        return array(
            'text' => isset($vip['text']) ? $vip['text'] : '',
            'start_at' => isset($vip['start_at']) ? $vip['start_at'] : '',
            'end_at' => isset($vip['end_at']) ? $vip['end_at'] : '',
        );
    }

    /**
     * 新增页默认数据。
     *
     * 须含 user.htm 表单所读键，避免 Smarty 编译模板在 PHP 8+ 访问缺键告警。
     *
     * @return array
     */
    public function buildUserDefaultData()
    {
        $user = array(
            'user_id' => '',
            'mobile' => '',
            'email' => '',
            'contact' => '',
            'first_name' => '',
            'last_name' => '',
            'country' => '',
            'province' => '',
            'phone' => '',
            'address' => '',
            'postcode' => '',
            'sex' => '1',
            'avatar' => '',
            'nickname' => '',
        );
        return $user;
    }

    /**
     * 编辑表单数据。
     *
     * @param int|string $userId
     * @return array|null
     */
    public function buildUserEditData($userId)
    {
        $userModel = User::findByUserId($userId);
        if (!$userModel) {
            return null;
        }
        $contactSnap = $this->contactQuery->defaultContactSnapshot((int) $userId);
        $user = array_merge($contactSnap, $userModel->getAttributes());
        $user['user_id'] = (int) $user['id'];
        $user['file_number'] = $user['avatar'];
        $user['avatar'] = $user['avatar'] ? attachment()->url($user['avatar']) : '';

        $statusRaw = UserStatus::normalize(isset($user['status']) ? $user['status'] : '');
        $user['status'] = $statusRaw;
        $user['status_format'] = lang('user_status_' . $statusRaw);
        $user['status_actions'] = array();
        foreach (UserStatus::all() as $target) {
            if (UserStatus::canTransit($statusRaw, $target)) {
                $user['status_actions'][] = array(
                    'value' => $target,
                    'label' => lang('user_status_action_' . $target),
                );
            }
        }

        return $user;
    }

    /**
     * 新增提交。
     *
     * 字段白名单与校验规则定义在 UserFormRequest::rules()。
     * @see \Dou\Admin\Request\User\UserFormRequest::rules()
     *
     * @param array $data validated 数据
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $mobile = isset($data['mobile']) ? trim((string) $data['mobile']) : '';
        if ($email === '' && $mobile === '') {
            throw new DomainException(lang('user_username_cue'), route('admin.user'));
        }

        $password = isset($data['password']) ? $data['password'] : '';
        $insertData = array(
            'user_sn' => user()->randUserSn(),
            'level_id' => isset($data['level_id']) ? (int) $data['level_id'] : 0,
            'distribution_level_id' => isset($data['distribution_level_id']) ? (int) $data['distribution_level_id'] : 0,
            'email' => Str::nullIfEmpty($email),
            'mobile' => Str::nullIfEmpty($mobile),
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'sex' => Arr::get($data, 'sex', ''),
            'nickname' => Arr::get($data, 'nickname', ''),
            'created_at' => date('Y-m-d H:i:s'),
        );

        $adminId = (int) auth('admin')->id();
        $avatar = attachment()->reserve('user', UploadedFile::fromGlobals('avatar'), 'admin', $adminId, 'main', AttachmentUploadOptions::create()->withImageWidth(200));
        $insertData['avatar'] = $avatar;

        $newUserId = (int) DB::transaction(function () use ($insertData, $data, $avatar, $adminId) {
            $model = User::create($insertData);
            if (!$model) {
                throw new DomainException(lang('operation_failed'));
            }
            $newUserId = (int) $model->getKey();
            if ($newUserId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }

            $this->contactQuery->upsertDefaultContact($newUserId, array(
                'contact' => Arr::get($data, 'contact', ''),
                'first_name' => Arr::get($data, 'first_name', ''),
                'last_name' => Arr::get($data, 'last_name', ''),
                'phone' => Arr::get($data, 'phone', ''),
                'country' => Arr::get($data, 'country', ''),
                'province' => Arr::get($data, 'province', ''),
                'address' => Arr::get($data, 'address', ''),
                'postcode' => Arr::get($data, 'postcode', ''),
            ));

            if ($avatar !== '' && attachment()->adopt('user', $avatar, $newUserId, 'admin', $adminId) < 1) {
                throw new DomainException(lang('file_claim_failed'));
            }

            return $newUserId;
        });

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $insertData['user_sn']);

        return $newUserId;
    }

    /**
     * 更新提交。
     *
     * 字段白名单与校验规则定义在 UserFormRequest::rules()。
     * @see \Dou\Admin\Request\User\UserFormRequest::rules()
     *
     * @param array $data validated 数据
     * @param string $avatar 头像文件号（由 Controller 上传完成后传入，空表示不更新）
     * @return void
     */
    public function update(array $data, $avatar = '')
    {
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $user = User::findByUserId($userId);
        if (!$user) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $mobile = isset($data['mobile']) ? trim((string) $data['mobile']) : '';
        if ($email === '' && $mobile === '') {
            throw new DomainException(lang('user_username_cue'), route('admin.user'));
        }

        $oldLevelId = (int) $user->getRawAttribute('level_id');
        $oldDistributionLevelId = (int) $user->getRawAttribute('distribution_level_id');

        $updateData = array(
            'email' => Str::nullIfEmpty($email),
            'mobile' => Str::nullIfEmpty($mobile),
            'sex' => Arr::get($data, 'sex', ''),
            'nickname' => Arr::get($data, 'nickname', ''),
        );

        $newLevelId = $oldLevelId;
        if (array_key_exists('level_id', $data)) {
            $newLevelId = (int) $data['level_id'];
            if ($newLevelId > 0 && (!DB::tableExist('user_level') || !DB::table('user_level')->where('id', $newLevelId)->value('id'))) {
                throw new DomainException(lang('item_not_exist'), route('admin.user.edit', array('id' => $userId)));
            }
            $updateData['level_id'] = $newLevelId;
        }

        $newDistributionLevelId = $oldDistributionLevelId;
        if (array_key_exists('distribution_level_id', $data)) {
            $newDistributionLevelId = (int) $data['distribution_level_id'];
            $updateData['distribution_level_id'] = $newDistributionLevelId;
        }

        $newPassword = isset($data['password']) ? (string) $data['password'] : '';
        $passwordChanged = false;
        if ($newPassword !== '') {
            $updateData['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
            $passwordChanged = true;
        }

        if ($avatar !== '' && $avatar !== null) {
            $updateData['avatar'] = $avatar;
        }

        $contactPayload = array();
        foreach (array('contact', 'first_name', 'last_name', 'phone', 'country', 'province', 'address', 'postcode') as $col) {
            if (array_key_exists($col, $data)) {
                $contactPayload[$col] = (string) $data[$col];
            }
        }

        DB::beginTransaction();
        try {
            $saved = $user->fill($updateData, 'update')->save();
            if (!$saved) {
                throw new DomainException(lang('operation_failed', '操作失败，请重试'));
            }

            $this->writeManualUserLevelLog($userId, $oldLevelId, $newLevelId);
            $this->writeManualDistributionLevelLog($userId, $oldDistributionLevelId, $newDistributionLevelId);

            if (!empty($contactPayload)) {
                $this->contactQuery->upsertDefaultContact($userId, $contactPayload);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        if ($passwordChanged) {
            // 后台重置密码后旧 Web remember / API token 必须立即失效
            (new UserAuthService())->revokeAllSessions($userId);
            audit()->writeUserLog($userId, UserLogAction::PASSWORD_CHANGE, 1);
        }

        $logName = isset($data['nickname']) ? (string) $data['nickname'] : '';
        if ($logName === '' && !empty($user['email'])) {
            $logName = $user['email'];
        } elseif ($logName === '' && !empty($user['user_sn'])) {
            $logName = $user['user_sn'];
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $logName);
    }

    /**
     * 删除会员。
     *
     * @param int|string $userId
     * @param array $post POST 数据（confirm）
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 参数不合法时抛出
     */
    public function delete($userId, array $post)
    {
        $userId = (int) $userId;
        if ($userId < 1) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }

        $user = User::findByUserId($userId);
        if (!$user) {
            throw new DomainException(lang('illegal'), route('admin.user'));
        }
        $email = (string) $user->getRawAttribute('email');

        if (isset($post['confirm'])) {
            DB::beginTransaction();
            try {
                $this->deleteUserWithRelations($userId, $user);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, $email);
            return array(
                'message' => lang('del_success'),
                'back_url' => route('admin.user'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $email, lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.user'),
            'timeout' => '30',
            'confirm_url' => route('admin.user.destroy', array('id' => $userId)),
        );
    }

    /**
     * 批量操作。
     *
     * 字段白名单与校验规则定义在 UserActionFormRequest::rules()。
     * @see \Dou\Admin\Request\User\UserActionFormRequest::rules()
     *
     * @param array $data validated 数据（action、checkbox）
     * @return array|null array 表示 happy-path 消息；excel/excel_all 分支由 Excel::export 直接发送二进制响应后返回 null
     * @throws DomainException 参数不合法时抛出
     */
    public function action(array $data)
    {
        $action = Arr::get($data, 'action', '');
        $checkbox = Arr::get($data, 'checkbox', array());

        if (!empty($checkbox) && is_array($checkbox)) {
            if ($action === 'del_all') {
                $ids = Check::intIds($checkbox);
                if (empty($ids)) {
                    throw new DomainException(lang('select_empty'), route('admin.user'));
                }

                $deletedCount = 0;
                DB::beginTransaction();
                try {
                    foreach ($ids as $uid) {
                        $user = User::findByUserId($uid);
                        if (!$user) {
                            continue;
                        }
                        $this->deleteUserWithRelations($uid, $user);
                        $deletedCount++;
                    }
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }

                audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:USER (' . $deletedCount . ' items)', 'user');
                return array('message' => lang('del_succes'), 'back_url' => route('admin.user'));
            }
            if ($action === 'excel') {
                Excel::export('user', $this->buildExcelUserList($checkbox));
                return null;
            }
            throw new DomainException(lang('select_empty'), route('admin.user'));
        }

        if ($action === 'excel_all') {
            Excel::export('user', $this->buildExcelUserList());
            return null;
        }

        throw new DomainException(lang('user_select_empty'), route('admin.user'));
    }

    /**
     * 删除会员及其 PII / 会话关联数据。
     *
     * 余额、冻结金额、积分为正时由 assertUserDeletable() 阻断；订单、支付、审计日志保留。
     *
     * @param int $userId
     * @param \Dou\Admin\Model\User\User $user
     * @return void
     * @throws DomainException
     */
    private function deleteUserWithRelations($userId, $user)
    {
        $userId = (int) $userId;
        $this->assertUserDeletable($userId);

        $avatar = (string) $user->getRawAttribute('avatar');
        $relations = array('user_sns', 'user_contact', 'user_token', 'user_tag', 'user_level_log');
        foreach ($relations as $table) {
            if (DB::tableExist($table)) {
                DB::table($table)->where('user_id', $userId)->delete();
            }
        }
        if (DB::tableExist('user_wallet')) {
            // user_wallet 的主键 id 即会员 ID
            DB::table('user_wallet')->where('id', $userId)->delete();
        }
        if ($avatar !== '' && Check::fileNumber($avatar)) {
            attachment()->delete($avatar);
        }

        if (!User::destroy($userId)) {
            throw new DomainException(lang('operation_failed', '操作失败，请重试'));
        }
    }

    /**
     * 删除前置校验：有余额/冻结/积分的会员不允许直接删除，避免财务数据静默丢失。
     *
     * @param int $userId
     * @return void
     * @throws DomainException
     */
    private function assertUserDeletable($userId)
    {
        $userId = (int) $userId;
        // 与钱包写入、提现申请共用会员行锁，检查和删除必须在调用方事务内完成。
        if (!DB::table('user')->where('id', $userId)->lock('FOR UPDATE')->value('id')) {
            throw new DomainException(lang('illegal'));
        }
        if (DB::tableExist('withdraw') && DB::table('withdraw')->where('user_id', $userId)
            ->whereIn('status', array('pending', 'approved'))->lock('FOR UPDATE')->find()) {
            throw new DomainException(lang('user_delete_withdraw_exists', '该会员仍有未结清提现，无法删除'));
        }
        $wallet = DB::tableExist('user_wallet')
            ? DB::table('user_wallet')->where('id', $userId)->lock('FOR UPDATE')->find() : array();
        $money = isset($wallet['money_balance']) ? (float) $wallet['money_balance'] : 0.0;
        $freeze = isset($wallet['money_freeze']) ? (float) $wallet['money_freeze'] : 0.0;
        $point = isset($wallet['point_balance']) ? (float) $wallet['point_balance'] : 0.0;
        $pointFreeze = isset($wallet['point_freeze']) ? (float) $wallet['point_freeze'] : 0.0;
        // 快照缺失或失配时也不能绕过财务保护；流水采用当前读。
        foreach (array('money', 'point') as $table) {
            if (DB::tableExist($table)) {
                $last = DB::table($table)->where('user_id', $userId)->order('id DESC')->lock('FOR UPDATE')->find();
                if ($last && (float) $last['total'] > 0) {
                    throw new DomainException(lang('user_delete_balance_exists', '该会员仍有余额、冻结金额或积分，无法删除'));
                }
            }
        }
        if ($money > 0 || $freeze > 0 || $point > 0 || $pointFreeze > 0) {
            throw new DomainException(lang('user_delete_balance_exists', '该会员仍有余额、冻结金额或积分，无法删除'));
        }
    }

    /**
     * 初始化登录相关参数项并跳转参数中心。
     *
     * @return void
     */
    public function set()
    {
        $setList = array('login_mode', 'loginid_mode');
        foreach ($setList as $name) {
            if (!DB::table('parameter')->where('name', $name)->value('id')) {
                DB::table('parameter')->data(array(
                    'name' => $name,
                    'lang' => lang('user_' . $name . '_lang'),
                    'value' => lang('user_' . $name . '_value'),
                    'cue' => lang('user_' . $name . '_cue'),
                    'group' => 'user',
                ))->insert();
            }
        }
    }

    /**
     * Excel 导出用列表（head + list）。
     *
     * 会员联系信息一次性批量取回，避免逐会员查询（导出全量时是明显的 N+1）。
     * 列定义见 {@see UserExcelDefinition}。
     *
     * @param string|array $checkbox user_id 列表；空表示全部
     * @return array head, list
     */
    public function buildExcelUserList($checkbox = '')
    {
        $query = User::query();
        if ($checkbox) {
            $query->whereIn('id', $checkbox);
        }
        $resultList = $query->order('id DESC')->get();

        $userIds = array();
        foreach ($resultList as $userModel) {
            $userIds[] = (int) $userModel->getRawAttribute('id');
        }
        $contactMap = $this->contactQuery->defaultSnapshotBatch($userIds);

        $excelList = array('head' => UserExcelDefinition::head(), 'list' => array());
        foreach ($resultList as $userModel) {
            $user = $userModel->getAttributes();
            $userId = (int) $user['id'];
            $contactSnap = isset($contactMap[$userId]) ? $contactMap[$userId] : array();
            $user = array_merge($contactSnap, $user);
            $excelList['list'][] = UserExcelDefinition::row($user);
        }

        return $excelList;
    }

    /**
     * 后台手工调整会员等级时写入 user_level_log。
     *
     * @param int $userId
     * @param int $oldLevelId
     * @param int $newLevelId
     * @return void
     */
    private function writeManualUserLevelLog($userId, $oldLevelId, $newLevelId)
    {
        $userId = (int) $userId;
        $oldLevelId = (int) $oldLevelId;
        $newLevelId = (int) $newLevelId;
        if ($userId <= 0 || $oldLevelId === $newLevelId || !DB::tableExist('user_level_log')) {
            return;
        }

        DB::table('user_level_log')->insert(array(
            'user_id' => $userId,
            'old_level_id' => $oldLevelId,
            'new_level_id' => $newLevelId,
            'upgrade_type' => 'admin',
            'condition_value' => 0,
            'order_sn' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * 后台手工调整分销等级时写入 distribution_level_log。
     *
     * @param int $userId
     * @param int $oldLevelId
     * @param int $newLevelId
     * @return void
     */
    private function writeManualDistributionLevelLog($userId, $oldLevelId, $newLevelId)
    {
        $userId = (int) $userId;
        $oldLevelId = (int) $oldLevelId;
        $newLevelId = (int) $newLevelId;
        if ($userId <= 0 || $oldLevelId === $newLevelId || !DB::tableExist('distribution_level_log')) {
            return;
        }

        DB::table('distribution_level_log')->insert(array(
            'user_id' => $userId,
            'old_level_id' => $oldLevelId,
            'new_level_id' => $newLevelId,
            'condition_value' => 0,
            'order_sn' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }
}
