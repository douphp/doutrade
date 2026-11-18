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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员导出列声明。
 *
 * 只描述「导出哪些列、表头文案、值如何格式化」，读写能力全部由
 * {@see \Dou\Vendor\Excel\Excel} 承担，本类不接触文件与数据库。
 */
class UserExcelDefinition
{
    /**
     * 导出字段（顺序即 Excel 列顺序）。
     *
     * @return array
     */
    public static function fields()
    {
        return array('email', 'nickname', 'mobile', 'contact', 'address', 'postcode', 'sex');
    }

    /**
     * 表头行。
     *
     * @return array
     */
    public static function head()
    {
        $head = array();
        foreach (self::fields() as $field) {
            $head[] = lang('user_' . $field);
        }

        return $head;
    }

    /**
     * 单行数据（$user 为会员属性与默认联系人快照合并后的数组）。
     *
     * @param array $user
     * @return array
     */
    public static function row(array $user)
    {
        $line = array();
        foreach (self::fields() as $field) {
            $value = isset($user[$field]) ? $user[$field] : '';
            if ($field === 'sex') {
                // dou_user.sex 为 0/1，导出为可读文案
                $value = $value ? lang('user_man') : lang('user_woman');
            }
            $line[] = $value;
        }

        return $line;
    }
}
