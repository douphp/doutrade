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

use Dou\Admin\Model\Order\Order;
use Dou\Admin\Model\User\UserLevel;
use Dou\Admin\Model\User\UserLevelLog;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员等级变动日志列表（route=user/level_log）。
 */
class LevelLogService extends BaseService
{
    /**
     * upgrade_type 下拉白名单。
     *
     * @return array
     */
    public function upgradeTypeOptions()
    {
        return array('shopping', 'promote', 'admin');
    }

    /**
     * 变动日志列表。
     *
     * @param string $username
     * @param int $userId
     * @param string $upgradeType
     * @param string $dateStart
     * @param string $dateEnd
     * @param string $pageUrl
     * @param int $page
     * @param array $rejectFilter
     * @return array
     */
    public function buildLevelLogListData($username, $userId, $upgradeType, $dateStart, $dateEnd, $pageUrl, $page = 1, array $rejectFilter = array())
    {
        if (!DB::tableExist('user_level_log')) {
            return array(
                'list' => array(),
                'pager' => $this->emptyPager($pageUrl),
                'table_missing' => true,
            );
        }

        $query = UserLevelLog::filterByUpgradeType($upgradeType);

        $forceEmpty = !empty($rejectFilter['username'])
            || !empty($rejectFilter['date_start'])
            || !empty($rejectFilter['date_end']);

        if ($forceEmpty) {
            $query->where('id', -1);
        } else {
            $userId = (int) $userId;
            if ($userId > 0) {
                $query->filterByUserId($userId);
            } elseif ($username !== '') {
                $resolved = user() !== null ? user()->findUserId($username) : null;
                if ($resolved) {
                    $query->filterByUserId((int) $resolved);
                } else {
                    $query->where('id', -1);
                }
            }

            $start = $this->parseDateStart($dateStart);
            if ($start !== null) {
                $query->where('created_at', '>=', date('Y-m-d H:i:s', $start));
            }
            $end = $this->parseDateEnd($dateEnd);
            if ($end !== null) {
                $query->where('created_at', '<=', date('Y-m-d H:i:s', $end));
            }
        }

        $result = $query->order('id DESC')->paginate(15, $page, $pageUrl);
        $levelNameMap = $this->buildUserLevelNameMap($result['list']);
        $orderIdMap = $this->buildOrderIdMapBySn($result['list']);

        $list = array();
        $dash = '-';
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $rowUserId = (int) $row['user_id'];
            $userInfo = $this->formatUserInfo($rowUserId, $dash);

            $orderSn = isset($row['order_sn']) ? (string) $row['order_sn'] : '';
            $orderId = $orderSn !== '' && isset($orderIdMap[$orderSn]) ? (int) $orderIdMap[$orderSn] : 0;

            $rawCreate = Util::toTimestamp($model->getRawAttribute('created_at'));
            $list[] = array(
                'id' => (int) $row['id'],
                'user' => $userInfo,
                'old_level_name' => $this->formatLevelName((int) $row['old_level_id'], $levelNameMap),
                'new_level_name' => $this->formatLevelName((int) $row['new_level_id'], $levelNameMap),
                'upgrade_type' => isset($row['upgrade_type']) ? (string) $row['upgrade_type'] : '',
                'upgrade_type_label' => $this->formatUpgradeTypeLabel(isset($row['upgrade_type']) ? $row['upgrade_type'] : ''),
                'condition_value' => isset($row['condition_value']) ? $row['condition_value'] : 0,
                'order_sn' => $orderSn,
                'order_id' => $orderId,
                'created_at' => $rawCreate !== null ? $row['created_at'] : $dash,
            );
        }

        return array(
            'list' => $list,
            'pager' => $result['pager'],
            'table_missing' => false,
        );
    }

    /**
     * @param int $levelId
     * @param array $nameMap
     * @return string
     */
    private function formatLevelName($levelId, array $nameMap)
    {
        $levelId = (int) $levelId;
        if ($levelId <= 0) {
            return lang('user_level_no');
        }

        return isset($nameMap[$levelId]) ? $nameMap[$levelId] : (string) $levelId;
    }

    /**
     * @param string $upgradeType
     * @return string
     */
    private function formatUpgradeTypeLabel($upgradeType)
    {
        $upgradeType = (string) $upgradeType;
        if ($upgradeType === 'shopping') {
            return lang('user_level_upgrade_type_shopping');
        }
        if ($upgradeType === 'promote') {
            return lang('user_level_upgrade_type_promote');
        }
        if ($upgradeType === 'admin') {
            return lang('user_level_upgrade_type_admin');
        }

        return $upgradeType !== '' ? $upgradeType : '-';
    }

    /**
     * @param int $userId
     * @param string $dash
     * @return array
     */
    private function formatUserInfo($userId, $dash)
    {
        $userId = (int) $userId;
        if ($userId > 0 && user() !== null) {
            $formatted = user()->format($userId);
            if (is_array($formatted)) {
                return $formatted;
            }
        }

        return array(
            'id' => $userId,
            'user_id' => $userId,
            'username' => $dash,
        );
    }

    /**
     * @param iterable $models
     * @return array
     */
    private function buildUserLevelNameMap($models)
    {
        $ids = array();
        foreach ($models as $model) {
            $row = $model->getAttributes();
            $ids[] = (int) $row['old_level_id'];
            $ids[] = (int) $row['new_level_id'];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids) || !DB::tableExist('user_level')) {
            return array();
        }

        $rows = UserLevel::whereIn('id', $ids)->field('id, name')->get();
        $map = array();
        foreach ($rows as $levelRow) {
            $map[(int) $levelRow['id']] = (string) $levelRow['name'];
        }

        return $map;
    }

    /**
     * @param iterable $models
     * @return array order_sn => order id
     */
    private function buildOrderIdMapBySn($models)
    {
        $sns = array();
        foreach ($models as $model) {
            $attrs = $model->getAttributes();
            $sn = isset($attrs['order_sn']) ? (string) $attrs['order_sn'] : '';
            if ($sn !== '') {
                $sns[] = $sn;
            }
        }
        $sns = array_values(array_unique($sns));
        if (empty($sns)) {
            return array();
        }

        $rows = Order::whereIn('order_sn', $sns)->field('id, order_sn')->get();
        $map = array();
        foreach ($rows as $orderRow) {
            $map[(string) $orderRow['order_sn']] = (int) $orderRow['id'];
        }

        return $map;
    }

    /**
     * @param string $pageUrl
     * @return array
     */
    private function emptyPager($pageUrl)
    {
        return array(
            'record_count' => 0,
            'page_size' => 15,
            'page' => 1,
            'page_count' => 0,
            'box' => array(),
            'previous' => $pageUrl,
            'next' => $pageUrl,
            'first' => $pageUrl,
            'last' => $pageUrl,
        );
    }

    /**
     * @param string $value
     * @return int|null
     */
    private function parseDateStart($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 00:00:00');
        return $ts === false ? null : (int) $ts;
    }

    /**
     * @param string $value
     * @return int|null
     */
    private function parseDateEnd($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value . ' 23:59:59');
        return $ts === false ? null : (int) $ts;
    }
}
