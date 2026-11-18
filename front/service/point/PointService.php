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

namespace Dou\Front\Service\Point;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;
use Dou\Core\Support\Util;
use Dou\Front\Model\Point\PointLog;
use Dou\Front\Model\Product\Product;
use Dou\Front\Service\Product\ProductService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 积分商城业务
 */
class PointService extends BaseService
{
    /** @var ProductService */
    private $productService;

    /**
     * @param ProductService $productService
     */
    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * @param int $page
     * @param string $pageUrl
     * @return array product_list、pager
     */
    public function buildPointListData($page, $pageUrl)
    {
        $display = Config::get('pagination.point', 10);
        $pageSize = $display ? $display : 10;

        // AR：Product 接管 translatable/casts/prefetchers（image/created_at/title 翻译/缩略图预热）
        $result = Product::published()->where('point', '>', 0)
            ->applyDefaultOrder()
            ->paginate((int) $pageSize, (int) $page, $pageUrl);

        $productList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $description = Str::excerpt($row['description'], 150, false);
            $productList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'name' => isset($row['title']) ? $row['title'] : '',
                'price' => $model->getRawAttribute('price') > 0 ? Util::formatPrice($model->getRawAttribute('price')) : lang('price_discuss'),
                'thumb' => $row['thumb'],
                'image' => $row['image'],
                'point' => $row['point'],
                'created_at' => $row['created_at'],
                'description' => $description,
                'url' => route('point.show', ['id' => $row['id']]),
            );
        }

        return array(
            'product_list' => $productList,
            'pager' => $result['pager'],
        );
    }

    /**
     * @param int $productId
     * @return array|false 已格式化展示字段
     */
    public function buildPointShowData($productId)
    {
        // AR：Product 接管 image/title-content-description 翻译；favorites/gallery/model_list 走 per-row enrich
        $model = Product::published()->where('point', '>', 0)->where('id', (int) $productId)->first();
        if (!$model) {
            return false;
        }
        $product = $model->toArray();
        $id = (int) $product['id'];
        $product['price'] = $model->getRawAttribute('price') > 0 ? Util::formatPrice($model->getRawAttribute('price')) : lang('price_discuss');
        $product['gallery_list'] = attachment()->gallery('product', $id, 'gallery', true);
        $product['model_list'] = $this->productService->buildModelList($product['model'], $id);
        $uid = 0;
        $authGuard = auth('front');
        if ($authGuard && method_exists($authGuard, 'id')) {
            $uid = (int) $authGuard->id();
        }
        $favorites = Module::make('favorites');
        $product['favorites'] = $favorites ? $favorites->getFavoritesState('product', $id, $uid) : null;
        $product['name'] = isset($product['title']) ? $product['title'] : '';
        $attribute = Module::make('attribute');
        $product['attribute_list'] = $attribute
            ? $attribute->getAttributeList('product', isset($product['category_id']) ? $product['category_id'] : 0, $id)
            : array();

        return $product;
    }

    /**
     * @param int $userId
     * @param int $page
     * @param string $pageUrl
     * @return array point_log_list、pager
     */
    public function buildPointLogListData($userId, $page, $pageUrl)
    {
        // AR：PointLog 接管 point 表 casts（created_at/point/total）
        $result = PointLog::filterByUserId($userId)
            ->applyDefaultOrder()
            ->paginate(15, (int) $page, $pageUrl);

        $pointLogList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $actionKey = 'point_action_' . $row['action'];
            $actionFormat = ((lang($actionKey) !== '')) ? lang($actionKey) : $row['action'];
            $pointLogList[] = array(
                'id' => $row['id'],
                'user' => (user() !== null ? user()->format($row['user_id']) : false),
                'action' => $row['action'],
                'action_format' => $actionFormat,
                'from' => $row['from'],
                'point' => $row['point'],
                'total' => $row['total'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'point_log_list' => $pointLogList,
            'pager' => $result['pager'],
        );
    }
}
