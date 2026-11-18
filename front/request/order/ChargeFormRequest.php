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

namespace Dou\Front\Request\Order;

use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台发起支付（route=order/cashier/charge）。
 *
 * cashier 选定支付方式后 form POST 到这里，控制器据此创建 dou_order_payment 行并调起第三方网关。
 */
class ChargeFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'order_sn' => 'required|number',
            // 购物订单必须选择支付渠道。
            'pay_id' => 'required|illegal_char',
        );
    }
}
