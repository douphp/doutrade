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

namespace Dou\Core\Infra\Plugin\Registry;

use Dou\Core\Foundation\Container\Container;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\Contract\ConnectPluginProviderInterface;
use Dou\Core\Infra\Plugin\ManifestValidator;
use Dou\Core\Infra\Plugin\PluginDiscovery;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 第三方登录插件注册中心（自动发现 manifest + provider）。
 */
class ConnectPluginRegistry
{
    /** @var Container */
    private $container;

    /** @var array<string,string> pluginId => providerClass */
    private $providerClassMap = array();

    /** @var array<string,ConnectPluginProviderInterface> */
    private $providerInstances = array();

    /**
     * @return void
     */
    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->discover();
    }

    /**
     * 返回已发现的登录插件元信息。
     *
     * @return array
     */
    public function allMeta()
    {
        $list = array();
        foreach ($this->providerClassMap as $pluginId => $className) {
            unset($className);
            $provider = $this->provider($pluginId);
            if ($provider === null) {
                continue;
            }
            $meta = $provider->meta();
            if (!is_array($meta)) {
                continue;
            }
            $meta['slug'] = $pluginId;
            if (!isset($meta['plugin_group'])) {
                $meta['plugin_group'] = 'connect';
            }
            if (!isset($meta['allow_client'])) {
                $meta['allow_client'] = 'ALL';
            }
            if (!isset($meta['config']) || !is_array($meta['config'])) {
                $meta['config'] = array();
            }
            $list[] = $meta;
        }

        return $list;
    }

    /**
     * 按插件 ID 获取 provider。
     *
     * @param string $pluginId
     * @return ConnectPluginProviderInterface|null
     */
    public function provider($pluginId)
    {
        $pluginId = strtolower(trim((string) $pluginId));
        if ($pluginId === '' || !isset($this->providerClassMap[$pluginId])) {
            return null;
        }

        if (isset($this->providerInstances[$pluginId])) {
            return $this->providerInstances[$pluginId];
        }

        $className = $this->providerClassMap[$pluginId];
        if (!class_exists($className)) {
            return null;
        }

        $instance = $this->container->make($className);
        if (!$instance instanceof ConnectPluginProviderInterface) {
            return null;
        }

        $this->providerInstances[$pluginId] = $instance;
        return $instance;
    }

    /**
     * 是否为受支持的登录插件。
     *
     * @param string $pluginId
     * @return bool
     */
    public function has($pluginId)
    {
        return $this->provider($pluginId) !== null;
    }

    /**
     * 扫描插件目录下的 manifest.php 自动发现登录 Provider。
     *
     * @return void
     */
    private function discover()
    {
        foreach (PluginDiscovery::manifests() as $dirName => $manifest) {
            unset($dirName);

            // 校验 manifest 结构（白名单键 + provider FQCN 命名空间约束），通过才取 provider 类名
            $providerClass = ManifestValidator::extractProvider($manifest, 'connect');
            if ($providerClass === null) {
                continue;
            }
            if (!class_exists($providerClass)) {
                continue;
            }
            // 构造 Provider 可能因其依赖的可选模块未安装而失败：登录插件的 Service
            // 硬注入了 user 模块下发的 {@see \Dou\Core\Service\User\SnsLoginService}，
            // 未装 user 模块时该类不在磁盘，容器解析构造参数会抛 RuntimeException。
            // 按发现阶段既有的「静默跳过」约定处理：单个插件依赖缺失不应拖垮整个注册中心，
            // 否则后台 route=plugin 页在构造 PluginService 时即崩溃。跳过项记 debug 便于排查。
            try {
                $provider = $this->container->make($providerClass);
            } catch (\Exception $e) {
                $this->logSkipped($providerClass, $e);
                continue;
            } catch (\Throwable $e) {
                $this->logSkipped($providerClass, $e);
                continue;
            }
            if (!$provider instanceof ConnectPluginProviderInterface) {
                continue;
            }

            $pluginId = strtolower(trim((string) $provider->pluginId()));
            if ($pluginId === '') {
                continue;
            }
            $this->providerInstances[$pluginId] = $provider;
            $this->providerClassMap[$pluginId] = $providerClass;
        }
    }

    /**
     * 记录被跳过的 Provider。
     *
     * @param string $providerClass
     * @param \Exception|\Throwable $e
     * @return void
     */
    private function logSkipped($providerClass, $e)
    {
        Log::debug('connect plugin provider skipped: dependency unavailable', array(
            'channel' => 'plugin',
            'group' => 'connect',
            'provider' => $providerClass,
            'reason' => $e->getMessage(),
        ));
    }
}
