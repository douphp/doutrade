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
 * 插件配置编解码。
 *
 * 历史版本把 config 存成 PHP serialize，新版本统一写 JSON；读取端必须同时兼容：
 *   - 数组（已解码）
 *   - JSON 字符串
 *   - PHP serialize 字符串
 *
 * 统一入口放在 Infra 层，避免核心 Service、后台 Service、结算读模型各写一套。
 */
class PluginConfig
{
    /**
     * 解码插件配置。
     *
     * @param mixed $raw
     * @return array
     */
    public static function decode($raw)
    {
        if (is_array($raw)) {
            return $raw;
        }

        $raw = (string) $raw;
        if ($raw === '') {
            return array();
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }

        // 只接受数组形态的 serialize，避免反序列化出对象。
        if (strpos($raw, 'a:') === 0) {
            // 数组内部也可能夹带对象（O:<len>:"…" / C:<len>:"…"），历史插件配置只允许标量，直接拒绝。
            // 用 token 形态匹配而不是裸 "O:"，避免配置值里恰好含 "O:" 文本时被误判为空配置。
            if (preg_match('/[OC]:\d+:"/', $raw) === 1) {
                return array();
            }
            if (PHP_VERSION_ID >= 70000) {
                $data = @unserialize($raw, array('allowed_classes' => false));
            } else {
                $data = @unserialize($raw);
            }
            if (is_array($data)) {
                return $data;
            }
        }

        return array();
    }

    /**
     * 编码插件配置为 JSON。
     *
     * @param mixed $config
     * @return string
     */
    public static function encode($config)
    {
        $config = is_array($config) ? $config : array();
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
