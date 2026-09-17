<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\WebhookDeliveryRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\HttpClient;

/**
 * 出站 Webhook：HMAC 签名投递 + 失败退避重试（cron 驱动）+ 投递留痕。
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly array $config,
        private readonly WebhookDeliveryRepository $deliveries,
    ) {
    }

    private function url(): string
    {
        return (string) (Arr::get($this->config, 'order.url', '') ?: Arr::get($this->config, 'url', ''));
    }

    private function secret(): string
    {
        return (string) (Arr::get($this->config, 'order.secret', '') ?: Arr::get($this->config, 'secret', ''));
    }

    private function enabled(): bool
    {
        return (bool) (Arr::get($this->config, 'order.enabled', false) || Arr::get($this->config, 'enabled', false)) && $this->url() !== '';
    }

    public function dispatch(string $event, array $payload): void
    {
        if (!$this->enabled()) {
            return;
        }
        $delivery = $this->deliveries->insert([
            'event' => $event,
            'url' => $this->url(),
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'next_attempt_at' => date('c'),
        ]);
        $this->attempt($delivery);
    }

    /**
     * cron：重试到期的投递。
     *
     * @return array{retried:int, succeeded:int}
     */
    public function retryDue(): array
    {
        $retried = 0;
        $succeeded = 0;
        foreach ($this->deliveries->dueForRetry() as $delivery) {
            $retried++;
            if (($this->attempt($delivery)['status'] ?? '') === 'success') {
                $succeeded++;
            }
        }

        return ['retried' => $retried, 'succeeded' => $succeeded];
    }

    public function resend(string $deliveryId): void
    {
        $delivery = $this->deliveries->find($deliveryId);
        if ($delivery === null) {
            return;
        }
        $this->deliveries->update($deliveryId, ['status' => 'pending', 'next_attempt_at' => date('c')]);
        $this->attempt($this->deliveries->find($deliveryId) ?? $delivery);
    }

    private function attempt(array $delivery): array
    {
        $body = (string) json_encode([
            'event' => $delivery['event'],
            'data' => $delivery['payload'],
            'sent_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $this->secret());
        $attempts = ((int) ($delivery['attempts'] ?? 0)) + 1;
        $maxAttempts = max(1, (int) Arr::get($this->config, 'max_attempts', 5));

        try {
            $response = HttpClient::request('POST', (string) $delivery['url'], $body, [
                'Content-Type' => 'application/json',
                'X-PayFlow-Event' => (string) $delivery['event'],
                'X-PayFlow-Signature' => $signature,
            ], 10);
            $ok = $response['status'] >= 200 && $response['status'] < 300;
            if ($ok) {
                return $this->deliveries->update((string) $delivery['id'], [
                    'status' => 'success',
                    'attempts' => $attempts,
                    'last_status' => $response['status'],
                    'updated_at' => date('c'),
                ]) ?? $delivery;
            }
            $error = 'HTTP ' . $response['status'];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
        $backoff = min(3600, 30 * (2 ** ($attempts - 1)));

        return $this->deliveries->update((string) $delivery['id'], [
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => mb_substr((string) $error, 0, 300),
            'next_attempt_at' => date('c', time() + $backoff),
        ]) ?? $delivery;
    }
}
