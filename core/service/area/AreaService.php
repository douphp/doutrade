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

namespace Dou\Core\Service\Area;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class AreaService extends BaseService
{
    /** 数据分区固定两项，取值为语言包目录名 */
    const GROUPS = array('zh_cn', 'en_us');

    /** 默认数据分区 */
    const DEFAULT_GROUP = 'zh_cn';

    /** @var array|null 全部地区缓存 */
    private $allAreasCache = null;

    /** @var array|null 按 parent_id 分组缓存 */
    private $areasByParentCache = null;

    /** @var string|null 当前数据分区 */
    private $groupCache = null;

    /** @var bool|null 表结构是否具备分区列 */
    private $langEnabledCache = null;

    /**
     * 执行__construct操作。
     *
     * @return void 返回结果。
     */
    public function __construct()
    {
    }

    /**
     * 指定数据分区（后台切换分区时使用），非法值回落默认分区。
     *
     * @param string $group
     * @return $this
     */
    public function setGroup($group)
    {
        $group = (string) $group;
        $this->groupCache = in_array($group, self::GROUPS, true) ? $group : self::DEFAULT_GROUP;
        $this->allAreasCache = null;
        $this->areasByParentCache = null;

        return $this;
    }

    /**
     * 当前请求使用的数据分区。
     *
     * 只识别中文 / 英文两个分区：中文系语言（zh_cn / zh_tw 等）对应 zh_cn 分区，
     * 其它所有语言对应 en_us 分区；当前语言取语言选择 → site.language.default。
     *
     * @return string
     */
    public function group()
    {
        if ($this->groupCache !== null) {
            return $this->groupCache;
        }

        $this->groupCache = self::DEFAULT_GROUP;
        if (!$this->langEnabled()) {
            return $this->groupCache;
        }

        $pack = (string) locale()->pack();
        if ($pack === '') {
            $pack = (string) Config::get('site.language.default', '');
        }
        $this->groupCache = self::languageGroup($pack);

        return $this->groupCache;
    }

    /**
     * 语言 → 数据分区映射：中文系（zh 前缀，如 zh_cn / zh_tw）归 zh_cn 分区，
     * 其它所有语言归 en_us 分区；未指定语言时回落默认分区。
     *
     * @param string $pack
     * @return string
     */
    public static function languageGroup($pack)
    {
        $pack = strtolower(trim((string) $pack));
        if ($pack === '' || substr($pack, 0, 2) === 'zh') {
            return self::DEFAULT_GROUP;
        }

        return 'en_us';
    }

    /**
     * 表结构是否已具备分区列（未升级的旧库退化为单分区）。
     *
     * @return bool
     */
    private function langEnabled()
    {
        if ($this->langEnabledCache === null) {
            $this->langEnabledCache = DB::fieldExist('area', 'lang');
        }

        return $this->langEnabledCache;
    }

    /**
     * 执行loadAllAreas操作。
     *
     * @return void 返回结果。
     */
    private function loadAllAreas()
    {
        if ($this->allAreasCache === null) {
            $query = DB::table('area')->field('*');
            if ($this->langEnabled()) {
                $query->where('lang', $this->group());
            }
            $this->allAreasCache = $query
                ->order('sort ASC, id ASC')
                ->select();

            // 按 parent_id 分组
            $this->areasByParentCache = array();
            foreach ((array) $this->allAreasCache as $area) {
                $this->areasByParentCache[$area['parent_id']][] = $area;
            }
        }
    }

    /**
     * 当前分区的顶层节点（国家层）。
     *
     * @return array
     */
    public function getRoots()
    {
        $this->loadAllAreas();

        return isset($this->areasByParentCache[0]) ? $this->areasByParentCache[0] : array();
    }

    /**
     * 当前分区是否只有一个顶层节点（单国家场景）。
     *
     * @return bool
     */
    public function hasSingleRoot()
    {
        return count($this->getRoots()) === 1;
    }

    /**
     * 单国家场景下的唯一顶层 id；多国家或无限国家时返回 0。
     *
     * @return int
     */
    public function getSingleRootId()
    {
        $roots = $this->getRoots();

        return count($roots) === 1 ? (int) $roots[0]['id'] : 0;
    }

    /**
     * 在当前分区内按名称反查地区 id（找不到返回 0）。
     *
     * 仅供未传 parent_id 的旧版前端兼容，同分区重名时命中首个匹配项。
     *
     * @param string $name
     * @return int
     */
    public function findIdByName($name)
    {
        $this->loadAllAreas();

        $name = (string) $name;
        if ($name === '') {
            return 0;
        }
        foreach ((array) $this->allAreasCache as $area) {
            if ((string) $area['name'] === $name) {
                return (int) $area['id'];
            }
        }

        return 0;
    }

    /**
     * 执行getAreaAll操作。
     *
     * @param int $parent_id 参数parent_id。
     * @param string $current_id 参数current_id。
     * @return mixed 返回结果。
     */
    public function getAreaAll($parentId = 0, $currentId = '')
    {
        $this->loadAllAreas();

        $byParent = $this->areasByParentCache;
        $buildTree = function ($pid) use (&$buildTree, $byParent) {
            $result = array();
            if (isset($byParent[$pid])) {
                foreach ($byParent[$pid] as $area) {
                    $area['child'] = $buildTree($area['id']);
                    $result[] = $area;
                }
            }
            return $result;
        };

        return $buildTree($parentId);
    }

    /**
     * 执行getAreaNolevel操作。
     *
     * @param int $parent_id 参数parent_id。
     * @param int $level 参数level。
     * @param string $current_id 参数current_id。
     * @param array $area_nolevel 参数area_nolevel。
     * @param string $mark 参数mark。
     * @return mixed 返回结果。
     */
    public function getAreaNolevel($parentId = 0, $level = 0, $currentId = '', &$areaNolevel = array(), $mark = '-')
    {
        $this->loadAllAreas();

        // 使用栈进行深度优先遍历，避免递归的重复遍历
        $stack = array(array($parentId, $level));
        $exclude = ($currentId !== '') ? array($currentId => true) : array();

        while (!empty($stack)) {
            list($pid, $curLevel) = array_pop($stack);
            if (isset($this->areasByParentCache[$pid])) {
                // 倒序入栈以保持原始排序（sort ASC 已排序）
                $children = array_reverse($this->areasByParentCache[$pid]);
                foreach ($children as $area) {
                    if (isset($exclude[$area['id']])) {
                        continue;
                    }
                    $area['mark'] = str_repeat($mark, $curLevel);
                    $area['level'] = $curLevel;
                    $areaNolevel[] = $area;
                    // 子节点入栈，层级+1
                    $stack[] = array($area['id'], $curLevel + 1);
                }
            }
        }

        return $areaNolevel;
    }

    /**
     * 执行getAreaList操作。
     *
     * @param int $parent_id 参数parent_id。
     * @param string $current 参数current。
     * @return mixed 返回结果。
     */
    public function getAreaList($parentId = 0, $current = '')
    {
        $this->loadAllAreas();

        $list = array();
        if (isset($this->areasByParentCache[$parentId])) {
            foreach ($this->areasByParentCache[$parentId] as $area) {
                $list[] = array(
                    'id' => $area['id'],
                    'name' => $area['name'],
                    'cur' => $area['name'] == $current,
                );
            }
        }
        return $list;
    }

    /**
     * 执行getContact操作。
     *
     * @param mixed $id 参数id。
     * @return mixed 返回结果。
     */
    public function getContact($id, $userId = null)
    {
        $query = DB::table('user_contact')
            ->field('*')
            ->where('id', (int) $id);
        if ($userId !== null) {
            $query->where('user_id', (int) $userId);
        }
        $contact = $query->find();

        if ($contact) {
            $contact['selectedCountry'] = isset($contact['country']) ? $contact['country'] : '';
            $contact['selectedProvince'] = $contact['province'];
            $contact['selectedCity'] = $contact['city'];
            $contact['selectedDistrict'] = $contact['district'];
        }

        return $contact;
    }
}
