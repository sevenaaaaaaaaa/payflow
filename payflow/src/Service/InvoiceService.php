<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\EventRepository;
use PayFlow\Domain\InvoiceRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\Signature;

/**
 * 发票 / 收据：订单支付后按需生成，凭签名链接查看/打印。
 */
final class InvoiceService
{
    public function __construct(
        private readonly array $config,
        private readonly InvoiceRepository $invoices,
        private readonly EventRepository $events,
        private readonly string $baseUrl = '',
    ) {
    }

    public function enabled(): bool
    {
        return (bool) Arr::get($this->config, 'invoice.enabled', true);
    }

    public function issueForOrder(array $order): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $existing = $this->invoices->forOrder((string) $order['id']);
        if ($existing !== null) {
            return $existing;
        }
        $prefix = strtoupper((string) Arr::get($this->config, 'invoice.prefix', 'INV'));
        $date = date('Ymd');
        $seq = $this->invoices->nextSequence($prefix, $date);
        $number = sprintf('%s-%s-%04d', $prefix, $date, $seq);

        $invoice = $this->invoices->insert([
            'order_id' => $order['id'],
            'order_no' => $order['order_no'] ?? null,
            'number' => $number,
            'amount_cents' => (int) ($order['amount_cents'] ?? 0),
            'currency' => $order['currency'] ?? 'CNY',
            'email' => strtolower((string) ($order['email'] ?? '')),
            'name' => (string) ($order['name'] ?? ''),
            'seller_name' => (string) Arr::get($this->config, 'invoice.seller_name', 'PayFlow'),
            'seller_tax_id' => (string) Arr::get($this->config, 'invoice.seller_tax_id', ''),
            'seller_address' => (string) Arr::get($this->config, 'invoice.seller_address', ''),
            'items' => [['name' => (string) ($order['product_name'] ?? ''), 'amount_cents' => (int) ($order['amount_cents'] ?? 0)]],
            'issued_at' => date('c'),
        ]);
        $this->events->log('invoice.issued', ['invoice_id' => $invoice['id'], 'number' => $number, 'order_no' => $order['order_no'] ?? null]);

        return $invoice;
    }

    public function tokenFor(array $invoice): string
    {
        return Signature::encode(['inv' => $invoice['id'], 'exp' => time() + 365 * 86400]);
    }

    public function urlFor(array $invoice): string
    {
        return rtrim($this->baseUrl, '/') . '/invoice/' . $this->tokenFor($invoice);
    }

    public function resolveToken(string $token): ?array
    {
        $data = Signature::decode($token);
        if ($data === null || empty($data['inv'])) {
            return null;
        }

        return $this->invoices->find((string) $data['inv']);
    }
}
