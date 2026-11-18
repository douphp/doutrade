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

namespace Dou\Admin\Service\Coupon;

use Dou\Admin\Model\Coupon\Coupon;
use Dou\Admin\Model\Product\ProductCategory;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Coupon\CouponService as CouponCore;
use Dou\Core\Service\Order\OrderableModule;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台优惠券服务（route=coupon/...）
 */
class CouponService extends BaseService
{
    use SavesLanguageDrafts;

    /** @var CouponCore */
    private $coupon;

    /**
     * @param CouponCore $coupon
     */
    public function __construct(CouponCore $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * @param int|string $page
     * @return array list, pager
     */
    public function buildCouponListData($page)
    {
        $p = Check::number((string) $page) ? (int) $page : 1;
        // AR：casts 接管 created_at/start_at/end_at/status 格式化
        $result = Coupon::applyDefaultOrder()
            ->paginate(15, $p, route('admin.coupon'));

        $couponList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $rawType = (string) $model->getRawAttribute('type');
            $couponList[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'coupon_sn' => $row['coupon_sn'],
                'type' => lang('coupon_type_' . $rawType, $rawType),
                'type_value' => $rawType,
                'money' => CouponCore::faceValueLabel(array(
                    'type' => $rawType,
                    'face_value' => $model->getRawAttribute('face_value'),
                )),
                'condition' => $row['condition'],
                'scope_text' => $this->scopeLabel($row),
                'issued_quantity' => (int) $model->getRawAttribute('issued_quantity'),
                'total_quantity' => (int) $model->getRawAttribute('total_quantity'),
                'limit_per_user' => (int) $model->getRawAttribute('limit_per_user'),
                'is_first_order_only' => (int) $model->getRawAttribute('is_first_order_only'),
                'start_at' => $row['start_at'],
                'end_at' => $row['end_at'],
                'status' => $row['status'],
                'sort' => $row['sort'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'list' => $couponList,
            'pager' => $result['pager'],
        );
    }

    /**
     * @return array coupon row defaults for add form
     */
    public function buildCouponDefaultData()
    {
        return array(
            'id' => '',
            'name' => '',
            'type' => 'money',
            'face_value' => 0,
            'max_discount' => 0,
            'limit_per_user' => 1,
            'total_quantity' => 0,
            'issued_quantity' => 0,
            'is_first_order_only' => 0,
            'condition' => 0,
            'scope_type' => 'all',
            'scope_ids' => array(),
            'scope_value' => '',
            'status' => 1,
            'sort' => 0,
            'start_at' => date('Y-m-d H:i:s', time()),
            'end_at' => date('Y-m-d H:i:s', strtotime('+30day', time())),
            'brief' => '',
            'image' => '',
            'image_url' => '',
            'file_number' => '',
        );
    }

    /**
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $adminId = (int) auth('admin')->id();
        $data = $this->normalizeData($data, 0);
        $data['coupon_sn'] = '';
        $data['issued_quantity'] = 0;
        $data['created_at'] = time();

        $image = attachment()->reserve(
            'coupon',
            UploadedFile::fromGlobals('image'),
            'admin',
            $adminId,
            'main',
            AttachmentUploadOptions::create()
        );
        $data['image'] = $image;

        $languagePlan = $this->languageDraftBegin('coupon', $data, 0, $adminId);

        try {
            $id = (int) DB::transaction(function () use ($data, $image, $adminId, $languagePlan) {
                $model = Coupon::create($data);
                $id = (int) $model->getKey();
                if ($id <= 0) {
                    throw new DomainException(lang('operation_failed'));
                }
                if ($image !== '' && attachment()->adopt('coupon', $image, $id, 'admin', $adminId) < 1) {
                    throw new DomainException(lang('file_claim_failed'));
                }
                DB::table('coupon')
                    ->where('id', $id)
                    ->data(array('coupon_sn' => str_pad((string) $id, 6, '0', STR_PAD_LEFT)))
                    ->update();
                $this->languageDraftCommit($languagePlan, $id, $adminId);

                return $id;
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $id);
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $id;
    }

    /**
     * @param string $id
     * @return array|null
     */
    public function buildCouponEditData($id)
    {
        $couponModel = Coupon::find((int) $id);
        $coupon = $couponModel ? $couponModel->getAttributes() : null;
        if (!$coupon) {
            return null;
        }

        $coupon['start_at'] = $coupon['start_at'] ? date('Y-m-d H:i:s', strtotime($coupon['start_at'])) : date('Y-m-d H:i:s', time());
        $coupon['end_at'] = $coupon['end_at'] ? date('Y-m-d H:i:s', strtotime($coupon['end_at'])) : date('Y-m-d H:i:s', strtotime('+30day', time()));
        $coupon['scope_ids'] = $this->decodeScopeIds($coupon);
        $coupon['scope_type'] = isset($coupon['scope_type']) && $coupon['scope_type'] !== '' ? $coupon['scope_type'] : 'all';
        $coupon['issued_quantity'] = isset($coupon['issued_quantity']) ? (int) $coupon['issued_quantity'] : 0;
        $coupon['total_quantity'] = isset($coupon['total_quantity']) ? (int) $coupon['total_quantity'] : 0;
        $coupon['file_number'] = isset($coupon['image']) ? $coupon['image'] : '';
        $coupon['image_url'] = $coupon['file_number'] !== '' ? attachment()->url($coupon['file_number']) : '';
        $coupon['image'] = $coupon['image_url'];

        return $coupon;
    }

    /**
     * 表单可选的模块 / 分类 / 商品范围。
     *
     * @param string $scopeType
     * @param array $scopeIds
     * @return array module_list、category_list、selected_products
     */
    public function buildCouponFormOptions($scopeType, array $scopeIds)
    {
        $scopeType = (string) $scopeType;
        $selectedModules = $scopeType === 'module' ? array_map('strval', $scopeIds) : array();
        $selectedCategories = $scopeType === 'category' ? array_map('intval', $scopeIds) : array();
        $selectedProducts = $scopeType === 'product' ? array_map('intval', $scopeIds) : array();

        return array(
            'module_list' => $this->moduleOptions($selectedModules),
            'category_list' => $this->categoryOptions($selectedCategories),
            'selected_products' => $this->selectedProducts($selectedProducts),
        );
    }

    /**
     * 商品范围搜索（后台表单 AJAX）。
     *
     * @param string $keyword
     * @param int $limit
     * @return array
     */
    public function searchProducts($keyword, $limit)
    {
        if (!DB::tableExist('product')) {
            return array();
        }

        $keyword = trim((string) $keyword);
        $limit = max(1, min(60, (int) $limit));

        $query = DB::table('product')
            ->field('id, title, price')
            ->where('status', '1')
            ->order('id DESC');
        if ($keyword !== '') {
            $query->where('title', 'LIKE', '%' . $keyword . '%');
        }

        $list = array();
        foreach ((array) $query->limit($limit)->select() as $row) {
            $list[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['title'],
                'price_format' => Util::formatPrice($row['price']),
            );
        }

        return $list;
    }

    /**
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }

        $coupon = Coupon::find($id);
        if (!$coupon) {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }

        $issuedQuantity = (int) $coupon->getRawAttribute('issued_quantity');
        $couponAttributes = $coupon->getAttributes();
        $oldImageNumber = isset($couponAttributes['image']) ? (string) $couponAttributes['image'] : '';
        $data = $this->normalizeData($data, $issuedQuantity);

        $adminId = (int) auth('admin')->id();
        $image = attachment()->store(
            'coupon',
            $id,
            UploadedFile::fromGlobals('image'),
            'main',
            AttachmentUploadOptions::create()->withUploader('admin', $adminId)
        );
        $deleteImage = ($image === '' && !empty($data['image_delete']));
        if ($image) {
            $data['image'] = $image;
        } elseif ($deleteImage) {
            $data['image'] = '';
        }

        $languagePlan = $this->languageDraftBegin('coupon', $data, $id, $adminId);

        try {
            DB::transaction(function () use ($coupon, $data, $id, $adminId, $languagePlan) {
                $coupon->fill($data, 'update')->save();
                $this->languageDraftCommit($languagePlan, $id, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $id);
        if ($deleteImage && $oldImageNumber !== '') {
            attachment()->delete($oldImageNumber);
        }
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * @param int|string $id
     * @param array $data
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 记录不存在或有领取记录时抛出
     */
    public function delete($id, array $data)
    {
        $name = Coupon::getNameById((int) $id);
        if ($name === null || $name === false || $name === '') {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }

        $this->assertNoLogs((int) $id, (string) $name);

        if (isset($data['confirm'])) {
            $this->assertNoLogs((int) $id, (string) $name);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $name);
            Coupon::destroy((int) $id);

            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.coupon'),
            );
        }

        $msg = preg_replace('/d%/Ums', $name, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.coupon'),
            'timeout' => '30',
            'confirm_url' => route('admin.coupon.destroy', array('id' => (int) $id)),
        );
    }

    /**
     * @param array $data
     * @return array{message: string, back_url: string}
     * @throws DomainException 参数不合法或动作类型不支持时抛出
     */
    public function action(array $data)
    {
        if (empty($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('select_empty'), route('admin.coupon'));
        }
        if (!isset($data['action']) || $data['action'] !== 'del_all') {
            throw new DomainException(lang('select_empty'), route('admin.coupon'));
        }

        $safeIds = array();
        foreach ($data['checkbox'] as $rid) {
            if (Check::number((string) $rid)) {
                $safeIds[] = (int) $rid;
            }
        }
        if (empty($safeIds)) {
            throw new DomainException(lang('select_empty'), route('admin.coupon'));
        }

        foreach ($safeIds as $id) {
            $model = Coupon::find($id);
            if ($model) {
                $this->assertNoLogs($id, (string) $model->getRawAttribute('name'));
            }
        }

        foreach ($safeIds as $id) {
            Coupon::destroy($id);
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:COUPON (' . count($safeIds) . ' items)');

        return array('message' => lang('del_succes'), 'back_url' => route('admin.coupon'));
    }

    /**
     * @param array $data
     * @param int $issuedQuantity
     * @return array
     */
    private function normalizeData(array $data, $issuedQuantity)
    {
        $type = isset($data['type']) ? (string) $data['type'] : 'money';
        if (!in_array($type, array('money', 'percent'), true)) {
            throw new DomainException(lang('illegal'), route('admin.coupon'));
        }

        $faceValue = isset($data['face_value']) ? (float) $data['face_value'] : 0.0;
        if ($faceValue <= 0 || ($type === 'percent' && $faceValue > 100)) {
            throw new DomainException(lang('coupon_face_value_invalid'), route('admin.coupon'));
        }

        $startTs = !empty($data['start_at']) ? strtotime($data['start_at']) : 0;
        $endTs = !empty($data['end_at']) ? strtotime($data['end_at']) : 0;
        if ($startTs <= 0 || $endTs <= 0 || $endTs <= $startTs) {
            throw new DomainException(lang('coupon_time_invalid'), route('admin.coupon'));
        }

        $scopeType = isset($data['scope_type']) ? (string) $data['scope_type'] : 'all';
        if (!in_array($scopeType, array('all', 'module', 'category', 'product'), true)) {
            $scopeType = 'all';
        }
        $scopeIds = $this->normalizeScopeIds($scopeType, isset($data['scope_ids']) ? $data['scope_ids'] : array());
        if ($scopeType !== 'all' && empty($scopeIds)) {
            throw new DomainException(lang('coupon_scope_invalid'), route('admin.coupon'));
        }

        $totalQuantity = isset($data['total_quantity']) ? (int) $data['total_quantity'] : 0;
        if ($totalQuantity < 0) {
            $totalQuantity = 0;
        }
        if ($totalQuantity > 0 && $totalQuantity < (int) $issuedQuantity) {
            throw new DomainException(lang('coupon_total_quantity_invalid'), route('admin.coupon'));
        }

        $data['type'] = $type;
        $data['face_value'] = $faceValue;
        $data['max_discount'] = $type === 'percent'
            ? max(0, (float) (isset($data['max_discount']) ? $data['max_discount'] : 0))
            : 0;
        $data['condition'] = max(0, (float) (isset($data['condition']) ? $data['condition'] : 0));
        $data['limit_per_user'] = max(0, (int) (isset($data['limit_per_user']) ? $data['limit_per_user'] : 1));
        $data['total_quantity'] = $totalQuantity;
        $data['is_first_order_only'] = !empty($data['is_first_order_only']) ? 1 : 0;
        $data['scope_type'] = $scopeType;
        $data['scope_value'] = $scopeType === 'all' ? '' : json_encode($scopeIds);
        $data['start_at'] = $startTs;
        $data['end_at'] = $endTs;
        $data['sort'] = (int) (isset($data['sort']) ? $data['sort'] : 0);
        unset($data['scope_ids']);

        return $data;
    }

    /**
     * @param string $scopeType
     * @param mixed $raw
     * @return array
     */
    private function normalizeScopeIds($scopeType, $raw)
    {
        if (!is_array($raw)) {
            return array();
        }

        if ($scopeType === 'module') {
            $ids = array();
            foreach ($raw as $id) {
                $id = trim((string) $id);
                if ($id !== '' && OrderableModule::allows($id)) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        $ids = array();
        foreach ($raw as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids) || ($scopeType !== 'category' && $scopeType !== 'product')) {
            return $ids;
        }

        $table = $scopeType === 'category' ? 'product_category' : 'product';
        if (!DB::tableExist($table)) {
            return array();
        }
        $count = DB::table($table)->whereIn('id', $ids)->count();

        return (int) $count === count($ids) ? $ids : array();
    }

    /**
     * @param array $coupon
     * @return array
     */
    private function decodeScopeIds(array $coupon)
    {
        $raw = isset($coupon['scope_value']) ? $coupon['scope_value'] : '';
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array $row
     * @return string
     */
    private function scopeLabel(array $row)
    {
        $scopeType = isset($row['scope_type']) && $row['scope_type'] !== '' ? (string) $row['scope_type'] : 'all';
        $label = lang('coupon_scope_' . $scopeType, $scopeType);
        if ($scopeType !== 'all') {
            $ids = $this->decodeScopeIds($row);
            if (!empty($ids)) {
                $label .= ' (' . count($ids) . ')';
            }
        }

        return $label;
    }

    /**
     * @param array $selected
     * @return array
     */
    private function moduleOptions(array $selected)
    {
        $candidates = array('product');
        foreach (array('column_module', 'single_module', 'link_order_item') as $key) {
            foreach ((array) Config::get('module.' . $key, array()) as $module) {
                $candidates[] = (string) $module;
            }
        }
        $candidates = array_values(array_unique($candidates));

        $list = array();
        foreach ($candidates as $module) {
            if (!OrderableModule::allows($module)) {
                continue;
            }
            $list[] = array(
                'id' => $module,
                'name' => lang($module, $module),
                'selected' => in_array($module, $selected, true),
            );
        }

        return $list;
    }

    /**
     * @param array $selected
     * @return array
     */
    private function categoryOptions(array $selected)
    {
        if (!DB::tableExist('product_category')) {
            return array();
        }

        $list = ProductCategory::flat();
        foreach ($list as $index => $category) {
            $list[$index]['selected'] = in_array((int) $category['id'], $selected, true);
        }

        return $list;
    }

    /**
     * @param array $ids
     * @return array
     */
    private function selectedProducts(array $ids)
    {
        if (empty($ids) || !DB::tableExist('product')) {
            return array();
        }

        $list = array();
        foreach ((array) DB::table('product')->whereIn('id', $ids)->field('id, title')->select() as $row) {
            $list[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['title'],
            );
        }

        return $list;
    }

    /**
     * @param int $id
     * @param string $name
     * @return void
     */
    private function assertNoLogs($id, $name)
    {
        if (DB::table('coupon_log')->where('coupon_id', (int) $id)->exists()) {
            throw new DomainException(
                preg_replace('/d%/Ums', $name, lang('coupon_del_have_log')),
                route('admin.coupon')
            );
        }
    }
}
