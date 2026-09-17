<?php

declare(strict_types=1);

namespace PayFlow\Payment\Channel;

use PayFlow\Http\Request;
use PayFlow\Payment\ChannelInterface;
use PayFlow\Payment\NotifyResult;
use PayFlow\Payment\PaymentIntent;
use PayFlow\Payment\Signer\WechatSigner;
use PayFlow\Support\HttpClient;
use RuntimeException;

/**
 * 微信支付 Native（扫码）——APIv3。
 *
 * 需要配置 mch_id / app_id / 证书序列号 / APIv3 密钥 / 商户私钥 / 平台证书。
 */
final class WechatChannel implements ChannelInterface
{
    private const API_BASE = 'https://api.mch.weixin.qq.com';

    public function __construct(private readonly array $config = [], private readonly string $baseUrl = '')
    {
    }

    public function id(): string
    {
        return 'wechat';
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? '微信支付');
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && ($this->config['mch_id'] ?? '') !== ''
            && ($this->config['private_key'] ?? '') !== ''
            && ($this->config['serial_no'] ?? '') !== '';
    }

    public function createPayment(array $order): PaymentIntent
    {
        $body = [
            'appid' => (string) $this->config['app_id'],
            'mchid' => (string) $this->config['mch_id'],
            'description' => mb_substr((string) ($order['product_name'] ?? '订单'), 0, 120),
            'out_trade_no' => (string) $order['order_no'],
            'notify_url' => $this->notifyUrl(),
            'amount' => [
                'total' => (int) $order['amount_cents'],
                'currency' => 'CNY',
            ],
        ];
        $response = $this->request('POST', '/v3/pay/transactions/native', $body);
        $codeUrl = (string) ($response['code_url'] ?? '');
        if ($codeUrl === '') {
            throw new RuntimeException('微信创建支付失败：未返回 code_url');
        }

        return new PaymentIntent('qrcode', $codeUrl, null, null, (string) $order['order_no'], $response);
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        $timestamp = $request->header('Wechatpay-Timestamp', '') ?? '';
        $nonce = $request->header('Wechatpay-Nonce', '') ?? '';
        $signature = $request->header('Wechatpay-Signature', '') ?? '';
        if ($timestamp === '' || $signature === '') {
            return null;
        }

        $platformCert = (string) ($this->config['platform_cert'] ?? '');
        if ($platformCert !== '' && !WechatSigner::verifyNotify($timestamp, $nonce, $request->rawBody(), $signature, $platformCert)) {
            throw new RuntimeException('微信回调验签失败');
        }

        $payload = json_decode($request->rawBody(), true);
        $resource = is_array($payload) ? ($payload['resource'] ?? null) : null;
        if (!is_array($resource)) {
            throw new RuntimeException('微信回调缺少 resource');
        }

        $plain = WechatSigner::decryptResource(
            (string) ($resource['ciphertext'] ?? ''),
            (string) ($resource['nonce'] ?? ''),
            (string) ($resource['associated_data'] ?? ''),
            (string) $this->config['api_v3_key'],
        );
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new RuntimeException('微信回调解密后非 JSON');
        }

        $paid = ($data['trade_state'] ?? '') === 'SUCCESS';

        return new NotifyResult(
            $paid,
            (string) ($data['out_trade_no'] ?? ''),
            (string) ($data['transaction_id'] ?? ''),
            (int) ($data['amount']['total'] ?? 0),
            $data,
        );
    }

    public function query(string $orderNo): ?NotifyResult
    {
        try {
            $response = $this->request('GET', '/v3/pay/transactions/out-trade-no/' . rawurlencode($orderNo) . '?mchid=' . rawurlencode((string) $this->config['mch_id']));
        } catch (RuntimeException) {
            return null;
        }
        $paid = ($response['trade_state'] ?? '') === 'SUCCESS';

        return new NotifyResult(
            $paid,
            (string) ($response['out_trade_no'] ?? $orderNo),
            (string) ($response['transaction_id'] ?? ''),
            (int) ($response['amount']['total'] ?? 0),
            $response,
        );
    }

    public function refund(array $order, int $amountCents, string $reason = ''): bool
    {
        if (($order['channel_trade_no'] ?? '') === '') {
            return false;
        }
        try {
            $this->request('POST', '/v3/refund/domestic/refunds', [
                'out_trade_no' => (string) $order['order_no'],
                'out_refund_no' => 'RF' . (string) $order['order_no'],
                'reason' => mb_substr($reason, 0, 80),
                'amount' => [
                    'refund' => $amountCents,
                    'total' => (int) $order['amount_cents'],
                    'currency' => 'CNY',
                ],
            ]);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $json = $body === null ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $authorization = WechatSigner::authorization(
            $method,
            $path,
            $json,
            (string) $this->config['mch_id'],
            (string) $this->config['serial_no'],
            (string) $this->config['private_key'],
        );
        $headers = [
            'Authorization' => $authorization,
            'Accept' => 'application/json',
            'User-Agent' => 'PayFlow/1.0 (+php)',
        ];
        if ($json !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $response = HttpClient::request($method, self::API_BASE . $path, $json, $headers);
        $decoded = json_decode($response['body'], true);
        if ($response['status'] >= 400) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['code'] ?? '') : mb_substr($response['body'], 0, 200);

            throw new RuntimeException("微信接口失败 [{$response['status']}]: {$message}");
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('微信返回非 JSON：' . mb_substr($response['body'], 0, 200));
        }

        return $decoded;
    }

    private function notifyUrl(): string
    {
        $configured = (string) ($this->config['notify_url'] ?? '');

        return $configured !== '' ? $configured : rtrim($this->baseUrl, '/') . '/notify/wechat';
    }
}
