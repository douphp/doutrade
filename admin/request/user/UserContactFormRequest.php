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
 * 后台会员联系方式更新请求（route=user/contact/update、book/contact/update）。
 */
class UserContactFormRequest extends FormRequest
{
    /**
     * 合并校验数据：id 通常来自查询串，这里防御性合并到校验数据。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if (!isset($data['id']) && request()) {
            $data['id'] = request()->integer('id', 0);
        }

        return $data;
    }

    /**
     * 输入规则（请求层）。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'id' => 'required|integer|min_value:1',
            'name' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'id_card' => 'illegal_char',
            'country' => 'illegal_char',
            'province' => 'illegal_char',
            'city' => 'illegal_char',
            'district' => 'illegal_char',
            'address' => 'illegal_char',
            'tag' => 'illegal_char',
            'is_default' => 'integer',
        );
    }
}
