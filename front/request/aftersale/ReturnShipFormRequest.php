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

namespace Dou\Front\Request\Aftersale;

use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 买家填写寄回运单（route=aftersale/return_ship）。
 *
 * 业务规则（状态归属、是否处于 WAITING_RETURN）留在 AftersaleService。
 */
class ReturnShipFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'id' => 'required|integer|min_value:1',
            'shipping_company' => 'required|illegal_char',
            'tracking_no' => 'required|illegal_char',
        );
    }
}
