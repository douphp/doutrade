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

namespace Dou\Admin\Request\User;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员状态变更请求校验（route=user/set_status）。
 *
 * new_status 必须落在 UserStatus::all() 内；合法迁移与审计由 UserService::setStatus 承担。
 */
class UserStatusFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'user_id' => 'required|integer|min_value:1',
            'new_status' => 'required|in:active,suspended,deactivated',
        );
    }
}
