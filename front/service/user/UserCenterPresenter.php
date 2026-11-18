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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Vendor\Barcode\Barcode;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 会员中心展示拼装（front/api 共用）。
 *
 * 负责会员中心首页周边的展示型数据：第三方连接插件列表、欢迎语、推广二维码生成；
 * 与具体业务（注册/登录/资料）解耦，由会员域内供 ProfileService 与 Controller 共同调用。
 */
class UserCenterPresenter extends BaseService
{
    /**
     * 构建第三方连接插件列表（front/api 共用）。
     *
     * plugin 模块未装时返回空数组：第一闸 {@see plugin()}::isAvailable() 检查
     * features.plugin 与 plugin 表存在性；第二闸 {@see Module::make()} 检查
     * features.plugin 与目标类文件磁盘存在性（具体 FQCN 见 Module::$definitions
     * 中的 'plugin.connect_registry' 默认 override）。两个守卫共同保护后续的
     * DB::table('plugin') 查询与反射构造。
     *
     * @param int $userId
     * @return array
     */
    public function buildConnectPluginList($userId = 0)
    {
        if (!plugin()->isAvailable()) {
            return array();
        }

        $registry = Module::make('plugin.connect_registry');
        if ($registry === null) {
            return array();
        }

        $userId = (int) $userId;
        $rowsByUniqueId = array();
        foreach ((array) DB::table('plugin')->where('plugin_group', 'connect')->select() as $row) {
            $rowsByUniqueId[(string) $row['slug']] = $row;
        }

        $pluginList = array();
        foreach ($registry->allMeta() as $meta) {
            $uniqueId = isset($meta['slug']) ? (string) $meta['slug'] : '';
            if ($uniqueId === '' || !isset($rowsByUniqueId[$uniqueId])) {
                continue;
            }
            $row = $rowsByUniqueId[$uniqueId];

            $linked = false;
            if ($userId > 0) {
                $linked = DB::table('user_sns')
                    ->where('group', $uniqueId)
                    ->where('user_id', $userId)
                    ->value('id') ? true : false;
            }

            $pluginList[] = array(
                'slug' => $uniqueId,
                'name' => isset($row['name']) ? $row['name'] : (isset($meta['name']) ? $meta['name'] : $uniqueId),
                'url' => route('user.login', array(), array('query' => array('sns' => $uniqueId))),
                'icon' => PLUGIN_URL . $uniqueId . '/icon.png',
                'linked' => $linked ? 1 : 0,
                'code' => isset($meta['code']) ? $meta['code'] : '',
            );
        }

        return $pluginList;
    }

    /**
     * 构建会员中心欢迎语（front/api 共用）。
     *
     * @return string
     */
    public function buildWelcomeText()
    {
        $hour = date('H');
        if ($hour < 11) {
            return lang('user_welcom_morning');
        } elseif ($hour < 13) {
            return lang('user_welcom_noon');
        } elseif ($hour < 17) {
            return lang('user_welcom_afternoon');
        }

        return lang('user_welcom_night');
    }

    /**
     * 构建推广二维码 URL（front/api 共用）；落盘已存在则直接返回 URL。
     *
     * @param mixed $userSn
     * @return string
     */
    public function buildPromotionQrcode($userSn)
    {
        $userSn = preg_replace('/[^a-zA-Z0-9]/', '', (string) $userSn);
        if ($userSn === '') {
            return '';
        }

        $path = ROOT_PATH . 'images/promotion/';
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }

        $isApi = defined('IS_API') && IS_API;
        $suffix = $isApi ? '_mini' : '_web';
        $filename = $path . $userSn . $suffix . '.png';
        $fileUrl = ROOT_URL . 'images/promotion/' . $userSn . $suffix . '.png';
        if (file_exists($filename)) {
            return $fileUrl;
        }

        $barCode = new Barcode();
        if ($isApi) {
            $result = $barCode->miniCode($userSn, 'save', array(
                'scene' => 'user_sn=' . $userSn,
                'page' => 'pages/user/login_weixin',
                'width' => 430,
                'auto_color' => false,
                'line_color' => array('r' => 0, 'g' => 0, 'b' => 0),
                'is_hyaline' => false,
                'file' => $filename,
            ));
            if ($result === true && file_exists($filename)) {
                return $fileUrl;
            }

            return '';
        }

        $code = route('user.register', array(), array('query' => array('user_sn' => $userSn)));
        $result = $barCode->qrCode($code, 'save', array(
            'level' => 'L',
            'size' => 10,
            'margin' => 2,
            'file' => $filename,
        ));
        if ($result === true && file_exists($filename)) {
            return $fileUrl;
        }

        return '';
    }
}
