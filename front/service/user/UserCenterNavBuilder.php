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

namespace Dou\Front\Service\User;

use Dou\Core\Facade\Url;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\MenuIconMap;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台「会员中心子导航」ViewModel。
 *
 * `module.link_user_center` 是全量候选清单（与安装状态无关），本类负责按 `Config('features')`
 * 过滤出「已安装且可用」的模块，前台网页与小程序共用同一份结果：
 *  - 未安装/已关闭的模块不会出现在 features 中，直接跳过；
 *  - 会员衍生模块（{@see Module::requiresUser()}）还需 features.user 可用。
 * 再按语言包 `xxx_user_tree` 与是否小程序请求生成 URL；返回 `['user_tree' => [...]]`，
 * 由控制器显式 `assign('link_user_center', $builder->build(...))`。
 */
class UserCenterNavBuilder extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 构建会员中心子导航树。
     *
     * @param string $current_module 当前路由模块短名（用于 cur 标记）
     * @return array ['user_tree' => array]
     */
    public function build($current_module = '')
    {
        $userTree = array();
        foreach ((array) Config::get('module.link_user_center') as $value) {
            if (!$this->isModuleAvailable($value)) {
                continue;
            }
            $langKey = $value . '_user_tree';
            if (!lang_has($langKey) || !lang($langKey)) {
                continue;
            }
            $url = defined('IS_MINIPROGRAM')
                ? Url::urlMini($value, 'user')
                : route($value . '.user');
            $name = lang($langKey);
            if (!$name && lang_has($value)) {
                $name = lang($value);
            }
            if (!$name) {
                $name = $value;
            }
            $userTree[] = array(
                'module' => $value,
                'name' => $name,
                'url' => $url,
                'icon' => MenuIconMap::resolve($value),
                'cur' => $value == $current_module ? true : false,
            );
        }

        return array('user_tree' => $userTree);
    }

    /**
     * 模块是否已安装且当前可用。
     *
     * features 由 SystemBootstrap/ModuleFeatureGate 依安装状态计算，未安装的模块不会下发该键，
     * 因此以 features 是否存在作为安装判定；会员衍生模块再叠加 features.user 判定
     * （对应小程序端 utils/module-guard.ts 的 isUserDerivedModuleEnabled）。
     *
     * @param string $module 模块短名
     * @return bool
     */
    protected function isModuleAvailable($module)
    {
        if (!$module || !Config::get('features.' . $module, false)) {
            return false;
        }

        return !Module::requiresUser($module) || Config::get('features.user', false);
    }
}
