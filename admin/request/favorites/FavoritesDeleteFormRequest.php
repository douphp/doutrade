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

namespace Dou\Admin\Request\Favorites;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台收藏单条删除（含二次确认）请求校验。
 */
class FavoritesDeleteFormRequest extends FormRequest
{
    /**
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
     * @return array
     */
    public function rules()
    {
        return array(
            'id' => 'required|integer|min_value:1',
            'confirm' => '',
        );
    }
}
