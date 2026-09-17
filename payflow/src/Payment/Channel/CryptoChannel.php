<?php

declare(strict_types=1);

namespace PayFlow\Payment\Channel;

use PayFlow\Http\Request;
use PayFlow\Payment\ChannelInterface;
use PayFlow\Payment\NotifyResult;
use PayFlow\Payment\PaymentIntent;
use PayFlow\Support\Signature;
use RuntimeException;

/**
 * 加密货币支付：静态收款地址 + 精确金额二维码。
 *
 * 由于不内嵌全节点，这里生成「地址 + 金额」的支付意图；确认由链上监听服务
 * （或人工）调用签名回调 /notify/crypto 完成：
 *   body: {order_no, txid, currency, amount, sign}
 *   sign = hex(HMAC_SHA256(secret, order_no|txid|currency|amount))
 * 其中 secret = channels.crypto.notify_secret（留空则用全站 secret）。
 */
final class CryptoChannel implements ChannelInterface
{
    public function __construct(private readonly array $config = [], private readonly string $baseUrl = '')
    {
    }

    public function id(): string
    {
        return 'crypto';
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? '加密货币');
    }

    public function isEnabled(): bool
    {
        if (!(bool) ($this->config['enabled'] ?? false)) {
            return false;
        }

        return $this->currencyConfig($this->defaultCurrency()) !== null;
    }

    public function createPayment(array $order): PaymentIntent
    {
        $currency = (string) ($order['crypto_currency'] ?? $this->defaultCurrency());
        $cfg = $this->currencyConfig($currency);
        if ($cfg === null) {
            throw new RuntimeException('加密通道未配置收款地址');
        }

        $fiat = ((int) $order['amount_cents']) / 100;
        $amount = round($fiat / max(0.0000001, (float) $cfg['rate']), (int) $cfg['decimals']);
        $uri = $this->paymentUri((string) $cfg['scheme'], (string) $cfg['address'], $amount);

        return new PaymentIntent(
            'qrcode',
            $uri,
            null,
            sprintf('请向下方地址转入 %s %s（约 %s），到账后自动交付。', $amount, $currency, \PayFlow\Support\Money::yuan((int) $order['amount_cents'])),
            (string) $order['order_no'],
            [
                'currency' => $currency,
                'address' => $cfg['address'],
                'amount_crypto' => $amount,
                'uri' => $uri,
                'confirmations' => $cfg['confirmations'],
            ],
        );
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        $body = $request->json();
        if ($body === [] && $request->rawBody() !== '') {
            $decoded = json_decode($request->rawBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $orderNo = (string) ($body['order_no'] ?? '');
        $txid = (string) ($body['txid'] ?? '');
        $currency = (string) ($body['currency'] ?? '');
        $amount = (string) ($body['amount'] ?? '');
        $sign = (string) ($body['sign'] ?? '');
        if ($orderNo === '' || $txid === '' || $sign === '') {
            return null;
        }

        $secret = (string) ($this->config['notify_secret'] ?? '');
        if ($secret === '') {
            $secret = Signature::secret();
        }
        $expected = hash_hmac('sha256', implode('|', [$orderNo, $txid, $currency, $amount]), $secret);
        if (!hash_equals($expected, $sign)) {
            throw new RuntimeException('加密回调签名校验失败');
        }

        return new NotifyResult(true, $orderNo, $txid, 0, $body);
    }

    public function query(string $orderNo): ?NotifyResult
    {
        return null;
    }

    public function refund(array $order, int $amountCents, string $reason = ''): bool
    {
        return false; // 链上退款需人工处理
    }

    private function defaultCurrency(): string
    {
        return (string) ($this->config['default_currency'] ?? 'USDT_TRC20');
    }

    /**
     * @return array{address:string,rate:float,confirmations:int,scheme:string,decimals:int}|null
     */
    private function currencyConfig(string $currency): ?array
    {
        $currencies = (array) ($this->config['currencies'] ?? []);
        $cfg = $currencies[$currency] ?? null;
        if (!is_array($cfg) || (string) ($cfg['address'] ?? '') === '') {
            return null;
        }

        return [
            'address' => (string) $cfg['address'],
            'rate' => (float) ($cfg['rate'] ?? 1),
            'confirmations' => (int) ($cfg['confirmations'] ?? 1),
            'scheme' => (string) ($cfg['scheme'] ?? ''),
            'decimals' => (int) ($cfg['decimals'] ?? 2),
        ];
    }

    private function paymentUri(string $scheme, string $address, float $amount): string
    {
        $amount = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');

        return match ($scheme) {
            'bitcoin' => 'bitcoin:' . $address . '?amount=' . $amount,
            'ethereum' => 'ethereum:' . $address . '?value=' . $amount,
            'tron' => 'tron:' . $address . '?amount=' . $amount,
            default => $address,
        };
    }
}
