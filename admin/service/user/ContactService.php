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

use Dou\Admin\Model\User\UserContact;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserContactQuery;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员联系方式服务。
 *
 * 列表 / 编辑数据用 `build*Data`；`update` / `delete` 与控制器同名；
 * 输入校验由 UserContactFormRequest 承担。
 */
class ContactService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 联系方式列表数据。
     *
     * @param string $username 会员关键字（手机/邮箱/user_sn/联系人，Core\UserService::findUserId）
     * @param string $name 姓名
     * @param string $phone 手机号（已通过控制器校验；未通过时见 rejectFilter）
     * @param string $pageUrl 分页 URL（由控制器按原始查询参数拼装）
     * @param int $page 页码
     * @param array $rejectFilter 有输入但未通过对应校验时为 true：`username` / `name` / `phone`
     * @return array list, pager
     */
    public function buildContactListData($username, $name, $phone, $pageUrl, $page = 1, array $rejectFilter = array())
    {
        $query = UserContact::query();

        $rejectUsername = !empty($rejectFilter['username']);
        $rejectName = !empty($rejectFilter['name']);
        $rejectPhone = !empty($rejectFilter['phone']);
        if ($rejectUsername || $rejectName || $rejectPhone) {
            $query->where('id', -1);
        } else {
            if ($username) {
                $userId = user() !== null ? user()->findUserId($username) : null;
                if ($userId) {
                    $query->where('user_id', (int) $userId);
                } else {
                    $query->where('user_id', -1);
                }
            }

            if ($name) {
                $query->where('name', 'LIKE', '%' . $name . '%');
            }

            if ($phone) {
                $query->where('phone', 'LIKE', '%' . $phone . '%');
            }
        }

        $result = $query->order('id DESC')->paginate(15, $page, $pageUrl);

        $list = array();
        $yes = lang('yes', 'Yes');
        $no = lang('no', 'No');

        foreach ($result['list'] as $rowModel) {
            $row = $rowModel->getAttributes();
            $user = (user() !== null ? user()->format($row['user_id']) : false);
            if (!is_array($user)) {
                $fallbackUserId = isset($row['user_id']) ? (int) $row['user_id'] : 0;
                $user = array(
                    'id' => $fallbackUserId,
                    'user_id' => $fallbackUserId,
                    'username' => '',
                );
            }

            $list[] = array(
                'id' => $row['id'],
                'user' => $user,
                'name' => UserContactQuery::displayName($row),
                'phone' => $row['phone'],
                'id_card' => $row['id_card'],
                'address' => $row['address'],
                'address_full' => user()->addressFull($row),
                'tag' => $row['tag'],
                'is_default' => $row['is_default'] ? true : false,
                'is_default_format' => !empty($row['is_default']) ? $yes : $no,
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            );
        }

        return array(
            'list' => $list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 编辑表单数据。
     *
     * @param int $id
     * @return array|null
     */
    public function buildContactEditData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $contactModel = UserContact::find($id);
        $contact = $contactModel ? $contactModel->getAttributes() : null;
        if (!$contact) {
            return null;
        }

        // 历史英文记录 name 可能为空，表单预填归一展示名
        $contact['name'] = UserContactQuery::displayName($contact);

        return $contact;
    }

    /**
     * 更新联系方式。
     *
     * 字段白名单与校验规则定义在 UserContactFormRequest::rules()。
     * @see \Dou\Admin\Request\User\UserContactFormRequest::rules()
     *
     * @param array $data validated 数据
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        $contactModel = UserContact::find($id);
        if (!$contactModel) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        $updateData = array(
            'name' => isset($data['name']) ? $data['name'] : '',
            'phone' => isset($data['phone']) ? $data['phone'] : '',
        );
        // 只在表单实际提交时更新：id_card 与地区/地址区块随 features.book 切换显隐，
        // 未展示的字段不能回写成空串，否则编辑一次就会把存量地址清空
        foreach (array('id_card', 'country', 'province', 'city', 'district', 'address', 'tag') as $col) {
            if (array_key_exists($col, $data)) {
                $updateData[$col] = $data[$col];
            }
        }
        // 表单未提交 is_default 时保持原值，不能默认写 0 清空默认标记
        if (array_key_exists('is_default', $data)) {
            $updateData['is_default'] = ((int) $data['is_default']) ? 1 : 0;
        }

        if (!$contactModel->fill($updateData, 'update')->save()) {
            throw new DomainException(lang('operation_failed', '操作失败，请重试'));
        }

        if (isset($updateData['is_default']) && $updateData['is_default'] === 1) {
            DB::table('user_contact')
                ->where('user_id', (int) $contactModel->getRawAttribute('user_id'))
                ->where('id', '<>', $id)
                ->data(array('is_default' => 0))
                ->update();
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) (isset($data['name']) ? $data['name'] : ''), 'user_contact');
    }

    /**
     * 删除联系方式（二次确认逻辑相同）。
     *
     * @param int $id
     * @param array $post POST 数据（confirm）
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在或进入二次确认分支时抛出
     */
    public function delete($id, array $post)
    {
        $id = (int) $id;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        $contactModel = UserContact::find($id);
        $contact = $contactModel ? $contactModel->getAttributes() : null;
        if (!$contact) {
            throw new DomainException(lang('illegal'), route('admin.user.contact'));
        }

        if (isset($post['confirm'])) {
            UserContact::destroy($id);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $contact['name'], 'user_contact');
            return array(
                'message' => lang('del_success'),
                'back_url' => route('admin.user.contact'),
            );
        }

        $msg = preg_replace('/d%/Ums', $contact['name'], lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.user.contact'),
            'timeout' => '30',
            'confirm_url' => route('admin.user.contact.destroy', array('id' => $id)),
        );
    }
}
