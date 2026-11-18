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

namespace Dou\Core\Service\Plugin;

use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Plugin\PluginClient;
use Dou\Core\Infra\Plugin\PluginConfig;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentCurrencyPolicy;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 插件查询服务（Domain 服务）。
 *
 * 封装 plugin 表可用性、分组/槽位查询、默认支付方式等规则。
 * 跟随 plugin 模块装卸；卸载时由 {@see \Dou\Core\Service\Noop\NullPluginService} 兜底，
 * 由 {@see \Dou\Core\Foundation\Provider\PluginServiceProvider} 在容器中按 class_exists
 * 路由到本类或 Null 实现。
 */
class PluginService extends BaseService implements PluginServiceContract
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * plugin 模块已开启且 plugin 数据表存在。
     *
     * @return bool
     */
    public function isAvailable()
    {
        return Config::get('features.plugin', false) && DB::tableExist('plugin');
    }

    /**
     * 指定分组下是否存在插件记录。
     *
     * @param string $group 如 payment、shipping、connect
     * @return bool
     */
    public function hasGroup($group)
    {
        if (!$this->isAvailable() || $group === '') {
            return false;
        }

        return DB::table('plugin')->where('plugin_group', $group)->exists();
    }

    /**
     * 读取指定分组下首条插件记录的字段值。
     *
     * @param string $group
     * @param string $field
     * @return mixed
     */
    public function valueByGroup($group, $field = 'config')
    {
        if (!$this->isAvailable() || $group === '') {
            return false;
        }

        return DB::table('plugin')->where('plugin_group', $group)->value($field);
    }

    /**
     * 按 slug 读取插件表字段值。
     *
     * @param string $slug
     * @param string $field
     * @return mixed
     */
    public function valueBySlug($slug, $field = 'name')
    {
        if (!$this->isAvailable() || $slug === '') {
            return false;
        }

        return DB::table('plugin')->where('slug', $slug)->value($field);
    }

    /**
     * 按 slug 读取插件行（不存在返回空数组）。
     *
     * @param string $slug
     * @return array
     */
    public function getBySlug($slug)
    {
        if (!$this->isAvailable() || $slug === '') {
            return array();
        }

        $row = DB::table('plugin')->where('slug', $slug)->find();

        return is_array($row) ? $row : array();
    }

    /**
     * 按 slug 判断插件是否存在。
     *
     * @param string $slug
     * @return bool
     */
    public function existsBySlug($slug)
    {
        if (!$this->isAvailable() || $slug === '') {
            return false;
        }

        return DB::table('plugin')->where('slug', $slug)->exists();
    }

    /**
     * 按 slug 读取插件行，附带反序列化后的 `config`。
     *
     * 与 {@see getBySlug} 的差异：仅以 `tableExist('plugin')` 兜底，不要求 `features.plugin`，
     * 用于支付/物流/connect 等插件运行时读取自身配置（即使 plugin 模块未在前台菜单开启）。
     *
     * @param string $slug
     * @return array
     */
    public function getWithConfig($slug)
    {
        if (!DB::tableExist('plugin') || $slug === '') {
            return array();
        }

        $plugin = DB::table('plugin')->where('slug', $slug)->find();
        if (!is_array($plugin)) {
            return array();
        }
        $plugin['config'] = PluginConfig::decode(isset($plugin['config']) ? $plugin['config'] : '');

        return $plugin;
    }

    /**
     * 默认支付方式（网页端 offlinepay 优先，否则 wxpay）。
     *
     * 小程序端请使用支付方式列表（会额外校验 Provider 的小程序能力）。
     *
     * @param string $currency 目标币种；非空时过滤不支持该币种的网关
     * @return string 无可用网关时返回空串
     */
    public function defaultPaymentSlug($currency = '')
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $rows = (array) DB::table('plugin')->where('plugin_group', 'payment')->select();
        $rows = array_values(array_filter($rows, function ($row) {
            $slug = isset($row['slug']) ? (string) $row['slug'] : '';
            $allow = isset($row['allow_client']) ? $row['allow_client'] : 'ALL';

            return $slug !== 'wallet'
                && !PluginClient::isDeprecated($slug)
                && PluginClient::matches($allow, PluginClient::WEB);
        }));

        $currency = strtoupper(trim((string) $currency));
        if ($currency !== '') {
            $rows = PaymentCurrencyPolicy::filterRows($rows, $currency);
        }

        foreach ($rows as $row) {
            if (isset($row['slug']) && (string) $row['slug'] === 'offlinepay') {
                return 'offlinepay';
            }
        }
        foreach ($rows as $row) {
            if (isset($row['slug']) && (string) $row['slug'] === 'wxpay') {
                return 'wxpay';
            }
        }
        if (!empty($rows) && isset($rows[0]['slug'])) {
            return (string) $rows[0]['slug'];
        }

        return '';
    }

    /**
     * 是否启用了第三方登录插件（connect 分组下有记录）。
     *
     * @return bool
     */
    public function hasConnect()
    {
        return $this->hasGroup('connect');
    }
}
