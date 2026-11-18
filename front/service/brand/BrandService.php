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

namespace Dou\Front\Service\Brand;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Str;
use Dou\Front\Model\Brand\Brand;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台品牌业务。
 *
 * 列表 / 详情读路径走 AR：Model 的 $casts/$prefetchers/$translatable 承担
 * image URL、多语言、列表 url、description 摘要回退等格式化；Service 仅负责分页参数、
 * product_list 跳转拼装、markdown 与 click 自增。
 */
class BrandService extends BaseService
{
    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param MarkdownRenderer $markdown
     */
    public function __construct(MarkdownRenderer $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * @param int $page
     * @param string $class
     * @param int $pageSize 每页数量，0 为使用配置值
     * @return array
     */
    public function buildBrandListData($page, $class, $pageSize = 0)
    {
        $display = Config::get('pagination.brand', 10);
        $size = ($pageSize > 0) ? $pageSize : ($display ? $display : 12);

        $get = '';
        if ($class !== '') {
            $get = '?class=' . $class;
        }

        $result = Brand::filterByClass($class)
            ->applyDefaultOrder()
            ->paginate((int) $size, (int) $page, route('brand'), $get);

        $brandList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $brandList[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'class' => $row['class'],
                'image' => $row['image'],
                'description' => Str::excerpt($row['description'], 200, false),
                'url' => $row['url'],
                'product_list' => route('product.category', array(), array('query' => array('brand_id' => $row['id']))),
            );
        }

        return array(
            'brand_list' => $brandList,
            'pager' => $result['pager'],
            'screen' => Brand::distinctValues('class', $class),
        );
    }

    /**
     * @param int $brandId
     * @return array|null 键名 brand；无效 id / 未发布返回 null
     */
    public function buildBrandShowData($brandId)
    {
        $brandId = (int) $brandId;
        if ($brandId < 1) {
            return null;
        }

        $model = Brand::findPublishedById($brandId);
        if (!$model) {
            return null;
        }

        $data = $model->toArray();
        $data['content'] = $this->markdown->toHtml(isset($data['content']) ? $data['content'] : '');
        $data['product_list'] = route('product.category', array(), array('query' => array('brand_id' => $data['id'])));

        Brand::updateClick($brandId);
        $data['click'] = (int) (isset($data['click']) ? $data['click'] : 0) + 1;

        return array(
            'brand' => $data,
        );
    }
}
