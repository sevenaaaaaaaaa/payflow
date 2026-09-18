<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\OutboxRepository;
use PayFlow\Domain\WebhookDeliveryRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\HttpClient;

/**
 * 出站事件：统一信封 + Outbox 留痕 + 多目标投递 + HMAC + 退避重试。
 *
 * targets 解析优先级：
 *   1) webhooks.endpoints（多目标）：[{ name, url, secret, enabled, events:['*'] }]
 *   2) 兼容旧配置：webhooks.order = { enabled, url, secret }（视为单一目标）
 */
final class WebhookDispatcher
{
    public function __construct(
        private readonly array $config,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly OutboxRepository $outbox,
    ) {
    }

    private function enabled(): bool
    {
        return $this->targets() !== [];
    }

    /**
     * @return list<array{name:string,url:string,secret:string,events:list<string>}>
     */
    public function targets(): array
    {
        $out = [];
        foreach ((array) Arr::get($this->config, 'endpoints', []) as $i => $e) {
            if (!is_array($e) || empty($e['enabled']) || empty($e['url'])) {
                continue;
            }
            $out[] = [
                'name' => (string) ($e['name'] ?? ('endpoint' . ($i + 1))),
                'url' => (string) $e['url'],
                'secret' => (string) ($e['secret'] ?? ''),
                'events' => array_map('strval', (array) ($e['events'] ?? ['*'])),
            ];
        }
        if ($out !== []) {
            return $out;
        }

        // 兼容旧配置
        $legacyEnabled = (bool) (Arr::get($this->config, 'order.enabled', false) || Arr::get($this->config, 'enabled', false));
        $legacyUrl = (string) (Arr::get($this->config, 'order.url', '') ?: Arr::get($this->config, 'url', ''));
        if ($legacyEnabled && $legacyUrl !== '') {
            $out[] = [
                'name' => 'default',
                'url' => $legacyUrl,
                'secret' => (string) (Arr::get($this->config, 'order.secret', '') ?: Arr::get($this->config, 'secret', '')),
                'events' => ['*'],
            ];
        }

        return $out;
    }

    private function envelope(string $type, array $payload): array
    {
        $now = date('c');
        $isTest = ($payload['test'] ?? false) === true;
        $discriminator = (string) (
            $payload['order_no']
            ?? $payload['id']
            ?? $payload['subscription_id']
            ?? $payload['referral_id']
            ?? $payload['payout_id']
            ?? bin2hex(random_bytes(6))
        );

        return [
            'id' => 'evt_' . bin2hex(random_bytes(10)),
            'type' => $type,
            'version' => 1,
            'occurred_at' => $now,
            'source' => 'payflow',
            'subject' => [
                'email' => strtolower((string) ($payload['email'] ?? '')) ?: null,
                'external_id' => $payload['external_id'] ?? null,
                'tenant' => $payload['tenant'] ?? null,
            ],
            'data' => $payload,
            'mode' => $isTest ? 'test' : 'live',
            'idempotency_key' => $type . ':' . $discriminator . ':1',
            'event' => $type,
            'sent_at' => $now,
        ];
    }

    public function dispatch(string $event, array $payload): void
    {
        $envelope = $this->envelope($event, $payload);

        // Outbox：无论是否启用投递都留痕，供增量拉取
        $this->outbox->insert([
            'type' => $envelope['type'],
            'version' => $envelope['version'],
            'subject' => $envelope['subject'],
            'data' => $payload,
            'idempotency_key' => $envelope['idempotency_key'],
            'event_id' => $envelope['id'],
        ]);

        foreach ($this->targets() as $target) {
            if (!$this->matches($target['events'], $event)) {
                continue;
            }
            $delivery = $this->deliveries->insert([
                'event' => $event,
                'endpoint' => $target['name'],
                'url' => $target['url'],
                'secret' => $target['secret'],
                'payload' => $payload,
                'envelope' => $envelope,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => date('c'),
            ]);
            $this->attempt($delivery);
        }
    }

    /**
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

    private function matches(array $events, string $event): bool
    {
        return in_array('*', $events, true) || in_array($event, $events, true);
    }

    private function attempt(array $delivery): array
    {
        $envelope = is_array($delivery['envelope'] ?? null)
            ? $delivery['envelope']
            : $this->envelope((string) $delivery['event'], (array) ($delivery['payload'] ?? []));
        $body = (string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $secret = (string) ($delivery['secret'] ?? Arr::get($this->config, 'order.secret', ''));
        $signature = hash_hmac('sha256', $body, $secret);
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
