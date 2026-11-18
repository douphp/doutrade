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
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员收货联系人读模型。
 */
class UserContactQuery extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 联系人展示名单点出口：name 为规范展示字段（写入时已归一），
     * 为空才回落 first_name + last_name 拼接，兜底历史数据与 API 直写记录。
     *
     * @param array $row 含 name / first_name / last_name 任意子集
     * @return string
     */
    public static function displayName(array $row)
    {
        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($name !== '') {
            return $name;
        }

        $firstName = isset($row['first_name']) ? trim((string) $row['first_name']) : '';
        $lastName = isset($row['last_name']) ? trim((string) $row['last_name']) : '';

        return trim($firstName . ' ' . $lastName);
    }

    /**
     * 取会员收货联系人列表（含 address_full 拼装、当前选中标记）。
     *
     * @param mixed $user_id
     * @param string $current_contact_id 选中的联系人 id（用于 cur 标记）
     * @return array
     */
    public function contactList($user_id, $current_contact_id = '')
    {
        $user_id = intval($user_id);
        if (!$user_id) {
            return array();
        }

        $result_list = DB::table('user_contact')
            ->where('user_id', $user_id)
            ->order('id DESC')
            ->select();

        $contact_list = array();
        foreach ((array) $result_list as $row) {
            $phone = isset($row['phone']) ? $row['phone'] : '';
            $contact_list[] = array(
                'id' => $row['id'],
                'name' => self::displayName($row),
                'phone' => $phone,
                'mobile' => $phone,
                'id_card' => $row['id_card'],
                'address' => $row['address'],
                'address_full' => $this->addressFull($row),
                'tag' => $row['tag'],
                'cur' => $row['id'] == $current_contact_id ? true : false,
            );
        }

        return $contact_list;
    }

    /**
     * 拼装完整收货地址：英文站点以「逗号+逆序」展示，其余站点直接拼接。
     *
     * @param array $contact 需含 country / province / city / district / address
     * @return string
     */
    public function addressFull($contact)
    {
        $country = isset($contact['country']) ? (string) $contact['country'] : '';
        $province = isset($contact['province']) ? (string) $contact['province'] : '';
        $city = isset($contact['city']) ? (string) $contact['city'] : '';
        $district = isset($contact['district']) ? (string) $contact['district'] : '';
        $address = isset($contact['address']) ? (string) $contact['address'] : '';

        // 有效语言：手动切换（locale）优先，回落站点默认语言
        $pack = locale()->isActive() ? (string) locale()->pack() : (string) Config::get('site.language.default', '');
        if ($pack === 'en_us') {
            return $address
                . ($district ? ', ' . $district : '')
                . ($city ? ', ' . $city : '')
                . ($province ? ', ' . $province : '')
                . ($country ? ', ' . $country : '');
        }

        return $country . $province . $city . $district . $address;
    }

    /**
     * 返回 dou_user_contact 的 is_default=1 行（无则 sort/id 倒序首条），
     * 按 dou_user 旧平拍 8 字段格式标准化（缺字段补空串）。
     *
     * 用途：dou_user 主表 first_name/last_name/contact/country/province/address/postcode/phone 列
     * 已迁至 dou_user_contact；读路径经本方法 merge 进会员行，模板 `{$user.contact}` 等仍可读。
     *
     * @param int $user_id
     * @return array 固定键：first_name, last_name, contact, phone, country, province, city, district, address, postcode
     */
    public function defaultContactSnapshot($user_id)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !DB::tableExist('user_contact')) {
            return self::emptySnapshot();
        }

        $row = DB::table('user_contact')
            ->where('user_id', $user_id)
            ->order('`is_default` DESC, `sort` ASC, `id` ASC')
            ->find();
        if (!$row) {
            return self::emptySnapshot();
        }

        return self::normalizeSnapshotRow($row);
    }

    /**
     * 批量版 defaultContactSnapshot：一次 whereIn 取回多个会员的默认联系人行。
     *
     * 导出等需要遍历大量会员的场景必须走本方法，避免逐会员查询造成 N+1。
     * 未命中任何联系人行的会员不会出现在返回 map 中，调用方用空快照兜底。
     *
     * @param array $user_ids 会员 ID 集合。
     * @return array user_id => 联系人快照（键同 defaultContactSnapshot）
     */
    public function defaultSnapshotBatch(array $user_ids)
    {
        $ids = array();
        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;
            if ($user_id > 0) {
                $ids[$user_id] = $user_id;
            }
        }
        if (empty($ids) || !DB::tableExist('user_contact')) {
            return array();
        }

        $rows = DB::table('user_contact')
            ->whereIn('user_id', array_values($ids))
            ->order('`user_id` ASC, `is_default` DESC, `sort` ASC, `id` ASC')
            ->select();

        $map = array();
        foreach ((array) $rows as $row) {
            $user_id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($user_id <= 0 || isset($map[$user_id])) {
                continue; // 排序已保证每个会员的首条即默认行
            }
            $map[$user_id] = self::normalizeSnapshotRow($row);
        }

        return $map;
    }

    /**
     * 空联系人快照（固定 10 键，缺字段补空串）。
     *
     * @return array
     */
    private static function emptySnapshot()
    {
        return array(
            'first_name' => '',
            'last_name' => '',
            'contact' => '',
            'phone' => '',
            'country' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'address' => '',
            'postcode' => '',
        );
    }

    /**
     * 把 dou_user_contact 行归一为导出/展示用的固定快照。
     *
     * @param array $row
     * @return array
     */
    private static function normalizeSnapshotRow(array $row)
    {
        return array(
            'first_name' => isset($row['first_name']) ? (string) $row['first_name'] : '',
            'last_name' => isset($row['last_name']) ? (string) $row['last_name'] : '',
            'contact' => self::displayName($row),
            'phone' => isset($row['phone']) ? (string) $row['phone'] : '',
            'country' => isset($row['country']) ? (string) $row['country'] : '',
            'province' => isset($row['province']) ? (string) $row['province'] : '',
            'city' => isset($row['city']) ? (string) $row['city'] : '',
            'district' => isset($row['district']) ? (string) $row['district'] : '',
            'address' => isset($row['address']) ? (string) $row['address'] : '',
            'postcode' => isset($row['postcode']) ? (string) $row['postcode'] : '',
        );
    }

    /**
     * 写入会员默认收货行（dou_user_contact 中 is_default=1）。
     *
     * 已有默认行则 update，否则 insert。空字段不覆盖（避免编辑某一字段时把其它清空）。
     * 与 ContactService::insertContact / updateContact 的「地址簿管理」职责不同：
     * 这里专用承接 dou_user 旧平拍字段的同步写入；其它分页 / CRUD 仍走 ContactService。
     *
     * @param int $user_id
     * @param array $fields 可含: first_name, last_name, contact, phone, country, province, city, district, address, postcode
     * @return void
     */
    public function upsertDefaultContact($user_id, array $fields)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0 || !DB::tableExist('user_contact')) {
            return;
        }

        // 标准化输入：仅取白名单字段；contact 映射为 user_contact.name
        $data = array();
        foreach (array('first_name', 'last_name', 'phone', 'country', 'province', 'city', 'district', 'address', 'postcode') as $col) {
            if (array_key_exists($col, $fields)) {
                $data[$col] = (string) $fields[$col];
            }
        }
        if (array_key_exists('contact', $fields)) {
            $data['name'] = (string) $fields['contact'];
        } elseif (array_key_exists('name', $fields)) {
            $data['name'] = (string) $fields['name'];
        }
        // 英文表单只交 first/last：name 为空时回填拼接名，保证各读取端统一读 name
        $derivedName = self::displayName($data);
        if ($derivedName !== '') {
            $data['name'] = $derivedName;
        }
        if (empty($data)) {
            return;
        }

        $defaultId = (int) DB::table('user_contact')
            ->where('user_id', $user_id)
            ->order('`is_default` DESC, `sort` ASC, `id` ASC')
            ->value('id');

        if ($defaultId > 0) {
            DB::table('user_contact')->where('id', $defaultId)->data($data)->update();
            DB::table('user_contact')->where('id', $defaultId)->data(array('is_default' => 1))->update();
            return;
        }

        $insert = array_merge(array(
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'country' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'address' => '',
            'postcode' => '',
        ), $data);
        $insert['user_id'] = $user_id;
        $insert['is_default'] = 1;
        $insert['sort'] = 50;
        // dou_user_contact.created_at 是 datetime，不能写 unix 时间戳；严格模式下会静默插入失败
        $insert['created_at'] = date('Y-m-d H:i:s');
        $inserted = DB::table('user_contact')->data($insert)->insert();
        if (!$inserted) {
            throw new \Dou\Core\Foundation\Exception\DomainException(lang('operation_failed', '操作失败，请重试'));
        }
    }
}
