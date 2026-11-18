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

namespace Dou\Core\Infra\Plugin;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * manifest.php 返回值的结构校验器。
 *
 * manifest.php 仍是 PHP 文件（return array(...)），由 Registry 在发现阶段 include。
 * 为降低 include 任意文件带来的风险，本校验器对返回数组做严格的白名单校验：
 *   - 仅允许已知的顶层键；
 *   - plugin_group 必须属于允许集合且与调用方期望分组一致；
 *   - provider 必须是字符串，且 FQCN 必须落在 `Dou\Plugin\` 命名空间下
 *     （autoload 仅会从 plugin/ 目录解析该前缀类，故可避免指向核心/管理端类）；
 *   - currencies / currency_field 为支付插件的能力声明，由核心按白名单规则解析。
 *
 * 校验失败一律返回 null，由调用方静默跳过该插件。
 */
class ManifestValidator
{
    /**
     * 允许出现在 manifest 顶层的键（白名单）。
     *
     * @var array
     */
    const ALLOWED_KEYS = array(
        'plugin_group',
        'provider',
        'currencies',
        'currency_field',
    );

    /**
     * 允许的插件分组。
     *
     * @var array
     */
    const ALLOWED_GROUPS = array(
        'payment',
        'connect',
        'shipping',
    );

    /**
     * provider FQCN 必须匹配的规则：
     * 以 `Dou\Plugin\` 开头，后接至少两段由字母/数字/下划线组成的命名空间段。
     * 例：Dou\Plugin\Alipay\AlipayProvider
     *
     * @var string
     */
    const PROVIDER_PATTERN = '/^Dou\\\\Plugin\\\\[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)+$/';

    /**
     * 校验 manifest 返回值并提取安全的 provider FQCN。
     *
     * @param mixed $manifest manifest.php 的返回值
     * @param string $expectedGroup 调用方期望的分组（payment/connect/shipping）
     *
     * @return string|null 校验通过的 provider FQCN；失败返回 null
     */
    public static function extractProvider($manifest, $expectedGroup)
    {
        if (!is_array($manifest)) {
            return null;
        }

        // 白名单键校验：出现未知键直接拒绝（防御 manifest 夹带异常声明）。
        foreach (array_keys($manifest) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                return null;
            }
        }

        if (!isset($manifest['plugin_group']) || !is_string($manifest['plugin_group'])) {
            return null;
        }
        $group = $manifest['plugin_group'];
        if (!in_array($group, self::ALLOWED_GROUPS, true)) {
            return null;
        }
        if ($group !== $expectedGroup) {
            return null;
        }

        if (!isset($manifest['provider']) || !is_string($manifest['provider'])) {
            return null;
        }
        $providerClass = $manifest['provider'];
        if ($providerClass === '' || preg_match(self::PROVIDER_PATTERN, $providerClass) !== 1) {
            return null;
        }

        return $providerClass;
    }

    /**
     * 解析支付插件声明的支持币种。
     *
     * 约定：`'currencies' => array('CNY', 'USD')`；`array('*')` 表示不限制币种
     * （线下付款、货到付款等由人工收款的支付方式）。
     *
     * @param mixed $manifest manifest.php 的返回值
     * @return array|null 归一后的币种代码列表；未声明或格式非法返回 null
     */
    public static function extractCurrencies($manifest)
    {
        if (!is_array($manifest) || !isset($manifest['currencies'])) {
            return null;
        }

        $list = $manifest['currencies'];
        if (!is_array($list) || $list === array()) {
            return null;
        }

        $out = array();
        foreach ($list as $code) {
            if (!is_string($code)) {
                return null;
            }
            $code = strtoupper(trim($code));
            if ($code === '*') {
                $out[] = '*';
                continue;
            }
            // 正向白名单：只接受 3 位字母的 ISO 4217 风格代码
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                return null;
            }
            $out[] = $code;
        }

        return array_values(array_unique($out));
    }

    /**
     * 解析支付插件 config 中“实际收款币种”字段名。
     *
     * @param mixed $manifest manifest.php 的返回值
     * @return string|null 合法字段名；未声明或格式非法返回 null
     */
    public static function extractCurrencyField($manifest)
    {
        if (!is_array($manifest) || !isset($manifest['currency_field'])) {
            return null;
        }

        $field = $manifest['currency_field'];
        if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) !== 1) {
            return null;
        }

        return $field;
    }
}
