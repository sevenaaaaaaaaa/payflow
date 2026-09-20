<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\EntitlementRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\InboundEventRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\Subject;

/**
 * 入站事件处理（其它矩阵产品 → PayFlow），HMAC 鉴权在 API 层完成，此处负责幂等与领域动作。
 */
final class InboundEventService
{
    /** @var array<string,string> */
    private const ALIASES = [
        'learnflow.enrollment.cancelled' => 'entitlement.revoke',
        'learnflow.enrollment.removed' => 'entitlement.revoke',
        'learnflow.enroll_remove' => 'entitlement.revoke',
        'customer.upsert' => 'customer.update',
    ];

    public function __construct(
        private readonly InboundEventRepository $inbound,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly EntitlementRepository $entitlements,
        private readonly EventRepository $events,
    ) {
    }

    /**
     * @return array{ok:bool,duplicate:bool,applied:string,type:string}
     */
    public function handle(array $envelope): array
    {
        $rawType = (string) ($envelope['type'] ?? $envelope['event'] ?? '');
        $type = self::ALIASES[$rawType] ?? $rawType;
        $key = (string) ($envelope['idempotency_key'] ?? '');
        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $subject = is_array($envelope['subject'] ?? null) ? $envelope['subject'] : [];
        $identity = Subject::from($envelope + ['data' => $data], []);
        if ($identity['email'] === '') {
            $identity['email'] = strtolower(trim((string) ($data['email'] ?? '')));
        }
        if ($identity['external_id'] === '') {
            $identity['external_id'] = trim((string) ($data['external_id'] ?? $subject['external_id'] ?? ''));
        }
        if ($identity['tenant'] === '') {
            $identity['tenant'] = trim((string) ($data['tenant'] ?? $subject['tenant'] ?? ''));
        }

        if ($key !== '' && $this->inbound->findByIdempotencyKey($key) !== null) {
            return ['ok' => true, 'duplicate' => true, 'applied' => 'duplicate', 'type' => $type];
        }

        $record = $this->inbound->insert([
            'type' => $type,
            'original_type' => $rawType,
            'version' => (int) ($envelope['version'] ?? 1),
            'source' => (string) ($envelope['source'] ?? ''),
            'subject' => $subject,
            'data' => $data,
            'idempotency_key' => $key,
        ]);

        $applied = match ($type) {
            'entitlement.revoke' => $this->revokeEntitlement($data, $identity),
            'customer.update' => $this->updateCustomer($data, $identity, $rawType === 'customer.upsert'),
            default => 'noop',
        };

        $this->inbound->update((string) $record['id'], ['applied' => $applied]);
        $this->events->log('inbound.' . ($type !== '' ? $type : 'unknown'), [
            'inbound_id' => $record['id'],
            'applied' => $applied,
            'original_type' => $rawType,
        ]);

        return ['ok' => true, 'duplicate' => false, 'applied' => $applied, 'type' => $type];
    }

    /**
     * @param array{email:string,external_id:string,tenant:string} $identity
     */
    private function revokeEntitlement(array $data, array $identity): string
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

        $email = $this->resolveEmail($identity);
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

    /**
     * @param array{email:string,external_id:string,tenant:string} $identity
     */
    private function updateCustomer(array $data, array $identity, bool $upsert): string
    {
        $email = $this->resolveEmail($identity);
        if ($email === '' && $identity['external_id'] === '') {
            return 'invalid';
        }

        $customer = $email !== ''
            ? $this->customers->findByEmail($email)
            : $this->customers->findByExternalId($identity['external_id']);
        $created = false;

        if ($customer === null) {
            if (!$upsert || $email === '') {
                return 'not_found';
            }
            $name = trim((string) ($data['name'] ?? ''));
            $customer = $this->customers->findOrCreate(
                $email,
                $name,
                $identity['external_id'],
                $identity['tenant'],
            );
            $created = true;
        }

        $patch = [];
        $tags = Arr::get($data, 'tags');
        if (is_array($tags)) {
            $patch['tags'] = array_values(array_unique(array_merge((array) ($customer['tags'] ?? []), array_map('strval', $tags))));
        }
        if ($identity['external_id'] !== '' && ($customer['external_id'] ?? '') !== $identity['external_id']) {
            $patch['external_id'] = $identity['external_id'];
        } elseif (isset($data['external_id'])) {
            $patch['external_id'] = (string) $data['external_id'];
        }
        if ($identity['tenant'] !== '' && ($customer['tenant'] ?? '') !== $identity['tenant']) {
            $patch['tenant'] = $identity['tenant'];
        } elseif (isset($data['tenant'])) {
            $patch['tenant'] = (string) $data['tenant'];
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '' && ($customer['name'] ?? '') !== $name) {
            $patch['name'] = $name;
        }
        if ($patch === []) {
            return $created ? 'created' : 'unchanged';
        }
        $this->customers->update((string) $customer['id'], $patch);

        return $created ? 'created' : 'updated';
    }

    /**
     * @param array{email:string,external_id:string,tenant:string} $identity
     */
    private function resolveEmail(array $identity): string
    {
        if ($identity['email'] !== '') {
            return $identity['email'];
        }
        if ($identity['external_id'] === '') {
            return '';
        }
        $customer = $this->customers->findByExternalId($identity['external_id']);

        return strtolower((string) ($customer['email'] ?? ''));
    }
}
