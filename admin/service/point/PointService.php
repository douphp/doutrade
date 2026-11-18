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

namespace Dou\Admin\Service\Point;

use Dou\Admin\Model\Point\Point;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台积分流水服务（route=point/...）
 */
class PointService extends BaseService
{
    /**
     * @param string $username 会员关键字（手机/邮箱/user_sn/联系人，Core\UserService::findUserId）；空则不过滤
     * @param string $timeStart
     * @param string $timeEnd
     * @param int|string $page
     * @return array list, pager
     */
    public function buildPointListData($username, $timeStart, $timeEnd, $page)
    {
        $page_url = route('admin.point');
        $user_id = 0;
        if ($username) {
            $user_id = (int) (user() !== null ? user()->findUserId($username) : null);
            $page_url .= '&username=' . $username;
        }
        if ($timeStart !== '') {
            $page_url .= '&time_start=' . $timeStart;
        }
        if ($timeEnd !== '') {
            $page_url .= '&time_end=' . $timeEnd;
        }

        $p = Check::number((string) $page) ? (int) $page : 1;

        $result = Point::filterByUserId($user_id)
            ->filterByTimeStart($timeStart)
            ->filterByTimeEnd($timeEnd)
            ->applyDefaultOrder()
            ->paginate(15, $p, Util::normalizeQueryString($page_url));

        $point_log_list = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $akey = 'point_action_' . $row['action'];
            $point_log_list[] = array(
                'id' => $row['id'],
                'user' => (user() !== null ? user()->format($row['user_id']) : false),
                'action' => $row['action'],
                'action_format' => lang($akey) !== '' ? lang($akey) : $row['action'],
                'from' => $row['from'],
                'point' => $row['point'],
                'total' => $row['total'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'list' => $point_log_list,
            'pager' => $result['pager'],
        );
    }

    /**
     * @param int|string $id
     * @param array $post
     * @return array message、back_url、timeout、confirm_url
     */
    public function deleteByIdOrConfirm($id, array $post)
    {
        // 流水同时承担余额基线和业务防重，不能作为普通日志删除。
        throw new DomainException(lang('point_ledger_immutable', '积分流水不可删除，请通过积分调整更正'), route('admin.point'));
    }

    /**
     * 批量删除（数据来自 PointLogBatchActionFormRequest::validated()）。
     *
     * @param array $data
     * @return array
     */
    public function action(array $data)
    {
        throw new DomainException(lang('point_ledger_immutable', '积分流水不可删除，请通过积分调整更正'), route('admin.point'));
    }

    /**
     * @return void
     */
    public function seedPointScaleParameter()
    {
        $name = 'point_scale';
        if (!DB::table('parameter')->where('name', $name)->exists()) {
            DB::table('parameter')->data(array(
                'name' => $name,
                'lang' => lang($name . '_lang'),
                'value' => lang($name . '_value'),
                'cue' => lang($name . '_cue'),
                'group' => 'point',
            ))->insert();
        }
        return route('admin.parameter.set', ['group' => 'point']);
    }
}
