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

namespace Dou\Admin\Request\Attribute;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 属性表单请求（按场景复用）。
 */
class AttributeFormRequest extends FormRequest
{
    /**
     * 更新场景补齐 att_id 入校验数据。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update' && !isset($data['att_id']) && request()) {
            $data['att_id'] = request()->integer('att_id', 0);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'category_id' => 'integer',
            'name' => 'required|max:150',
            'type' => 'required|in:text,image',
            'sort' => 'integer',
        );

        if ($this->scene === 'update') {
            $rules['att_id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
