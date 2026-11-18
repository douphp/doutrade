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

namespace Dou\Admin\Model\Attribute;

use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台款式属性值数据访问（attribute_value）
 */
class AttributeValue extends Model
{
    /** @var string */
    protected $table = 'attribute_value';

    /** @var string */
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'item_id' => 'int',
        'att_id' => 'int',
        'price_change' => 'float',
    );

    /** @var array */
    protected $fillable = array(
        'module',
        'item_id',
        'att_id',
        'value',
        'type',
        'image',
        'remark',
        'price_change',
    );
}
