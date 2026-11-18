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
 * 插件目录发现（单请求缓存）。
 *
 * 三个 Registry 过去各自 scandir + include manifest，同一请求最多扫描三遍；
 * 这里统一扫描一次，按目录名返回 manifest，由各 Registry 再按分组过滤。
 */
class PluginDiscovery
{
    /** @var array|null 目录名 => manifest 数组 */
    private static $manifests = null;

    /**
     * @return array
     */
    public static function manifests()
    {
        if (self::$manifests !== null) {
            return self::$manifests;
        }

        $root = defined('PLUGIN_PATH') ? PLUGIN_PATH : '';
        if ($root === '' || !is_dir($root)) {
            return self::$manifests = array();
        }

        $dirs = scandir($root);
        if (!is_array($dirs)) {
            return self::$manifests = array();
        }

        $manifests = array();
        foreach ($dirs as $dirName) {
            if ($dirName === '.' || $dirName === '..') {
                continue;
            }
            $manifestPath = $root . $dirName . '/manifest.php';
            if (!is_file($manifestPath)) {
                continue;
            }
            $manifests[$dirName] = include $manifestPath;
        }

        return self::$manifests = $manifests;
    }

    /**
     * 清空缓存（测试/热重载场景）。
     *
     * @return void
     */
    public static function reset()
    {
        self::$manifests = null;
    }
}
