<?php

declare(strict_types=1);

namespace PayFlow\Payment\Channel;

use PayFlow\Http\Request;
use PayFlow\Payment\ChannelInterface;
use PayFlow\Payment\NotifyResult;
use PayFlow\Payment\PaymentIntent;
use PayFlow\Payment\Signer\AlipaySigner;
use PayFlow\Support\HttpClient;
use PayFlow\Support\Money;
use RuntimeException;

/**
 * 支付宝当面付（alipay.trade.precreate）——扫码支付。
 *
 * 需要在 config.channels.alipay 配置 app_id / 应用私钥 / 支付宝公钥。
 */
final class AlipayChannel implements ChannelInterface
{
    public function __construct(private readonly array $config = [], private readonly string $baseUrl = '')
    {
    }

    public function id(): string
    {
        return 'alipay';
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? '支付宝');
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ($this->config['app_id'] ?? '') !== ''
            && ($this->config['private_key'] ?? '') !== '';
    }

    public function createPayment(array $order): PaymentIntent
    {
        $bizContent = [
            'out_trade_no' => (string) $order['order_no'],
            'total_amount' => number_format(((int) $order['amount_cents']) / 100, 2, '.', ''),
            'subject' => mb_substr((string) ($order['product_name'] ?? '订单'), 0, 120),
            'timeout_express' => '30m',
        ];

        $response = $this->call('alipay.trade.precreate', $bizContent);
        $qrCode = (string) ($response['qr_code'] ?? '');
        if ($qrCode === '') {
            throw new RuntimeException('支付宝创建支付失败：未返回 qr_code');
        }

        return new PaymentIntent('qrcode', $qrCode, null, null, (string) ($response['out_trade_no'] ?? $order['order_no']), $response);
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        $params = $request->post;
        if (($params['app_id'] ?? '') !== '' && ($params['app_id'] ?? '') !== ($this->config['app_id'] ?? '')) {
            return null;
        }
        if (!AlipaySigner::verify($params, (string) $this->config['alipay_public_key'])) {
            throw new RuntimeException('支付宝回调验签失败');
        }

        $tradeStatus = (string) ($params['trade_status'] ?? '');
        $paid = in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);

        return new NotifyResult(
            $paid,
            (string) ($params['out_trade_no'] ?? ''),
            (string) ($params['trade_no'] ?? ''),
            Money::toCents((string) ($params['total_amount'] ?? '0')),
            $params,
        );
    }

    public function query(string $orderNo): ?NotifyResult
    {
        try {
            $response = $this->call('alipay.trade.query', ['out_trade_no' => $orderNo]);
        } catch (RuntimeException) {
            return null;
        }
        $paid = ($response['trade_status'] ?? '') === 'TRADE_SUCCESS';

        return new NotifyResult(
            $paid,
            (string) ($response['out_trade_no'] ?? $orderNo),
            (string) ($response['trade_no'] ?? ''),
            Money::toCents((string) ($response['total_amount'] ?? '0')),
            $response,
        );
    }

    public function refund(array $order, int $amountCents, string $reason = ''): bool
    {
        if (($order['channel_trade_no'] ?? '') === '') {
            return false;
        }
        try {
            $response = $this->call('alipay.trade.refund', [
                'out_trade_no' => (string) $order['order_no'],
                'trade_no' => (string) $order['channel_trade_no'],
                'refund_amount' => number_format($amountCents / 100, 2, '.', ''),
                'refund_reason' => mb_substr($reason, 0, 120),
            ]);
        } catch (RuntimeException) {
            return false;
        }

        return ($response['fund_change'] ?? '') === 'Y' || ($response['code'] ?? '') === '10000';
    }

    /**
     * 统一网关调用，返回业务响应体（去掉 alipay_xxx_response 外层）。
     */
    private function call(string $method, array $bizContent): array
    {
        $params = [
            'app_id' => (string) $this->config['app_id'],
            'method' => $method,
            'format' => 'JSON',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => $this->notifyUrl(),
            'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ];
        $params['sign'] = AlipaySigner::sign($params, (string) $this->config['private_key']);

        $response = HttpClient::request('POST', (string) $this->config['gateway'], http_build_query($params), [
            'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
        ]);
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('支付宝返回非 JSON：' . mb_substr($response['body'], 0, 200));
        }

        $key = str_replace('.', '_', $method) . '_response';
        $body = $decoded[$key] ?? null;
        if (!is_array($body)) {
            throw new RuntimeException('支付宝响应缺少业务节点：' . $key);
        }
        if (($body['code'] ?? '') !== '10000') {
            throw new RuntimeException('支付宝调用失败：[' . ($body['code'] ?? '?') . '] ' . ($body['sub_msg'] ?? $body['msg'] ?? '未知错误'));
        }

        return $body;
    }

    private function notifyUrl(): string
    {
        $configured = (string) ($this->config['notify_url'] ?? '');

        return $configured !== '' ? $configured : rtrim($this->baseUrl, '/') . '/notify/alipay';
    }
}
