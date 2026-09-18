<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\EntitlementRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\InboundEventRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Support\Arr;

/**
 * 入站事件处理（其它矩阵产品 → PayFlow），HMAC 鉴权在 API 层完成，此处负责幂等与领域动作。
 */
final class InboundEventService
{
    public function __construct(
        private readonly InboundEventRepository $inbound,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly EntitlementRepository $entitlements,
        private readonly EventRepository $events,
    ) {
    }

    /**
     * @return array{ok:bool,duplicate:bool,applied:string}
     */
    public function handle(array $envelope): array
    {
        $type = (string) ($envelope['type'] ?? $envelope['event'] ?? '');
        $key = (string) ($envelope['idempotency_key'] ?? '');
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $subject = is_array($envelope['subject'] ?? null) ? $envelope['subject'] : [];
        $email = strtolower((string) ($subject['email'] ?? $data['email'] ?? ''));

        if ($key !== '' && $this->inbound->findByIdempotencyKey($key) !== null) {
            return ['ok' => true, 'duplicate' => true, 'applied' => 'duplicate'];
        }

        $record = $this->inbound->insert([
            'type' => $type,
            'version' => (int) ($envelope['version'] ?? 1),
            'source' => (string) ($envelope['source'] ?? ''),
            'subject' => $subject,
            'data' => $data,
            'idempotency_key' => $key,
        ]);

        $applied = 'stored';
        switch ($type) {
            case 'entitlement.revoke':
                $applied = $this->revokeEntitlement($data, $email);
                break;
            case 'customer.update':
                $applied = $this->updateCustomer($data, $email);
                break;
            default:
                $applied = 'noop';
        }

        $this->inbound->update((string) $record['id'], ['applied' => $applied]);
        $this->events->log('inbound.' . ($type !== '' ? $type : 'unknown'), ['inbound_id' => $record['id'], 'applied' => $applied]);

        return ['ok' => true, 'duplicate' => false, 'applied' => $applied];
    }

    private function revokeEntitlement(array $data, string $email): string
    {
        $orderNo = (string) ($data['order_no'] ?? '');
        if ($orderNo !== '') {
            $order = $this->orders->findByOrderNo($orderNo);
            if ($order !== null) {
                $ent = $this->entitlements->forOrder((string) $order['id']);
                if ($ent !== null && ($ent['status'] ?? '') === 'active') {
                    $this->entitlements->update((string) $ent['id'], ['status' => 'revoked', 'revoked_at' => date('c'), 'revoke_reason' => 'inbound']);

                    return 'revoked';
                }
            }

            return 'not_found';
        }
        if ($email === '') {
            return 'invalid';
        }
        $count = 0;
        foreach ($this->entitlements->query(['email' => $email]) as $ent) {
            if (($ent['status'] ?? 'active') === 'active') {
                $this->entitlements->update((string) $ent['id'], ['status' => 'revoked', 'revoked_at' => date('c'), 'revoke_reason' => 'inbound']);
                $count++;
            }
        }

        return $count > 0 ? "revoked:{$count}" : 'not_found';
    }

    private function updateCustomer(array $data, string $email): string
    {
        if ($email === '') {
            return 'invalid';
        }
        $customer = $this->customers->findByEmail($email);
        if ($customer === null) {
            return 'not_found';
        }
        $patch = [];
        $tags = Arr::get($data, 'tags');
        if (is_array($tags)) {
            $patch['tags'] = array_values(array_unique(array_merge((array) ($customer['tags'] ?? []), array_map('strval', $tags))));
        }
        if (isset($data['external_id'])) {
            $patch['external_id'] = (string) $data['external_id'];
        }
        if (isset($data['tenant'])) {
            $patch['tenant'] = (string) $data['tenant'];
        }
        if ($patch === []) {
            return 'unchanged';
        }
        $this->customers->update((string) $customer['id'], $patch);

        return 'updated';
    }
}
