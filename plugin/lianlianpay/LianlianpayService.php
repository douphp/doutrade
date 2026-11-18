<?php

namespace Dou\Plugin\Lianlianpay;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 连连支付业务服务。
 *
 * no_order 字段使用 dou_order_payment.payment_sn；notify/finish 验签通过后由
 * {@see PaymentService::markSucceeded()} 推进状态机。
 *
 * 不参与对账：仓内 SDK 仅含 submit/notify/md5/rsa/cls_json，无主动查单接口；
 * 实现需要连连商户平台「查单 API」文档与签名细节，本仓库暂无。后续如需补齐，
 * 在 LianlianpayProvider 上实现 ReconcilablePaymentProviderInterface 并补 query()。
 */
class LianlianpayService extends BaseService
{
    const PLUGIN_ID = 'lianlianpay';

    /** @var PaymentService */
    private $paymentService;

    /**
     * @param PaymentService $paymentService
     */
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * @param PaymentRequest $request
     * @return string
     */
    public function start(PaymentRequest $request)
    {
        $config = $this->buildConfig();
        if (empty($config['oid_partner']) || empty($config['rsa_private_key']) || empty($config['key'])) {
            throw new DomainException('连连支付配置不完整，请联系管理员。', HOME_URL);
        }

        require_once PLUGIN_PATH . self::PLUGIN_ID . '/sdk/lib/llpay_submit.class.php';

        $auth = auth('front');
        $userId = (is_object($auth) && method_exists($auth, 'id')) ? (int) $auth->id() : 0;
        $parameter = array(
            'oid_partner' => trim((string) $config['oid_partner']),
            'app_request' => trim((string) $config['app_request']),
            'sign_type' => trim((string) $config['sign_type']),
            'valid_order' => trim((string) $config['valid_order']),
            'user_id' => $userId,
            'busi_partner' => '109001',
            'no_order' => $request->getPaymentSn(),
            'dt_order' => date('YmdHis'),
            'name_goods' => 'Order Sn : ' . $request->getOrderSn() . ' (' . Config::get('site.site_name', '') . ')',
            'info_order' => 'Order Sn : ' . $request->getOrderSn(),
            'money_order' => $request->getOrderAmount(),
            'notify_url' => $config['notify_url'],
            'url_return' => $config['return_url'],
            'card_no' => '',
            'acct_name' => '',
            'id_no' => '',
            'no_agree' => '',
            'risk_item' => '',
        );

        $submit = new \LLpaySubmit($config);
        return $submit->buildRequestForm($parameter, 'post', '确认');
    }

    /**
     * @param PaymentCallbackPayload $payload
     * @return Response
     */
    public function notify(PaymentCallbackPayload $payload)
    {
        $config = $this->buildConfig();
        require_once PLUGIN_PATH . self::PLUGIN_ID . '/sdk/lib/llpay_notify.class.php';

        $notify = new \LLpayNotify($config);
        $notify->verifyNotify($payload->getRawBody());

        if (!$notify->result) {
            return new Response('fail', 400);
        }

        $resp = is_array($notify->notifyResp) ? $notify->notifyResp : array();
        $paymentSn = isset($resp['no_order']) ? trim((string) $resp['no_order']) : '';
        $resultPay = isset($resp['result_pay']) ? $resp['result_pay'] : '';
        $transactionId = isset($resp['oid_paybill']) ? trim((string) $resp['oid_paybill']) : '';
        if ($resultPay === 'SUCCESS' && $paymentSn === '') {
            return new Response('fail', 400);
        }

        if ($paymentSn !== '' && $resultPay === 'SUCCESS') {
            $payment = $this->paymentService->findBySn($paymentSn);
            if (!$payment || (string) $payment['gateway'] !== self::PLUGIN_ID || $transactionId === ''
                || (!empty($payment['transaction_id']) && (string) $payment['transaction_id'] !== $transactionId)
                || !isset($resp['money_order']) || !is_numeric($resp['money_order'])
                || !is_finite((float) $resp['money_order']) || (float) $resp['money_order'] <= 0
                || abs((float) $resp['money_order'] - (float) $payment['amount']) > 0.005
                || !isset($payment['currency_code']) || $payment['currency_code'] !== 'CNY') {
                return new Response('fail', 400);
            }
            $paidAmount = (float) $resp['money_order'];
            $ok = $this->paymentService->markSucceeded($paymentSn, $transactionId, $this->encodeRaw($resp), $paidAmount, 'CNY');
            return new Response($ok ? '{"ret_code":"0000","ret_msg":"交易成功"}' : 'fail', $ok ? 200 : 500);
        }
        return new Response('{"ret_code":"0000","ret_msg":"交易成功"}', 200);
    }

    /**
     * @param PaymentCallbackPayload $payload
     * @return string
     */
    public function finish(PaymentCallbackPayload $payload)
    {
        $data = $payload->getRequestData();
        $raw = isset($data['res_data']) && is_string($data['res_data']) ? $data['res_data'] : '';
        $this->notify(new PaymentCallbackPayload(self::PLUGIN_ID, array(), $raw));
        return route('order.user');
    }

    /**
     * @return array
     */
    private function buildConfig()
    {
        $plugin = plugin()->getWithConfig(self::PLUGIN_ID);
        $config = (is_array($plugin) && isset($plugin['config']) && is_array($plugin['config'])) ? $plugin['config'] : array();

        $transport = defined('HTTP') && HTTP ? HTTP : 'http';

        return array(
            'oid_partner' => isset($config['oid_partner']) ? $config['oid_partner'] : '',
            'rsa_private_key' => isset($config['rsa_private_key']) ? $config['rsa_private_key'] : '',
            'key' => isset($config['key']) ? $config['key'] : '',
            'notify_url' => ROOT_URL . 'index.php?route=plugin/' . self::PLUGIN_ID . '/notify',
            'return_url' => ROOT_URL . 'index.php?route=plugin/' . self::PLUGIN_ID . '/finish',
            'gatewayUrl' => 'https://accpgw-ste.lianlianpay-inc.com/v1/cashier/paycreate',
            'version' => '1.2',
            'app_request' => '3',
            'sign_type' => strtoupper('RSA'),
            'valid_order' => '10080',
            'input_charset' => strtolower('utf-8'),
            'transport' => $transport,
        );
    }

    /**
     * @param mixed $data
     * @return string
     */
    private function encodeRaw($data)
    {
        if (is_string($data)) {
            return $data;
        }
        if (function_exists('json_encode')) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            return $encoded === false ? '' : $encoded;
        }
        return serialize($data);
    }
}
