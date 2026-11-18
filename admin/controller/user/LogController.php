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
use Dou\Admin\Service\User\LogService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员操作日志（route=user/log/...）。
 *
 * 控制器只做路由调度、取参、assign；
 * 列表数据来自 LogService::buildUserLogListData，不接收 POST，仅 index。
 */
class LogController extends BaseController
{
    /** @var LogService */
    private $logService;

    /**
     * @param LogService $logService
     */
    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * 操作日志列表（route=user/log）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $usernameRaw = trim((string) $request->input('username'));
        $userIdRaw = $request->integer('user_id', 0);
        $actionRaw = trim((string) $request->input('action'));
        $ipRaw = trim((string) $request->input('ip'));
        $dateStartRaw = trim((string) $request->input('date_start'));
        $dateEndRaw = trim((string) $request->input('date_end'));

        $usernameValid = $usernameRaw === '' || Check::username($usernameRaw);
        $ipValid = $ipRaw === '' || !Check::illegalChar($ipRaw);
        $dateStartValid = $dateStartRaw === '' || $this->isDate($dateStartRaw);
        $dateEndValid = $dateEndRaw === '' || $this->isDate($dateEndRaw);

        $username = $usernameValid ? $usernameRaw : '';
        $ip = $ipValid ? $ipRaw : '';
        $dateStart = $dateStartValid ? $dateStartRaw : '';
        $dateEnd = $dateEndValid ? $dateEndRaw : '';
        $action = in_array($actionRaw, $this->logService->actionOptions(), true) ? $actionRaw : '';

        $rejectFilter = array(
            'username' => !$usernameValid,
            'ip' => !$ipValid,
            'date_start' => !$dateStartValid,
            'date_end' => !$dateEndValid,
        );

        $pageUrl = route('admin.user.log');
        if ($userIdRaw > 0) {
            $pageUrl .= '&user_id=' . $userIdRaw;
        }
        if ($usernameRaw !== '') {
            $pageUrl .= '&username=' . rawurlencode($usernameRaw);
        }
        if ($actionRaw !== '') {
            $pageUrl .= '&action=' . rawurlencode($actionRaw);
        }
        if ($ipRaw !== '') {
            $pageUrl .= '&ip=' . rawurlencode($ipRaw);
        }
        if ($dateStartRaw !== '') {
            $pageUrl .= '&date_start=' . rawurlencode($dateStartRaw);
        }
        if ($dateEndRaw !== '') {
            $pageUrl .= '&date_end=' . rawurlencode($dateEndRaw);
        }

        $page = $request->integer('page', 1);

        $result = $this->logService->buildUserLogListData(
            $username,
            $userIdRaw,
            $action,
            $ip,
            $dateStart,
            $dateEnd,
            $pageUrl,
            $page,
            $rejectFilter
        );

        $actionList = array();
        foreach ($this->logService->actionOptions() as $value) {
            $actionList[] = array(
                'value' => $value,
                'cur' => $value === $action,
            );
        }

        return $this->view('user_log.htm', [
            'ur_here' => lang('user_log'),
            'page_actions' => array(
                array('href' => route('admin.user'), 'text' => lang('user'), 'style' => ''),
            ),
            'rec' => 'default',
            'req' => array(
                'username' => $usernameRaw,
                'user_id' => $userIdRaw,
                'action' => $actionRaw,
                'ip' => $ipRaw,
                'date_start' => $dateStartRaw,
                'date_end' => $dateEndRaw,
            ),
            'action_list' => $actionList,
            'log_list' => $result['list'],
            'pager' => $result['pager'],
        ]);
    }

    /**
     * 验证 YYYY-MM-DD 形式的日期字符串（不接受其它分隔符）。
     *
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
