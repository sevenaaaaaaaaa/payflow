<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CommissionRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\ReferralRepository;
use PayFlow\Support\Arr;

/**
 * 佣金：订单支付后按比例记佣（冻结 hold_days 天）→ 解冻可提现 → 打款。
 */
final class CommissionService
{
    public function __construct(
        private readonly array $config,
        private readonly CommissionRepository $commissions,
        private readonly ReferralRepository $referrals,
        private readonly Notifier $notifier,
        private readonly WebhookDispatcher $webhooks,
        private readonly EventRepository $events,
    ) {
    }

    public function rateFor(array $referral): float
    {
        if (isset($referral['commission_rate']) && $referral['commission_rate'] !== null && $referral['commission_rate'] !== '') {
            return (float) $referral['commission_rate'];
        }
        $levels = (array) Arr::get($this->config, 'referral.levels', []);
        $level = (string) ($referral['level'] ?? 'standard');

        return (float) ($levels[$level] ?? Arr::get($this->config, 'referral.default_commission_rate', 0.2));
    }

    /**
     * 订单支付成功 → 记佣金（pending）。
     */
    public function earn(array $order, array $referral): ?array
    {
        if ($this->commissions->forOrder((string) $order['id']) !== null) {
            return null;
        }
        $base = (int) ($order['amount_cents'] ?? 0);
        if ($base <= 0) {
            return null;
        }
        $rate = $this->rateFor($referral);
        $amount = (int) round($base * $rate);
        if ($amount <= 0) {
            return null;
        }
        $holdDays = max(0, (int) Arr::get($this->config, 'referral.hold_days', 7));

        $commission = $this->commissions->insert([
            'referral_id' => $referral['id'],
            'referral_code' => $referral['code'],
            'referrer_email' => $referral['email'],
            'order_id' => $order['id'],
            'order_no' => $order['order_no'] ?? null,
            'customer_id' => $order['customer_id'] ?? null,
            'email' => $order['email'] ?? null,
            'base_amount_cents' => $base,
            'rate' => $rate,
            'amount_cents' => $amount,
            'hold_days' => $holdDays,
            'available_at' => date('c', time() + $holdDays * 86400),
            'status' => 'pending',
        ]);

        $this->referrals->increment((string) $referral['id'], 'orders', 1);
        $this->referrals->increment((string) $referral['id'], 'total_commission_cents', $amount);
        $this->notifier->commissionEarned($commission + ['hold_days' => $holdDays], $referral);
        $this->events->log('commission.pending', ['commission_id' => $commission['id'], 'referral_id' => $referral['id'], 'amount_cents' => $amount]);
        $this->webhooks->dispatch('commission.pending', $commission);

        return $commission;
    }

    /**
     * 解冻到期的佣金（cron）。
     *
     * @return list<string>
     */
    public function mature(): array
    {
        $done = [];
        foreach ($this->commissions->dueForMaturity() as $commission) {
            $this->commissions->update((string) $commission['id'], ['status' => 'available', 'matured_at' => date('c')]);
            $this->events->log('commission.available', ['commission_id' => $commission['id'], 'amount_cents' => $commission['amount_cents']]);
            $this->webhooks->dispatch('commission.available', $commission);
            $done[] = (string) $commission['id'];
        }

        return $done;
    }

    /**
     * 退款 → 冲正佣金。
     */
    public function reverseForOrder(string $orderId): void
    {
        $commission = $this->commissions->forOrder($orderId);
        if ($commission === null || in_array($commission['status'] ?? '', ['reversed', 'paid'], true)) {
            $commission = $commission ?? [];
        }
        if ($commission === []) {
            return;
        }
        if (($commission['status'] ?? '') === 'paid') {
            return; // 已打款不自动冲正，人工处理
        }
        $this->commissions->update((string) $commission['id'], ['status' => 'reversed', 'reversed_at' => date('c')]);
        $this->referrals->increment((string) $commission['referral_id'], 'total_commission_cents', -(int) $commission['amount_cents']);
        $this->events->log('commission.reversed', ['commission_id' => $commission['id'], 'amount_cents' => $commission['amount_cents']]);
        $this->webhooks->dispatch('commission.reversed', $commission);
    }

    /**
     * @return array{pending:int,available:int,paid:int,reversed:int,total:int}
     */
    public function summary(string $referralId): array
    {
        $pending = $this->commissions->sumByStatus($referralId, 'pending');
        $available = $this->commissions->sumByStatus($referralId, 'available');
        $paid = $this->commissions->sumByStatus($referralId, 'paid');
        $reversed = $this->commissions->sumByStatus($referralId, 'reversed');

        return [
            'pending' => $pending,
            'available' => $available,
            'paid' => $paid,
            'reversed' => $reversed,
            'total' => $pending + $available + $paid,
        ];
    }

    /**
     * 按最早的可用佣金依次核销为已打款。
     */
    public function claim(string $referralId, int $amountCents, string $payoutId): int
    {
        $remaining = $amountCents;
        $claimed = 0;
        $list = array_values(array_filter($this->commissions->forReferral($referralId), static fn (array $c): bool => ($c['status'] ?? '') === 'available'));
        usort($list, static fn (array $a, array $b): int => strcmp((string) $a['created_at'], (string) $b['created_at']));

        foreach ($list as $commission) {
            if ($remaining <= 0) {
                break;
            }
            $amount = (int) $commission['amount_cents'];
            if ($amount > $remaining) {
                continue;
            }
            $this->commissions->update((string) $commission['id'], ['status' => 'paid', 'paid_at' => date('c'), 'payout_id' => $payoutId]);
            $remaining -= $amount;
            $claimed += $amount;
        }

        return $claimed;
    }
}
