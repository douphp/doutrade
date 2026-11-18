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

namespace Dou\Admin\Model\Aftersale;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 售后寄回物流（aftersale_return）后台模型。
 */
class AftersaleReturn extends Model
{
    protected $table = 'aftersale_return';

    /**
     * 按售后单取寄回记录。
     *
     * @param Builder $query
     * @param mixed $aftersaleId
     * @return Builder
     */
    public function scopeFilterByAftersale(Builder $query, $aftersaleId)
    {
        return $query->where('aftersale_id', (int) $aftersaleId);
    }
}
