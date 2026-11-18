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

namespace Dou\Front\Service\Order;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Extension\Module;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Coupon\CouponEligibility;
use Dou\Core\Service\Coupon\CouponQuoteService;
use Dou\Core\Service\Order\OrderableModule;
use Dou\Core\Service\Order\OrderService as OrderCore;
use Dou\Core\Service\Pricing\PricingService;
use Dou\Core\Service\User\UserContactQuery;
use Dou\Core\Service\Wallet\WalletService;
use Dou\Core\Support\Num;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台结算业务层（结算页数据 / 运费重算 / 优惠券试算 / 下单事务）。
 *
 * 控制器仅负责参数收集与响应格式化，结算与下单的数据库操作与业务规则在此类完成。
 */
class CheckoutService extends BaseService
{
    /** @var OrderCore */
    private $order;

    /** @var WalletService */
    private $wallet;

    /** @var PricingService */
    private $pricingService;

    /** @var CouponEligibility|null */
    private $eligibility;

    /** @var CouponQuoteService|null */
    private $couponQuote;

    /** @var OrderItemValidator */
    private $itemValidator;

    /** @var CheckoutQuoteService */
    private $quoteService;

    /** @var OrderSubmissionGuard */
    private $submissionGuard;

    /**
     * @param OrderCore $order
     * @param WalletService $wallet 钱包台账服务
     * @param PricingService $pricingService 定价服务
     * @param CouponEligibility|null $eligibility 优惠券资格谓词（单券可用性 / 生效范围金额）；优惠券为可选模块，未安装时容器注入 null
     * @param OrderItemValidator|null $itemValidator 商品校验（加购 / 报价 / 下单共用）
     * @param CheckoutQuoteService|null $quoteService 立即购买报价单签名
     * @param OrderSubmissionGuard|null $submissionGuard 提交幂等守卫
     *
     * 注：可选依赖参数不声明类型，隐式可空参数自 PHP 8.4 起已弃用，
     * 而显式写作 ?Type 需要 PHP 7.1+，会破坏 5.6 兼容。
     */
    public function __construct(
        OrderCore $order,
        WalletService $wallet,
        PricingService $pricingService,
        $eligibility = null,
        $itemValidator = null,
        $quoteService = null,
        $submissionGuard = null,
        $couponQuote = null
    ) {
        $this->order = $order;
        $this->wallet = $wallet;
        $this->pricingService = $pricingService;
        // 容器按类型提示自动注入；去掉类型声明后在此显式恢复同样的解析语义：
        // 类文件缺席（可选模块未安装）时保持 null，否则实例化默认实现。
        if ($eligibility === null && class_exists('Dou\\Core\\Service\\Coupon\\CouponEligibility')) {
            $eligibility = new CouponEligibility();
        }
        $this->eligibility = $eligibility;
        $this->couponQuote = $couponQuote ? $couponQuote : ($eligibility ? new CouponQuoteService($eligibility) : null);
        $this->itemValidator = $itemValidator ? $itemValidator : new OrderItemValidator($order);
        $this->quoteService = $quoteService ? $quoteService : new CheckoutQuoteService();
        $this->submissionGuard = $submissionGuard ? $submissionGuard : new OrderSubmissionGuard();
    }

    /**
     * 立即购买 / 积分 / 套餐：校验并签发报价单（不写 order_cart）。
     *
     * @param int $userId
     * @param string $module
     * @param int $itemId
     * @param int $number
     * @param string $mode 'money' | 'point'
     * @param array $attParams
     * @return array ok=true + quote_token/cart/mode | ok=false + code/msg
     */
    public function prepareQuote($userId, $module, $itemId, $number, $mode, array $attParams = array())
    {
        $result = $this->itemValidator->validate($module, $itemId, $number, $mode, $attParams);
        if (!$result['ok']) {
            return $result;
        }

        $payload = array(
            'uid' => (int) $userId,
            'module' => $result['module'],
            'item_id' => (int) $result['item']['id'],
            'item_number' => (int) $result['number'],
            'attribute' => (string) $result['attribute'],
            'mode' => $result['mode'],
        );

        $quote = $this->order->getQuoteCart((int) $userId, $payload);
        if (!$quote['ok']) {
            return $quote;
        }
        if ($result['mode'] === 'point' && (!$this->isPointCart($quote['cart']) || intval($quote['cart']['order_point']) <= 0)) {
            return array(
                'ok' => false,
                'code' => 'point_not_supported',
                'msg' => lang('order_point_not_supported', lang('order_item_wrong')),
            );
        }

        $payload['quote_amount'] = floatval($quote['cart']['item_amount']);
        if ($result['module'] === 'vip_package') {
            $payload['vip_days'] = (int) $quote['cart']['list'][0]['vip_days'];
        }
        $token = $this->quoteService->issue($payload);
        if ($token === '') {
            return array(
                'ok' => false,
                'code' => 'quote_unavailable',
                'msg' => lang('order_item_wrong'),
            );
        }

        return array(
            'ok' => true,
            'quote_token' => $token,
            'cart' => $quote['cart'],
            'mode' => $result['mode'],
        );
    }

    /**
     * 结算预览（纯函数）：不读写 Session，金额全部按显式入参重算。
     *
     * @param int $userId
     * @param array $source ['type'=>'cart'] | ['type'=>'quote','token'=>'...']
     * @param int $contactId
     * @param string $shippingId
     * @param int $couponId
     * @return array ok=true + cart/shipping_list/shipping_id/coupon_list/coupon_id/amount/mode/source
     *               ok=false + code/msg
     */
    public function preview($userId, array $source, $contactId = 0, $shippingId = '', $couponId = 0)
    {
        $resolved = $this->resolveCheckoutSource((int) $userId, $source, isset($source['mode']) ? $source['mode'] : 'money');
        if (!$resolved['ok']) {
            return $resolved;
        }

        $cart = $resolved['cart'];
        $mode = $resolved['mode'];
        $module = (string) $cart['module'];

        $shippingList = $this->order->getShippingList();
        $shippingId = trim((string) $shippingId);
        if ($shippingId === '' && !empty($shippingList)) {
            $shippingId = (string) $shippingList[0]['slug'];
        }

        $shippingConfig = array();
        if ($mode !== 'point' && $module === 'product') {
            $shippingConfig = $this->resolveShippingConfig($shippingId);
            if ($shippingConfig === null) {
                return array(
                    'ok' => false,
                    'code' => 'shipping_invalid',
                    'msg' => lang('order_shipping_invalid', lang('order_shipping_select')),
                );
            }
        } elseif ($shippingId !== '' && $this->resolveShippingConfig($shippingId) === null) {
            return array(
                'ok' => false,
                'code' => 'shipping_invalid',
                'msg' => lang('order_shipping_invalid', lang('order_shipping_select')),
            );
        }

        foreach ($shippingList as $index => $row) {
            $shippingList[$index]['checked'] = isset($row['slug']) && (string) $row['slug'] === $shippingId;
        }

        $couponId = (int) $couponId;
        $couponAmount = 0.0;
        $couponError = '';
        $cartLines = array();
        if ($mode !== 'point' && $this->couponQuote) {
            $cartLines = $this->buildCouponCartLines($cart);
        }

        if ($mode === 'point' || !$this->couponQuote) {
            $couponId = 0;
        } elseif ($couponId > 0) {
            $coupon = $this->loadOwnedValidCoupon($couponId, (int) $userId);
            if (!$coupon) {
                $couponId = 0;
                $couponError = lang('coupon_reason_not_found');
            } else {
                $quote = $this->couponQuote->evaluate($coupon, (int) $userId, $cartLines);
                if (!empty($quote['usable']) && $quote['discount'] > 0) {
                    $couponAmount = (float) $quote['discount'];
                } else {
                    $couponId = 0;
                    $couponError = !empty($quote['reason_text'])
                        ? $quote['reason_text']
                        : lang('order_coupon_invalid', lang('order_item_wrong'));
                }
            }
        }

        $couponList = array();
        if ($this->couponQuote && $mode !== 'point') {
            $couponList = $this->couponQuote->listForCheckout((int) $userId, $cartLines);
        }

        return array(
            'ok' => true,
            'cart' => $cart,
            'mode' => $mode,
            'shipping_list' => $shippingList,
            'shipping_id' => $shippingId,
            'coupon_list' => $couponList,
            'coupon_id' => $couponId,
            'coupon_error' => $couponError,
            'amount' => $this->computeTotals($cart, $mode, $shippingConfig, $couponAmount),
            'source' => $resolved['source_type'],
        );
    }

    /**
     * 解析结算来源：购物车（默认）或报价单。
     *
     * @param int $userId
     * @param array $source
     * @param string $mode 购物车模式（报价单以自己的 mode 为准）
     * @return array ok/code/msg/cart/mode/source_type
     */
    private function resolveCheckoutSource($userId, array $source, $mode)
    {
        $type = isset($source['type']) ? (string) $source['type'] : 'cart';

        if ($type === 'quote') {
            $token = isset($source['token']) ? (string) $source['token'] : '';
            $payload = $this->quoteService->verifyForUser($token, (int) $userId);
            if (!$payload) {
                return array('ok' => false, 'code' => 'quote_invalid', 'msg' => lang('order_quote_invalid'));
            }

            $quote = $this->order->getQuoteCart((int) $userId, $payload);
            if (!$quote['ok']) {
                return $quote;
            }

            $cart = $quote['cart'];
            $quoteMode = $quote['mode'];
            if (isset($cart['module']) && $cart['module'] === 'vip_package'
                && (!isset($payload['vip_days']) || (int) $payload['vip_days'] <= 0
                    || (int) $payload['vip_days'] !== (int) $cart['list'][0]['vip_days'])) {
                return array('ok' => false, 'code' => 'price_changed', 'msg' => lang('order_price_changed'));
            }
            $vipGuard = $this->assertVipPackageCartSingle($cart);
            if ($vipGuard !== null) {
                return $vipGuard;
            }
            if ($quoteMode === 'point' && (!$this->isPointCart($cart) || intval($cart['order_point']) <= 0)) {
                return array(
                    'ok' => false,
                    'code' => 'point_not_supported',
                    'msg' => lang('order_point_not_supported', lang('order_item_wrong')),
                );
            }
            if (isset($payload['quote_amount'])
                && abs(floatval($payload['quote_amount']) - floatval($cart['item_amount'])) > 0.01
            ) {
                return array(
                    'ok' => false,
                    'code' => 'price_changed',
                    'msg' => lang('order_price_changed'),
                    'amount' => floatval($cart['item_amount']),
                );
            }

            return array(
                'ok' => true,
                'cart' => $cart,
                'mode' => $quoteMode,
                'source_type' => 'quote',
            );
        }

        $cart = $this->order->getCart((int) $userId);
        if (!$cart || empty($cart['list'])) {
            $msg = (is_array($cart) && !empty($cart['invalid_list']))
                ? lang('order_cart_invalid_only')
                : lang('order_cart_empty');

            return array('ok' => false, 'code' => 'cart_empty', 'msg' => $msg);
        }

        $vipGuard = $this->assertVipPackageCartSingle($cart);
        if ($vipGuard !== null) {
            return $vipGuard;
        }

        $mode = ((string) $mode === 'point') ? 'point' : 'money';
        if ($mode === 'point' && !$this->isPointCart($cart)) {
            $mode = 'money';
        }

        return array(
            'ok' => true,
            'cart' => $cart,
            'mode' => $mode,
            'source_type' => 'cart',
        );
    }

    /**
     * VIP 套餐只允许单行、单份结算。
     *
     * @param array $cart
     * @return array|null null=通过；否则返回统一错误结构
     */
    private function assertVipPackageCartSingle(array $cart)
    {
        $vipCount = 0;
        foreach ((array) $cart['list'] as $item) {
            if ((string) $item['module'] !== 'vip_package') {
                continue;
            }
            $vipCount++;
            if ((int) $item['number'] !== 1) {
                return array(
                    'ok' => false,
                    'code' => 'package_quantity_invalid',
                    'msg' => lang('order_vip_number_invalid'),
                );
            }
        }
        if ($vipCount > 1) {
            return array(
                'ok' => false,
                'code' => 'package_conflict',
                'msg' => lang('order_vip_number_invalid'),
            );
        }

        return null;
    }

    /**
     * 统一金额计算：预览与下单共用，免邮门槛按「券后商品金额」判定。
     *
     * @param array $cart
     * @param string $mode
     * @param array $shippingConfig
     * @param float $couponAmount
     * @return array
     */
    private function computeTotals(array $cart, $mode, array $shippingConfig, $couponAmount)
    {
        $itemAmount = Num::round(isset($cart['item_amount']) ? $cart['item_amount'] : 0, 2);
        $couponAmount = Num::round($couponAmount, 2);
        if ($couponAmount < 0) {
            $couponAmount = 0.0;
        }
        if ($couponAmount > $itemAmount) {
            $couponAmount = $itemAmount;
        }

        $shippingFee = 0.0;
        $orderAmount = 0.0;
        if ($mode !== 'point') {
            $netItemAmount = Num::round($itemAmount - $couponAmount, 2);
            if ($cart['module'] === 'product') {
                $shippingFee = isset($shippingConfig['fee']) ? Num::round($shippingConfig['fee'], 2) : 0.0;
                if (isset($shippingConfig['free']) && $shippingConfig['free']
                    && $netItemAmount >= floatval($shippingConfig['free'])
                ) {
                    $shippingFee = 0.0;
                }
            }
            $orderAmount = Num::round($netItemAmount + $shippingFee, 2);
        } else {
            $couponAmount = 0.0;
        }

        // 订单币预览金额：与下单一致，按明细行逐一换算后再汇总，避免整单换算产生分位差
        $ctx = currency()->currentContext();
        $currencyItemAmount = 0.0;
        if (!empty($cart['list']) && is_array($cart['list'])) {
            foreach ($cart['list'] as $cartRow) {
                $rowPrice = isset($cartRow['sale_price']['value'])
                    ? floatval($cartRow['sale_price']['value'])
                    : floatval(isset($cartRow['price_normal']) ? $cartRow['price_normal'] : 0);
                $currencyItemAmount += Num::round(
                    currency()->fromBase($rowPrice, $ctx) * intval($cartRow['number']),
                    2
                );
            }
            $currencyItemAmount = Num::round($currencyItemAmount, 2);
        } else {
            $currencyItemAmount = currency()->fromBase($itemAmount, $ctx);
        }
        $currencyCouponAmount = min(currency()->fromBase($couponAmount, $ctx), $currencyItemAmount);
        $currencyShippingFee = currency()->fromBase($shippingFee, $ctx);
        $currencyOrderAmount = Num::round($currencyItemAmount - $currencyCouponAmount + $currencyShippingFee, 2);

        return array(
            'item_amount' => $itemAmount,
            'item_amount_format' => Util::formatPrice($itemAmount),
            'shipping_fee' => $shippingFee,
            'shipping_fee_format' => Util::formatPrice($shippingFee),
            'coupon_amount' => $couponAmount,
            'coupon_amount_format' => Util::formatPrice($couponAmount),
            'order_amount' => $orderAmount,
            'order_amount_format' => Util::formatPrice($orderAmount),
            'currency_code' => $ctx['code'],
            'currency_rate' => $ctx['rate'],
            'currency_decimal' => $ctx['decimals'],
            'currency_item_amount' => $currencyItemAmount,
            'currency_coupon_amount' => $currencyCouponAmount,
            'currency_shipping_fee' => $currencyShippingFee,
            'currency_order_amount' => $currencyOrderAmount,
            'currency_order_amount_format' => Util::formatCurrency($currencyOrderAmount, $ctx),
        );
    }

    /**
     * 加载会员已领取且未核销的单张优惠券完整行（供资格判定 / 抵扣计算）。
     *
     * 仅按「coupon_log 归属 + 未核销」过滤；有效期 / 条件 / 范围 / 首单等资格判定
     * 由 {@see \Dou\Core\Service\Coupon\CouponEligibility} 完成。
     *
     * @param int $couponId
     * @param int $userId
     * @return array|null dou_coupon 行；不属于该会员或已核销时返回 null
     */
    private function loadOwnedValidCoupon($couponId, $userId)
    {
        $couponId = (int) $couponId;
        if ($couponId <= 0) {
            return null;
        }

        $owned = DB::table('coupon_log')
            ->where('user_id', (int) $userId)
            ->where('status', '0')
            ->where('coupon_id', $couponId)
            ->exists();
        if (!$owned) {
            return null;
        }

        return DB::table('coupon')->where('id', $couponId)->find();
    }

    /**
     * 将购物车折算为优惠券资格判定所需的行集合。
     *
     * 行字段：module / category_id / category_ids（自身+祖先）/ item_id / amount。
     *
     * @param array $cart getCart 结果
     * @return array
     */
    private function buildCouponCartLines(array $cart)
    {
        $module = isset($cart['module']) ? (string) $cart['module'] : '';
        $items = (array) (isset($cart['list']) ? $cart['list'] : array());

        $categoryByItem = array();
        if ($module === 'product' && DB::tableExist('product')) {
            $itemIds = array();
            foreach ($items as $item) {
                if (!empty($item['item_id'])) {
                    $itemIds[] = (int) $item['item_id'];
                }
            }
            $itemIds = array_values(array_unique($itemIds));
            if (!empty($itemIds)) {
                foreach ((array) DB::table('product')->whereIn('id', $itemIds)->field('id, category_id')->select() as $row) {
                    $categoryByItem[(int) $row['id']] = (int) $row['category_id'];
                }
            }
        }

        $lines = array();
        foreach ($items as $item) {
            $price = isset($item['sale_price']['value']) && $item['sale_price']['value'] !== ''
                ? (float) $item['sale_price']['value']
                : (float) (isset($item['price_normal']) ? $item['price_normal'] : 0);
            $number = isset($item['number']) ? (int) $item['number'] : 0;
            $itemId = isset($item['item_id']) ? (int) $item['item_id'] : 0;
            $catId = isset($categoryByItem[$itemId]) ? $categoryByItem[$itemId] : 0;

            $lines[] = array(
                'module' => $module,
                'category_id' => $catId,
                'category_ids' => array(),
                'item_id' => $itemId,
                'amount' => $price * $number,
            );
        }

        if (!empty($categoryByItem) && DB::tableExist('product_category')) {
            $parentMap = array();
            foreach ((array) DB::table('product_category')->field('id, parent_id')->select() as $row) {
                $parentMap[(int) $row['id']] = (int) $row['parent_id'];
            }
            foreach ($lines as $index => $line) {
                $lines[$index]['category_ids'] = $this->categoryPath((int) $line['category_id'], $parentMap);
            }
        }

        return $lines;
    }

    /**
     * @param int $categoryId
     * @param array $parentMap
     * @return array
     */
    private function categoryPath($categoryId, array $parentMap)
    {
        $path = array();
        $seen = array();
        $categoryId = (int) $categoryId;
        while ($categoryId > 0 && !isset($seen[$categoryId])) {
            $path[] = $categoryId;
            $seen[$categoryId] = true;
            $categoryId = isset($parentMap[$categoryId]) ? (int) $parentMap[$categoryId] : 0;
        }

        return $path;
    }

    /**
     * 创建订单（幂等入口）。
     *
     * 同一 submit_token 的重复请求直接返回已创建的订单，避免双击 / 弱网重试产生多笔订单。
     *
     * @param int $userId
     * @param array $form
     * @return array
     */
    public function createOrder($userId, array $form)
    {
        $userId = (int) $userId;
        $submitToken = isset($form['submit_token']) ? (string) $form['submit_token'] : '';

        $quoteToken = '';
        if (!empty($form['quote_token'])) {
            $quoteToken = (string) $form['quote_token'];
        } elseif (isset($form['source']) && is_array($form['source'])
            && isset($form['source']['type']) && $form['source']['type'] === 'quote'
            && !empty($form['source']['token'])
        ) {
            $quoteToken = (string) $form['source']['token'];
        }

        // 报价来源优先用 nonce 做幂等键（同一报价只能落一笔订单）
        $guardKey = $submitToken;
        if ($quoteToken !== '') {
            $payload = $this->quoteService->verifyForUser($quoteToken, $userId);
            if (is_array($payload) && !empty($payload['nonce'])) {
                $guardKey = 'q:' . (string) $payload['nonce'];
            }
        }

        // 用户级串行锁：防止不同 token 的并发请求同时读到同一购物车并各自建单
        $userLock = $this->submissionGuard->lockUser($userId);
        if (empty($userLock['ok'])) {
            return $userLock;
        }

        try {
            $guard = $this->submissionGuard->begin($userId, $guardKey);
            if (!$guard['ok']) {
                return $guard;
            }

            if (!empty($guard['duplicate'])) {
                $duplicate = $this->duplicateOrderResult($userId, (string) $guard['order_sn']);
                if ($duplicate !== null) {
                    return $duplicate;
                }
                // done 记录对应的订单已不存在（极端清理场景）：丢弃记录后继续建单
                $this->submissionGuard->forget($userId, $guardKey);
            }

            $result = $this->doCreateOrder($userId, $form);

            if (!empty($result['ok']) && !empty($result['order_sn'])) {
                $this->submissionGuard->finish($guard['handle'], (string) $result['order_sn']);
            } else {
                $this->submissionGuard->fail($guard['handle']);
            }

            return $result;
        } finally {
            $this->submissionGuard->unlockUser($userLock['handle']);
        }
    }

    /**
     * 重复提交时回读已创建的订单。
     *
     * @param int $userId
     * @param string $orderSn
     * @return array|null null 表示订单不存在
     */
    private function duplicateOrderResult($userId, $orderSn)
    {
        if ($orderSn === '') {
            return null;
        }

        $order = DB::table('order')
            ->where('order_sn', $orderSn)
            ->where('user_id', (int) $userId)
            ->find();
        if (!is_array($order)) {
            return null;
        }

        $ctx = currency()->contextForOrder($order);
        $orderAmount = currency()->orderAmount($order);

        return array(
            'ok' => true,
            'duplicate' => true,
            'order_sn' => (string) $order['order_sn'],
            'order_amount' => $orderAmount,
            'order_amount_format' => Util::formatCurrency($orderAmount, $ctx),
            'order_point' => intval($order['order_point']),
            'mode' => (string) $order['mode'],
        );
    }

    /**
     * 创建订单（事务）
     *
     * @param int $userId
     * @param array $form keys: contact_id, shipping_id, mode, coupon_id(int), postcode, source, quote_token
     * @return array ok=true + order_sn/order_amount/order_amount_format/order_point/mode
     *               ok=false + code/msg
     */
    private function doCreateOrder($userId, array $form)
    {
        $contactId = isset($form['contact_id']) ? (int) $form['contact_id'] : 0;
        $shippingId = isset($form['shipping_id']) ? trim((string) $form['shipping_id']) : '';
        if ($shippingId === '0') {
            $shippingId = '';
        }
        $mode = isset($form['mode']) ? (string) $form['mode'] : 'money';
        $couponId = isset($form['coupon_id']) ? (int) $form['coupon_id'] : 0;
        $postcode = isset($form['postcode']) ? $form['postcode'] : '';

        $mode = ($mode === 'point') ? 'point' : 'money';

        $source = isset($form['source']) && is_array($form['source']) ? $form['source'] : array();
        if (empty($source) && !empty($form['quote_token'])) {
            $source = array('type' => 'quote', 'token' => (string) $form['quote_token']);
        }
        $resolved = $this->resolveCheckoutSource((int) $userId, $source, $mode);
        if (!$resolved['ok']) {
            return $resolved;
        }

        $cart = $resolved['cart'];
        $mode = $resolved['mode'];
        $sourceType = $resolved['source_type'];
        $module = (string) $cart['module'];
        if (!OrderableModule::allows($module)) {
            return array('ok' => false, 'code' => 'product_not_exist', 'msg' => lang('product_not_exist'));
        }

        // 订单币结算快照：展示币来自当前语言，基准币来自系统设置
        $currencyCtx = currency()->currentContext();

        // 有支付插件但全部不支持当前订单币时禁止下单，避免产生无法支付的订单
        if ($mode !== 'point' && plugin()->hasGroup('payment')
            && empty($this->order->getPaymentList($currencyCtx['code'], $module))
        ) {
            return array(
                'ok' => false,
                'code' => 'payment_unavailable',
                'msg' => lang('order_payment_currency_unsupported', lang('order_payment_invalid', lang('illegal'))),
            );
        }

        $orderPoint = intval($cart['order_point']);
        if ($mode === 'point' && (!$this->isPointCart($cart) || $orderPoint <= 0)) {
            return array(
                'ok' => false,
                'code' => 'point_not_supported',
                'msg' => lang('order_point_not_supported', lang('order_item_wrong')),
            );
        }

        $addTime = date('Y-m-d H:i:s');

        // 收货人信息
        $userContact = DB::table('user_contact')
            ->where('id', $contactId)
            ->where('user_id', $userId)
            ->find();
        $contact = UserContactQuery::displayName((array) $userContact);
        $phone = isset($userContact['phone']) ? $userContact['phone'] : '';
        $address = (user() && !empty($userContact)) ? user()->addressFull($userContact) : '';
        // 表单未单独提交邮编时取收货地址本中的邮编，避免订单地址快照永远为空
        if ($postcode === '' && isset($userContact['postcode'])) {
            $postcode = (string) $userContact['postcode'];
        }

        // 商品订单（非一键购买）必须有属于当前会员的收货地址；此校验同时覆盖 API 下单
        if ($module === 'product' && !Config::get('param.order_quick_buy')
            && (!$userContact || $contact === '' || $phone === '' || trim((string) $address) === '')
        ) {
            return array('ok' => false, 'code' => 'contact_invalid', 'msg' => lang('order_consignee_wrong'));
        }

        // 配送方式：积分订单运费为 0，但仍保留用户选择的配送方式（积分商品可能是实物）；
        // 商品订单（money 模式）必须选择有效的 shipping 插件
        $shippingConfig = array();
        if ($mode !== 'point') {
            if ($module !== 'product') {
                if ($shippingId !== '' && $this->resolveShippingConfig($shippingId) === null) {
                    return array('ok' => false, 'code' => 'shipping_invalid', 'msg' => lang('order_shipping_invalid', lang('order_shipping_select')));
                }
                $shippingId = '';
            } else {
                $shippingConfig = $this->resolveShippingConfig($shippingId);
                if ($shippingConfig === null) {
                    return array('ok' => false, 'code' => 'shipping_invalid', 'msg' => lang('order_shipping_invalid', lang('order_shipping_select')));
                }
            }
        } else {
            // 积分订单允许空配送，也允许选择有效配送方式；非法非空值仍拒绝
            if ($shippingId !== '' && $this->resolveShippingConfig($shippingId) === null) {
                return array('ok' => false, 'code' => 'shipping_invalid', 'msg' => lang('order_shipping_invalid', lang('order_shipping_select')));
            }
        }

        // 库存快速校验（事务内会再以产品行锁复核一次）
        foreach ($cart['list'] as $item) {
            $checkStock = $this->order->checkStock($module, $item['item_id'], $item['number']);
            if ($checkStock['code'] !== 'without_limit') {
                return array('ok' => false, 'code' => 'stock_error', 'msg' => $item['name'] . $checkStock['msg']);
            }
        }

        $user = DB::table('user')->where('id', $userId)->find();
        if (!$user) {
            return array('ok' => false, 'code' => 'user_not_found', 'msg' => lang('user_not_found'));
        }

        // 价格防篡改验证
        $recalculatedAmount = 0.0;
        $priceChangedItems = array();
        $couponCartLines = array();
        $categoryParentMap = array();
        if ($module === 'product' && DB::tableExist('product_category')) {
            foreach ((array) DB::table('product_category')->field('id, parent_id')->select() as $categoryRow) {
                $categoryParentMap[(int) $categoryRow['id']] = (int) $categoryRow['parent_id'];
            }
        }
        $currencyRowAmounts = array();
        foreach ($cart['list'] as $index => $item) {
            $product = DB::table($module)->where('id', $item['item_id'])->find();
            if (!$product) {
                return array('ok' => false, 'code' => 'product_not_exist', 'msg' => lang('product_not_exist'));
            }
            if ($module === 'vip_package' && (!isset($item['vip_days']) || (int) $item['vip_days'] <= 0
                || (int) $item['vip_days'] !== (int) $product['day'])) {
                // 报价后权益变化与价格变化同样要求重新确认，不能按旧报价收款。
                return array('ok' => false, 'code' => 'price_changed', 'msg' => lang('order_price_changed'));
            }
            if (!OrderableModule::isPublished($module, $product)) {
                return array(
                    'ok' => false,
                    'code' => 'off_shelf',
                    'msg' => $item['name'] . lang('order_item_off_shelf'),
                );
            }

            $salePrice = $this->pricingService->salePrice($module, $item['item_id'], $userId);
            $priceChange = 0.0;

            if (!empty($item['attribute']) && Config::get('features.attribute', false)) {
                foreach (explode('_', $item['attribute']) as $valueId) {
                    $attrValue = DB::table('attribute_value')
                        ->where('id', intval($valueId))
                        ->where('item_id', $item['item_id'])
                        ->find();
                    if ($attrValue) {
                        $priceChange += floatval($attrValue['price_change']);
                    } else {
                        return array('ok' => false, 'code' => 'invalid_attribute', 'msg' => lang('attribute_invalid'));
                    }
                }
            }

            $finalPrice = ($salePrice ? $salePrice['value'] : floatval($product['price'])) + $priceChange;
            if ($mode !== 'point' && $finalPrice <= 0) {
                return array(
                    'ok' => false,
                    'code' => 'price_invalid',
                    'msg' => $item['name'] . lang('order_price_invalid', lang('order_item_wrong')),
                );
            }

            $oldPrice = isset($item['sale_price']['value']) ? $item['sale_price']['value'] : $item['price_normal'];
            if (abs($finalPrice - $oldPrice) > 0.01) {
                $priceChangedItems[] = $item['name'];
            }
            $recalculatedAmount += $finalPrice * $item['number'];
            $currencyRowAmounts[$index] = round(
                currency()->fromBase($finalPrice, $currencyCtx) * $item['number'],
                2
            );

            $categoryId = ($module === 'product' && isset($product['category_id'])) ? (int) $product['category_id'] : 0;
            $couponCartLines[] = array(
                'module' => $module,
                'category_id' => $categoryId,
                'category_ids' => $this->categoryPath($categoryId, $categoryParentMap),
                'item_id' => (int) $item['item_id'],
                'amount' => $finalPrice * $item['number'],
            );
        }

        if (abs($recalculatedAmount - $cart['item_amount']) > 0.01) {
            $msg = !empty($priceChangedItems)
                ? lang('order_price_changed') . '：' . implode('、', $priceChangedItems)
                : lang('order_price_changed');
            return array('ok' => false, 'code' => 'price_changed', 'msg' => $msg);
        }

        // 订单号在事务外生成，积分扣减时需要；user_sn 取自上方已按 $userId 查到的 $user 行，
        // 避免在跨壳复用本服务时回查 auth('front')（API shell 只注册 api guard 会 fail-fast）。
        $userSn = isset($user['user_sn']) ? (string) $user['user_sn'] : '';
        $orderSn = $this->order->createOrderSn($userSn);

        try {
            DB::beginTransaction();

            // 积分兑换：先扣积分（失败不再清空购物车）
            if ($mode === 'point') {
                if (!$this->wallet->createPoint($userId, 'exchange', $orderPoint * (-1), $orderSn)) {
                    DB::rollback();
                    return array('ok' => false, 'code' => 'point_not_enough', 'msg' => lang('point_not_enough'));
                }
            }

            $couponAmount = 0.0;
            $appliedCouponRows = array();

            // 优惠券：单券模式，校验可用性后核销 + 写明细；积分订单不使用优惠券
            if ($mode !== 'point' && $couponId > 0 && $this->couponQuote) {
                $coupon = $this->loadOwnedValidCoupon($couponId, $userId);
                $quote = $coupon
                    ? $this->couponQuote->evaluate($coupon, $userId, $couponCartLines)
                    : array('usable' => false, 'reason_text' => lang('coupon_reason_not_found'), 'discount' => 0.0);

                if (empty($quote['usable']) || $quote['discount'] <= 0) {
                    DB::rollback();
                    return array(
                        'ok' => false,
                        'code' => 'coupon_invalid',
                        'msg' => !empty($quote['reason_text'])
                            ? $quote['reason_text']
                            : lang('order_coupon_invalid', lang('order_item_wrong')),
                    );
                }

                $lineAmount = (float) $quote['discount'];
                $couponIdApplied = (int) $coupon['id'];

                // 限领 > 1 时同一券可能有多条未核销记录，只锁定并核销其中一条。
                $couponLogId = (int) DB::table('coupon_log')
                    ->where('coupon_id', $couponIdApplied)
                    ->where('user_id', $userId)
                    ->where('status', '0')
                    ->order('id ASC')
                    ->value('id');
                if ($couponLogId <= 0) {
                    DB::rollback();
                    return array('ok' => false, 'code' => 'coupon_invalid', 'msg' => lang('order_coupon_invalid', lang('order_item_wrong')));
                }

                $affected = DB::table('coupon_log')
                    ->where('id', $couponLogId)
                    ->where('status', '0')
                    ->data(array('used_at' => $addTime, 'order_sn' => $orderSn, 'status' => '1'))
                    ->update();
                if ($affected === false || $affected < 1) {
                    DB::rollback();
                    return array('ok' => false, 'code' => 'coupon_invalid', 'msg' => lang('order_coupon_invalid', lang('order_item_wrong')));
                }

                $appliedCouponRows[] = array(
                    'coupon_id' => $couponIdApplied,
                    'coupon_log_id' => $couponLogId,
                    'amount' => $lineAmount,
                    'currency_amount' => currency()->fromBase($lineAmount, $currencyCtx),
                    'type' => isset($coupon['type']) ? (string) $coupon['type'] : '',
                );
                $couponAmount = $lineAmount;
            }

            // 统一金额口径：预览与下单共用 computeTotals（免邮按券后商品金额）
            $totals = $this->computeTotals($cart, $mode, $shippingConfig, $couponAmount);
            $couponAmount = $totals['coupon_amount'];
            $shippingFee = $totals['shipping_fee'];
            $orderAmount = $totals['order_amount'];
            $storedItemAmount = ($mode === 'point') ? 0 : Num::round($totals['item_amount'] - $couponAmount, 2);

            // 订单币金额：明细逐行换算后汇总，避免基准分位差累积
            $currencyItemGross = 0.0;
            foreach ($currencyRowAmounts as $rowAmount) {
                $currencyItemGross += $rowAmount;
            }
            $currencyItemGross = Num::round($currencyItemGross, 2);
            $currencyCouponAmount = 0.0;
            foreach ($appliedCouponRows as $couponRow) {
                $currencyCouponAmount += (float) $couponRow['currency_amount'];
            }
            $currencyCouponAmount = min(Num::round($currencyCouponAmount, 2), $currencyItemGross);
            $currencyShippingFee = $mode === 'point' ? 0.0 : currency()->fromBase($shippingFee, $currencyCtx);
            $currencyItemAmount = $mode === 'point' ? 0.0 : Num::round($currencyItemGross - $currencyCouponAmount, 2);
            $currencyOrderAmount = $mode === 'point' ? 0.0 : Num::round($currencyItemAmount + $currencyShippingFee, 2);

            // 分销奖励比例（积分订单不产生分销奖励）
            $directReward = 0;
            $indirectReward = 0;
            if ($mode !== 'point') {
                $distributionSvc = Module::make('distribution');
                if ($distributionSvc) {
                    $directReward = $distributionSvc->rewardPercent('direct', $user['direct_user_id']);
                    $indirectReward = $distributionSvc->rewardPercent('indirect', $user['indirect_user_id']);
                }
            }

            // 库存锁定前以产品行锁复核（同一商品多规格行先合并数量），避免并发结算超卖
            if ($module === 'product' && Config::get('site.stock', false)) {
                $requiredByItem = array();
                $itemNameById = array();
                foreach ($cart['list'] as $item) {
                    $itemId = intval($item['item_id']);
                    $requiredByItem[$itemId] = isset($requiredByItem[$itemId])
                        ? $requiredByItem[$itemId] + intval($item['number'])
                        : intval($item['number']);
                    $itemNameById[$itemId] = $item['name'];
                }
                ksort($requiredByItem);

                foreach ($requiredByItem as $itemId => $requiredNumber) {
                    $productRow = DB::table($module)
                        ->where('id', $itemId)
                        ->lock('FOR UPDATE')
                        ->field('id,stock')
                        ->find();
                    if (!$productRow) {
                        DB::rollback();
                        return array('ok' => false, 'code' => 'product_not_exist', 'msg' => lang('product_not_exist'));
                    }
                    // realTimeStock 恒 >= 0，库存被并发扣到 0 时同样必须拦截，不能按 stock 真值短路
                    if ($requiredNumber > $this->order->realTimeStock($module, $itemId, true)) {
                        DB::rollback();
                        return array('ok' => false, 'code' => 'stock_error', 'msg' => $itemNameById[$itemId] . lang('order_out_stock'));
                    }
                }
            }

            // 需要在线支付的订单必须至少有一种可用支付方式，避免落单后收银台为空
            if ($mode !== 'point' && $currencyOrderAmount > 0 && empty($this->order->getPaymentList($currencyCtx['code'], $module))) {
                DB::rollback();

                return array(
                    'ok' => false,
                    'code' => 'payment_unavailable',
                    'msg' => lang('order_payment_currency_unsupported', lang('order_payment_invalid', lang('illegal'))),
                );
            }

            // 付款后需联动的模块：config/module.php 的 link_order_item 为模块键清单，
            // 商品表名去掉 _package 后缀即其模块键（vip_package → vip / authorize → authorize）。
            $linkModuleKey = str_replace('_package', '', $module);
            $itemLinkStatus = in_array($linkModuleKey, (array) Config::get('module.link_order_item'), true) ? 1 : 0;

            DB::table('order')->data(array(
                'order_sn' => $orderSn,
                'currency_code' => $currencyCtx['code'],
                'base_currency_code' => $currencyCtx['base_code'],
                'currency_rate' => $currencyCtx['rate'],
                'currency_decimal' => $currencyCtx['decimals'],
                'base_currency_decimal' => $currencyCtx['base_decimals'],
                'user_id' => $userId,
                'direct_user_id' => $user['direct_user_id'],
                'indirect_user_id' => $user['indirect_user_id'],
                'mode' => $mode,
                'module' => $module,
                'contact_id' => $contactId,
                'shipping_id' => $shippingId,
                'item_amount' => $storedItemAmount,
                'currency_item_amount' => $currencyItemAmount,
                'currency_coupon_amount' => $currencyCouponAmount,
                'shipping_fee' => $shippingFee,
                'currency_shipping_fee' => $currencyShippingFee,
                'order_amount' => $orderAmount,
                'currency_order_amount' => $currencyOrderAmount,
                'order_point' => $orderPoint,
                'direct_reward' => $directReward ? $directReward : 0,
                'indirect_reward' => $indirectReward ? $indirectReward : 0,
                'item_link_status' => $itemLinkStatus,
                'created_at' => $addTime,
            ))->insert();

            $orderId = (int) DB::insertId();
            if ($orderId <= 0) {
                DB::rollback();

                return array('ok' => false, 'code' => 'order_create_failed', 'msg' => lang('order_item_wrong'));
            }
            // 仅对库存表中带 stock 字段的模块加锁，与付款时的库存扣减口径保持一致
            $stockLock = (Config::get('site.stock', false) && DB::fieldExist($module, 'stock')) ? 1 : 0;

            foreach ($cart['list'] as $index => $item) {
                $catId = $module === 'product'
                    ? (int) DB::table($module)->where('id', $item['item_id'])->value('category_id')
                    : 0;
                $salePriceData = $item['sale_price'];
                $currencyRowAmount = isset($currencyRowAmounts[$index])
                    ? $currencyRowAmounts[$index]
                    : Num::round(currency()->fromBase($salePriceData['value'], $currencyCtx) * $item['number'], 2);
                DB::table('order_item')->data(array(
                    'user_id' => $userId,
                    'direct_user_id' => $user['direct_user_id'],
                    'indirect_user_id' => $user['indirect_user_id'],
                    'order_id' => $orderId,
                    'module' => $module,
                    'category_id' => $catId,
                    'item_id' => $item['item_id'],
                    'name' => $item['name'],
                    'price' => $item['price_normal'],
                    'currency_price' => currency()->fromBase($item['price_normal'], $currencyCtx),
                    'sale_price' => $salePriceData['value'],
                    'currency_sale_price' => currency()->fromBase($salePriceData['value'], $currencyCtx),
                    'currency_row_amount' => $currencyRowAmount,
                    'sale_price_type' => $salePriceData['type'],
                    'point' => $item['point'],
                    'direct_reward' => $directReward ? $directReward : 0,
                    'indirect_reward' => $indirectReward ? $indirectReward : 0,
                    'item_number' => $item['number'],
                    'attribute' => $item['attribute'],
                    'attribute_name' => $item['attribute_name'],
                    'stock_lock' => $stockLock,
                    'created_at' => $addTime,
                ))->insert();
                if (DB::insertId() <= 0) {
                    DB::rollback();

                    return array('ok' => false, 'code' => 'order_create_failed', 'msg' => lang('order_item_wrong'));
                }
            }

            // 收货地址快照：下单瞬间冻结到 order_address（与会员地址本解耦，已脱离 dou_order 平拍）。
            DB::table('order_address')->data(array(
                'order_id' => $orderId,
                'user_id' => $userId,
                'contact_name' => $contact,
                'phone' => $phone,
                'id_card' => isset($userContact['id_card']) ? (string) $userContact['id_card'] : '',
                'country' => isset($userContact['country']) ? (string) $userContact['country'] : '',
                'province' => isset($userContact['province']) ? (string) $userContact['province'] : '',
                'city' => isset($userContact['city']) ? (string) $userContact['city'] : '',
                'district' => isset($userContact['district']) ? (string) $userContact['district'] : '',
                'address' => $address,
                'postcode' => $postcode,
                'created_at' => $addTime,
            ))->insert();
            if (DB::insertId() <= 0) {
                DB::rollback();

                return array('ok' => false, 'code' => 'order_create_failed', 'msg' => lang('order_item_wrong'));
            }

            // 优惠券明细：单券模式下写入一行 order_coupon（已脱离 dou_order.coupon_id/coupon_amount 平拍）。
            foreach ($appliedCouponRows as $couponRow) {
                DB::table('order_coupon')->data(array(
                    'order_id' => $orderId,
                    'coupon_id' => $couponRow['coupon_id'],
                    'coupon_log_id' => $couponRow['coupon_log_id'],
                    'amount' => $couponRow['amount'],
                    'currency_amount' => $couponRow['currency_amount'],
                    'type' => $couponRow['type'],
                    'created_at' => $addTime,
                ))->insert();
                if (DB::insertId() <= 0) {
                    DB::rollback();

                    return array('ok' => false, 'code' => 'order_create_failed', 'msg' => lang('order_item_wrong'));
                }
            }

            // 购物车来源：事务内按本次下单快照的行 id 删除，避免误删并发加购的新行
            if ($sourceType === 'cart') {
                $cartIds = array();
                foreach ($cart['list'] as $cartItem) {
                    if (!empty($cartItem['id'])) {
                        $cartIds[] = intval($cartItem['id']);
                    }
                }
                if (!empty($cartIds)) {
                    DB::table('order_cart')
                        ->where('user_id', (int) $userId)
                        ->whereIn('id', $cartIds)
                        ->delete();
                }
            }

            DB::commit();

            // 0 元订单（积分兑换 mode='point' 或 money 模式被优惠券打到 0）跨过 PENDING，
            // 由 OrderStatusTransition 按现有规则收尾：无 shipping 插件 / 非 product 模块
            // 自动到 COMPLETED；product + shipping 停 PAID 等发货。
            if ($mode === 'point' || $orderAmount == 0) {
                $this->order->changeStatus($orderSn, OrderStatus::PAID);
                $currentStatus = (string) DB::table('order')->where('order_sn', $orderSn)->value('status');
                if ($currentStatus !== OrderStatus::PAID && $currentStatus !== OrderStatus::COMPLETED) {
                    // 收尾失败：走既有取消补偿（退积分/退券/解锁库存），不再对用户谎报成功
                    $compensated = $this->order->changeStatus($orderSn, OrderStatus::CANCELLED, 'system', 0, '', 'zero amount finalize failed');
                    Log::error('0 amount order finalize failed', array(
                        'channel' => 'order',
                        'module' => 'order',
                        'payload' => array('order_sn' => $orderSn, 'status' => $currentStatus, 'compensated' => $compensated),
                    ));

                    return array('ok' => false, 'code' => 'order_finalize_failed', 'msg' => lang('order_item_wrong'));
                }
            }

            return array(
                'ok' => true,
                'order_sn' => $orderSn,
                'order_amount' => $currencyOrderAmount,
                'order_amount_format' => Util::formatCurrency($currencyOrderAmount, $currencyCtx),
                'order_point' => $orderPoint,
                'mode' => $mode,
            );
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('Order create failed: ' . $e->getMessage(), array(
                'channel' => 'order',
                'module' => 'order',
                'payload' => array(
                    'user_id' => (int) $userId,
                    'order_sn' => isset($orderSn) ? (string) $orderSn : '',
                    'mode' => (string) $mode,
                    'cart_module' => isset($cart['module']) ? (string) $cart['module'] : '',
                    'item_amount' => isset($cart['item_amount']) ? $cart['item_amount'] : null,
                    'coupon_id' => (int) $couponId,
                    'shipping_id' => (string) $shippingId,
                ),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 4000),
            ));

            return array('ok' => false, 'code' => 'db_error', 'msg' => lang('order_create_failed'));
        }
    }

    /**
     * 购物车是否满足积分兑换条件（所有条目 point > 0 且积分功能开启）。
     *
     * @param array $cart getCart 结果
     * @return bool
     */
    private function isPointCart(array $cart)
    {
        if (!Config::get('features.point', false) || empty($cart['list'])) {
            return false;
        }

        foreach ($cart['list'] as $item) {
            if (!isset($item['point']) || intval($item['point']) <= 0) {
                return false;
            }
        }

        return intval(isset($cart['order_point']) ? $cart['order_point'] : 0) > 0;
    }

    /**
     * 解析配送插件配置；非法 slug 返回 null，无配送插件且 slug 为空时返回空数组。
     *
     * @param string $slug
     * @return array|null
     */
    private function resolveShippingConfig($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '0') {
            $slug = '';
        }
        $hasShipping = plugin()->isAvailable() && plugin()->valueByGroup('shipping', 'slug');

        if ($slug === '') {
            return $hasShipping ? null : array();
        }

        $plugin = plugin()->getWithConfig($slug);
        if (!is_array($plugin)
            || !isset($plugin['plugin_group'])
            || (string) $plugin['plugin_group'] !== 'shipping'
        ) {
            return null;
        }

        return (isset($plugin['config']) && is_array($plugin['config'])) ? $plugin['config'] : array();
    }
}
