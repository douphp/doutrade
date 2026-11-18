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

use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Front\Model\User\UserContact;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员地址簿业务层（route=user/contact/...）
 */
class ContactService extends BaseService
{
    /**
     * 地址簿分页列表（不含操作 URL，由控制器拼接）
     *
     * @param int $userId
     * @param int $page
     * @param string $pagerUrl 翻页基础 URL
     * @return array list => 条目数组, pager => 分页 HTML
     */
    public function buildContactListData($userId, $page, $pagerUrl)
    {
        $result = UserContact::where('user_id', $userId)
            ->order('id DESC')
            ->paginate(15, $page, $pagerUrl);

        $list = array();
        foreach ($result['list'] as $rowModel) {
            $row = $rowModel->getAttributes();
            $phone = isset($row['phone']) ? $row['phone'] : '';
            $list[] = array(
                'id' => isset($row['id']) ? $row['id'] : 0,
                'name' => UserContactQuery::displayName($row),
                'phone' => $phone,
                'mobile' => $phone,
                'id_card' => isset($row['id_card']) ? $row['id_card'] : '',
                'address' => isset($row['address']) ? $row['address'] : '',
                'address_full' => user() ? user()->addressFull($row) : '',
                'is_default' => !empty($row['is_default']),
            );
        }

        return array('list' => $list, 'pager' => $result['pager']);
    }

    /**
     * 新增地址
     *
     * @param int $userId
     * @param array $data 已由控制器校验的字段：name, phone, id_card, province, city, district, address, tag, is_default
     * @return int 新记录 ID
     */
    public function insertContact($userId, array $data)
    {
        $data = xss()->post($data);
        if (!empty($data['is_default'])) {
            UserContact::where('user_id', $userId)->update(array('is_default' => 0));
        }

        $payload = array(
            'user_id' => $userId,
            'name' => isset($data['name']) ? $data['name'] : '',
            'first_name' => isset($data['first_name']) ? $data['first_name'] : '',
            'last_name' => isset($data['last_name']) ? $data['last_name'] : '',
            'phone' => isset($data['phone']) ? $data['phone'] : '',
            'id_card' => isset($data['id_card']) ? $data['id_card'] : '',
            'country' => isset($data['country']) ? $data['country'] : '',
            'province' => isset($data['province']) ? $data['province'] : '',
            'city' => isset($data['city']) ? $data['city'] : '',
            'district' => isset($data['district']) ? $data['district'] : '',
            'address' => isset($data['address']) ? $data['address'] : '',
            'postcode' => isset($data['postcode']) ? $data['postcode'] : '',
            'tag' => isset($data['tag']) ? $data['tag'] : '',
            'is_default' => isset($data['is_default']) ? (int) $data['is_default'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
        );
        // 英文表单只交 first/last：name 为空时回填拼接名，各读取端统一读 name
        $payload['name'] = UserContactQuery::displayName($payload);
        $contact = UserContact::create($payload);

        return (int) $contact->getKey();
    }

    /**
     * 更新地址
     *
     * @param int $userId
     * @param int $id
     * @param array $data 已由控制器校验的字段：name, phone, id_card, province, city, district, address, tag, is_default
     * @return void
     */
    public function updateContact($userId, $id, array $data)
    {
        $data = xss()->post($data);
        $update = array(
            'name' => isset($data['name']) ? $data['name'] : '',
            'first_name' => isset($data['first_name']) ? $data['first_name'] : '',
            'last_name' => isset($data['last_name']) ? $data['last_name'] : '',
            'phone' => isset($data['phone']) ? $data['phone'] : '',
            'id_card' => isset($data['id_card']) ? $data['id_card'] : '',
            'country' => isset($data['country']) ? $data['country'] : '',
            'province' => isset($data['province']) ? $data['province'] : '',
            'city' => isset($data['city']) ? $data['city'] : '',
            'district' => isset($data['district']) ? $data['district'] : '',
            'address' => isset($data['address']) ? $data['address'] : '',
            'postcode' => isset($data['postcode']) ? $data['postcode'] : '',
            'tag' => isset($data['tag']) ? $data['tag'] : '',
        );
        // 英文表单只交 first/last：name 为空时回填拼接名，各读取端统一读 name
        $update['name'] = UserContactQuery::displayName($update);
        if (array_key_exists('is_default', $data)) {
            $isDefault = !empty($data['is_default']) ? 1 : 0;
            if ($isDefault) {
                UserContact::where('user_id', $userId)->update(array('is_default' => 0));
            }
            $update['is_default'] = $isDefault;
        }
        UserContact::where('id', $id)->where('user_id', $userId)->update($update);
    }

    /**
     * 删除地址
     *
     * @param int $userId
     * @param int $id
     * @return bool
     */
    public function delete($userId, $id)
    {
        return (bool) UserContact::where('id', $id)->where('user_id', $userId)->delete();
    }

    /**
     * 设为默认地址
     *
     * @param int $userId
     * @param int $id
     * @return void
     */
    public function setDefaultContact($userId, $id)
    {
        UserContact::where('user_id', $userId)->update(array('is_default' => 0));
        UserContact::where('id', $id)->where('user_id', $userId)->update(array('is_default' => 1));
    }

    /**
     * JSON 地址列表（供订单/预约等下拉选择）
     *
     * @param int $userId
     * @param int $contactId 当前选中 ID（传 0 则取默认）
     * @return array
     */
    public function getJsonList($userId, $contactId)
    {
        return user() ? user()->contactList($userId, $contactId) : array();
    }

    /**
     * JSON 单条地址详情
     *
     * @param int $userId
     * @param int $contactId
     * @return array
     */
    public function getJsonInfo($userId, $contactId)
    {
        $contactModel = UserContact::where('id', $contactId)->where('user_id', $userId)->first();
        $contact = $contactModel ? $contactModel->getAttributes() : null;
        if ($contact) {
            $contact['name'] = UserContactQuery::displayName($contact);
            $contact['address_full'] = user() ? user()->addressFull($contact) : '';
            $contact['is_default'] = !empty($contact['is_default']);
        }
        return $contact ?: array();
    }

    /**
     * 解析 contact_id：传入有效值则直接用，否则取该用户的默认/最新地址 ID
     *
     * @param int $userId
     * @param int $contactId
     * @return int
     */
    public function resolveContactId($userId, $contactId)
    {
        if ($contactId > 0) {
            return $contactId;
        }
        return (int) UserContact::where('user_id', $userId)
            ->order('`is_default` DESC, id DESC')
            ->value('id');
    }
}
