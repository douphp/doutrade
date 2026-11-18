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

namespace Dou\Api\Controller\Order;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Order\CartService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序购物车子控制器（route=order/cart/*）
 */
class CartController extends BaseController
{
    /** @var CartService */
    private $cartService;

    /** @var OrderCore */
    private $order;

    /**
     * @param CartService $cartService 购物车业务
     * @param OrderCore $order 订单核心（有效件数统计）
     */
    public function __construct(CartService $cartService, OrderCore $order)
    {
        $this->cartService = $cartService;
        $this->order = $order;
    }

    /**
     * 购物车数量（route=order/cart/cart_number）。
     *
     * 未登录时静默返回 cart_number=0（保留客户端 graceful 兜底契约，不抛 401）。
     *
     * @return Response
     */
    public function cartNumber()
    {
        $userId = auth('api') ? (int) auth('api')->id() : 0;
        if ($userId <= 0) {
            return ApiResponse::success(array('cart_number' => 0));
        }

        $count = $this->order->countCartNumber($userId);

        return ApiResponse::success(array('cart_number' => $count));
    }

    /**
     * 添加购物车（POST order/cart）
     *
     * @param Request $request
     * @return Response
     */
    public function store(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $post = $this->normalizeStorePost($request);

        $module = Check::extendId(isset($post['module']) ? $post['module'] : '') ? $post['module'] : 'product';
        $itemId = Check::number(isset($post['item_id']) ? $post['item_id'] : '') ? (int) $post['item_id'] : 0;
        $itemNumber = Check::number(isset($post['item_number']) ? $post['item_number'] : '') ? (int) $post['item_number'] : 1;
        $mode = Check::letter(isset($post['mode']) ? $post['mode'] : '') ? $post['mode'] : 'money';
        $action = Check::letter(isset($post['action']) ? $post['action'] : '') ? $post['action'] : 'addtocart';
        $replace = !empty($post['replace']);
        $attParams = $this->collectAttParams($post);

        // 立即购买已迁移到 order/buynow 报价单；旧版小程序不再允许从加购接口触发
        if ($action === 'buynow') {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                lang('order_quote_invalid'),
                array('reason' => 'client_outdated'),
                422
            );
        }

        $result = $this->cartService->addToCart($userId, $module, $itemId, $itemNumber, $mode, $attParams, $replace);

        if (!$result['ok']) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                (string) $result['msg'],
                array('reason' => isset($result['code']) ? (string) $result['code'] : ''),
                422
            );
        }

        return ApiResponse::success(array(
            'mode' => $mode === 'point' ? 'point' : 'money',
            'cart_number' => isset($result['cart_number']) ? (int) $result['cart_number'] : 0,
        ));
    }

    /**
     * 更新购物车数量（PUT order/cart/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function update(Request $request)
    {
        $userId = $this->mustLoginUserId();

        $action = $request->alpha('action');
        $id = $request->integer('id', 0);
        $itemNumber = $this->resolveUpdateNumber($request, $userId, $id, $action);

        $payload = $this->cartService->updateCartItem($userId, $id, $itemNumber);
        if (empty($payload['ok'])) {
            return ApiResponse::error(
                ApiCodes::BUSINESS_RULE_VIOLATION,
                isset($payload['msg']) ? (string) $payload['msg'] : lang('order_item_wrong'),
                array('reason' => isset($payload['code']) ? (string) $payload['code'] : ''),
                422
            );
        }

        return ApiResponse::success((array) $payload);
    }

    /**
     * 清空失效购物车行（POST order/cart/clear_invalid）
     *
     * @param Request $request
     * @return Response
     */
    public function clearInvalid(Request $request)
    {
        $userId = $this->mustLoginUserId();

        return ApiResponse::success($this->cartService->clearInvalid($userId));
    }

    /**
     * 删除购物车项（DELETE order/cart/{id}）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $userId = $this->mustLoginUserId();
        $id = $request->integer('id', 0);
        $payload = $this->cartService->deleteCartItem($userId, $id);

        return ApiResponse::success(is_array($payload) ? $payload : array());
    }

    /**
     * 规范化 store 参数，兼容 post JSON 字符串
     *
     * @param Request $request
     * @return array
     */
    private function normalizeStorePost(Request $request)
    {
        $post = $request->post();
        if (!isset($post['module']) || !isset($post['item_id'])) {
            $postRaw = str_replace('\\', '', $request->input('post'));
            $decoded = json_decode($postRaw, true);
            if (!is_array($decoded)) {
                return array();
            }
            // 兼容旧版小程序：action/mode/replace 在顶层，表单字段在 post JSON 内
            foreach (array('action', 'mode', 'replace') as $key) {
                if (isset($post[$key]) && !isset($decoded[$key])) {
                    $decoded[$key] = $post[$key];
                }
            }

            return $decoded;
        }
        return $post;
    }

    /**
     * 收集规格参数
     *
     * @param array $source
     * @return array
     */
    private function collectAttParams(array $source)
    {
        $att = array();
        foreach ($source as $k => $v) {
            if (strncmp($k, 'att_', 4) === 0) {
                $att[$k] = $v;
            }
        }
        return $att;
    }

    /**
     * 根据 action 计算目标数量
     *
     * @param Request $request
     * @param int $userId
     * @param int $id
     * @param string $action
     * @return int
     */
    private function resolveUpdateNumber(Request $request, $userId, $id, $action)
    {
        if ($action === 'input') {
            $itemNumber = $request->post('item_number');
            return Check::number($itemNumber) ? (int) $itemNumber : 1;
        }
        $itemNumber = DB::table('order_cart')->where('id', $id)->where('user_id', $userId)->value('item_number');
        $itemNumber = $action === 'plus' ? $itemNumber + 1 : $itemNumber - 1;
        return $itemNumber > 0 ? (int) $itemNumber : 1;
    }

    /**
     * 登录校验并返回 user_id。
     *
     * @return int
     */
    private function mustLoginUserId()
    {
        $userId = auth('api') ? (int) auth('api')->id() : 0;
        if ($userId > 0) {
            return $userId;
        }
        ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('login_timeout'), array(), 401);
    }
}
