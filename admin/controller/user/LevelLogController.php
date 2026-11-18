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

namespace Dou\Admin\Controller\User;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\User\LevelLogService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员等级变动日志（route=user/level_log/...）。
 */
class LevelLogController extends BaseController
{
    /** @var LevelLogService */
    private $levelLogService;

    /**
     * @param LevelLogService $levelLogService
     */
    public function __construct(LevelLogService $levelLogService)
    {
        $this->levelLogService = $levelLogService;
    }

    /**
     * 变动日志列表（route=user/level_log）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $usernameRaw = trim((string) $request->input('username'));
        $userIdRaw = $request->integer('user_id', 0);
        $upgradeTypeRaw = trim((string) $request->input('upgrade_type'));
        $dateStartRaw = trim((string) $request->input('date_start'));
        $dateEndRaw = trim((string) $request->input('date_end'));

        $usernameValid = $usernameRaw === '' || Check::username($usernameRaw);
        $dateStartValid = $dateStartRaw === '' || $this->isDate($dateStartRaw);
        $dateEndValid = $dateEndRaw === '' || $this->isDate($dateEndRaw);

        $username = $usernameValid ? $usernameRaw : '';
        $dateStart = $dateStartValid ? $dateStartRaw : '';
        $dateEnd = $dateEndValid ? $dateEndRaw : '';
        $upgradeType = in_array($upgradeTypeRaw, $this->levelLogService->upgradeTypeOptions(), true) ? $upgradeTypeRaw : '';

        $rejectFilter = array(
            'username' => !$usernameValid,
            'date_start' => !$dateStartValid,
            'date_end' => !$dateEndValid,
        );

        $pageUrl = route('admin.user.level_log');
        if ($userIdRaw > 0) {
            $pageUrl .= '&user_id=' . $userIdRaw;
        }
        if ($usernameRaw !== '') {
            $pageUrl .= '&username=' . rawurlencode($usernameRaw);
        }
        if ($upgradeTypeRaw !== '') {
            $pageUrl .= '&upgrade_type=' . rawurlencode($upgradeTypeRaw);
        }
        if ($dateStartRaw !== '') {
            $pageUrl .= '&date_start=' . rawurlencode($dateStartRaw);
        }
        if ($dateEndRaw !== '') {
            $pageUrl .= '&date_end=' . rawurlencode($dateEndRaw);
        }

        $page = $request->integer('page', 1);
        $result = $this->levelLogService->buildLevelLogListData(
            $username,
            $userIdRaw,
            $upgradeType,
            $dateStart,
            $dateEnd,
            $pageUrl,
            $page,
            $rejectFilter
        );

        $upgradeTypeList = array();
        $upgradeTypeList[] = array('value' => '', 'label' => lang('user_level_log_upgrade_type'), 'cur' => $upgradeType === '');
        foreach ($this->levelLogService->upgradeTypeOptions() as $value) {
            $label = $value === 'admin'
                ? lang('user_level_upgrade_type_admin')
                : ($value === 'shopping' ? lang('user_level_upgrade_type_shopping') : lang('user_level_upgrade_type_promote'));
            $upgradeTypeList[] = array(
                'value' => $value,
                'label' => $label,
                'cur' => $value === $upgradeType,
            );
        }

        return $this->view('user_level_log.htm', array(
            'ur_here' => lang('user_level_log'),
            'page_actions' => array(
                array('href' => route('admin.user.level'), 'text' => lang('user_level'), 'style' => ''),
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'default',
            'req' => array(
                'username' => $usernameRaw,
                'user_id' => $userIdRaw,
                'upgrade_type' => $upgradeTypeRaw,
                'date_start' => $dateStartRaw,
                'date_end' => $dateEndRaw,
            ),
            'upgrade_type_list' => $upgradeTypeList,
            'log_list' => $result['list'],
            'pager' => $result['pager'],
            'table_missing' => !empty($result['table_missing']),
        ));
    }

    /**
     * @param string $value
     * @return bool
     */
    private function isDate($value)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
