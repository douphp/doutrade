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

use Dou\Admin\Model\User\UserLevel;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员等级业务（route=user/level/...）。
 *
 * `insert` / `update` / `delete` 与控制器同名；
 * 列表 / 默认 / 编辑数据用 `build*Data`；
 * 输入校验由 LevelFormRequest 承担；业务阻断在此抛 DomainException。
 */
class LevelService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * 列表行数据。
     *
     * @return array
     */
    public function buildLevelListData()
    {
        $rows = UserLevel::selectAllOrdered();
        $levelList = array();
        foreach ($rows as $row) {
            $levelList[] = array(
                'level_id' => $row['id'],
                'name' => $row['name'],
                'upgrade_type' => $row['upgrade_type'],
                'upgrade_type_label' => $this->formatUpgradeTypeLabel($row['upgrade_type'], $row['upgrade_condition']),
                'upgrade_condition' => $row['upgrade_condition'],
                'good_discount' => $row['good_discount'],
                'icon' => attachment()->url($row['icon']),
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            );
        }
        return $levelList;
    }

    /**
     * 会员等级列表为全量加载；与 `inc/pager.tpl` 及 Connection 分页数组形状一致，供单页展示。
     *
     * @param int $recordCount 列表行数
     * @return array
     */
    public function buildLevelListPager($recordCount)
    {
        $recordCount = (int) $recordCount;
        $pageUrl = route('admin.user.level');
        $getPage = strpos($pageUrl, '?') !== false ? '&page=' : '?page=';
        $pageSize = $recordCount > 0 ? $recordCount : 1;
        $box = array();
        if ($recordCount > 0) {
            $box[] = array(
                'page' => 1,
                'url' => $pageUrl . $getPage . '1',
                'cur' => true,
            );
        }
        return array(
            'record_count' => $recordCount,
            'page_size' => $pageSize,
            'page' => 1,
            'page_count' => 1,
            'box' => $box,
            'previous' => $pageUrl . $getPage . '1',
            'next' => $pageUrl . $getPage . '1',
            'first' => $pageUrl . $getPage . '1',
            'last' => $pageUrl . $getPage . '1',
        );
    }

    /**
     * 新增表单默认值。
     *
     * @return array
     */
    public function buildLevelDefaultData()
    {
        return array(
            'level_id' => '',
            'name' => '',
            'upgrade_type' => '',
            'good_discount' => 100,
            'upgrade_condition' => 0,
            'icon' => '',
            'file_number' => '',
        );
    }

    /**
     * 编辑表单数据。
     *
     * @param int|string $levelId
     * @return array|null
     */
    public function buildLevelEditData($levelId)
    {
        $levelModel = UserLevel::find($levelId);
        $row = $levelModel ? $levelModel->getAttributes() : null;
        if (!$row) {
            return null;
        }
        $row['level_id'] = $row['id'];
        $row['file_number'] = Check::fileNumber($row['icon']) ? $row['icon'] : '';
        $row['icon'] = Check::fileNumber($row['icon']) ? attachment()->url($row['icon']) : $row['icon'];
        return $row;
    }

    /**
     * 新增提交。
     *
     * 字段白名单与校验规则定义在 LevelFormRequest::rules()。
     * @see \Dou\Admin\Request\User\LevelFormRequest::rules()
     *
     * @param array $data validated 数据
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $insertData = array(
            'name' => Arr::get($data, 'name', ''),
            'upgrade_type' => Arr::get($data, 'upgrade_type', ''),
            'upgrade_condition' => Arr::get($data, 'upgrade_condition', ''),
            'good_discount' => Arr::get($data, 'good_discount', ''),
            'icon' => '',
            'created_at' => date('Y-m-d H:i:s'),
        );

        $adminId = (int) auth('admin')->id();
        $icon = attachment()->reserve('user_level', UploadedFile::fromGlobals('icon'), 'admin', $adminId, 'main', AttachmentUploadOptions::create());
        $insertData['icon'] = $icon;

        $levelId = (int) DB::transaction(function () use ($insertData, $icon, $adminId) {
            $model = UserLevel::create($insertData);
            if (!$model) {
                throw new DomainException(lang('operation_failed'));
            }
            $levelId = (int) $model->getKey();
            if ($levelId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }

            if ($icon !== '' && attachment()->adopt('user_level', $icon, $levelId, 'admin', $adminId) < 1) {
                throw new DomainException(lang('file_claim_failed'));
            }

            return $levelId;
        });

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $insertData['name'], 'user_level');

        return $levelId;
    }

    /**
     * 更新提交。
     *
     * 字段白名单与校验规则定义在 LevelFormRequest::rules()。
     * @see \Dou\Admin\Request\User\LevelFormRequest::rules()
     *
     * @param array $data validated 数据
     * @return void
     */
    public function update(array $data)
    {
        $levelId = isset($data['level_id']) ? (int) $data['level_id'] : 0;
        if ($levelId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        $record = UserLevel::find($levelId);
        if (!$record) {
            throw new DomainException(lang('item_not_exist'), route('admin.user.level'));
        }
        $recordAttributes = $record->getAttributes();
        $oldIconNumber = isset($recordAttributes['icon']) ? (string) $recordAttributes['icon'] : '';

        $uploaded = attachment()->store('user_level', $levelId, UploadedFile::fromGlobals('icon'), 'main', AttachmentUploadOptions::create()->withUploader('admin', (int) auth('admin')->id()));
        $deleteIcon = ($uploaded === '' && !empty($data['icon_delete']));

        $updateData = array(
            'name' => Arr::get($data, 'name', ''),
            'upgrade_type' => Arr::get($data, 'upgrade_type', ''),
            'upgrade_condition' => Arr::get($data, 'upgrade_condition', ''),
            'good_discount' => Arr::get($data, 'good_discount', ''),
        );
        if ($uploaded) {
            $updateData['icon'] = $uploaded;
        } elseif ($deleteIcon) {
            $updateData['icon'] = '';
        }

        $record->fill($updateData, 'update')->save();
        if ($deleteIcon && $oldIconNumber !== '') {
            attachment()->delete($oldIconNumber);
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $updateData['name'], 'user_level');
    }

    /**
     * 删除（二次确认逻辑相同）。
     *
     * @param int|string $levelId
     * @param array $post POST 数据（confirm）
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在或进入二次确认分支时抛出
     */
    public function delete($levelId, array $post)
    {
        $levelId = (int) $levelId;
        if ($levelId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        $name = UserLevel::getNameById($levelId);
        if (!$name) {
            throw new DomainException(lang('illegal'), route('admin.user.level'));
        }

        if (DB::table('user')->where('level_id', $levelId)->count() > 0) {
            throw new DomainException(lang('user_level_in_use', '该等级下仍有会员，无法删除'), route('admin.user.level'));
        }

        if (isset($post['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name, 'user_level');
            UserLevel::destroy($levelId);
            return array(
                'message' => lang('del_success'),
                'back_url' => route('admin.user.level'),
            );
        }

        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.user.level'),
            'timeout' => '30',
            'confirm_url' => route('admin.user.level.destroy', array('id' => $levelId)),
        );
    }

    /**
     * 列表展示用：升级方式中文标签 + 门槛金额。
     *
     * @param string $upgradeType
     * @param mixed $condition
     * @return string
     */
    private function formatUpgradeTypeLabel($upgradeType, $condition)
    {
        $upgradeType = (string) $upgradeType;
        if ($upgradeType === 'shopping') {
            return lang('user_level_upgrade_type_shopping') . ' ' . $condition;
        }
        if ($upgradeType === 'promote') {
            return lang('user_level_upgrade_type_promote') . ' ' . $condition;
        }

        return lang('user_level_upgrade_type_noauto');
    }
}
