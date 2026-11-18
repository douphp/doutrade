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

namespace Dou\Admin\Request\Coupon;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台优惠券日志批量操作请求
 */
class CouponLogBatchActionFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        if ($this->scene === 'action') {
            return array(
                'action' => 'required|in:del_all',
                'checkbox' => 'required|array',
            );
        }

        return array();
    }
}
