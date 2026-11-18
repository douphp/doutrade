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
 * 后台会员批量操作请求校验（route=user/action）。
 *
 * 支持 del_all / excel / excel_all 三种动作；excel_all 允许 checkbox 为空。
 */
class UserActionFormRequest extends FormRequest
{
    /**
     * 输入规则（请求层）：
     * - action 必填，限定为 del_all/excel/excel_all；
     * - checkbox 可选数组，excel_all 时无需勾选条目。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'action' => 'required|in:del_all,excel,excel_all',
            'checkbox' => 'array',
        );
    }
}
