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

namespace Dou\Admin\Request\Plugin;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台插件表单请求（store / update 场景）。
 *
 * 字段白名单与校验规则定义于此；持久化字段与 {@see \Dou\Admin\Model\Plugin\Plugin::$fillable} 对齐。
 * slug 规则与后台路由 key 保持一致（字母开头，仅字母/数字/下划线/连字符，最长 30）。
 */
class PluginFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'slug' => 'required|regex:/^[A-Za-z][A-Za-z0-9_-]{0,29}$/',
            'name' => 'required|max:120',
            'description' => 'max:255',
            'plugin_group' => '',
            'allow_client' => '',
            'config' => 'array',
        );
    }
}
