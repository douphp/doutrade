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

namespace Dou\Admin\Service\Plugin;

use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Plugin\Plugin;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Plugin\PluginClient;
use Dou\Core\Infra\Plugin\PluginConfig;
use Dou\Core\Infra\Plugin\Registry\ConnectPluginRegistry;
use Dou\Core\Infra\Plugin\Registry\PaymentPluginRegistry;
use Dou\Core\Infra\Plugin\Registry\ShippingPluginRegistry;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台插件业务（PluginController）
 */
class PluginService extends BaseService
{
    /** @var PaymentPluginRegistry */
    private $paymentRegistry;

    /** @var ConnectPluginRegistry */
    private $connectRegistry;

    /** @var ShippingPluginRegistry */
    private $shippingRegistry;

    /** @var Cloud */
    private $cloud;

    /**
     * @param PaymentPluginRegistry $paymentRegistry
     * @param ConnectPluginRegistry $connectRegistry
     * @param ShippingPluginRegistry $shippingRegistry
     * @param Cloud $cloud
     */
    public function __construct(
        PaymentPluginRegistry $paymentRegistry,
        ConnectPluginRegistry $connectRegistry,
        ShippingPluginRegistry $shippingRegistry,
        Cloud $cloud
    ) {
        $this->paymentRegistry = $paymentRegistry;
        $this->connectRegistry = $connectRegistry;
        $this->shippingRegistry = $shippingRegistry;
        $this->cloud = $cloud;
    }

    /**
     * @return string 绝对路径，末尾含 /
     */
    public function getPluginCoreDirectory()
    {
        return PLUGIN_PATH;
    }

    /**
     * @return void
     */
    public function ensurePluginCoreDirectory()
    {
        $dir = $this->getPluginCoreDirectory();
        if (!file_exists($dir)) {
            mkdir($dir, 0777);
        }
    }

    /**
     * 已安装目录下的插件列表（含是否已在库中启用），三大 Registry 合并输出。
     *
     * @return array
     */
    public function buildPluginListData()
    {
        $plugin_list = array();
        $allMeta = array_merge(
            $this->paymentRegistry->allMeta(),
            $this->connectRegistry->allMeta(),
            $this->shippingRegistry->allMeta()
        );

        // 一次查询拿到全部已启用 slug，避免逐行查询。
        $enabledSlugs = array();
        if (DB::tableExist('plugin')) {
            foreach ((array) DB::table('plugin')->select() as $row) {
                if (isset($row['slug'])) {
                    $enabledSlugs[strtolower((string) $row['slug'])] = true;
                }
            }
        }

        $seen = array();
        foreach ($allMeta as $meta) {
            $uniqueId = isset($meta['slug']) ? (string) $meta['slug'] : '';
            if ($uniqueId === '' || isset($seen[$uniqueId])) {
                continue;
            }
            $seen[$uniqueId] = true;

            $allowClient = isset($meta['allow_client']) ? (string) $meta['allow_client'] : 'ALL';
            $group = isset($meta['plugin_group']) ? (string) $meta['plugin_group'] : '';
            $deprecated = PluginClient::isDeprecated($uniqueId);
            $webCapable = PluginClient::matches($allowClient, PluginClient::WEB);
            $mpCapable = !$deprecated
                && $group === 'payment'
                && PluginClient::matches($allowClient, PluginClient::MP)
                && $this->paymentRegistry->supportsMiniProgram($uniqueId);

            $plugin_list[] = array(
                'slug' => $uniqueId,
                'name' => isset($meta['name']) ? $meta['name'] : $uniqueId,
                'description' => isset($meta['description']) ? $meta['description'] : '',
                'ver' => isset($meta['ver']) ? $meta['ver'] : '1.0',
                'plugin_group' => $group,
                'plugin_group_label' => $this->groupLabel($group),
                'allow_client' => $allowClient,
                'clients_label' => $deprecated
                    ? (lang('plugin_deprecated') !== '' ? lang('plugin_deprecated') : '已废弃')
                    : $this->clientsLabel($webCapable, $mpCapable),
                'deprecated' => $deprecated,
                'enabled' => isset($enabledSlugs[$uniqueId]),
                'if_read' => '1',
            );
        }

        return $plugin_list;
    }

    /**
     * 插件分组展示文案。
     *
     * @param string $group
     * @return string
     */
    private function groupLabel($group)
    {
        $map = array(
            'payment' => array(lang('plugin_group_payment'), '支付'),
            'connect' => array(lang('plugin_group_connect'), '登录'),
            'shipping' => array(lang('plugin_group_shipping'), '物流'),
        );
        $group = (string) $group;
        if (isset($map[$group])) {
            return $map[$group][0] !== '' ? $map[$group][0] : $map[$group][1];
        }

        $other = lang('plugin_group_other');

        return $group !== '' ? $group : ($other !== '' ? $other : '其他');
    }

    /**
     * 客户端能力的展示文案。
     *
     * @param bool $webCapable
     * @param bool $mpCapable
     * @return string
     */
    private function clientsLabel($webCapable, $mpCapable)
    {
        if ($webCapable && $mpCapable) {
            $label = lang('plugin_client_all');

            return $label !== '' ? $label : '网页 + 小程序';
        }
        if ($mpCapable) {
            $label = lang('plugin_client_mp');

            return $label !== '' ? $label : '小程序';
        }
        if ($webCapable) {
            $label = lang('plugin_client_web');

            return $label !== '' ? $label : '网页';
        }

        return '';
    }

    /**
     * 云安装列表页所需 assign 数据（由 Controller 传入 GET 数组）。
     *
     * @param array $getRequest 查询参数
     * @return array
     */
    public function buildPluginInstallData(array $getRequest)
    {
        return array(
            'get' => urlencode(serialize($getRequest)),
            'localsite' => $this->cloud->localSitePayload('plugin'),
        );
    }

    /**
     * @param string $uniqueId
     * @return array|null
     */
    public function findInstalledByUniqueId($uniqueId)
    {
        return Plugin::findByUniqueId(strtolower(trim((string) $uniqueId)));
    }

    /**
     * 通过 Registry 解析插件定义，合并库中配置（编辑）或空默认（启用）。
     *
     * @param string $uniqueId
     * @param array|null $rowFromDb find() 结果；null 表示首次启用
     * @return array|null 失败返回 null
     */
    public function loadPluginDefinition($uniqueId, $rowFromDb = null)
    {
        $uniqueId = strtolower(trim((string) $uniqueId));
        $meta = null;
        if (($provider = $this->paymentRegistry->provider($uniqueId)) !== null) {
            $meta = $provider->meta();
        } elseif (($provider = $this->connectRegistry->provider($uniqueId)) !== null) {
            $meta = $provider->meta();
        } elseif (($provider = $this->shippingRegistry->provider($uniqueId)) !== null) {
            $meta = $provider->meta();
        }
        if (!is_array($meta) || $provider === null) {
            return null;
        }

        if ($rowFromDb === null) {
            $pluginMysql = array(
                'name' => '',
                'description' => '',
                'config' => array(),
            );
        } else {
            $pluginMysql = $rowFromDb;
            if (isset($pluginMysql['config'])) {
                $pluginMysql['config'] = PluginConfig::decode($pluginMysql['config']);
            }
        }

        $pluginId = strtolower(trim((string) $provider->pluginId()));
        $plugin = array(
            'slug' => $pluginId,
            'name' => (isset($pluginMysql['name']) && $pluginMysql['name'] !== '')
                ? $pluginMysql['name']
                : (isset($meta['name']) ? $meta['name'] : ''),
            'description' => (isset($pluginMysql['description']) && $pluginMysql['description'] !== '')
                ? $pluginMysql['description']
                : (isset($meta['description']) ? $meta['description'] : ''),
            'ver' => isset($meta['ver']) ? $meta['ver'] : '1.0',
            'plugin_group' => isset($meta['plugin_group']) ? $meta['plugin_group'] : '',
            'allow_client' => isset($meta['allow_client']) ? $meta['allow_client'] : 'ALL',
            'deprecated' => PluginClient::isDeprecated($pluginId),
            'config' => array(),
        );

        $savedConfig = isset($pluginMysql['config']) && is_array($pluginMysql['config']) ? $pluginMysql['config'] : array();
        $schema = isset($meta['config']) && is_array($meta['config']) ? $meta['config'] : array();
        foreach ($schema as $item) {
            if (!isset($item['field'])) {
                continue;
            }
            $field = $item['field'];
            $entry = $item;
            $savedValue = isset($savedConfig[$field]) ? $savedConfig[$field] : '';
            if (is_array($savedValue) || is_object($savedValue)) {
                $savedValue = '';
            }
            $entry['value'] = isset($savedConfig[$field])
                ? (string) $savedValue
                : (isset($entry['value']) ? (string) $entry['value'] : '');
            if (!isset($entry['type']) || $entry['type'] === '') {
                $entry['type'] = 'text';
            }
            $entry['secret'] = $this->isSensitiveField($field)
                || (isset($entry['type']) && $entry['type'] === 'password');
            $entry['has_value'] = $entry['secret'] && (string) $savedValue !== '';
            if ($entry['secret']) {
                // 密钥不回显：表单留空表示不修改，由 update() 合并旧值。
                $entry['value'] = '';
            }
            if (!isset($entry['option']) || !is_array($entry['option'])) {
                $entry['option'] = array();
            }
            if (!isset($entry['desc'])) {
                $entry['desc'] = '';
            }
            if (!isset($entry['name'])) {
                $entry['name'] = $field;
            }
            $plugin['config'][] = $entry;
        }

        return $plugin;
    }

    /**
     * 只保留 schema 中声明的字段，并归一为字符串，避免写入未知键或数组。
     *
     * @param array $config
     * @param array $schema
     * @return array
     */
    private function sanitizeConfig(array $config, array $schema)
    {
        $fields = array();
        foreach ($schema as $item) {
            if (isset($item['field'])) {
                $fields[(string) $item['field']] = true;
            }
        }

        $out = array();
        foreach ($config as $key => $value) {
            if (!isset($fields[$key]) || is_array($value) || is_object($value)) {
                continue;
            }
            $out[(string) $key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $out;
    }

    /**
     * 是否为密钥类字段（不回显，留空不覆盖）。
     *
     * @param string $field
     * @return bool
     */
    private function isSensitiveField($field)
    {
        $field = strtolower(trim((string) $field));
        if ($field === '') {
            return false;
        }
        if (in_array($field, array(
            'key',
            'secret',
            'psk',
            'password',
            'appsecret',
            'appkey',
            'access_token',
            'client_secret',
            'merchant_private_key',
            'rsa_private_key',
        ), true)) {
            return true;
        }
        if (strpos($field, 'appsecret') === 0) {
            return true;
        }
        if (substr($field, -4) === '_key' || substr($field, -7) === '_secret') {
            return true;
        }

        return false;
    }

    /**
     * 首次启用表单数据（插件尚未入库）。
     *
     * @param string $slug Registry 中的插件标识
     * @return array|null 形如 array('plugin' => …)，无效时返回 null
     */
    public function buildPluginCreateData($slug)
    {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || PluginClient::isDeprecated($slug)) {
            return null;
        }

        $plugin = $this->loadPluginDefinition($slug, null);
        if ($plugin === null) {
            return null;
        }

        return array('plugin' => $plugin);
    }

    /**
     * 已启用插件的编辑表单数据。
     *
     * @param string $slug 插件 slug
     * @return array|null 形如 array('plugin' => …, 'plugin_mysql' => …)，无效时返回 null
     */
    public function buildPluginEditData($slug)
    {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '') {
            return null;
        }

        $pluginMysql = $this->findInstalledByUniqueId($slug);
        if (!$pluginMysql) {
            return null;
        }

        $plugin = $this->loadPluginDefinition($slug, $pluginMysql);
        if ($plugin === null) {
            return null;
        }

        return array(
            'plugin' => $plugin,
            'plugin_mysql' => $pluginMysql,
        );
    }

    /**
     * 新增启用后的入库（字段已由 PluginFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return void
     */
    public function insert(array $data)
    {
        $data['slug'] = strtolower(trim(isset($data['slug']) ? (string) $data['slug'] : ''));
        if (PluginClient::isDeprecated($data['slug'])) {
            throw new DomainException(lang('plugin_deprecated_enable_forbidden'), route('admin.plugin'));
        }

        $definition = $this->loadPluginDefinition(isset($data['slug']) ? $data['slug'] : '', null);
        if ($definition === null) {
            throw new DomainException(lang('plugin_not_found'), route('admin.plugin'));
        }
        if (Plugin::findByUniqueId($data['slug'])) {
            throw new DomainException(lang('plugin_already_enabled'), route('admin.plugin'));
        }
        $data['plugin_group'] = $definition['plugin_group'];
        $data['allow_client'] = $definition['allow_client'];

        $allowClient = (isset($data['allow_client']) && $data['allow_client'] !== '')
            ? $data['allow_client']
            : 'ALL';
        $configRaw = $this->sanitizeConfig(
            (isset($data['config']) && is_array($data['config'])) ? $data['config'] : array(),
            $definition['config']
        );
        $config = PluginConfig::encode($configRaw);

        Plugin::create(array(
            'slug' => $data['slug'],
            'name' => $data['name'],
            'config' => $config,
            'plugin_group' => isset($data['plugin_group']) ? $data['plugin_group'] : '',
            'allow_client' => $allowClient,
            'description' => isset($data['description']) ? $data['description'] : '',
        ));

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::ENABLE, 1, (string) $data['name']);
    }

    /**
     * 更新已启用插件（字段已由 PluginFormRequest 校验与白名单）。
     *
     * @param array $data
     * @return void
     */
    public function update(array $data)
    {
        $slug = strtolower(trim(isset($data['slug']) ? (string) $data['slug'] : ''));

        $definition = $this->loadPluginDefinition($slug, null);
        if ($definition === null) {
            throw new DomainException(lang('plugin_not_found'), route('admin.plugin'));
        }
        $data['plugin_group'] = $definition['plugin_group'];
        $data['allow_client'] = $definition['allow_client'];

        $allowClient = (isset($data['allow_client']) && $data['allow_client'] !== '')
            ? $data['allow_client']
            : 'ALL';

        $model = Plugin::find($slug);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.plugin'));
        }
        $oldRow = $model->getAttributes();
        $oldConfig = PluginConfig::decode(isset($oldRow['config']) ? $oldRow['config'] : array());

        $configRaw = $this->sanitizeConfig(
            (isset($data['config']) && is_array($data['config'])) ? $data['config'] : array(),
            $definition['config']
        );
        // 密钥字段留空表示不修改：把旧值合并回来，避免空提交覆盖已有密钥。
        foreach ($definition['config'] as $item) {
            if (empty($item['secret']) || !isset($item['field'])) {
                continue;
            }
            $field = (string) $item['field'];
            if (!isset($configRaw[$field]) || (string) $configRaw[$field] === '') {
                $oldValue = isset($oldConfig[$field]) ? $oldConfig[$field] : '';
                if (is_array($oldValue) || is_object($oldValue)) {
                    $oldValue = '';
                }
                $configRaw[$field] = (string) $oldValue;
            }
        }
        $config = PluginConfig::encode($configRaw);

        $model->fill(array(
            'name' => $data['name'],
            'config' => $config,
            'plugin_group' => isset($data['plugin_group']) ? $data['plugin_group'] : '',
            'allow_client' => $allowClient,
            'description' => isset($data['description']) ? $data['description'] : '',
        ), 'update')->save();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * @param string $uniqueId
     * @return void
     */
    public function disable($uniqueId)
    {
        $uniqueId = strtolower(trim((string) $uniqueId));
        $name = Plugin::getNameByUniqueId($uniqueId);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DISABLE, 1, (string) $name);
        Plugin::destroy($uniqueId);
        return $name;
    }

    /**
     * @param string $uniqueId
     * @param array $post
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException
     */
    public function delete($uniqueId, array $post)
    {
        $uniqueId = strtolower(trim((string) $uniqueId));
        if (!empty($post['confirm'])) {
            if (Plugin::findByUniqueId($uniqueId)) {
                throw new DomainException(lang('plugin_delete_enabled_forbidden'), route('admin.plugin'));
            }

            $plugin_dir = $this->getPluginCoreDirectory();
            $plugin_array = FileHelper::getSubdirs($plugin_dir);
            if (!in_array($uniqueId, $plugin_array)) {
                throw new DomainException(lang('illegal'), route('admin.plugin'));
            }
            FileHelper::delDir($plugin_dir . $uniqueId);
            $this->cloud->changeUpdateDate('plugin', $uniqueId, true);
            // 插件卸载后其专属还原点不可再用，一并清理（还原中的快照由仓库层保护）
            if (class_exists('Dou\\Core\\Service\\Snapshot\\SnapshotRepository')) {
                $repository = new \Dou\Core\Service\Snapshot\SnapshotRepository(ROOT_PATH);
                $repository->deleteByScope('plugin', $uniqueId);
            }
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $uniqueId);
            return array(
                'message' => lang('del_succes'),
                'back_url' => route('admin.plugin'),
            );
        }

        $msg = preg_replace('/d%/Ums', $uniqueId, lang('del_check'));
        return array(
            'message' => $msg,
            'back_url' => route('admin.plugin'),
            'timeout' => '30',
            'confirm_url' => route('admin.plugin.destroy', array('slug' => $uniqueId)),
        );
    }
}
