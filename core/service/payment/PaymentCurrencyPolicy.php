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

namespace Dou\Core\Service\Payment;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Plugin\ManifestValidator;
use Dou\Core\Infra\Plugin\PluginConfig;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 支付方式币种能力判定。
 *
 * 币种能力由插件自身声明（plugin/<slug>/manifest.php），核心不内置任何插件币种清单：
 *   - `'currencies' => array('CNY')`：支持币种集合；
 *   - `'currencies' => array('*')`：不限制币种（线下转账 / 货到付款等人工收款方式）；
 *   - `'currency_field' => 'currency_code'`：可选，插件 config 中“实际收款币种”字段名。
 *
 * 判定规则：
 *   - 未声明 currencies 的插件（含第三方旧包）只允许基准币，避免外币金额交给未知网关；
 *   - 声明了 currency_field 的插件，配置的收款币种必须与订单币种一致（网关按配置币种发起收款）。
 */
class PaymentCurrencyPolicy
{
    /** @var array<string,array> manifest 缓存（slug => 声明数组） */
    private static $manifestCache = array();

    /**
     * 判断支付方式是否支持指定币种。
     *
     * @param string $slug 插件 slug
     * @param string $currency 订单币种代码
     * @param array|string $config 插件 config（数组或原始序列化/JSON 字符串）
     * @return bool
     */
    public static function supports($slug, $currency, $config = array())
    {
        $slug = strtolower(trim((string) $slug));
        $currency = strtoupper(trim((string) $currency));
        if ($slug === '') {
            return false;
        }
        if ($currency === '') {
            return true;
        }

        $manifest = self::manifest($slug);
        $currencies = ManifestValidator::extractCurrencies($manifest);
        if ($currencies === null) {
            return $currency === self::baseCode();
        }
        if (in_array('*', $currencies, true)) {
            return true;
        }
        if (!in_array($currency, $currencies, true)) {
            return false;
        }

        $field = ManifestValidator::extractCurrencyField($manifest);
        if ($field === null) {
            return true;
        }

        $config = self::decodeConfig($config);
        $configured = isset($config[$field]) ? strtoupper(trim((string) $config[$field])) : '';
        return $configured !== '' && $configured === $currency;
    }

    /**
     * 读取插件 manifest（slug 必须是合法目录名，防止路径穿越）。
     *
     * @param string $slug
     * @return array
     */
    private static function manifest($slug)
    {
        if (isset(self::$manifestCache[$slug])) {
            return self::$manifestCache[$slug];
        }

        $manifest = array();
        if (defined('PLUGIN_PATH') && preg_match('/^[a-z0-9_]+$/', $slug) === 1) {
            $path = PLUGIN_PATH . $slug . '/manifest.php';
            if (is_file($path)) {
                $data = include $path;
                if (is_array($data)) {
                    $manifest = $data;
                }
            }
        }

        self::$manifestCache[$slug] = $manifest;
        return $manifest;
    }

    /**
     * 插件 config 兼容解析：数组 / JSON / PHP serialize 三种存储形态。
     *
     * @param array|string $raw
     * @return array
     */
    public static function decodeConfig($raw)
    {
        return PluginConfig::decode($raw);
    }

    /**
     * 支付列表行过滤：保留支持指定币种的插件。
     *
     * @param array $rows plugin 表行列表
     * @param string $currency
     * @return array
     */
    public static function filterRows(array $rows, $currency)
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            return $rows;
        }

        $result = array();
        foreach ($rows as $row) {
            $slug = isset($row['slug']) ? (string) $row['slug'] : '';
            $config = isset($row['config']) ? $row['config'] : array();
            if (self::supports($slug, $currency, $config)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * 基准币代码（config.currency_code 归一）。
     *
     * @return string
     */
    public static function baseCode()
    {
        $code = strtoupper(trim((string) Config::get('site.currency_base_code', '')));
        return $code !== '' ? $code : 'CNY';
    }
}
