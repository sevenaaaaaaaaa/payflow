<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CommissionRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\PayoutRepository;
use PayFlow\Domain\ReferralRepository;
use PayFlow\Support\Arr;
use PayFlow\Support\Signature;
use RuntimeException;

/**
 * 推荐裂变：推荐码 → 点击 → 归因 → 订单归属 → 佣金；以及提现申请/审核。
 */
final class ReferralService
{
    public function __construct(
        private readonly array $config,
        private readonly ReferralRepository $referrals,
        private readonly CommissionRepository $commissions,
        private readonly PayoutRepository $payouts,
        private readonly CommissionService $commissionService,
        private readonly Notifier $notifier,
        private readonly EventRepository $events,
        private readonly WebhookDispatcher $webhooks,
    ) {
    }

    public function findOrCreate(string $email, string $name = '', string $customerId = ''): array
    {
        return $this->referrals->findOrCreate($email, $name, $customerId);
    }

    public function resolve(?string $code): ?array
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return $this->referrals->findByCode($code);
    }

    public function trackClick(string $code): ?array
    {
        $referral = $this->referrals->findByCode($code);
        if ($referral === null || ($referral['active'] ?? true) !== true) {
            return null;
        }
        $referral = $this->referrals->increment((string) $referral['id'], 'clicks', 1) ?? $referral;
        $this->events->log('referral.click', ['referral_id' => $referral['id'], 'code' => $referral['code']]);
        $this->webhooks->dispatch('referral.click', ['referral_id' => $referral['id'], 'code' => $referral['code']]);

        return $referral;
    }

    /**
     * 结账归因：把推荐人写到订单上。
     *
     * @return array{referral_code:?string,referral_id:?string}
     */
    public function attribution(?string $code): array
    {
        $referral = $this->resolve($code);
        if ($referral === null) {
            return ['referral_code' => null, 'referral_id' => null];
        }

        return ['referral_code' => $referral['code'], 'referral_id' => $referral['id']];
    }

    /**
     * 订单支付成功 → 记佣（排除自荐）。
     */
    public function onPaid(array $order): void
    {
        $referralId = (string) ($order['referral_id'] ?? '');
        $referral = $referralId !== '' ? $this->referrals->find($referralId) : $this->resolve($order['referral_code'] ?? null);
        if ($referral === null) {
            return;
        }
        if (strtolower((string) $referral['email']) === strtolower((string) ($order['email'] ?? ''))) {
            return; // 自荐不计佣
        }
        $this->commissionService->earn($order, $referral);
    }

    public function onRefund(array $order): void
    {
        $this->commissionService->reverseForOrder((string) $order['id']);
    }

    /**
     * 推荐人概览：点击/成交 + 佣金汇总 + 可提现。
     *
     * @return array<string,mixed>
     */
    public function dashboard(array $referral): array
    {
        $summary = $this->commissionService->summary((string) $referral['id']);
        $reserved = $this->payouts->reservedCents((string) $referral['id']);
        $summary['withdrawable'] = max(0, $summary['available'] - $reserved);
        $summary['reserved'] = $reserved;

        return [
            'referral' => $referral,
            'summary' => $summary,
            'commissions' => $this->commissions->forReferral((string) $referral['id']),
            'payouts' => $this->payouts->forReferral((string) $referral['id']),
        ];
    }

    public function withdrawable(string $referralId): int
    {
        $summary = $this->commissionService->summary($referralId);

        return max(0, $summary['available'] - $this->payouts->reservedCents($referralId));
    }

    public function requestPayout(array $referral, int $amountCents, string $method, string $account, string $note = ''): array
    {
        $min = max(0, (int) Arr::get($this->config, 'payout.min_amount_cents', 0));
        $methods = (array) Arr::get($this->config, 'payout.methods', ['manual']);
        if ($amountCents <= 0) {
            throw new RuntimeException('提现金额无效');
        }
        if ($amountCents < $min) {
            throw new RuntimeException('最低提现金额为 ' . \PayFlow\Support\Money::yuan($min));
        }
        if (!in_array($method, $methods, true)) {
            throw new RuntimeException('提现方式不支持');
        }
        if ($account === '') {
            throw new RuntimeException('请填写收款账号');
        }
        if ($amountCents > $this->withdrawable((string) $referral['id'])) {
            throw new RuntimeException('可提现余额不足');
        }

        $payout = $this->payouts->insert([
            'referral_id' => $referral['id'],
            'referral_code' => $referral['code'],
            'customer_id' => $referral['customer_id'] ?? null,
            'email' => $referral['email'],
            'amount_cents' => $amountCents,
            'method' => $method,
            'account' => $account,
            'note' => $note,
            'status' => 'requested',
            'requested_at' => date('c'),
        ]);
        $this->events->log('payout.requested', ['payout_id' => $payout['id'], 'referral_id' => $referral['id'], 'amount_cents' => $amountCents]);
        $this->webhooks->dispatch('payout.requested', $payout);

        return $payout;
    }

    public function approvePayout(string $payoutId, string $by = 'admin'): ?array
    {
        $payout = $this->payouts->find($payoutId);
        if ($payout === null || ($payout['status'] ?? '') !== 'requested') {
            return null;
        }
        $payout = $this->payouts->update($payoutId, ['status' => 'approved', 'reviewed_at' => date('c'), 'reviewed_by' => $by]);
        $this->notifier->payoutUpdated($payout, '已批准，待打款');
        $this->events->log('payout.approved', ['payout_id' => $payoutId]);

        return $payout;
    }

    public function rejectPayout(string $payoutId, string $reason = '', string $by = 'admin'): ?array
    {
        $payout = $this->payouts->find($payoutId);
        if ($payout === null || !in_array($payout['status'] ?? '', ['requested', 'approved'], true)) {
            return null;
        }
        $payout = $this->payouts->update($payoutId, ['status' => 'rejected', 'reviewed_at' => date('c'), 'reviewed_by' => $by, 'reject_reason' => $reason]);
        $this->notifier->payoutUpdated($payout, '已驳回：' . $reason);
        $this->events->log('payout.rejected', ['payout_id' => $payoutId, 'reason' => $reason]);

        return $payout;
    }

    public function payPayout(string $payoutId, string $transferNo = '', string $by = 'admin'): ?array
    {
        $payout = $this->payouts->find($payoutId);
        if ($payout === null || ($payout['status'] ?? '') !== 'approved') {
            return null;
        }
        $claimed = $this->commissionService->claim((string) $payout['referral_id'], (int) $payout['amount_cents'], $payoutId);
        $payout = $this->payouts->update($payoutId, [
            'status' => 'paid',
            'paid_at' => date('c'),
            'reviewed_by' => $by,
            'transfer_no' => $transferNo,
            'claimed_cents' => $claimed,
        ]);
        $this->notifier->payoutUpdated($payout, '已打款');
        $this->events->log('payout.paid', ['payout_id' => $payoutId, 'amount_cents' => $payout['amount_cents'], 'transfer_no' => $transferNo]);
        $this->webhooks->dispatch('payout.paid', $payout);

        return $payout;
    }

    /**
     * 推荐人自助页的签名令牌（90 天有效）。
     */
    public function partnerToken(array $referral): string
    {
        return Signature::encode(['rid' => $referral['id'], 'exp' => time() + 90 * 86400]);
    }

    public function resolvePartnerToken(string $token): ?array
    {
        $data = Signature::decode($token);
        if ($data === null || empty($data['rid'])) {
            return null;
        }

        return $this->referrals->find((string) $data['rid']);
    }

    public function link(array $referral): string
    {
        $base = rtrim((string) Arr::get($this->config, 'app.base_url', ''), '/');

        return $base . '/r/' . rawurlencode((string) $referral['code']);
    }
}
