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

namespace Dou\Front\Request\User;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Area\AreaService as AreaCore;
use Dou\Front\Controller\Asset\LangController;
use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台收货地址 / 联系人提交（route=user/contact/store, user/contact/update）。
 *
 * 姓名按有效语言（手动切换优先，回落 site.language.default）切换：en_us 渲染
 * first_name/last_name 并必填，其余渲染 name 并必填；
 * `phone` 规则按有效语言 zh_cn 切换为 `phone`（大陆 11 位手机号）；
 * `features.area=true` 时 province/city 必填。id_card 字段格式自检，是否在内地必填的
 * 业务规则留在 ContactService。
 */
class ContactFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        $pack = LangController::currentPack();

        $rules = array(
            'name' => 'required|illegal_char',
            'phone' => 'required|illegal_char',
            'address' => 'required|illegal_char',
            'tag' => 'illegal_char',
            'id_card' => 'illegal_char',
        );
        if ($pack === 'en_us') {
            $rules['name'] = 'illegal_char';
            $rules['first_name'] = 'required|illegal_char';
            $rules['last_name'] = 'required|illegal_char';
        }
        if ($pack === 'zh_cn') {
            $rules['phone'] = 'required|phone';
        }
        if (Config::get('features.area', false)) {
            // 层级随数据分区：中文分区四级（省/市均有数据），英文分区两级且部分国家无下级，省/市不作必填
            $rules['province'] = 'illegal_char';
            $rules['city'] = 'illegal_char';
            if (AreaCore::languageGroup($pack) === 'zh_cn') {
                $rules['province'] = 'required|illegal_char';
                $rules['city'] = 'required|illegal_char';
            }
        }

        return $rules;
    }
}
