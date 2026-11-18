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
 * 售后申请提交（route=aftersale/apply_post）。
 *
 * 业务规则（订单归属、是否已申请过等）留在 AftersaleService。
 */
class AftersaleApplyFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'order_sn' => 'required',
            'type' => 'required|in:refund_only,refund_with_return',
            'reason' => 'required|illegal_char',
        );
    }
}
