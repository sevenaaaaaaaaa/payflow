<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\OutboxRepository;
use PayFlow\Domain\WebhookDeliveryRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\HttpClient;

/**
 * 出站事件：统一信封 + Outbox 留痕 + HMAC 投递 + 退避重试。
 *
 * 信封（兼容旧字段 event/sent_at）：
 *   { id, type, version, occurred_at, source, subject{email,external_id,tenant},
 *     data, idempotency_key, event, sent_at }
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly array $config,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly OutboxRepository $outbox,
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

    private function envelope(string $type, array $payload): array
    {
        $now = date('c');
        $subject = [
            'email' => strtolower((string) ($payload['email'] ?? '')) ?: null,
            'external_id' => $payload['external_id'] ?? null,
            'tenant' => $payload['tenant'] ?? null,
        ];
        $discriminator = (string) (
            $payload['order_no']
            ?? $payload['id']
            ?? $payload['subscription_id']
            ?? $payload['referral_id']
            ?? $payload['payout_id']
            ?? bin2hex(random_bytes(6))
        );

        $isTest = ($payload['test'] ?? false) === true;

        return [
            'id' => 'evt_' . bin2hex(random_bytes(10)),
            'type' => $type,
            'version' => 1,
            'occurred_at' => $now,
            'source' => 'payflow',
            'subject' => $subject,
            'data' => $payload,
            'mode' => $isTest ? 'test' : 'live',
            'idempotency_key' => $type . ':' . $discriminator . ':1',
            // 兼容旧字段
            'event' => $type,
            'sent_at' => $now,
        ];
    }

    public function dispatch(string $event, array $payload): void
    {
        $envelope = $this->envelope($event, $payload);

        // Outbox：无论 webhook 是否启用都留痕，供增量拉取
        $this->outbox->insert([
            'type' => $envelope['type'],
            'version' => $envelope['version'],
            'subject' => $envelope['subject'],
            'data' => $payload,
            'idempotency_key' => $envelope['idempotency_key'],
            'event_id' => $envelope['id'],
        ]);

        if (!$this->enabled()) {
            return;
        }

        $delivery = $this->deliveries->insert([
            'event' => $event,
            'url' => $this->url(),
            'payload' => $payload,
            'envelope' => $envelope,
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
        $envelope = is_array($delivery['envelope'] ?? null)
            ? $delivery['envelope']
            : $this->envelope((string) $delivery['event'], (array) ($delivery['payload'] ?? []));
        $body = (string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $this->secret());
        $attempts = ((int) ($delivery['attempts'] ?? 0)) + 1;
        $maxAttempts = max(1, (int) Arr::get($this->config, 'max_attempts', 5));

        try {
            $response = HttpClient::request('POST', (string) $delivery['url'], $body, [
                'Content-Type' => 'application/json',
                'X-PayFlow-Event' => (string) ($envelope['type'] ?? $delivery['event']),
                'X-PayFlow-Event-Id' => (string) ($envelope['id'] ?? ''),
                'X-PayFlow-Idempotency-Key' => (string) ($envelope['idempotency_key'] ?? ''),
                'X-PayFlow-Mode' => (string) ($envelope['mode'] ?? 'live'),
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
