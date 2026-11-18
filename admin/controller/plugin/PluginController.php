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

namespace Dou\Admin\Controller\Plugin;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Plugin\PluginFormRequest;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Plugin\PluginService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台插件（route=plugin / plugin/install / plugin/create …）
 *
 * 业务由 PluginService 与 PluginModel 承担；提交字段校验见 {@see PluginFormRequest}。
 */
class PluginController extends BaseController
{
    /** @var PluginService */
    private $pluginService;

    /** @var CloudService */
    private $cloudService;

    /**
     * @param PluginService $pluginService
     * @param CloudService $cloudService
     */
    public function __construct(PluginService $pluginService, CloudService $cloudService)
    {
        $this->pluginService = $pluginService;
        $this->cloudService = $cloudService;
    }

    /**
     * 插件列表
     *
     * @return Response
     */
    public function index()
    {
        $this->pluginService->ensurePluginCoreDirectory();

        return $this->view('plugin.htm', [
            'ur_here' => lang('plugin'),
            'rec' => 'default',
            'plugin_list' => $this->pluginService->buildPluginListData(),
        ]);
    }

    /**
     * 云安装列表
     *
     * @param Request $request
     * @return Response
     */
    public function install(Request $request)
    {
        $assigns = $this->pluginService->buildPluginInstallData($request->get());
        $cloudExtend = $this->cloudService->fetchExtendList('plugin', $request->get(), $assigns['localsite']);

        return $this->view('plugin.htm', [
            'ur_here' => lang('plugin'),
            'rec' => 'install',
            'cloud_extend' => $cloudExtend,
            // 安装状态（已安装 / 未安装）与模块页同口径；三列卡片布局由 cloud.css 默认提供
            'cloud_extend_show_state' => true,
            'cloud_extend_error' => $cloudExtend === null ? lang('cloud_connect_failed') : '',
        ]);
    }

    /**
     * 启用表单（首次入库前）
     *
     * @param Request $request
     * @return Response
     */
    public function create(Request $request)
    {
        $slug = $request->extendId('slug');
        if ($slug === '') {
            throw new DomainException(lang('illegal'), route('admin.plugin'));
        }

        $bundle = $this->pluginService->buildPluginCreateData($slug);
        if ($bundle === null) {
            throw new DomainException(lang('illegal'), route('admin.plugin'));
        }

        return $this->view('plugin.htm', [
            'ur_here' => lang('plugin_enable'),
            'page_actions' => array(
                array('href' => route('admin.plugin'), 'text' => lang('plugin'), 'style' => ''),
            ),
            'rec' => 'create',
            'plugin' => $bundle['plugin'],
        ]);
    }

    /**
     * 提交启用
     *
     * 字段白名单与校验规则定义在 {@see PluginFormRequest::rules()}。
     *
     * @param PluginFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(PluginFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->pluginService->insert($data);
        $slug = isset($data['slug']) ? (string) $data['slug'] : '';
        return redirect(route('admin.plugin.edit', array('slug' => rawurlencode($slug))))
            ->with('success', lang('plugin_enable_succes'), route('admin.plugin'), lang('back_to_list'));
    }

    /**
     * 编辑表单
     *
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request)
    {
        $slug = $request->extendId('slug');
        if ($slug === '') {
            throw new DomainException(lang('illegal'), route('admin.plugin'));
        }

        $bundle = $this->pluginService->buildPluginEditData($slug);
        if ($bundle === null) {
            throw new DomainException(lang('illegal'), route('admin.plugin'));
        }

        return $this->view('plugin.htm', [
            'ur_here' => lang('plugin_edit'),
            'page_actions' => array(
                array('href' => route('admin.plugin'), 'text' => lang('plugin'), 'style' => ''),
            ),
            'rec' => 'edit',
            'plugin' => $bundle['plugin'],
            'plugin_mysql' => $bundle['plugin_mysql'],
        ]);
    }

    /**
     * 提交编辑
     *
     * @param PluginFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function update(PluginFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();

        $this->pluginService->update($data);
        $slug = isset($data['slug']) ? (string) $data['slug'] : '';
        return redirect(route('admin.plugin.edit', array('slug' => rawurlencode($slug))))
            ->with('success', lang('plugin_edit_succes'), route('admin.plugin'), lang('back_to_list'));
    }

    /**
     * 禁用
     *
     * @param Request $request
     * @return Response
     */
    public function disable(Request $request)
    {
        $slug = $request->extendId('slug');
        if ($slug === '') {
            return redirect(route('admin.plugin'));
        }
        $this->pluginService->disable($slug);
        return redirect(route('admin.plugin'))->with('success', lang('plugin_disable_succes'));
    }

    /**
     * 删除插件目录
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $slug = $request->input('slug');
        if (!Check::extendId($slug)) {
            return redirect(route('admin.plugin'));
        }
        $result = $this->pluginService->delete($slug, $request->post());
        return $this->respondDeleteResult($result);
    }
}
