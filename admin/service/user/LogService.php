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

use Dou\Admin\Model\User\UserLog;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\User\UserLogAction;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员操作日志列表服务（route=user/log）。
 *
 * 列表数据用 `buildUserLogListData`；输入校验由控制器层负责。
 * 仅承担只读列表查询，写入仍由 `audit()->writeUserLog()` 处理。
 */
class LogService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * action 下拉候选（供模板渲染 filter）。
     *
     * 字典源为 {@see UserLogAction::all()}，新增 action 仅需在常量类追加，
     * 后台筛选下拉与白名单校验自动跟随。
     *
     * @return array<int, string>
     */
    public function actionOptions()
    {
        return UserLogAction::all();
    }

    /**
     * 日志列表数据。
     *
     * @param string $username 会员关键字（手机/邮箱/user_sn/姓名，Core\UserService::findUserId）
     * @param int $userId 指定会员（与 username 二选一，user_id 优先）
     * @param string $action 操作类型，须在 actionOptions() 内
     * @param string $ip 操作 IP（精确匹配）
     * @param string $dateStart YYYY-MM-DD（包含当日 0 点）
     * @param string $dateEnd YYYY-MM-DD（包含当日 23:59:59）
     * @param string $pageUrl 分页 URL（由控制器按原始查询参数拼装）
     * @param int $page
     * @param array $rejectFilter 有输入但未通过对应校验时为 true：`username` / `ip` / `date_start` / `date_end`
     * @return array list, pager
     */
    public function buildUserLogListData($username, $userId, $action, $ip, $dateStart, $dateEnd, $pageUrl, $page = 1, array $rejectFilter = array())
    {
        // AR：UserLog casts 接管 created_at/result 等
        $query = UserLog::query();

        $forceEmpty = !empty($rejectFilter['username'])
            || !empty($rejectFilter['ip'])
            || !empty($rejectFilter['date_start'])
            || !empty($rejectFilter['date_end']);

        if ($forceEmpty) {
            $query->where('id', -1);
        } else {
            $userId = (int) $userId;
            if ($userId > 0) {
                $query->where('user_id', $userId);
            } elseif ($username !== '') {
                $resolved = user() !== null ? user()->findUserId($username) : null;
                if ($resolved) {
                    $query->where('user_id', (int) $resolved);
                } else {
                    $query->where('user_id', -1);
                }
            }

            if ($action !== '' && in_array($action, UserLogAction::all(), true)) {
                $query->where('action', $action);
            }

            if ($ip !== '') {
                $query->where('ip', $ip);
            }

            // created_at 是 datetime，必须绑定同格式字符串；绑定 int 会触发数值比较导致筛选失效
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

        $list = array();
        $successLabel = lang('user_log_result_success');
        $failLabel = lang('user_log_result_fail');
        $dash = '-';

        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $rowUserId = (int) $row['user_id'];
            $userInfo = null;
            if ($rowUserId > 0 && user() !== null) {
                $formatted = user()->format($rowUserId);
                if (is_array($formatted)) {
                    $userInfo = $formatted;
                }
            }
            if ($userInfo === null) {
                $userInfo = array(
                    'id' => $rowUserId,
                    'user_id' => $rowUserId,
                    'username' => $dash,
                );
            }

            $rawCreate = Util::toTimestamp($model->getRawAttribute('created_at'));
            $list[] = array(
                'id' => (int) $row['id'],
                'user' => $userInfo,
                'action' => isset($row['action']) ? (string) $row['action'] : '',
                'ip' => isset($row['ip']) ? (string) $row['ip'] : '',
                'created_at' => $rawCreate !== null ? $row['created_at'] : $dash,
                'result' => $row['result'] ? 1 : 0,
                'result_format' => !empty($row['result']) ? $successLabel : $failLabel,
                'details' => isset($row['details']) ? (string) $row['details'] : '',
            );
        }

        return array(
            'list' => $list,
            'pager' => $result['pager'],
        );
    }

    /**
     * 解析「起始日期」为 0 点时间戳；空串或格式非法返回 null。
     *
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
     * 解析「结束日期」为 23:59:59 时间戳；空串或格式非法返回 null。
     *
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
