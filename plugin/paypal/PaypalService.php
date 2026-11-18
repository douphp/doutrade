<?php

namespace Dou\Plugin\Paypal;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Infra\Plugin\Dto\PaymentCallbackPayload;
use Dou\Core\Infra\Plugin\Dto\PaymentRequest;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Payment\PaymentService;
use Dou\Core\Web\Http\Client;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * PayPal 业务服务。
 *
 * invoice 字段使用 dou_order_payment.payment_sn；notify/finish 验签通过后由
 * {@see PaymentService::markSucceeded()} 推进状态机。
 *
 * 不参与对账：本插件走老版 Web Payment Standard（IPN）协议，配置仅含
 * seller_email，无 PayPal REST/NVP API 凭证，无法发起按 invoice 维度的主动
 * 查询。后续如需对账，需要切到 PayPal REST API 并补 client_id/secret 凭证。
 */
class PaypalService extends BaseService
{
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
        $plugin = plugin()->getWithConfig('paypal');
        $config = isset($plugin['config']) && is_array($plugin['config']) ? $plugin['config'] : array();

        $sellerEmail = isset($config['seller_email']) ? $config['seller_email'] : '';
        $currencyCode = isset($config['currency_code']) ? $config['currency_code'] : 'USD';
        $currencyCode = strtoupper(trim((string) $currencyCode));
        if (trim((string) $sellerEmail) === '' || ($request->getCurrencyCode() !== '' && $request->getCurrencyCode() !== $currencyCode)) {
            throw new DomainException('PayPal 收款账号或币种配置不完整，请联系管理员。', HOME_URL);
        }
        if ($currencyCode === 'JPY' && abs($request->getOrderAmount() - round($request->getOrderAmount())) > 0.000001) {
            throw new DomainException('PayPal 日元付款金额必须为整数，请联系管理员核对订单。', HOME_URL);
        }
        $itemName = 'Order Sn : ' . $request->getOrderSn() . ' (' . Config::get('site.site_name', '') . ')';
        $notifyUrl = ROOT_URL . 'index.php?route=plugin/paypal/notify';
        $returnUrl = ROOT_URL . 'index.php?route=plugin/paypal/finish';
        $cancelReturn = ROOT_URL;

        return
            "<form action='https://www.paypal.com/cgi-bin/webscr' method='post' name='paypalSubmit'>"
            . "<input type='hidden' name='cmd' value='_xclick'>"
            . "<input type='hidden' name='business' value='" . htmlspecialchars($sellerEmail, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='item_name' value='" . htmlspecialchars($itemName, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='currency_code' value='" . htmlspecialchars($currencyCode, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='amount' value='" . $request->getOrderAmount() . "'>"
            . "<input type='hidden' name='invoice' value='" . htmlspecialchars($request->getPaymentSn(), ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='charset' value='utf-8'>"
            . "<input type='hidden' name='no_shipping' value='1'>"
            . "<input type='hidden' name='notify_url' value='" . htmlspecialchars($notifyUrl, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='cancel_return' value='" . htmlspecialchars($cancelReturn, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='hidden' name='return' value='" . htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') . "'>"
            . "<input type='submit' class='btn-payment' value='立即付款'>"
            . "</form>"
            . "<script>function sub(){document.paypalSubmit.submit();}onload(sub());</script>";
    }

    /**
     * @param PaymentCallbackPayload $payload
     * @return Response
     */
    public function notify(PaymentCallbackPayload $payload)
    {
        $raw = $payload->getRawBody();
        $requestData = array();
        parse_str($raw, $requestData);
        foreach ($requestData as $value) {
            if (!is_string($value)) {
                return new Response('fail', 400);
            }
        }
        $plugin = plugin()->getWithConfig('paypal');
        $config = isset($plugin['config']) && is_array($plugin['config']) ? $plugin['config'] : array();

        $paymentSn = isset($requestData['invoice']) ? trim((string) $requestData['invoice']) : '';
        if ($raw === '' || $paymentSn === '') {
            return new Response('fail', 400);
        }

        $payment = $this->paymentService->findBySn($paymentSn);
        if (!$payment || (string) $payment['gateway'] !== 'paypal') {
            return new Response('fail', 400);
        }

        $response = Client::request('POST', 'https://ipnpb.paypal.com/cgi-bin/webscr',
            'cmd=_notify-validate&' . $raw,
            array('Content-Type: application/x-www-form-urlencoded'),
            array('return_meta' => true, 'connect_timeout' => 10, 'timeout' => 30));
        if (!is_array($response) || !empty($response['errno']) || (int) $response['http_code'] !== 200) {
            return new Response('fail', 500);
        }
        if ((string) $response['body'] !== 'VERIFIED') {
            return new Response('fail', 400);
        }

        if (!isset($requestData['payment_status']) || (string) $requestData['payment_status'] !== 'Completed') {
            return new Response('fail', 400);
        }
        if (empty($config['seller_email']) || !isset($requestData['receiver_email']) || strcasecmp((string) $requestData['receiver_email'], trim((string) $config['seller_email'])) !== 0) {
            return new Response('fail', 400);
        }
        if (!isset($requestData['mc_gross']) || !is_numeric($requestData['mc_gross'])
            || !is_finite((float) $requestData['mc_gross']) || (float) $requestData['mc_gross'] <= 0
            || abs((float) $requestData['mc_gross'] - (float) $payment['amount']) > 0.005) {
            return new Response('fail', 400);
        }
        $currency = isset($payment['currency_code']) ? strtoupper(trim((string) $payment['currency_code'])) : '';
        if ($currency === '' || !isset($requestData['mc_currency']) || (string) $requestData['mc_currency'] !== $currency) {
            return new Response('fail', 400);
        }

        $transactionId = isset($requestData['txn_id']) ? (string) $requestData['txn_id'] : '';
        if (trim($transactionId) === '' || (!empty($payment['transaction_id']) && (string) $payment['transaction_id'] !== $transactionId)) {
            return new Response('fail', 400);
        }
        $ok = $this->paymentService->markSucceeded(
            $paymentSn,
            $transactionId,
            $raw,
            (float) $requestData['mc_gross'],
            (string) $requestData['mc_currency']
        );
        return new Response($ok ? 'success' : 'fail', $ok ? 200 : 500);
    }

    /**
     * 同步回跳：PayPal 标准支付的回跳不带可验证签名，只做跳转，落账交给 IPN notify。
     *
     * @param PaymentCallbackPayload $payload
     * @return string
     */
    public function finish(PaymentCallbackPayload $payload)
    {
        $requestData = $payload->getRequestData();
        $paymentSn = isset($requestData['invoice']) ? trim((string) $requestData['invoice']) : '';
        if ($paymentSn === '') {
            return route('order.user');
        }

        $payment = $this->paymentService->findBySn($paymentSn);
        if ($payment && (string) $payment['gateway'] === 'paypal' && !empty($payment['order_sn'])) {
            return route('order.user.show', array('order_sn' => (string) $payment['order_sn']));
        }

        return route('order.user');
    }
}
