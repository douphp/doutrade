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

namespace Dou\Admin\Service\Coupon;

use Dou\Admin\Model\Coupon\Coupon;
use Dou\Admin\Model\Coupon\CouponLog;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Coupon\CouponService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台优惠券领取记录服务（route=coupon_log/...）
 */
class CouponLogService extends BaseService
{
    /** @var CouponService */
    private $coupon;

    /**
     * @param CouponService $coupon
     */
    public function __construct(CouponService $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * 同步 SESSION 筛选条件到当前请求参数。
     *
     * @param array $request
     * @return array
     */
    public function mergeSessionFilter(array $filters)
    {
        if (!empty($filters['key'])) {
            $request = array(
                'coupon_id' => Arr::get($filters, 'coupon_id', ''),
                'time_start' => Arr::get($filters, 'time_start', ''),
                'time_end' => Arr::get($filters, 'time_end', ''),
                'page' => Arr::get($filters, 'page', 1),
            );
            $_SESSION['req'] = $request;
        } else {
            $request = array(
                'coupon_id' => Arr::get($filters, 'coupon_id', ''),
                'time_start' => Arr::get($filters, 'time_start', ''),
                'time_end' => Arr::get($filters, 'time_end', ''),
                'page' => Arr::get($filters, 'page', 1),
            );
            if (!empty($filters['menu'])) {
                unset($_SESSION['req']);
            }
            if (!empty($_SESSION['req']) && is_array($_SESSION['req'])) {
                $request = array_merge($request, $_SESSION['req']);
            }
        }

        return $request;
    }

    /**
     * @param string $couponId
     * @param string $timeStart
     * @param string $timeEnd
     * @param int|string $page
     * @return array log_list, pager
     */
    public function buildCouponLogListData($couponId, $timeStart, $timeEnd, $page)
    {
        $pageUrl = route('admin.coupon.log');
        if ($couponId) {
            $pageUrl .= '&coupon_id=' . $couponId;
        }
        if ($timeStart !== '') {
            $pageUrl .= '&time_start=' . $timeStart;
        }
        if ($timeEnd !== '') {
            $pageUrl .= '&time_end=' . $timeEnd;
        }

        $p = Check::number((string) $page) ? (int) $page : 1;
        // AR：casts 接管 claimed_at/created_at/status；coupon 详情仍按 ID 逐行获取（per-row enrich）
        $result = CouponLog::filterByCouponId($couponId)
            ->filterByUsedAfter($timeStart)
            ->filterByUsedBefore($timeEnd)
            ->applyDefaultOrder()
            ->paginate(15, $p, Util::normalizeQueryString($pageUrl));

        $rows = $result['list'];
        $couponIds = array();
        foreach ($rows as $model) {
            $row = $model->toArray();
            $couponIds[] = (int) $row['coupon_id'];
        }
        $couponById = array();
        if (!empty($couponIds)) {
            foreach ((array) DB::table('coupon')->whereIn('id', array_values(array_unique($couponIds)))->select() as $couponRow) {
                $couponById[(int) $couponRow['id']] = $couponRow;
            }
        }

        $logList = array();
        foreach ($rows as $model) {
            $row = $model->toArray();
            $couponId = (int) $row['coupon_id'];
            $coupon = isset($couponById[$couponId]) ? $couponById[$couponId] : null;
            if ($coupon) {
                $coupon['money'] = CouponService::faceValueLabel($coupon);
                $coupon['condition'] = $coupon['condition'];
                $coupon['start_at'] = !empty($coupon['start_at']) ? date('Y-m-d', strtotime($coupon['start_at'])) : '';
                $coupon['end_at'] = !empty($coupon['end_at']) ? date('Y-m-d', strtotime($coupon['end_at'])) : '';
                $coupon['status'] = $this->coupon->statusByLog($coupon, $row);
            }

            $typeLabel = '';
            $moneyFmt = '';
            $cond = '';
            if ($coupon) {
                $typeLabel = lang('coupon_type_' . $coupon['type']);
                $moneyFmt = $coupon['money'];
                $cond = $coupon['condition'];
            }

            $logList[] = array(
                'id' => $row['id'],
                'user' => (user() !== null ? user()->format($row['user_id']) : false),
                'coupon' => $coupon,
                'type' => $typeLabel,
                'money' => $moneyFmt,
                'condition' => $cond,
                'claimed_at' => $row['claimed_at'],
                'used_at' => $row['used_at'] ? date('Y-m-d', strtotime($row['used_at'])) : '-',
                'order_sn' => $row['order_sn'] ? $row['order_sn'] : '-',
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'list' => $logList,
            'pager' => $result['pager'],
        );
    }

    /**
     * @param string $id
     * @param array $post
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     */
    public function delete($id, array $post)
    {
        $logItemModel = CouponLog::find((int) $id);
        $log = $logItemModel ? $logItemModel->getAttributes() : null;
        if (!$log) {
            throw new DomainException(lang('illegal'), route('admin.coupon.log'));
        }
        $user = (user() !== null ? user()->format($log['user_id']) : false);
        $couponItemModel = Coupon::find((int) $log['coupon_id']);
        $coupon = $couponItemModel ? $couponItemModel->getAttributes() : null;
        $cname = $coupon && Arr::get($coupon, 'name', '');

        $this->assertNotUsed($log);

        if (isset($post['confirm'])) {
            $this->assertNotUsed($log);
            $couponId = (int) $log['coupon_id'];

            DB::beginTransaction();
            try {
                CouponLog::destroy((int) $id);
                $this->decrementIssued($couponId, 1);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }

            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) ($user['username'] . '-' . $cname));
            return array(
                'message' => lang('coupon_log_delete') . lang('success'),
                'back_url' => route('admin.coupon.log'),
            );
        }

        $msg = preg_replace('/d%/Ums', $user['username'] . '-' . $cname, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.coupon.log'),
            'timeout' => '30',
            'confirm_url' => route('admin.coupon.log.destroy', array('id' => (int) $id)),
        );
    }

    /**
     * @param array $post
     * @return void
     */
    public function action(array $data)
    {
        if (empty($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('select_empty'), route('admin.coupon.log'));
        }
        if (!isset($data['action']) || $data['action'] !== 'del_all') {
            throw new DomainException(lang('select_empty'), route('admin.coupon.log'));
        }

        $safeIds = array();
        foreach ($data['checkbox'] as $rid) {
            if (Check::number((string) $rid)) {
                $safeIds[] = (int) $rid;
            }
        }
        if (empty($safeIds)) {
            throw new DomainException(lang('select_empty'), route('admin.coupon.log'));
        }

        $logs = DB::table('coupon_log')->whereIn('id', $safeIds)->select();
        $perCoupon = array();
        foreach ((array) $logs as $log) {
            $this->assertNotUsed($log);
            $couponId = (int) $log['coupon_id'];
            $perCoupon[$couponId] = isset($perCoupon[$couponId]) ? $perCoupon[$couponId] + 1 : 1;
        }

        DB::beginTransaction();
        try {
            CouponLog::deleteWhereIdIn($safeIds);
            foreach ($perCoupon as $couponId => $count) {
                $this->decrementIssued($couponId, $count);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:COUPON_LOG (' . count($safeIds) . ' items)');
    }

    /**
     * 已核销日志禁止删除（保留订单回退引用）。
     *
     * @param array $log
     * @return void
     */
    private function assertNotUsed(array $log)
    {
        $used = !empty($log['used_at']);
        if (!$used && isset($log['status']) && !is_array($log['status'])) {
            $used = (int) $log['status'] === 1;
        }
        if ($used) {
            throw new DomainException(lang('coupon_log_used_cannot_delete'), route('admin.coupon.log'));
        }
    }

    /**
     * 删除未使用日志时同步扣减已发放计数。
     *
     * @param int $couponId
     * @param int $count
     * @return void
     */
    private function decrementIssued($couponId, $count)
    {
        $issued = DB::table('coupon')->where('id', (int) $couponId)->lock('FOR UPDATE')->value('issued_quantity');
        if ($issued === null) {
            return;
        }

        $newValue = max(0, (int) $issued - (int) $count);
        DB::table('coupon')->where('id', (int) $couponId)->data(array('issued_quantity' => $newValue))->update();
    }
}
