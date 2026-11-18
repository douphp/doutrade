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

namespace Dou\Core\Service\Order;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单条目读模型（订单项域）。
 *
 * 装配 order_item 与查看者身份无关的视图字段；评价 URL（comment_url）由
 * {@see \Dou\Front\Service\View\OrderItemDecorator} 按显式 user_id 后置叠加。
 */
class OrderItemQuery extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /**
     */
    public function __construct()
    {
    }

    /**
     * 取单订单的明细视图列表；传入 user_id 时会校验订单归属。
     *
     * @param int $order_id
     * @param string $user_id 指定会员；为空则不校验归属
     * @param array|null $ctx 订单币上下文；null 表示按基准币展示（后台）
     * @return array
     */
    public function getOrderItem($order_id, $user_id = '', $ctx = null)
    {
        try {
            $order_id = intval($order_id);
            if (!$order_id) {
                return array();
            }

            if ($user_id) {
                $user_id = intval($user_id);
                $order_exists = DB::table('order')
                    ->where('id', $order_id)
                    ->where('user_id', $user_id)
                    ->exists();

                if (!$order_exists) {
                    return array();
                }
            }

            $query = DB::table('order_item')
                ->where('order_id', $order_id)
                ->order('id DESC')
                ->select();

            if (empty($query)) {
                return array();
            }

            $image_map = $this->getItemImagesBatch($query);

            $item_list = array();
            foreach ($query as $row) {
                $item_key = $row['module'] . '_' . $row['item_id'];
                $image = isset($image_map[$item_key]) ? $image_map[$item_key] : '';

                $item_image = $row['module'] == 'product'
                    ? attachment()->url($image, true)
                    : attachment()->url($image);

                $pointView = $this->pointForView($row['point']);

                // 外币订单按快照列展示；基准币订单与后台直接使用基准金额列
                if ($ctx !== null && !currency()->isBase($ctx)) {
                    $priceValue = isset($row['currency_price']) ? $row['currency_price'] : $row['price'];
                    $salePriceValue = isset($row['currency_sale_price']) ? $row['currency_sale_price'] : $row['sale_price'];
                    $priceFormat = Util::formatCurrency($priceValue, $ctx);
                    $salePriceFormat = Util::formatCurrency($salePriceValue, $ctx);
                } else {
                    $priceFormat = Util::formatBasePrice($row['price']);
                    $salePriceFormat = Util::formatBasePrice($row['sale_price']);
                }

                $item_list[] = array(
                    'id' => $row['id'],
                    'module' => $row['module'],
                    'item_id' => $row['item_id'],
                    'name' => $row['name'],
                    'attribute_name' => $row['attribute_name'],
                    'image' => $item_image,
                    'url' => OrderModuleLink::itemUrl($row['module'], $row['item_id']),
                    'item_number' => $row['item_number'],
                    'price' => $priceFormat,
                    'sale_price' => $salePriceFormat,
                    'sale_price_type' => $row['sale_price_type'],
                    'sale_price_name' => lang('price_' . $row['sale_price_type'], $row['sale_price_type']),
                    'point' => $pointView['point'],
                    'point_format' => $pointView['point_format'],
                );
            }

            return $item_list;
        } catch (\Exception $e) {
            $this->logError('get_order_item error: ' . $e->getMessage(), array(
                'order_id' => $order_id,
                'user_id' => $user_id,
            ));
            return array();
        }
    }

    /**
     * 校验某 order_item 是否可由指定会员发起评价：
     * 订单已完成（status=OrderStatus::COMPLETED）且未评过；具体业务权限交给 comment 模块。
     *
     * @param int $order_item_id
     * @param int $user_id 显式入参；<=0 直接返回 false
     * @return bool
     */
    public function ifCanComment($order_item_id, $user_id)
    {
        $comment = Module::make('comment');
        if (!$comment) {
            return false;
        }

        $order_item_id = intval($order_item_id);
        $user_id = (int) $user_id;
        if (!$order_item_id || $user_id <= 0) {
            return false;
        }

        try {
            $item = DB::table('order_item')
                ->where('id', $order_item_id)
                ->field('order_id,module,item_id,user_id')
                ->find();

            if (!$item) {
                return false;
            }

            if ($item['user_id'] != $user_id) {
                return false;
            }

            $allow_comment = DB::table('order')
                ->where('id', $item['order_id'])
                ->where('user_id', $user_id)
                ->where('status', OrderStatus::COMPLETED)
                ->value('allow_comment');

            if ($allow_comment != '1') {
                return false;
            }

            return !$comment->checkPermission(
                $item['module'],
                $item['item_id'],
                $order_item_id,
                $user_id
            );
        } catch (\Exception $e) {
            $this->logError('if_can_comment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 积分展示：与 {@see OrderCartQuery::pointForView} 对齐，便于本类内部装配视图字段。
     *
     * @param mixed $point
     * @return array point, point_format
     */
    private function pointForView($point)
    {
        $p = intval($point);
        $suffix = lang('point_point', lang('user_point', ''));

        return array(
            'point' => $p,
            'point_format' => $p . $suffix,
        );
    }

    /**
     * 批量按 module 拉取商品图片字段。
     *
     * @param array $items
     * @return array key 形如 "{module}_{id}"
     */
    private function getItemImagesBatch($items)
    {
        $module_groups = array();
        foreach ($items as $item) {
            $module_groups[$item['module']][] = intval($item['item_id']);
        }

        $image_map = array();
        foreach ($module_groups as $module => $ids) {
            if (!DB::fieldExist($module, 'image')) {
                continue;
            }

            $ids = array_unique($ids);
            if (empty($ids)) {
                continue;
            }

            $products = DB::table($module)
                ->whereIn('id', $ids)
                ->field('id,image')
                ->select();

            if ($products) {
                foreach ($products as $product) {
                    $image_map[$module . '_' . $product['id']] = $product['image'];
                }
            }
        }

        return $image_map;
    }

    /**
     * 记录错误：写入实例日志数组，同时投递到 Log::error（channel=order）。
     *
     * Auth 主体上下文不在此处装配；调用方若有需要，可在 $data 里携带 user_id / work_id。
     *
     * @param string $message
     * @param array $data
     * @return void
     */
    private function logError($message, $data = array())
    {
        $this->error_log[] = array(
            'time' => time(),
            'message' => $message,
            'data' => $data,
        );

        Log::error($message, array(
            'channel' => 'order',
            'module' => 'order',
            'payload' => is_array($data) ? $data : array(),
        ));
    }
}
