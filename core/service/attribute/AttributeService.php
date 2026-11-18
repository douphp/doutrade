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

namespace Dou\Core\Service\Attribute;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\CategoryIds;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}
class AttributeService extends BaseService
{
    /** @var array 请求内缓存：module|itemId => language_value 行（选项值译文） */
    private static $valueLangCache = array();

    /**
     * 执行__construct操作。
     *
     * @return void 返回结果。
     */
    public function __construct()
    {
    }
    /**
     * 执行getAttributeList操作。
     *
     * @param mixed $module 参数module。
     * @param string $category_id 分类 ID。
     * @param string $item_id 参数item_id。
     * @param string $mode 参数mode。
     * @param array $attribute_data 参数attribute_data。
     * @return mixed 返回结果。
     */
    public function getAttributeList($module, $category_id = '', $item_id = '', $mode = 'common', $attribute_data = array())
    {
        $query = DB::table('attribute')->where('module', $module);
        $attribute_list = array();
        if ($category_id) {
            $category_ids = array_merge([0, (int) $category_id], CategoryIds::ancestors($module . '_category', $category_id));
            $query->where('category_id', 'IN', $category_ids);
        }
        $rows = $query->order('sort ASC, id ASC')->select();

        // 商品已有属性值、但属性分类不在商品分类链时补查合并，避免前台看不到既有规格
        // （仅补有值的属性，且不影响无值属性的原有过滤行为）
        if ($item_id) {
            $ownedAttIds = array();
            foreach ((array) DB::table('attribute_value')
                ->field('att_id')
                ->where('module', $module)
                ->where('item_id', intval($item_id))
                ->select() as $valueRow) {
                $ownedAttIds[(int) $valueRow['att_id']] = true;
            }
            $existingIds = array();
            foreach ((array) $rows as $row) {
                $existingIds[(int) $row['id']] = true;
            }
            $missingIds = array();
            foreach (array_keys($ownedAttIds) as $attId) {
                if ($attId > 0 && !isset($existingIds[$attId])) {
                    $missingIds[] = $attId;
                }
            }
            if (!empty($missingIds)) {
                $missingRows = DB::table('attribute')
                    ->where('module', $module)
                    ->whereIn('id', $missingIds)
                    ->select();
                $rows = array_merge((array) $rows, (array) $missingRows);
                usort($rows, function ($a, $b) {
                    $sortA = (int) $a['sort'];
                    $sortB = (int) $b['sort'];
                    if ($sortA === $sortB) {
                        return (int) $a['id'] - (int) $b['id'];
                    }
                    return $sortA - $sortB;
                });
            }
        }

        // 属性名称支持多语言：循环前批量预热，循环内按当前语言覆写；
        // 后台/API 端 locale 未激活时 langValue 直接返回原值，无副作用。
        language()->warmup('attribute', array_column((array) $rows, 'id'), 'name');

        // 选项值多语言：前台按商品一次预热全部选项，避免逐个 getValueList 单查。
        if ($mode === 'common' && $item_id) {
            $value_rows = DB::table('attribute_value')
                ->field('id')
                ->where('module', $module)
                ->where('item_id', intval($item_id))
                ->select();
            language()->warmup('attribute_value', array_column((array) $value_rows, 'id'), 'value');
        }

        foreach ((array) $rows as $row) {
            $value_list = $item_id ? $this->getValueList($module, $item_id, $row['id'], $mode, $attribute_data) : array();
            $fromAttr = '';
            if (isset($attribute_data[$row['id']])) {
                $fromAttr = $attribute_data[$row['id']];
            }

            $selected_value_id = $fromAttr
                ? $fromAttr
                : ((is_array($value_list) && isset($value_list[0]) && isset($value_list[0]['id'])) ? $value_list[0]['id'] : '');

            if (defined('IS_ADMIN') || (!defined('IS_ADMIN') && $value_list)) {
                $attribute_list[] = array(
                    "att_id" => $row['id'],
                    "category_id" => $row['category_id'],
                    "name" => language()->langValue($row['name'], 'attribute', $row['id'], 'name'),
                    "type" => $row['type'],
                    "value_list" => $value_list,
                    "selected_value_id" => $selected_value_id,
                    "selected_value_price_change" => $item_id
                        ? DB::table('attribute_value')
                            ->where('module', $module)
                            ->where('item_id', intval($item_id))
                            ->where('att_id', intval($row['id']))
                            ->where('id', intval($selected_value_id))
                            ->value('price_change')
                        : ''
                );
            }
        }

        return $attribute_list;
    }

    /**
     * 执行getValueList操作。
     *
     * @param mixed $module 参数module。
     * @param mixed $item_id 参数item_id。
     * @param mixed $att_id 参数att_id。
     * @param string $mode 参数mode。
     * @param array $attribute_data 参数attribute_data。
     * @return mixed 返回结果。
     */
    public function getValueList($module, $item_id, $att_id, $mode = 'common', $attribute_data = array())
    {
        $rows = DB::table('attribute_value')
            ->where('module', $module)
            ->where('item_id', intval($item_id))
            ->where('att_id', intval($att_id))
            ->order('id ASC')
            ->select();
        $i = 1;
        $value_list = $mode == 'html' ? '' : array();
        $lang_buttons = $mode == 'html' ? $this->buildValueLangButtons($module, $item_id, (array) $rows) : array();
        foreach ((array) $rows as $row) {
            // 首个选项不加价的约束只在后台写路径归一化（新增 / 保存 / 删除），
            // 读取仅做展示，禁止在读接口产生 UPDATE 副作用。

            // price_change 始终按基准币存储；common 模式（前台/API）另给出当前展示币数值与文案
            $price_change_format = $row['price_change'] == 0 ? '' : ' ' . ($row['price_change'] > 0 ? '+' . $row['price_change'] : $row['price_change']);
            $price_change_display = 0;
            $price_change_display_format = '';
            if ($mode != 'html' && $row['price_change'] != 0) {
                $price_change_display = currency()->fromBase($row['price_change']);
                $price_change_display_format = ' ' . ($price_change_display > 0 ? '+' : '-')
                    . currency()->formatIn(abs($price_change_display), currency()->currentContext());
            }

            if ($mode == 'html') {
                $image = '';
                if ($row['type'] == 'image') {
                    if ($row['image']) {
                        $image = '<span class="img" onclick="' . "attImage('$att_id', '$row[id]');" . '"><img src="' . $this->escape(attachment()->url($row['image'])) . '"/></span>';
                    } else {
                        $image = '<span class="upload" onclick="' . "attImage('$att_id', '$row[id]');" . '"><i class="fa fa-image"></i></span>';
                    }
                }

                $buttons = isset($lang_buttons[$row['id']]) ? $lang_buttons[$row['id']] : '';
                $del_js = "attAction('del', '" . $this->moduleName($module) . "', '" . (int) $item_id . "', '" . (int) $att_id . "', '" . (int) $row['id'] . "');";
                $value_list .= '<li data-id="' . (int) $row['id'] . '" title="' . $this->escape($row['remark']) . '">'
                    . $image
                    . '<span class="value">' . $this->escape($row['value']) . $price_change_format . '</span>'
                    . '<span class="lang">' . $buttons . '</span>'
                    . '<input type="hidden" name="attribute_value_source[' . (int) $row['id'] . ']" value="' . $this->escape($row['value']) . '" />'
                    . '<span onclick="' . $del_js . '" class="del">X</span></li>';
            } else {
                if (isset($attribute_data[$att_id]) && $attribute_data[$att_id]) {
                    $cur = $row['id'] == $attribute_data[$att_id] ? true : false;
                } else {
                    $cur = $i == 1 ? true : false;
                }

                $value_list[] = array(
                    "id" => $row['id'],
                    "value" => language()->langValue($row['value'], 'attribute_value', $row['id'], 'value'),
                    "json" => json_encode(array("id" => $row['id'], "value" => $row['value'], "price_change" => $row['price_change'])),
                    "image" => attachment()->url($row['image']),
                    "remark" => $row['remark'],
                    "price_change" => $row['price_change'],
                    "price_change_display" => $price_change_display,
                    "price_change_format" => $price_change_display_format,
                    "cur" => $cur
                );
            }

            $i++;
        }

        return $value_list;
    }

    /**
     * 新增页属性列表：全部属性（按分类过滤）+ 每个属性的 chip 模板。
     *
     * @param string $module
     * @param int|string $category_id
     * @return array
     */
    public function getCreateAttributeList($module, $category_id = 0)
    {
        // 与全站既有语义一致：category_id 为空（未分类）时适用全部属性，非空时由 getAttributeList 按分类+祖先过滤
        $list = $this->getAttributeList($module, $category_id, 0, 'common');
        $result = array();
        foreach ($list as $item) {
            $item['value_list'] = '';
            $item['chip_template'] = $this->buildChipTemplate($item['att_id']);
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 某商品全部选项值的翻译行（排除默认包/非法包/空值，同键取最新）。
     *
     * @param string $module
     * @param int|string $item_id
     * @return array
     */
    public function getValueTranslations($module, $item_id)
    {
        return $this->valueLangRows($module, $item_id);
    }

    /**
     * 清空选项值译文请求内缓存（CLI 烟测等单进程多次写入场景使用）。
     *
     * @return void
     */
    public static function clearValueLangCache()
    {
        self::$valueLangCache = array();
    }

    /**
     * 生成单个选项 chip 的语言按钮。
     *
     * @param string $module
     * @param int|string $item_id
     * @param array $rows attribute_value 行
     * @return array value_id => HTML
     */
    private function buildValueLangButtons($module, $item_id, array $rows)
    {
        $buttons = array();
        if (!defined('IS_ADMIN') || !Config::get('features.language', false) || !method_exists(language(), 'buildLangButtons')) {
            return $buttons;
        }

        $defaultPack = (string) Config::get('site.language.default', 'zh_cn');
        $hasValue = array();
        foreach ($this->valueLangRows($module, $item_id) as $langRow) {
            $hasValue[(int) $langRow['item_id']][(string) $langRow['language_pack']] = true;
        }

        foreach ($rows as $row) {
            $valueId = (int) $row['id'];
            $packs = isset($hasValue[$valueId]) ? $hasValue[$valueId] : array();
            $btns = language()->buildLangButtons('attribute_value', $valueId, 'value', array(
                'draft' => true,
                'exclude_pack' => $defaultPack,
                // 未添加译文时弹框保持为空，不代入主语言选项值
                'blank_when_missing' => true,
                'has_value' => array('value' => $packs),
                'labels' => array('value' => lang('attribute_value_value')),
                'targets' => array('value' => array(
                    'kind' => 'text',
                    'target' => 'attribute_value_lang[' . $valueId . '][%s]',
                    'source' => 'attribute_value_source[' . $valueId . ']',
                    // buildLangButtons 不替换 trigger_id 中的 %s，留空可避免同一 chip 多语言按钮 id 重复
                    'trigger_id' => '',
                )),
            ));
            $buttons[$valueId] = isset($btns['value']) ? $btns['value'] : '';
        }

        return $buttons;
    }

    /**
     * 新增页 chip 模板（JS 克隆后替换 __IDX__）。
     *
     * @param int|string $att_id
     * @return string
     */
    private function buildChipTemplate($att_id)
    {
        $att_id = (int) $att_id;
        $buttons = '';
        if (defined('IS_ADMIN') && Config::get('features.language', false) && method_exists(language(), 'buildLangButtons')) {
            $btns = language()->buildLangButtons('attribute_value', 0, 'value', array(
                'draft' => true,
                'draft_create' => true,
                'exclude_pack' => (string) Config::get('site.language.default', 'zh_cn'),
                'blank_when_missing' => true,
                'labels' => array('value' => lang('attribute_value_value')),
                'targets' => array('value' => array(
                    'kind' => 'text',
                    'target' => 'attribute_value_lang[' . $att_id . '][__IDX__][%s]',
                    'source' => 'attribute_value[' . $att_id . '][__IDX__][value]',
                    'trigger_id' => '',
                )),
            ));
            $buttons = isset($btns['value']) ? $btns['value'] : '';
        }

        return '<script type="text/html" class="att-chip-tpl" id="att_' . $att_id . '_Tpl">'
            . '<li class="att-pending" data-att-id="' . $att_id . '" data-index="__IDX__" data-value="">'
            . '<span class="value"></span>'
            . '<span class="lang">' . $buttons . '</span>'
            . '<span class="del" onclick="attDraftDel(this);">X</span>'
            . '<input type="hidden" name="attribute_value[' . $att_id . '][__IDX__][value]" value="" />'
            . '<input type="hidden" name="attribute_value[' . $att_id . '][__IDX__][remark]" value="" />'
            . '<input type="hidden" name="attribute_value[' . $att_id . '][__IDX__][price_change]" value="" />'
            . '</li></script>';
    }

    /**
     * 选项值译文行（请求内缓存），供 chip 的 cur 判定与编辑页隐藏域共用。
     *
     * @param string $module
     * @param int|string $item_id
     * @return array
     */
    private function valueLangRows($module, $item_id)
    {
        $module = (string) $module;
        $item_id = (int) $item_id;
        $cacheKey = $module . '|' . $item_id;
        if (isset(self::$valueLangCache[$cacheKey])) {
            return self::$valueLangCache[$cacheKey];
        }

        $rows = array();
        if ($item_id > 0 && Config::get('features.language', false)) {
            $valueRows = DB::table('attribute_value')
                ->field('id')
                ->where('module', $module)
                ->where('item_id', $item_id)
                ->select();
            $valueIds = array_map('intval', array_column((array) $valueRows, 'id'));
            if (!empty($valueIds)) {
                $langRows = DB::table('language_value')
                    ->field('item_id, language_pack, value')
                    ->where('module', 'attribute_value')
                    ->where('field', 'value')
                    ->where('item_id', 'IN', $valueIds)
                    ->order('id DESC')
                    ->select();
                $defaultPack = (string) Config::get('site.language.default', 'zh_cn');
                foreach ((array) $langRows as $langRow) {
                    $pack = (string) $langRow['language_pack'];
                    if ($pack === $defaultPack || !Check::languagePack($pack) || (string) $langRow['value'] === '') {
                        continue;
                    }
                    $key = (int) $langRow['item_id'] . '|' . $pack;
                    if (isset($rows[$key])) {
                        continue;
                    }
                    $rows[$key] = $langRow;
                }
            }
        }

        return self::$valueLangCache[$cacheKey] = array_values($rows);
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
     * 模块名仅允许字母数字下划线，供内联 onclick 使用。
     *
     * @param mixed $module
     * @return string
     */
    private function moduleName($module)
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $module);
    }
}
