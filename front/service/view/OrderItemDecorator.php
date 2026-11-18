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

namespace Dou\Front\Service\View;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\Order\OrderItemQuery;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 订单项视图装配器（前台 shell 层）。
 *
 * 给 {@see OrderItemQuery::getOrderItem()} 返回的原始订单明细，按显式 $userId
 * 叠加 `comment_url` 字段：若该 order_item 仍可评价，给出评价提交 URL，
 * 否则置空串。
 *
 * 把「能否评价 + 评价 URL」这种依赖 user 上下文的视图字段从 core 移到 shell，
 * 让 core 的 OrderItemQuery 保持「与查看者身份无关」的纯数据装配责任。
 */
class OrderItemDecorator extends BaseService
{
    /** @var OrderItemQuery */
    private $itemQuery;

    /**
     * @param OrderItemQuery $itemQuery 核心订单项读模型
     */
    public function __construct(OrderItemQuery $itemQuery)
    {
        $this->itemQuery = $itemQuery;
    }

    /**
     * 给批量订单项追加 comment_url。
     *
     * @param array $items 来自 OrderItemQuery::getOrderItem() 的原始数组
     * @param int $userId 显式登录会员 ID；<=0 时所有 comment_url 置空串
     * @return array 同结构，新增/覆盖 comment_url 字段
     */
    public function decorate(array $items, $userId)
    {
        $userId = (int) $userId;
        if (empty($items)) {
            return $items;
        }

        foreach ($items as &$item) {
            $canComment = $userId > 0
                ? $this->itemQuery->ifCanComment($item['id'], $userId)
                : false;
            $item['comment_url'] = $canComment
                ? route('comment.user.create', array(), array('query' => array('order_item_id' => $item['id'])))
                : '';
        }
        unset($item);

        return $items;
    }
}
