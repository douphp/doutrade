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
 * 后台优惠券表单请求
 */
class CouponFormRequest extends FormRequest
{
    /**
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();
        if ($this->scene === 'update' && !isset($data['id']) && request()) {
            $data['id'] = request()->integer('id', 0);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required|max:150',
            'type' => 'required|in:money,percent',
            'face_value' => 'required|numeric|min_value:0',
            'max_discount' => 'numeric|min_value:0',
            'limit_per_user' => 'integer|min_value:0',
            'total_quantity' => 'integer|min_value:0',
            'is_first_order_only' => 'required|in:0,1',
            'condition' => 'required|numeric|min_value:0',
            'scope_type' => 'required|in:all,module,category,product',
            'scope_ids' => '',
            'start_at' => 'required|date',
            'end_at' => 'required|date',
            'brief' => '',
            'status' => 'required|in:0,1',
            'sort' => 'integer',
            // 主图删除标记：随主表单提交后由 Service 清空字段并回收旧图
            'image_delete' => '',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
