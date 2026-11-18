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

namespace Dou\Admin\Request\Area;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 地区表单请求（按场景复用）。
 */
class AreaFormRequest extends FormRequest
{
    /**
     * 提供校验数据源（在父类 POST 数据基础上按场景补充字段）。
     *
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
     * 表单规则（请求层）：
     * - 定义输入校验规则。
     * - 同时作为 FormRequest::validated() 的字段白名单键集合。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'name' => 'required|max:150',
            'parent_id' => 'integer',
            'sort' => 'integer',
            'lang' => 'alpha_dash|max:16',
        );

        if ($this->scene === 'update') {
            $rules['id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
