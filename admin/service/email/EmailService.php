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

namespace Dou\Admin\Service\Email;

use Dou\Admin\Model\Email\Email;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订阅邮箱业务服务
 */
class EmailService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * @param int $page
     * @return array
     */
    public function buildEmailListData($page)
    {
        $result = Email::applyDefaultOrder()
            ->paginate(30, $page, route('admin.email'));
        $emailList = array();
        foreach ($result['list'] as $model) {
            $row = $model->getAttributes();
            $emailList[] = array(
                'id' => $row['id'],
                'email' => $row['email'],
                'ip' => $row['ip'],
                'status' => language()->dataLangFormat('email_status_', $row['status']),
                'created_at' => date('Y-m-d', strtotime($row['created_at'])),
            );
        }
        return array(
            'email_list' => $emailList,
            'pager' => $result['pager'],
        );
    }

    /**
     * 标记已读并回到列表当前页
     *
     * @param string $id
     * @param int $page
     * @return void
     */
    public function markCheckedAndRedirect($id, $page)
    {
        if ($id > 0) {
            Email::markAsRead((int) $id);
        }
        throw new RedirectException(Util::normalizeQueryString(route('admin.email', array(), array('page' => (int) $page))));
    }

    /**
     * @param array $post
     * @return void
     */
    public function deleteSelected(array $ids)
    {
        if (empty($ids)) {
            throw new DomainException(lang('email_select_empty'), route('admin.email'));
        }

        Email::deleteByIds($ids);
        $checkbox = Util::buildSqlInClause($ids);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'EMAIL:' . addslashes($checkbox));
    }
}
