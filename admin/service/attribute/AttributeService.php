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

namespace Dou\Admin\Service\Attribute;

use Dou\Admin\Model\Attribute\Attribute;
use Dou\Admin\Model\Attribute\AttributeValue;
use Dou\Admin\Model\Product\ProductCategory;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\Attribute\AttributeService as AttributeCore;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\CategoryIds;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台款式属性业务服务
 */
class AttributeService extends BaseService
{
    use SavesLanguageDrafts;

    /** @var AttributeCore */
    private $lib;

    /**
     * @param AttributeCore $lib
     */
    public function __construct(AttributeCore $lib)
    {
        $this->lib = $lib;
    }

    /**
     * 列表页数据
     *
     * @param int $catId
     * @return array
     */
    public function buildAttributeListData($catId = 0)
    {
        $rows = Attribute::filterByProductCategory((int) $catId)
            ->applyDefaultOrder()
            ->get();

        $list = array();
        foreach ($rows as $model) {
            $row = $model->toArray();
            $catName = DB::table('product_category')->where('id', (int) $row['category_id'])->value('name');
            $list[] = array(
                'att_id' => $row['id'],
                'category_id' => $row['category_id'],
                'category_name' => $catName ? $catName : lang('attribute_category_all'),
                'name' => $row['name'],
                'type' => lang('attribute_type_' . $row['type']),
                'sort' => $row['sort'],
            );
        }

        return array(
            'attribute_list' => $list,
        );
    }

    /**
     * 添加页数据
     *
     * @return array product_category, form_action, token
     */
    public function buildAttributeDefaultData()
    {
        return array(
            'product_category' => ProductCategory::flat(),
            'attribute' => array(
                'att_id' => '',
                'name' => '',
                'category_id' => 0,
                'type' => 'text',
                'sort' => 50,
            ),
        );
    }

    /**
     * 编辑页数据
     *
     * @param string $att_id
     * @return array|null attribute, product_category, form_action, token
     */
    public function buildAttributeEditData($attId)
    {
        $attId = (int) $attId;
        if ($attId <= 0) {
            return null;
        }
        $attributeModel = Attribute::find($attId);
        $attribute = $attributeModel ? $attributeModel->getAttributes() : null;
        if (!$attribute) {
            return null;
        }
        $attribute['att_id'] = (int) $attribute['id'];

        return array(
            'attribute' => $attribute,
            'product_category' => ProductCategory::flat(),
        );
    }

    /**
     * 新增提交
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('attribute', $data, 0, $adminId);

        $attId = (int) DB::transaction(function () use ($data, $adminId, $languagePlan) {
            $model = Attribute::create($data);
            $attId = (int) $model->getKey();
            if ($attId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }
            $this->languageDraftCommit($languagePlan, $attId, $adminId);

            return $attId;
        });

        $this->languageDraftPurge($languagePlan, $attId);
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $attId;
    }

    /**
     * 更新提交
     *
     * @param array $post
     * @return void
     */
    public function update(array $data)
    {
        $attId = isset($data['att_id']) ? (int) $data['att_id'] : 0;
        if ($attId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }

        $attribute = Attribute::find($attId);
        if (!$attribute) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }

        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('attribute', $data, $attId, $adminId);

        try {
            DB::transaction(function () use ($attribute, $data, $attId, $adminId, $languagePlan) {
                $attribute->fill($data, 'update')->save();
                $this->languageDraftCommit($languagePlan, $attId, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $attId);
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * 删除处理（支持确认跳转）
     *
     * @param int|string $attId
     * @param array $data
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 参数不合法 / 存在子属性值时抛出
     */
    public function delete($attId, array $data)
    {
        $attId = (int) $attId;
        if ($attId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }

        $attributeModel = Attribute::find($attId, 'id,name');
        $attribute = $attributeModel ? $attributeModel->getAttributes() : null;
        if (!$attribute) {
            throw new DomainException(lang('illegal'), route('admin.attribute'));
        }

        if (Attribute::hasValueRecords($attId)) {
            $name = $attribute['name'];
            $msg = preg_replace('/d%/Ums', $name, lang('attribute_del_is_parent'));
            throw new DomainException($msg, route('admin.attribute'), '3');
        }

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $attribute['name']);
            Attribute::destroy($attId);
            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.attribute'),
            );
        }

        $msg = preg_replace('/d%/Ums', $attribute['name'], lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.attribute'),
            'timeout' => '30',
            'confirm_url' => route('admin.attribute.destroy', array('id' => $attId)),
        );
    }

    /**
     * 属性区 HTML（新增页/编辑页首屏与新增页分类切换共用）。
     *
     * @param string $module
     * @param int|string $categoryId
     * @param int|string $itemId create 模式传 0
     * @param string $mode create|edit
     * @return string
     */
    public function buildBoxHtml($module, $categoryId = 0, $itemId = 0, $mode = 'edit')
    {
        $module = (string) $module;
        $categoryId = (int) $categoryId;
        $itemId = (int) $itemId;
        $mode = $mode === 'create' ? 'create' : 'edit';

        $html = '<div id="attributeBox" class="attribute" data-mode="' . $mode . '"'
            . ' data-module="' . $this->escape($this->moduleName($module)) . '">';

        if ($mode === 'edit') {
            // 译文隐藏域放在 <ul> 之外：AJAX 增删选项只替换 <ul>，未提交的译文不会丢失
            foreach ($this->lib->getValueTranslations($module, $itemId) as $langRow) {
                $html .= '<input type="hidden" name="attribute_value_lang[' . (int) $langRow['item_id'] . ']'
                    . '[' . $this->escape($langRow['language_pack']) . ']"'
                    . ' value="' . $this->escape($langRow['value']) . '" />';
            }
            $list = $this->lib->getAttributeList($module, $categoryId, $itemId, 'html');
        } else {
            $list = $this->lib->getCreateAttributeList($module, $categoryId);
        }

        foreach ($list as $attribute) {
            $attId = (int) $attribute['att_id'];
            $html .= '<div class="att-head">' . $this->escape($attribute['name']) . '</div>'
                . '<div class="att-action">'
                . '<input type="text" id="' . $attId . 'Value" class="input" size="10" placeholder="' . $this->escape(lang('attribute_value_value')) . '" />'
                . '<input type="text" id="' . $attId . 'Remark" class="input" size="10" maxlength="60" placeholder="' . $this->escape(lang('attribute_value_remark')) . '" />'
                . '<input type="text" id="' . $attId . 'PriceChange" class="input" size="3" placeholder="' . $this->escape(lang('attribute_value_price_change')) . '" />';

            if ($mode === 'edit') {
                $addJs = "attAction('add', '" . $this->moduleName($module) . "', '" . $itemId . "', '" . $attId . "');";
                $html .= '<a href="javascript:;" class="btn-att" onclick="' . $addJs . '">' . $this->escape(lang('add')) . '</a>'
                    . '</div>'
                    . '<ul id="' . $attId . 'Html" class="att-value">' . $attribute['value_list'] . '</ul>';
                continue;
            }

            $html .= '<a href="javascript:;" class="btn-att" onclick="attDraftAdd(\'' . $attId . '\');">' . $this->escape(lang('add')) . '</a>'
                . '</div>'
                . '<ul id="' . $attId . 'Html" class="att-value"></ul>'
                . $attribute['chip_template'];
        }

        return $html . '</div>';
    }

    /**
     * 新增页分类切换：返回该分类可用的属性区 HTML（route=attribute/box）。
     *
     * @param array $requestData
     * @return void
     */
    public function processBoxAjax(array $requestData)
    {
        $module = isset($requestData['module']) ? (string) $requestData['module'] : 'product';
        $categoryId = isset($requestData['category_id']) ? (int) $requestData['category_id'] : 0;
        if (!Check::letter($module)) {
            ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
        }

        ApiResponse::throwSuccess(array('html' => $this->buildBoxHtml($module, $categoryId, 0, 'create')));
    }

    /**
     * 属性值 AJAX 操作（route=attribute/value）
     *
     * 响应统一遵循 {@see ApiResponse} 五段包络：
     *   - 成功：throwSuccess(['html' => 列表片段])；
     *   - 校验失败但仍需回传片段：throwError('VALIDATION_FAILED', $msg, [], 422, ['html' => $html])；
     *   - 参数非法（无片段）：throwError('INVALID_PARAMS', lang('illegal'))。
     *
     * @param string $act
     * @param array $requestData
     * @return void
     */
    public function processValueAjax($act, array $requestData)
    {
        if ($act === 'add') {
            $params = $this->validateAjaxCommonParams($requestData);
            $attributeModel = Attribute::find($params['att_id']);
            $attribute = $attributeModel ? $attributeModel->getAttributes() : null;
            if (!$attribute) {
                ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
            }

            $value = Arr::get($requestData, 'value', '');
            $remark = Arr::get($requestData, 'remark', '');
            $priceChangeRaw = Arr::get($requestData, 'price_change', '');
            $priceChange = preg_match('/^[0-9-]+$/', $priceChangeRaw) ? $priceChangeRaw : '';

            $message = '';
            if ($value === '') {
                $message = lang('attribute_value_value') . lang('is_empty');
            } else {
                $hasExisting = AttributeValue::where('att_id', $params['att_id'])
                    ->where('module', $params['module'])
                    ->where('item_id', $params['item_id'])
                    ->first();

                if (!$hasExisting && $priceChange > 0) {
                    $message = lang('attribute_value_price_cue');
                } else {
                    $exists = AttributeValue::where('value', $value)
                        ->where('module', $params['module'])
                        ->where('item_id', $params['item_id'])
                        ->where('att_id', $params['att_id'])
                        ->first();
                    if (!$exists) {
                        AttributeValue::create(array(
                            'module' => $params['module'],
                            'item_id' => $params['item_id'],
                            'att_id' => $params['att_id'],
                            'type' => $attribute['type'],
                            'value' => $value,
                            'remark' => $remark,
                            'price_change' => $priceChange,
                        ));
                    } else {
                        $message = $attribute['name'] . lang('is_exist');
                    }
                }
            }

            $html = $this->lib->getValueList($params['module'], $params['item_id'], $params['att_id'], 'html');
            if ($message !== '') {
                ApiResponse::throwError(ApiCodes::VALIDATION_FAILED, $message, array(), 422, array('html' => $html));
            }
            ApiResponse::throwSuccess(array('html' => $html));
        }

        if ($act === 'del') {
            $params = $this->validateAjaxCommonParams($requestData);
            $valueId = (int) Arr::get($requestData, 'value_id', 0);

            if ($valueId > 0) {
                $valueModel = AttributeValue::find($valueId);
                $value = $valueModel ? $valueModel->getAttributes() : null;
                if (!$value
                    || (string) $value['module'] !== $params['module']
                    || (int) $value['item_id'] !== $params['item_id']
                    || (int) $value['att_id'] !== $params['att_id']) {
                    ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
                }

                attachment()->delete($value['image']);
                AttributeValue::whereKey($valueId)->delete();
                language()->deleteLang('attribute_value', $valueId);
            } else {
                // 兼容旧版 JS：按 value 文本删除，并同步清理对应译文行
                $delValue = Arr::get($requestData, 'del_value', '');
                if (Check::illegalChar($delValue)) {
                    ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
                }

                $valueModels = AttributeValue::where('module', $params['module'])
                    ->where('item_id', $params['item_id'])
                    ->where('att_id', $params['att_id'])
                    ->where('value', $delValue)
                    ->get();
                foreach ($valueModels as $valueModel) {
                    $row = $valueModel->getAttributes();
                    attachment()->delete($row['image']);
                    AttributeValue::whereKey((int) $row['id'])->delete();
                    language()->deleteLang('attribute_value', (int) $row['id']);
                }
            }

            // 删除首条后，新首条若带正加价，在此写路径显式归零（读取路径不再做清零副作用）
            $firstModel = AttributeValue::where('module', $params['module'])
                ->where('item_id', $params['item_id'])
                ->where('att_id', $params['att_id'])
                ->order('id ASC')
                ->first();
            if ($firstModel) {
                $firstRow = $firstModel->getAttributes();
                if ((float) $firstRow['price_change'] > 0) {
                    AttributeValue::whereKey((int) $firstRow['id'])->update(array('price_change' => '0'));
                }
            }

            $html = $this->lib->getValueList($params['module'], $params['item_id'], $params['att_id'], 'html');
            ApiResponse::throwSuccess(array('html' => $html));
        }

        if ($act === 'image') {
            $valueId = (int) Arr::get($requestData, 'value_id', 0);
            if ($valueId <= 0) {
                ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
            }

            $valueModel = AttributeValue::find($valueId);
            $value = $valueModel ? $valueModel->getAttributes() : null;
            if (!$value) {
                ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
            }

            $image = attachment()->store('attribute_value', $value['item_id'], UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withImageWidth(500)->withWatermark(Config::get('site.watermark', false))->withUploader('admin', (int) auth('admin')->id()));
            AttributeValue::whereKey($valueId)->update(array('image' => $image));
            $html = $this->lib->getValueList($value['module'], $value['item_id'], $value['att_id'], 'html');
            ApiResponse::throwSuccess(array('html' => $html));
        }

        ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
    }

    /**
     * 解析商品表单里的规格选项与译文，生成待提交计划。
     *
     * create（$itemId=0）：收集 attribute_value 选项；update：只收集 attribute_value_lang 译文。
     *
     * @param string $module
     * @param array $data
     * @param int|string $itemId
     * @return array|null
     * @throws DomainException 属性不属于当前商品分类
     */
    public function beginPending($module, array $data, $itemId = 0)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        $plan = array(
            'module' => $module,
            'mode' => $itemId > 0 ? 'update' : 'create',
            'options' => array(),
            'translations' => array(),
        );

        if ($itemId <= 0) {
            $plan['options'] = $this->parsePendingOptions($module, $data);
        }
        $plan['translations'] = $this->parsePendingTranslations($module, $data, $itemId, $plan['options']);

        if (empty($plan['options']) && empty($plan['translations'])) {
            return null;
        }

        return $plan;
    }

    /**
     * 事务内提交计划：create 插入选项行并写译文；update 只写译文。
     *
     * @param array|null $plan
     * @param int|string $itemId
     * @return void
     * @throws DomainException 选项插入失败
     */
    public function commitPending($plan, $itemId)
    {
        if (empty($plan)) {
            return;
        }
        $itemId = (int) $itemId;
        if ($itemId <= 0) {
            return;
        }

        $module = (string) $plan['module'];
        $map = array();

        if ($plan['mode'] === 'create') {
            foreach ($plan['options'] as $attId => $options) {
                foreach ($options as $option) {
                    $model = AttributeValue::create(array(
                        'module' => $module,
                        'item_id' => $itemId,
                        'att_id' => (int) $attId,
                        'type' => $option['type'],
                        'value' => $option['value'],
                        'remark' => $option['remark'],
                        'price_change' => $option['price_change'],
                    ));
                    $valueId = $model ? (int) $model->getKey() : 0;
                    if ($valueId <= 0) {
                        throw new DomainException(lang('operation_failed'));
                    }
                    $map['pending:' . (int) $attId . ':' . $option['idx']] = $valueId;
                }
            }
        }

        foreach ($plan['translations'] as $key => $packs) {
            if (isset($map[$key])) {
                $valueId = $map[$key];
            } elseif (strpos($key, 'value:') === 0) {
                $valueId = (int) substr($key, 6);
            } else {
                continue;
            }

            foreach ($packs as $pack => $text) {
                $this->languageDraftService()->saveTranslation('attribute_value', $valueId, $pack, 'value', $text, 'text');
            }
        }

        // 译文写入后清掉请求内缓存，避免同请求后续读取到旧值
        AttributeCore::clearValueLangCache();
    }

    /**
     * create 模式：解析 attribute_value（归属、分类、去重、首项加价）。
     *
     * @param string $module
     * @param array $data
     * @return array att_id => 选项列表
     * @throws DomainException
     */
    private function parsePendingOptions($module, array $data)
    {
        $raw = isset($data['attribute_value']) && is_array($data['attribute_value']) ? $data['attribute_value'] : array();
        if (empty($raw)) {
            return array();
        }

        $attIds = array();
        foreach ($raw as $attId => $options) {
            if ((int) $attId > 0 && is_array($options)) {
                $attIds[] = (int) $attId;
            }
        }
        if (empty($attIds)) {
            return array();
        }

        // 未分类（0）沿用 core 语义：全部属性适用；非 0 时限定 0 + 自身 + 祖先分类
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : 0;
        $allowedCategories = array();
        if ($categoryId > 0) {
            $allowedCategories = array_merge(array(0, $categoryId), CategoryIds::ancestors($module . '_category', $categoryId));
        }

        $attributeMap = array();
        $rows = DB::table('attribute')
            ->field('id, category_id, type')
            ->where('module', $module)
            ->where('id', 'IN', array_values(array_unique($attIds)))
            ->select();
        foreach ((array) $rows as $row) {
            $attributeMap[(int) $row['id']] = $row;
        }

        $result = array();
        foreach ($raw as $attId => $options) {
            $attId = (int) $attId;
            if ($attId <= 0 || !is_array($options)) {
                continue;
            }

            $hasValue = false;
            foreach ($options as $option) {
                if (is_array($option) && isset($option['value']) && is_scalar($option['value']) && (string) $option['value'] !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if (!$hasValue) {
                continue;
            }

            if (!isset($attributeMap[$attId])
                || (!empty($allowedCategories) && !in_array((int) $attributeMap[$attId]['category_id'], $allowedCategories, true))) {
                throw new DomainException(lang('attribute_value_category_mismatch', '存在不属于当前商品分类的规格选项，请切换分类或移除对应选项'));
            }

            $seen = array();
            foreach ($options as $idx => $option) {
                if (!is_array($option)) {
                    continue;
                }
                $value = isset($option['value']) && is_scalar($option['value']) ? (string) $option['value'] : '';
                if ($value === '' || isset($seen[$value])) {
                    continue;
                }
                $seen[$value] = true;

                $remark = isset($option['remark']) && is_scalar($option['remark']) ? (string) $option['remark'] : '';
                $priceChange = isset($option['price_change']) && is_scalar($option['price_change']) ? (string) $option['price_change'] : '';
                $priceChange = preg_match('/^[0-9-]+$/', $priceChange) ? $priceChange : '';
                if (empty($result[$attId]) && (float) $priceChange > 0) {
                    $priceChange = '0';
                }

                $result[$attId][] = array(
                    'idx' => (string) $idx,
                    'value' => $value,
                    'remark' => $remark,
                    'price_change' => $priceChange,
                    'type' => (string) $attributeMap[$attId]['type'],
                );
            }
        }

        return $result;
    }

    /**
     * 解析 attribute_value_lang：update 键为 value_id，create 键为 att_id:idx。
     *
     * @param string $module
     * @param array $data
     * @param int|string $itemId
     * @param array $options
     * @return array key => [pack => text]
     */
    private function parsePendingTranslations($module, array $data, $itemId, array $options)
    {
        if (!Config::get('features.language', false)) {
            return array();
        }

        $raw = isset($data['attribute_value_lang']) && is_array($data['attribute_value_lang'])
            ? $data['attribute_value_lang']
            : array();
        if (empty($raw)) {
            return array();
        }

        $defaultPack = (string) Config::get('site.language.default', 'zh_cn');
        $translations = array();

        if ((int) $itemId > 0) {
            $valueIds = array();
            foreach ($raw as $valueId => $packs) {
                if ((int) $valueId > 0 && is_array($packs)) {
                    $valueIds[] = (int) $valueId;
                }
            }

            $owned = array();
            if (!empty($valueIds)) {
                $rows = DB::table('attribute_value')
                    ->field('id')
                    ->where('module', $module)
                    ->where('item_id', (int) $itemId)
                    ->where('id', 'IN', array_values(array_unique($valueIds)))
                    ->select();
                foreach ((array) $rows as $row) {
                    $owned[(int) $row['id']] = true;
                }
            }

            foreach ($raw as $valueId => $packs) {
                $valueId = (int) $valueId;
                if (!isset($owned[$valueId]) || !is_array($packs)) {
                    continue;
                }
                $cleaned = $this->cleanPacks($packs, $defaultPack);
                if (!empty($cleaned)) {
                    $translations['value:' . $valueId] = $cleaned;
                }
            }

            return $translations;
        }

        $pending = array();
        foreach ($options as $attId => $list) {
            foreach ($list as $option) {
                $pending[(int) $attId . ':' . $option['idx']] = true;
            }
        }

        foreach ($raw as $attId => $indexes) {
            $attId = (int) $attId;
            if (!is_array($indexes)) {
                continue;
            }
            foreach ($indexes as $idx => $packs) {
                $key = $attId . ':' . (string) $idx;
                if (!isset($pending[$key]) || !is_array($packs)) {
                    continue;
                }
                $cleaned = $this->cleanPacks($packs, $defaultPack);
                if (!empty($cleaned)) {
                    $translations['pending:' . $key] = $cleaned;
                }
            }
        }

        return $translations;
    }

    /**
     * @param array $packs
     * @param string $defaultPack
     * @return array
     */
    private function cleanPacks(array $packs, $defaultPack)
    {
        $cleaned = array();
        foreach ($packs as $pack => $text) {
            $pack = (string) $pack;
            if ($pack === $defaultPack || !Check::languagePack($pack)) {
                continue;
            }
            $cleaned[$pack] = is_scalar($text) ? (string) $text : '';
        }

        return $cleaned;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 模块名仅允许字母数字下划线（供内联 onclick 使用）。
     *
     * @param mixed $module
     * @return string
     */
    private function moduleName($module)
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $module);
    }

    /**
     * @param array $requestData
     * @return array
     */
    private function validateAjaxCommonParams(array $requestData)
    {
        $module = Arr::get($requestData, 'module', '');
        $itemId = (int) Arr::get($requestData, 'item_id', 0);
        $attId = (int) Arr::get($requestData, 'att_id', 0);

        if (!Check::letter($module) || $itemId <= 0 || $attId <= 0) {
            ApiResponse::throwError(ApiCodes::INVALID_PARAMS, lang('illegal'));
        }

        return array(
            'module' => $module,
            'item_id' => $itemId,
            'att_id' => $attId,
        );
    }
}
