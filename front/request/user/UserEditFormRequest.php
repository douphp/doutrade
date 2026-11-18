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

namespace Dou\Front\Request\User;

use Dou\Front\Controller\Asset\LangController;
use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 个人资料保存（route=user/edit_post）。
 *
 * 仅校验基础字段非法字符与必填；昵称唯一性、头像上传等业务规则由 controller / ProfileService 处理。
 */
class UserEditFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $rules = array(
            'nickname' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'first_name' => 'illegal_char',
            'last_name' => 'illegal_char',
            'country' => 'illegal_char',
            'province' => 'illegal_char',
            'city' => 'illegal_char',
            'district' => 'illegal_char',
            'address' => 'illegal_char',
            'postcode' => 'illegal_char',
            'sex' => 'in:0,1',
        );

        // 英文模板只渲染 first_name/last_name，不渲染 contact；必须按有效语言切换必填项
        if (LangController::currentPack() === 'en_us') {
            $rules['first_name'] = 'required|illegal_char';
            $rules['last_name'] = 'required|illegal_char';
            $rules['contact'] = 'illegal_char';
        } else {
            $rules['contact'] = 'required|illegal_char';
        }

        return $rules;
    }
}
