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
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员等级服务。
 */
class UserLevelService extends BaseService
{
    /** @var UserStatsService */
    private $userStats;

    /**
     * @param UserStatsService $userStats
     */
    public function __construct(UserStatsService $userStats)
    {
        $this->userStats = $userStats;
    }

    /**
     * 执行checkLevelUpgrade操作。
     *
     * @param mixed $user_id 参数user_id。
     * @param string $order_sn 参数order_sn。
     * @return mixed 返回结果。
     */
    public function checkLevelUpgrade($user_id, $order_sn = '')
    {
        $user_id = intval($user_id);
        if (!$user_id) {
            return false;
        }

        if (!Config::get('features.user', false) || !DB::tableExist('user_level')) {
            return false;
        }

        DB::beginTransaction();
        try {
            $user = DB::table('user')
                ->where('id', $user_id)
                ->lock('FOR UPDATE')
                ->find();
            if (!$user) {
                DB::rollback();
                return false;
            }
            $current_level_id = intval($user['level_id']);

            $levels = DB::table('user_level')
                ->order('id ASC')
                ->select();
            if (empty($levels)) {
                DB::commit();
                return false;
            }

            $current_index = $this->resolveCurrentLevelIndex($levels, $current_level_id);

            $total_consumption = $this->userStats->totalConsumption($user_id);
            $total_promote = $this->userStats->totalPromote($user_id);

            $new_level_id = $current_level_id;
            $upgraded = false;

            for ($i = $current_index + 1; $i < count($levels); $i++) {
                $level = $levels[$i];
                $upgrade_type = $level['upgrade_type'];
                $condition = floatval($level['upgrade_condition']);

                $satisfied = false;
                if ($upgrade_type == 'shopping' && $total_consumption >= $condition) {
                    $satisfied = true;
                } elseif ($upgrade_type == 'promote' && $total_promote >= $condition) {
                    $satisfied = true;
                } elseif ($upgrade_type == 'noauto') {
                    continue;
                }

                if ($satisfied) {
                    $new_level_id = $level['id'];
                    DB::table('user')->where('id', $user_id)->update(array('level_id' => $new_level_id));

                    DB::table('user_level_log')->insert(array(
                        'user_id' => $user_id,
                        'old_level_id' => $current_level_id,
                        'new_level_id' => $new_level_id,
                        'upgrade_type' => $upgrade_type,
                        'condition_value' => $condition,
                        'order_sn' => $order_sn,
                        'created_at' => date('Y-m-d H:i:s')
                    ));

                    $upgraded = true;
                    $current_level_id = $new_level_id;
                }
            }

            DB::commit();
            return $upgraded;
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('User level upgrade failed: ' . $e->getMessage(), array(
                'channel' => 'user',
                'module' => 'user_level',
                'payload' => array(
                    'user_id' => (int) $user_id,
                    'order_sn' => (string) $order_sn,
                ),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ));

            return false;
        }
    }

    /**
     * 会员中心成长值进度（有配置才显示）。
     *
     * @param int $userId
     * @param int $currentLevelId
     * @return array
     */
    public function buildGrowthProgress($userId, $currentLevelId)
    {
        $empty = $this->emptyGrowthProgress();
        if (!Config::get('features.user', false) || !DB::tableExist('user_level') || !DB::table('user_level')->exists()) {
            return $empty;
        }

        $levels = DB::table('user_level')->order('id ASC')->select();
        if (empty($levels)) {
            return $empty;
        }

        $userId = (int) $userId;
        $consumption = $this->userStats->totalConsumption($userId);
        $promote = $this->userStats->totalPromote($userId);

        return $this->assembleGrowthProgress($levels, $currentLevelId, $consumption, $promote);
    }

    /**
     * 按等级列表与累计值组装成长值展示字段。
     *
     * @param array $levels user_level 行（已按 id ASC）
     * @param int $currentLevelId
     * @param float|int $consumption
     * @param float|int $promote
     * @return array
     */
    private function assembleGrowthProgress(array $levels, $currentLevelId, $consumption, $promote)
    {
        $currentIndex = $this->resolveCurrentLevelIndex($levels, $currentLevelId);
        $next = $this->findNextAutoLevel($levels, $currentIndex);
        $consumption = (float) $consumption;
        $promote = (float) $promote;

        if ($next === null) {
            $metricType = $this->resolveMetricTypeForMax($levels, $currentIndex);
            $value = $metricType === 'promote' ? $promote : $consumption;
            $valueText = $this->formatGrowthNumber($value);

            return array(
                'has_user_level' => true,
                'growth_value' => $value,
                'growth_value_text' => $valueText,
                'growth_target' => 0,
                'growth_percent' => 100,
                'growth_next_name' => '',
                'growth_next_diff' => 0,
                'growth_next_diff_text' => '0',
                'growth_is_max' => true,
                'growth_hint' => sprintf(lang('user_growth_hint_max', '当前成长值 %s，已达最高等级'), $valueText),
                'growth_gap_hint' => lang('user_growth_max_short', '已达最高等级'),
            );
        }

        $upgradeType = isset($next['upgrade_type']) ? (string) $next['upgrade_type'] : 'shopping';
        $target = isset($next['upgrade_condition']) ? (float) $next['upgrade_condition'] : 0.0;
        $value = $upgradeType === 'promote' ? $promote : $consumption;
        $diff = $target > $value ? ($target - $value) : 0.0;
        $percent = 100;
        if ($target > 0) {
            $percent = (int) round(min(100, max(0, ($value / $target) * 100)));
        }
        $valueText = $this->formatGrowthNumber($value);
        $diffText = $this->formatGrowthNumber($diff);
        $nextName = isset($next['name']) ? (string) $next['name'] : '';

        return array(
            'has_user_level' => true,
            'growth_value' => $value,
            'growth_value_text' => $valueText,
            'growth_target' => $target,
            'growth_percent' => $percent,
            'growth_next_name' => $nextName,
            'growth_next_diff' => $diff,
            'growth_next_diff_text' => $diffText,
            'growth_is_max' => false,
            'growth_hint' => sprintf(lang('user_growth_hint', '当前成长值 %s，距%s还差 %s'), $valueText, $nextName, $diffText),
            'growth_gap_hint' => sprintf(lang('user_growth_remain_to', '距%s还差 %s'), $nextName, $diffText),
        );
    }

    /**
     * @return array
     */
    private function emptyGrowthProgress()
    {
        return array(
            'has_user_level' => false,
            'growth_value' => 0,
            'growth_value_text' => '0',
            'growth_target' => 0,
            'growth_percent' => 0,
            'growth_next_name' => '',
            'growth_next_diff' => 0,
            'growth_next_diff_text' => '0',
            'growth_is_max' => false,
            'growth_hint' => '',
            'growth_gap_hint' => '',
        );
    }

    /**
     * 当前档之后第一档 shopping / promote；没有则视为最高档。
     *
     * @param array $levels
     * @param int $currentIndex
     * @return array|null
     */
    private function findNextAutoLevel(array $levels, $currentIndex)
    {
        $start = (int) $currentIndex + 1;
        $count = count($levels);
        for ($i = $start; $i < $count; $i++) {
            $type = isset($levels[$i]['upgrade_type']) ? (string) $levels[$i]['upgrade_type'] : '';
            if ($type === 'shopping' || $type === 'promote') {
                return $levels[$i];
            }
        }

        return null;
    }

    /**
     * 已是最高档时用当前档类型决定成长值口径，否则用消费。
     *
     * @param array $levels
     * @param int $currentIndex
     * @return string shopping|promote
     */
    private function resolveMetricTypeForMax(array $levels, $currentIndex)
    {
        if ($currentIndex >= 0 && isset($levels[$currentIndex]['upgrade_type'])) {
            $type = (string) $levels[$currentIndex]['upgrade_type'];
            if ($type === 'promote' || $type === 'shopping') {
                return $type;
            }
        }

        return 'shopping';
    }

    /**
     * @param float|int $value
     * @return string
     */
    private function formatGrowthNumber($value)
    {
        return number_format((int) round($value), 0, '.', ',');
    }

    /**
     * 解析会员当前等级在等级列表中的索引。
     *
     * level_id 为 0 或不在列表中（孤儿 ID）时返回 -1，升级循环从第一档起算。
     *
     * @param array $levels user_level 行列表（已按 id ASC 排序）
     * @param int $currentLevelId 会员当前 level_id
     * @return int
     */
    private function resolveCurrentLevelIndex(array $levels, $currentLevelId)
    {
        $currentLevelId = (int) $currentLevelId;
        if ($currentLevelId <= 0) {
            return -1;
        }

        foreach ($levels as $index => $level) {
            if ((int) $level['id'] === $currentLevelId) {
                return (int) $index;
            }
        }

        return -1;
    }
}
