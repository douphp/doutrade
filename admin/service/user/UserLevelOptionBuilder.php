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

namespace Dou\Admin\Service\User;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员等级下拉/价格档位 ViewModel。
 *
 * 归属 user 模块的「跨模块只读入口」，暴露给 product 等可能在 user 未安装时仍存在的模块。
 * 调用方经 `Module::make('user.user_level_option_builder')` 取实例
 * （user 未启用 / 类不在磁盘时返回 null）：第一段 `user` = `features.user` 标志位 +
 * namespace 段 `User`，第二段 `user_level_option_builder` 对应类基名 `UserLevelOptionBuilder`，
 * 由 {@see \Dou\Core\Foundation\Extension\Module} 跨 layer 自动探测命中本类。
 */
class UserLevelOptionBuilder extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param string $curLevel 当前选中等级 ID
     * @param array $levelPrice 各等级价，键为 level_id
     * @return array 会员模块未开启返回空数组
     */
    public function options($curLevel = '', $levelPrice = array())
    {
        if (!Config::get('features.user', false)) {
            return array();
        }

        $resultList = DB::table('user_level')->order('id ASC')->select();
        $optionList = array();
        foreach ((array) $resultList as $row) {
            $price = (is_array($levelPrice) && isset($levelPrice[$row['id']])) ? $levelPrice[$row['id']] : '';
            $optionList[] = array(
                'level_id' => $row['id'],
                'name' => $row['name'],
                'cur' => $row['id'] == $curLevel ? true : false,
                'price' => $price,
                'price_format' => $price !== '' ? Util::formatPrice($price) : '',
            );
        }

        return $optionList;
    }
}
