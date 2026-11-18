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

namespace Dou\Admin\Service\Area;

use Dou\Admin\Model\Area\Area;
use Dou\Admin\Service\Concerns\SavesLanguageDrafts;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Area\AreaService as AreaCore;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台地区业务服务
 */
class AreaService extends BaseService
{
    use SavesLanguageDrafts;

    /** @var AreaCore */
    private $lib;

    /** @var bool|null 是否展示分区 Tab（多语言页已新增扩展语言） */
    private $showTabsCache = null;

    /**
     * @param AreaCore $lib
     */
    public function __construct(AreaCore $lib)
    {
        $this->lib = $lib;
    }

    /**
     * 解析并校验数据分区，非法值回落当前语言对应分区。
     *
     * @param string|null $group
     * @return string
     */
    private function resolveGroup($group = null)
    {
        $group = (string) $group;
        if (in_array($group, AreaCore::GROUPS, true)) {
            return $group;
        }

        $fallback = (string) $this->lib->group();

        return in_array($fallback, AreaCore::GROUPS, true) ? $fallback : AreaCore::DEFAULT_GROUP;
    }

    /**
     * 是否展示分区 Tab：多语言页已新增扩展语言，且地区表已具备分区列。
     *
     * 旧库未升级（无 lang 列）时切换分区没有意义，一律不展示。
     *
     * @return bool
     */
    private function showTabs()
    {
        if ($this->showTabsCache === null) {
            $this->showTabsCache = DB::tableExist('language')
                && (int) DB::table('language')->count() > 0
                && DB::fieldExist('area', 'lang');
        }

        return $this->showTabsCache;
    }

    /**
     * 构建分区 Tab 项（供后台统一 Tab 组件 inc/tab_nav.tpl 消费）。
     *
     * 首位跟随系统设置语言（site.language.default）映射的分区，另一分区在后；
     * 高亮仍跟随当前激活分区。
     *
     * @param string $current
     * @return array 每项：key / name / url / current
     */
    private function buildAreaTabs($current)
    {
        $first = AreaCore::languageGroup(Config::get('site.language.default', ''));
        $groups = array($first);
        foreach (AreaCore::GROUPS as $group) {
            if ($group !== $first) {
                $groups[] = $group;
            }
        }

        $tabs = array();
        foreach ($groups as $group) {
            $tabs[] = array(
                'key' => $group,
                'name' => lang('area_group_' . $group),
                'url' => route('admin.area', array('lang' => $group)),
                'current' => $group === $current,
            );
        }

        return $tabs;
    }

    /**
     * 列表页数据
     *
     * @param string|null $group 数据分区
     * @return array area_list、area_tabs、area_group、area_group_name、single_root
     */
    public function buildAreaListData($group = null)
    {
        $active = $this->resolveGroup($group);
        $this->lib->setGroup($active);

        $showTabs = $this->showTabs();

        return array(
            'area_list' => $this->lib->getAreaAll(),
            'area_group' => $active,
            'area_group_name' => lang('area_group_' . $active),
            'single_root' => $this->lib->hasSingleRoot(),
            'area_tabs' => $showTabs ? $this->buildAreaTabs($active) : array(),
        );
    }

    /**
     * 添加页数据
     *
     * @param string|null $group 数据分区
     * @return array area_list, form_action, token
     */
    public function buildAreaDefaultData($group = null)
    {
        $active = $this->resolveGroup($group);
        $this->lib->setGroup($active);

        return array(
            'area' => array(
                'id' => '',
                'name' => '',
                'parent_id' => '',
                'sort' => '50',
                'lang' => $active,
            ),
            'area_list' => $this->lib->getAreaNolevel(),
            'area_group' => $active,
            'area_group_name' => lang('area_group_' . $active),
        );
    }

    /**
     * 编辑页数据
     *
     * @param string $id
     * @return array|null area, area_list, form_action, token
     */
    public function buildAreaEditData($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $areaModel = Area::find($id);
        $area = $areaModel ? $areaModel->getAttributes() : null;
        if (!$area) {
            return null;
        }

        // 分区以记录自身归属为准
        $active = $this->resolveGroup(isset($area['lang']) ? $area['lang'] : null);
        $this->lib->setGroup($active);

        return array(
            'area' => $area,
            'area_list' => $this->lib->getAreaNolevel(0, 0, $id),
            'area_group' => $active,
            'area_group_name' => lang('area_group_' . $active),
        );
    }

    /**
     * 校验上级归属：上级必须存在且与当前分区一致（防跨分区挂树）。
     *
     * @param int|string $parentId
     * @param string $group
     * @return void
     * @throws DomainException 上级不存在或与当前分区不一致
     */
    private function assertParentInGroup($parentId, $group)
    {
        $parentId = (int) $parentId;
        if ($parentId <= 0) {
            return;
        }

        $parent = Area::find($parentId, 'id, lang');
        $attrs = $parent ? $parent->getAttributes() : null;
        if (!$attrs) {
            throw new DomainException(lang('item_not_exist'), route('admin.area'));
        }
        if (isset($attrs['lang']) && (string) $attrs['lang'] !== (string) $group) {
            throw new DomainException(lang('illegal'), route('admin.area'));
        }
    }

    /**
     * 防环：上级不能是自身或自身的子孙。
     *
     * @param int $id
     * @param int|string $parentId
     * @return void
     * @throws DomainException 形成环时抛出
     */
    private function assertNoCycle($id, $parentId)
    {
        $id = (int) $id;
        $cursor = (int) $parentId;
        $guard = 0;
        while ($cursor > 0 && $guard++ < 10) {
            if ($cursor === $id) {
                throw new DomainException(lang('illegal'), route('admin.area'));
            }
            $row = Area::find($cursor, 'id, parent_id');
            $attrs = $row ? $row->getAttributes() : null;
            if (!$attrs) {
                break;
            }
            $cursor = (int) $attrs['parent_id'];
        }
    }

    /**
     * 新增提交
     *
     * @param array $data
     * @return int 新增记录主键
     */
    public function insert(array $data)
    {
        // 分区只能由白名单决定，不接受任意写入
        $data['lang'] = $this->resolveGroup(isset($data['lang']) ? $data['lang'] : null);
        $this->assertParentInGroup(isset($data['parent_id']) ? $data['parent_id'] : 0, $data['lang']);

        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('area', $data, 0, $adminId);

        $newId = (int) DB::transaction(function () use ($data, $adminId, $languagePlan) {
            $model = Area::create($data);
            $newId = (int) $model->getKey();
            if ($newId <= 0) {
                throw new DomainException(lang('operation_failed'));
            }
            $this->languageDraftCommit($languagePlan, $newId, $adminId);

            return $newId;
        });

        $this->languageDraftPurge($languagePlan, $newId);
        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $newId;
    }

    /**
     * 更新提交
     *
     * @param array $post
     * @return void
     */
    public function update(array $data)
    {
        // 记录所属分区在新增后固定，不允许通过表单改动
        unset($data['lang']);

        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.area'));
        }
        $model = Area::find($id);
        if (!$model) {
            throw new DomainException(lang('item_not_exist'), route('admin.area'));
        }
        if (isset($data['parent_id']) && (int) $data['parent_id'] === $id) {
            throw new DomainException(lang('illegal'), route('admin.area'));
        }
        if (isset($data['parent_id'])) {
            $this->assertParentInGroup($data['parent_id'], (string) $model->getAttribute('lang'));
            $this->assertNoCycle($id, $data['parent_id']);
        }

        $adminId = (int) auth('admin')->id();
        $languagePlan = $this->languageDraftBegin('area', $data, $id, $adminId);

        try {
            DB::transaction(function () use ($model, $data, $id, $adminId, $languagePlan) {
                $model->fill($data, 'update')->save();
                $this->languageDraftCommit($languagePlan, $id, $adminId);
            });
        } catch (\Exception $e) {
            $this->languageDraftAbort($languagePlan);
            throw $e;
        }

        $this->languageDraftPurge($languagePlan, $id);
        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * 删除处理（支持确认跳转）
     *
     * @param int|string $id
     * @param array $data
     * @return array message、back_url、timeout、confirm_url
     * @throws DomainException 不存在 / 不可删除时抛出
     */
    public function delete($id, array $data)
    {
        $id = (int) $id;
        $areaModel = Area::find($id, 'id, name, lang');
        $area = $areaModel ? $areaModel->getAttributes() : null;
        if (!$area) {
            throw new DomainException(lang('item_not_exist'), route('admin.area'));
        }
        // 删除后回列表时停在该记录所属分区，不把用户弹回默认分区
        $backUrl = route('admin.area', array('lang' => $this->resolveGroup(isset($area['lang']) ? $area['lang'] : null)));

        if ($id === 1) {
            throw new DomainException(lang('area_del_wrong'), $backUrl, '3');
        }
        if (Area::hasChildArea($id)) {
            $msg = preg_replace('/d%/Ums', $area['name'], lang('area_del_is_parent'));
            throw new DomainException($msg, $backUrl, '3');
        }
        if (!isset($data['confirm'])) {
            $msg = preg_replace('/d%/Ums', $area['name'], lang('del_check'));
            return array(
                'message' => $msg,
                'back_url' => $backUrl,
                'timeout' => '30',
                'confirm_url' => route('admin.area.destroy', array('id' => $id)),
            );
        }

        Area::destroy($id);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $area['name']);
        return array(
            'message' => lang('del_succes'),
            'back_url' => $backUrl,
        );
    }
}
