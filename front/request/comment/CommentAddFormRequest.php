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

namespace Dou\Front\Request\Comment;

use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 提交评论（route=comment/insert）。
 *
 * 仅校验长度与必填；HTML 结构字符、中文必含、登录态、发表资格、验证码与
 * 业务规则由 CommentService::processStore 处理。
 */
class CommentAddFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'content' => 'required|max:300',
            'star' => 'integer',
            'module' => 'required',
            'item_id' => 'required',
        );
    }
}
