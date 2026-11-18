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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Infra\Plugin\PluginClient;
use Dou\Core\Infra\Plugin\PluginConfig;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentCurrencyPolicy;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 结算页选项（结算域）：支付/配送插件列表读取与「付款剩余时间」文案。
 *
 * 仅做读模型与时间换算，不触发状态变更；与 OrderStatusTransition 没有直接依赖关系。
 */
class OrderCheckoutOptions extends BaseService
{
    /** @var array 错误日志 */
    private $error_log = array();

    /**
     */
    public function __construct()
    {
    }

    /**
     * 取启用的支付方式插件列表。
     *
     * @param string $currency 订单币种代码；非空时只保留支持该币种的网关
     * @param string $module 订单模块
     * @param string $client 客户端：WEB（默认）| MP
     * @return array
     */
    public function getPaymentList($currency = '', $module = '', $client = PluginClient::WEB)
    {
        if (!DB::tableExist('plugin')) {
            return array();
        }

        try {
            $client = PluginClient::normalize($client);

            $query = DB::table('plugin')
                ->where('plugin_group', 'payment')
                ->where(function ($q) use ($client) {
                    $q->where('allow_client', $client)->whereOr('allow_client', PluginClient::ALL);
                    if ($client === PluginClient::WEB) {
                        // 兼容历史值：PC / MOBILE 都归入网页端。
                        $q->whereOr('allow_client', 'PC')->whereOr('allow_client', 'MOBILE');
                    }
                })
                ->select();

            $payment_list = array();
            $i = 0;

            foreach ((array) PaymentCurrencyPolicy::filterRows((array) $query, $currency) as $row) {
                $slug = (string) $row['slug'];
                if ($slug === 'wallet'
                    || PluginClient::isDeprecated($slug)
                    || ($slug === 'cod' && $module !== ''
                        && !\Dou\Core\Domain\Order\OrderPolicy::canUseCod($module))) {
                    continue;
                }
                // 非网页入口（CLI 等）可能未定义 PLUGIN_URL，此处兜底避免未定义常量致命错误。
                $pluginUrl = defined('PLUGIN_URL') ? PLUGIN_URL : (defined('ROOT_URL') ? ROOT_URL . 'plugin/' : '');
                $image = $pluginUrl . $row['slug'] . '/icon.gif';
                $payment_list[] = array(
                    'id' => $row['slug'],
                    'name' => $row['name'],
                    'image' => $image,
                    'checked' => $i == 0,
                );
                $i++;
            }

            return $payment_list;
        } catch (\Exception $e) {
            $this->logError('get_payment_list error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 取启用的配送方式插件列表，附带 fee/free 阈值文案。
     *
     * @return array
     */
    public function getShippingList()
    {
        if (!DB::tableExist('plugin')) {
            return array();
        }

        try {
            $query = DB::table('plugin')
                ->where('plugin_group', 'shipping')
                ->select();

            $shipping_list = array();
            $i = 0;

            foreach ((array) $query as $row) {
                $config = PluginConfig::decode(isset($row['config']) ? $row['config'] : array());

                $fee = isset($config['fee']) ? floatval($config['fee']) : 0;
                $free = isset($config['free']) ? floatval($config['free']) : 0;

                $fee_format = $fee > 0
                    ? Util::formatPrice($fee)
                    : lang('order_shipping_free');

                $free_format = '';
                if ($free > 0) {
                    $free_format = str_replace(
                        'd%',
                        Util::formatPrice($free),
                        lang('order_shipping_free_cue')
                    );
                }

                $shipping_list[] = array(
                    'slug' => $row['slug'],
                    'name' => $row['name'],
                    'description' => isset($row['description']) ? $row['description'] : '',
                    'fee' => $fee,
                    'fee_format' => $fee_format,
                    'free' => $free,
                    'free_format' => $free_format,
                    'checked' => $i == 0,
                );
                $i++;
            }

            return $shipping_list;
        } catch (\Exception $e) {
            $this->logError('get_shipping_list error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * 把秒数翻译成「N 天 HH 小时 MM 分钟 SS 秒」展示串。
     *
     * @param int $time_limit 剩余秒数
     * @return string
     */
    public function timeLimit($time_limit)
    {
        $second = intval($time_limit);
        if ($second <= 0) {
            return '';
        }

        $day = floor($second / (3600 * 24));
        $second = $second % (3600 * 24);
        $hour = sprintf('%02d', floor($second / 3600));
        $second = $second % 3600;
        $minute = sprintf('%02d', floor($second / 60));
        $second = sprintf('%02d', $second % 60);

        $result = '';
        if ($day) {
            $result .= $day . $this->orderLangOr('order_pay_time_out_day', '天');
        }
        if ($hour != '00') {
            $result .= $hour . $this->orderLangOr('order_pay_time_out_hour', '小时');
        }
        if ($minute != '00') {
            $result .= $minute . $this->orderLangOr('order_pay_time_out_minute', '分钟');
        }
        if ($second != '00' && !$day && $hour == '00') {
            $result .= $second . $this->orderLangOr('order_pay_time_out_second', '秒');
        }

        return $result;
    }

    /**
     * 未付款订单超时分钟数（全站唯一口径）。
     *
     * 配置缺失、非数字或 <= 0 时统一回落到默认 60 分钟，避免收银台、支付单
     * 过期时间与自动取消任务各自解释 0 而产生“永不过期 / 立即过期”分歧。
     *
     * @return int
     */
    public static function payTimeoutMinutes()
    {
        $minutes = Config::has('param.order_pay_timeout')
            ? intval(Config::get('param.order_pay_timeout'))
            : 60;

        return $minutes > 0 ? $minutes : 60;
    }

    /**
     * 计算订单付款剩余时间文案。
     *
     * @param string|int $created_at 订单 created_at；为空则按完整超时长度计算
     * @param bool $short 使用短文案模板 order_pay_time_out_short
     * @param bool $only_number 只返回时分秒字符串，不套用 d% 模板
     * @return string
     */
    public function payTimeLimit($created_at = '', $short = false, $only_number = false)
    {
        try {
            $timeout = self::payTimeoutMinutes() * 60;

            $createdAtTs = Util::toTimestamp($created_at);
            $time_limit = $createdAtTs !== null
                ? $timeout - (time() - $createdAtTs)
                : $timeout;

            if ($time_limit <= 0) {
                return $only_number ? '0' : $this->orderLangOr('order_pay_time_out_expired', '支付已超时');
            }

            $time_limit_str = $this->timeLimit($time_limit);

            if ($only_number) {
                return $time_limit_str;
            }

            $lang = $short
                ? $this->orderLangOr('order_pay_time_out_short', '剩余<b>d%</b>')
                : $this->orderLangOr('order_pay_time_out', '请您在<b>d%</b>内完成支付，否则订单会被自动取消');

            return str_replace('d%', $time_limit_str, $lang);
        } catch (\Exception $e) {
            $this->logError('pay_time_limit error: ' . $e->getMessage());
            return $only_number ? '0' : $this->orderLangOr('order_pay_time_out_error', '无法计算剩余支付时间');
        }
    }

    /**
     * 取订单相关语言串；未合并 order 语言包时回退为 $fallback。
     *
     * @param string $key
     * @param string $fallback
     * @return string
     */
    private function orderLangOr($key, $fallback)
    {
        return lang($key, $fallback);
    }

    /**
     * 记录错误：写入实例日志数组，同时投递到 Log::error（channel=order）。
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
