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
 * 后台订单物流提交请求校验（route=order/tracking）。
 *
 * 与 Order 中物流相关持久化字段（shipping_id、tracking_no、shipped_at）对应；
 * 业务逻辑（首次发货推进状态、解锁库存）在 OrderService::tracking()。
 */
class OrderTrackingFormRequest extends FormRequest
{
    /**
     * 输入规则（请求层）：
     * - order_id 必填且为正整数；
     * - shipping_id 与 tracking_no 为可选文本（首次填写时由 Service 推进状态）。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'order_id' => 'required|integer|min_value:1',
            'shipping_id' => 'max:50',
            'tracking_no' => 'max:100',
        );
    }
}
