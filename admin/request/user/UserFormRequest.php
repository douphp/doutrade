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
 * 后台会员表单请求（store / update 场景）。
 *
 * rules() 同时定义校验与 validated() 白名单；
 * 邮箱/手机至少其一非空、密码长度等业务规则由 UserService 处理。
 */
class UserFormRequest extends FormRequest
{
    /**
     * 合并校验数据（update 时 user_id 可能在查询串，此处防御性合并）。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update' && !isset($data['user_id']) && request()) {
            $data['user_id'] = request()->integer('user_id', 0);
        }

        return $data;
    }

    /**
     * 输入规则（请求层）；持久化字段白名单见 User::$fillable。
     *
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'level_id' => 'integer',
            'distribution_level_id' => 'integer',
            'email' => 'email',
            'mobile' => 'phone',
            'phone' => 'illegal_char',
            'contact' => 'illegal_char',
            'first_name' => 'illegal_char',
            'last_name' => 'illegal_char',
            'country' => 'illegal_char',
            'province' => 'illegal_char',
            'address' => 'illegal_char',
            'postcode' => 'illegal_char',
            'sex' => 'in:0,1',
            'nickname' => 'illegal_char',
            'password_confirm' => '',
        );

        if ($this->scene === 'update') {
            $rules['user_id'] = 'required|integer|min_value:1';
            $rules['email'] = 'email|unique:user,email,user_id';
            $rules['mobile'] = 'phone|unique:user,mobile,user_id';
            // 编辑场景：密码留空表示不修改；admin/view/user.htm 同步渲染了
            // password / password_confirm 两个输入框，confirmed 规则在空值时短路。
            $rules['password'] = 'password|confirmed';
        } else {
            $rules['email'] = 'email|unique:user,email';
            $rules['mobile'] = 'phone|unique:user,mobile';
            $rules['password'] = 'required|password|confirmed';
        }

        return $rules;
    }
}
