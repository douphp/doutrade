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

namespace Dou\Api\Controller\User;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\User\UserLogQuery;
use Dou\Core\Service\User\UserStatsService;
use Dou\Core\Service\Work\WorkService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序用户工作端子控制器（`route=user/work`）
 */
class WorkController extends BaseController
{
    /** @var WorkService */
    private $workService;

    /** @var UserStatsService */
    private $userStats;

    /** @var UserLogQuery */
    private $userLogQuery;

    /**
     * @param WorkService $workService
     * @param UserStatsService $userStats
     * @param UserLogQuery $userLogQuery
     */
    public function __construct(WorkService $workService, UserStatsService $userStats, UserLogQuery $userLogQuery)
    {
        $this->workService = $workService;
        $this->userStats = $userStats;
        $this->userLogQuery = $userLogQuery;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $userId = auth('api')->id();
        if (!$this->workService->checkPermission('user', $userId)) {
            return ApiResponse::error(ApiCodes::FORBIDDEN, lang('work_no_permission'), array(), 403);
        }

        $page = $request->integer('page', 1);
        $pageSize = Config::get('pagination.service', 10);
        $result = DB::table('user')->order('id DESC')->paginate($pageSize, $page);

        $userIds = array();
        foreach ((array) $result['list'] as $row) {
            $userIds[] = (int) $row['id'];
        }
        $lastLoginMap = $this->userLogQuery->latestLoginMap($userIds);

        $userList = array();
        foreach ((array) $result['list'] as $row) {
            $rowUserId = (int) $row['id'];
            $sex = $row['sex'] ? lang('user_man') : lang('user_woman');
            $lastLogin = isset($lastLoginMap[$rowUserId]) ? $lastLoginMap[$rowUserId] : null;
            $lastLoginFmt = $lastLogin ? date('Y-m-d H:i:s', strtotime($lastLogin['created_at'])) : '-';
            // 联系方式已迁移到 user_contact，必须经 format() 合并，不能读 dou_user 旧列
            $formattedUser = user() ? user()->format($rowUserId) : false;
            $contact = is_array($formattedUser) && isset($formattedUser['contact']) ? (string) $formattedUser['contact'] : '';
            $phone = is_array($formattedUser) && isset($formattedUser['phone']) ? (string) $formattedUser['phone'] : '';
            $firstName = is_array($formattedUser) && isset($formattedUser['first_name']) ? (string) $formattedUser['first_name'] : '';
            $lastName = is_array($formattedUser) && isset($formattedUser['last_name']) ? (string) $formattedUser['last_name'] : '';
            $userList[] = array(
                'user_id' => $rowUserId,
                'level_name' => user() ? user()->levelName($row['level_id']) : '',
                'vip' => user() ? user()->vip($rowUserId) : null,
                'user' => $formattedUser,
                'mobile' => $row['mobile'],
                'email' => $row['email'],
                'user_sn' => $row['user_sn'],
                'contact' => (Config::get('site.language.default', '') === 'en_us') ? trim($firstName . ' ' . $lastName) : $contact,
                'phone' => $phone,
                'point' => $this->userStats->totalPoint($rowUserId),
                'money_total' => $this->userStats->totalMoney($rowUserId),
                'sex' => $sex,
                'login_count' => $row['login_count'],
                'last_login' => $lastLoginFmt,
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            );
        }

        return ApiResponse::success(array(
            'title' => lang('order_my'),
            'user_list' => $userList,
        ));
    }
}
