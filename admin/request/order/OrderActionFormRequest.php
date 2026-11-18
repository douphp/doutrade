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

namespace Dou\Admin\Request\Order;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台订单批量操作请求校验（route=order/action）。
 *
 * 接受 del_all / cancel_all / excel / excel_all 四种动作。
 * checkbox 不再强制必填：excel_all 无需勾选，空值校验由 OrderService 按动作判定；
 * 筛选字段仅用于 excel_all 按当前筛选导出，只做白名单开禁，不强制校验。
 */
class OrderActionFormRequest extends FormRequest
{
    /**
     * 输入规则（请求层）：与 Order 持久化字段无直接对应关系。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'action' => 'required|in:del_all,cancel_all,excel,excel_all',
            'checkbox' => 'array',
            'username' => '',
            'status' => '',
            'order_sn' => '',
            'order_consignee' => '',
            'time_start' => '',
            'time_end' => '',
        );
    }
}
