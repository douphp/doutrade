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
 * 后台会员等级表单请求（store / update 场景）。
 *
 * rules() 同时定义校验与 validated() 白名单；
 * 持久化字段白名单见 UserLevel::$fillable。
 *
 * @see \Dou\Admin\Model\User\UserLevel
 */
class LevelFormRequest extends FormRequest
{
    /**
     * 合并校验数据（update 时 level_id 可能在查询串，此处防御性合并）。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update' && !isset($data['level_id']) && request()) {
            $data['level_id'] = request()->integer('level_id', 0);
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
        $rules = array(
            'name' => 'required|illegal_char',
            'upgrade_type' => 'illegal_char',
            'upgrade_condition' => 'required',
            'good_discount' => 'required|price',
            // 图标删除标记：随主表单提交后由 Service 清空字段并回收旧图
            'icon_delete' => '',
        );

        if ($this->scene === 'update') {
            $rules['level_id'] = 'required|integer|min_value:1';
        }

        return $rules;
    }
}
