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

namespace Dou\Api\Controller\Attribute;

use Dou\Api\Controller\BaseController;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Attribute\AttributeService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class AttributeController extends BaseController
{
    /** @var AttributeService */
    protected $attribute;

    /**
     * @param AttributeService $attribute
     */
    public function __construct(AttributeService $attribute)
    {
        $this->attribute = $attribute;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $rec = $request->rec('rec', 'list');

        if ($rec !== 'list') {
            return ApiResponse::success(array('attribute_list' => array()));
        }

        $module = $request->input('module', '');
        $itemId = $request->integer('item_id', 0);
        $catId = $request->integer('category_id', 0);
        if (!Check::letter($module) || $itemId <= 0) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, lang('page_wrong'), array(), 400);
        }

        if (empty(Config::get('features.attribute', false))) {
            return ApiResponse::success(array('attribute_list' => array()));
        }

        $raw = $this->attribute->getAttributeList($module, $catId, $itemId, 'common');

        $attributeList = array();
        foreach ((array) $raw as $att) {
            $valueList = array();
            foreach ((array) $att['value_list'] as $v) {
                $valueList[] = array(
                    'attribute_value_id' => $v['attribute_value_id'],
                    'value' => $v['value'],
                );
            }
            $attributeList[] = array(
                'attribute_id' => $att['attribute_id'],
                'name' => $att['name'],
                'value_list' => $valueList,
            );
        }

        return ApiResponse::success(array('attribute_list' => $attributeList));
    }
}
