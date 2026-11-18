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
 * 插件客户端归属判定。
 *
 * 现行客户端只有两端：
 *   - WEB：网页端，含 PC 与响应式手机浏览器（旧独立手机版已不存在）
 *   - MP ：微信小程序
 *   - ALL：两端都支持
 *
 * 历史值 PC / MOBILE 统一归一为 WEB；alipaywap 属于旧手机版遗留插件，标记废弃。
 */
class PluginClient
{
    const WEB = 'WEB';
    const MP = 'MP';
    const ALL = 'ALL';

    /**
     * 归一客户端值。
     *
     * @param string $client
     * @return string WEB|MP
     */
    public static function normalize($client)
    {
        $client = strtoupper(trim((string) $client));

        if ($client === self::MP) {
            return self::MP;
        }

        // PC / MOBILE / WEB / 空值统一按网页端处理。
        return self::WEB;
    }

    /**
     * 判断插件声明的客户端是否覆盖目标客户端。
     *
     * @param string $stored 插件声明的 allow_client
     * @param string $client 目标客户端
     * @return bool
     */
    public static function matches($stored, $client)
    {
        $stored = strtoupper(trim((string) $stored));
        $client = self::normalize($client);

        if ($stored === '' || $stored === self::ALL) {
            return true;
        }

        if ($client === self::WEB) {
            return in_array($stored, array(self::WEB, 'PC', 'MOBILE'), true);
        }

        return $stored === $client;
    }

    /**
     * 是否为已废弃插件（保留目录/记录，但不再进入业务列表）。
     *
     * @param string $slug
     * @return bool
     */
    public static function isDeprecated($slug)
    {
        return strtolower(trim((string) $slug)) === 'alipaywap';
    }
}
