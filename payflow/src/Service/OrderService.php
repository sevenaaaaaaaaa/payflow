<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Domain\OrderStateMachine;
use PayFlow\Domain\ProductRepository;
use PayFlow\Domain\SubscriptionRepository;
use PayFlow\Payment\ChannelManager;
use PayFlow\Support\Money;
use RuntimeException;

/**
 * 订单编排：结账 → 支付 → 交付 → 退款。
 *
 * 所有状态变更都经 OrderStateMachine 校验，并写入订单内嵌轨迹 + 全局事件日志。
 */
final class OrderService
{
    public function __construct(
        private readonly array $config,
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly EventRepository $events,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntitlementService $entitlements,
        private readonly ChannelManager $channels,
        private readonly Notifier $notifier,
        private readonly WebhookDispatcher $webhooks,
        private readonly CouponService $coupons,
        private readonly ReferralService $referrals,
        private readonly DeliveryService $delivery,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * 创建订单并唤起支付。
     *
     * @param array{coupon?:string,referral?:string} $options
     * @return array{order: array, intent: array}
     */
    public function startCheckout(string $productId, string $email, string $name, string $channelId, array $options = []): array
    {
        $product = $this->products->find($productId);
        if ($product === null || ($product['active'] ?? true) !== true) {
            throw new RuntimeException('商品不存在或已下架');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('请填写有效的邮箱地址');
        }
        if (!$this->channels->has($channelId) || !$this->channels->get($channelId)->isEnabled()) {
            throw new RuntimeException('支付通道不可用');
        }

        $subtotal = (int) $product['amount_cents'];
        $discount = 0;
        $coupon = null;
        $couponCode = trim((string) ($options['coupon'] ?? ''));
        if ($couponCode !== '') {
            $result = $this->coupons->validate($couponCode, $product, $subtotal, $email);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['reason'] ?? '优惠券不可用'));
            }
            $coupon = $result['coupon'];
            $discount = (int) $result['discount_cents'];
        }

        $customer = $this->customers->findOrCreate(
            $email,
            $name,
            (string) ($options['external_id'] ?? ''),
            (string) ($options['tenant'] ?? ''),
        );

        $referralAttr = ['referral_code' => null, 'referral_id' => null];
        $referralCode = trim((string) ($options['referral'] ?? ''));
        if ($referralCode !== '') {
            $referralAttr = $this->referrals->attribution($referralCode);
        }

        $order = $this->orders->create([
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'type' => $product['type'],
            'interval' => $product['interval'] ?? null,
            'interval_count' => $product['interval_count'] ?? 1,
            'trial_days' => $product['trial_days'] ?? 0,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'coupon_code' => $coupon['code'] ?? null,
            'coupon_id' => $coupon['id'] ?? null,
            'amount_cents' => max(0, $subtotal - $discount),
            'currency' => $product['currency'] ?? 'CNY',
            'customer_id' => $customer['id'],
            'email' => strtolower($email),
            'name' => $name,
            'entitlement' => $product['entitlement'] ?? null,
            'channel' => $channelId,
            'referral_code' => $referralAttr['referral_code'],
            'referral_id' => $referralAttr['referral_id'],
            'external_id' => $options['external_id'] ?? null,
            'tenant' => $options['tenant'] ?? null,
        ]);

        if ($coupon !== null) {
            $this->coupons->reserve($coupon, $order, $discount);
            $this->orders->appendEvent((string) $order['id'], ['type' => 'coupon.applied', 'code' => $coupon['code'], 'discount_cents' => $discount]);
        }
        if ($referralAttr['referral_id'] !== null) {
            $this->orders->appendEvent((string) $order['id'], ['type' => 'referral.attributed', 'code' => $referralAttr['referral_code']]);
        }

        $intent = $this->channels->get($channelId)->createPayment($order);
        $order = $this->orders->update((string) $order['id'], [
            'channel_trade_no' => $intent->channelTradeNo,
            'channel_payload' => $intent->raw,
            'payment_mode' => $intent->mode,
        ]) ?? $order;

        $this->orders->appendEvent((string) $order['id'], ['type' => 'checkout.created', 'channel' => $channelId]);
        $this->events->log('order.created', ['order_id' => $order['id'], 'order_no' => $order['order_no'], 'amount_cents' => $order['amount_cents'], 'channel' => $channelId]);

        return ['order' => $order, 'intent' => $intent->toArray()];
    }

    /**
     * 临时支付链接下单（任意金额）。
     *
     * @return array{order: array, intent: array}
     */
    public function startAdHocCheckout(array $link, string $email, string $name, string $channelId, array $options = []): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('请填写有效的邮箱地址');
        }
        $product = !empty($link['product_id']) ? $this->products->find((string) $link['product_id']) : null;
        $amount = (int) $link['amount_cents'];
        $title = (string) ($link['title'] ?? '支付链接');

        $discount = 0;
        $coupon = null;
        $couponCode = trim((string) ($options['coupon'] ?? ''));
        if ($couponCode !== '' && ($link['allow_coupon'] ?? false) === true) {
            $base = $product ?? ['id' => '_link', 'amount_cents' => $amount];
            $result = $this->coupons->validate($couponCode, $base, $amount, $email);
            if (($result['ok'] ?? false) !== true) {
                throw new RuntimeException((string) ($result['reason'] ?? '优惠券不可用'));
            }
            $coupon = $result['coupon'];
            $discount = (int) $result['discount_cents'];
        }

        if (!$this->channels->has($channelId) || !$this->channels->get($channelId)->isEnabled()) {
            throw new RuntimeException('支付通道不可用');
        }

        $customer = $this->customers->findOrCreate($email, $name);
        $referralAttr = ['referral_code' => null, 'referral_id' => null];
        if (trim((string) ($options['referral'] ?? '')) !== '') {
            $referralAttr = $this->referrals->attribution((string) $options['referral']);
        }

        $order = $this->orders->create([
            'product_id' => $product['id'] ?? null,
            'product_name' => $product['name'] ?? $title,
            'type' => 'link',
            'interval' => null,
            'subtotal_cents' => $amount,
            'discount_cents' => $discount,
            'coupon_code' => $coupon['code'] ?? null,
            'coupon_id' => $coupon['id'] ?? null,
            'amount_cents' => max(0, $amount - $discount),
            'currency' => (string) ($link['currency'] ?? 'CNY'),
            'customer_id' => $customer['id'],
            'email' => strtolower($email),
            'name' => $name,
            'entitlement' => $product['entitlement'] ?? ['kind' => 'content', 'items' => []],
            'channel' => $channelId,
            'payment_link_id' => $link['id'],
            'referral_code' => $referralAttr['referral_code'],
            'referral_id' => $referralAttr['referral_id'],
        ]);

        if ($coupon !== null) {
            $this->coupons->reserve($coupon, $order, $discount);
        }

        $intent = $this->channels->get($channelId)->createPayment($order);
        $order = $this->orders->update((string) $order['id'], [
            'channel_trade_no' => $intent->channelTradeNo,
            'channel_payload' => $intent->raw,
            'payment_mode' => $intent->mode,
        ]) ?? $order;

        $this->orders->appendEvent((string) $order['id'], ['type' => 'checkout.link', 'channel' => $channelId, 'link_id' => $link['id']]);
        $this->events->log('order.created', ['order_id' => $order['id'], 'order_no' => $order['order_no'], 'amount_cents' => $order['amount_cents'], 'channel' => $channelId, 'link' => true]);

        return ['order' => $order, 'intent' => $intent->toArray()];
    }

    /**
     * 兑换券核销：生成 0 元订单并即时交付。
     */
    public function redeemVoucher(array $voucher, string $email, string $name = ''): array
    {
        $product = $this->products->find((string) ($voucher['product_id'] ?? ''));
        if ($product === null || ($product['active'] ?? true) !== true) {
            throw new RuntimeException('兑换的商品不存在或已下架');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('请填写有效的邮箱地址');
        }

        $customer = $this->customers->findOrCreate($email, $name);
        $order = $this->orders->create([
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'type' => 'voucher',
            'subtotal_cents' => 0,
            'discount_cents' => 0,
            'amount_cents' => 0,
            'currency' => $product['currency'] ?? 'CNY',
            'customer_id' => $customer['id'],
            'email' => strtolower($email),
            'name' => $name,
            'entitlement' => $product['entitlement'] ?? null,
            'channel' => 'voucher',
            'voucher_code' => $voucher['code'] ?? null,
        ]);
        $this->orders->appendEvent((string) $order['id'], ['type' => 'voucher.redeemed', 'code' => $voucher['code'] ?? null]);
        $this->events->log('voucher.redeemed', ['order_id' => $order['id'], 'product_id' => $product['id'], 'code' => $voucher['code'] ?? null]);

        return $this->markPaid((string) $order['id'], 'VOUCHER-' . (string) ($voucher['code'] ?? ''), 0, ['voucher' => $voucher['code'] ?? null], true);
    }

    /**
     * 标记已支付（幂等）。来源：异步通知 / 主动查单 / 后台人工确认。
     */
    public function markPaid(string $orderId, string $tradeNo, int $amountCents = 0, array $raw = [], bool $autoDeliver = true): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在');
        }
        if (OrderStateMachine::isPaidLike((string) $order['status'])) {
            return $order; // 幂等：重复通知不重复发货
        }
        OrderStateMachine::assert((string) $order['status'], OrderStateMachine::PAID);

        if ($amountCents > 0 && $amountCents !== (int) $order['amount_cents']) {
            throw new RuntimeException(sprintf(
                '支付金额不一致：应付 %s，实付 %s',
                Money::yuan((int) $order['amount_cents']),
                Money::yuan($amountCents),
            ));
        }

        $order = $this->orders->update($orderId, [
            'status' => OrderStateMachine::PAID,
            'paid_at' => date('c'),
            'channel_trade_no' => $tradeNo !== '' ? $tradeNo : ($order['channel_trade_no'] ?? null),
            'channel_payload' => array_merge(is_array($order['channel_payload'] ?? null) ? $order['channel_payload'] : [], ['notify' => $raw]),
        ]) ?? $order;

        $isRenewal = false;
        $subscription = null;
        if (($order['type'] ?? '') === 'subscription') {
            $existing = $this->subscriptions->findByCustomerAndProduct((string) $order['customer_id'], (string) $order['product_id']);
            $isRenewal = $existing !== null && ($existing['renewal_order_id'] ?? '') === $order['id'];
            $subscription = $this->subscriptions->start($order);
            $order = $this->orders->update($orderId, ['subscription_id' => $subscription['id']]) ?? $order;
        }

        $this->orders->appendEvent($orderId, ['type' => 'order.paid', 'trade_no' => $tradeNo]);
        $this->events->log('order.paid', ['order_id' => $orderId, 'order_no' => $order['order_no'], 'amount_cents' => $order['amount_cents'], 'trade_no' => $tradeNo]);
        $this->webhooks->dispatch('order.paid', $this->publicOrder($order));
        $this->coupons->useForOrder($orderId);
        $this->referrals->onPaid($order);

        $order = $autoDeliver ? $this->deliver($orderId) : $order;

        if ($isRenewal && $subscription !== null) {
            $order['_delivery_url'] = $this->entitlements->deliveryUrl($order);
            $this->notifier->subscriptionRenewed($order, $subscription);
            $this->events->log('subscription.renewed', ['subscription_id' => $subscription['id'], 'order_no' => $order['order_no']]);
            $this->webhooks->dispatch('subscription.renewed', [
                'subscription_id' => $subscription['id'],
                'order_no' => $order['order_no'],
                'current_period_end' => $subscription['current_period_end'],
            ]);
        }

        return $order;
    }

    /**
     * 交付：发放权益 → 标记 delivered → 邮件通知。
     */
    public function deliver(string $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在');
        }
        if (($order['status'] ?? '') === OrderStateMachine::DELIVERED) {
            return $order;
        }
        OrderStateMachine::assert((string) $order['status'], OrderStateMachine::DELIVERED);

        $entitlement = $this->entitlements->grant($order);
        $license = $this->delivery->issueLicense($order);
        $invoice = $this->invoices->issueForOrder($order);
        $cards = $this->delivery->issueCards($order);
        $order = $this->orders->update($orderId, [
            'status' => OrderStateMachine::DELIVERED,
            'delivered_at' => date('c'),
            'entitlement_id' => $entitlement['id'],
            'license_id' => $license['id'] ?? null,
            'invoice_id' => $invoice['id'] ?? null,
            'cards' => $cards,
        ]) ?? $order;

        $order['_delivery_url'] = $this->entitlements->deliveryUrl($order);
        $items = $this->entitlements->itemsForOrder($order);

        $this->notifier->orderPaid($order);
        if ($items !== []) {
            $this->notifier->delivery($order, $items);
        }

        $this->orders->appendEvent($orderId, ['type' => 'order.delivered', 'entitlement_id' => $entitlement['id']]);
        $this->events->log('order.delivered', ['order_id' => $orderId, 'order_no' => $order['order_no'], 'kind' => $entitlement['kind']]);
        $this->webhooks->dispatch('order.delivered', $this->publicOrder($order));

        return $order;
    }

    /**
     * 退款：调用通道退款 → 标记 refunded → 撤销权益 → 通知。
     */
    public function refund(string $orderId, ?int $amountCents = null, string $reason = ''): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在');
        }
        OrderStateMachine::assert((string) $order['status'], OrderStateMachine::REFUNDED);
        $amountCents ??= (int) $order['amount_cents'];

        $channel = $this->channels->get((string) $order['channel']);
        $channel->refund($order, $amountCents, $reason);

        $order = $this->orders->update($orderId, [
            'status' => OrderStateMachine::REFUNDED,
            'refunded_at' => date('c'),
            'refund_amount_cents' => $amountCents,
            'refund_reason' => $reason,
        ]) ?? $order;

        $this->entitlements->revoke($order);
        if (!empty($order['subscription_id'])) {
            $this->subscriptions->cancel((string) $order['subscription_id'], 'refund');
        }
        $this->referrals->onRefund($order);

        $order['_delivery_url'] = $this->entitlements->deliveryUrl($order);
        $this->notifier->orderRefunded($order);

        $this->orders->appendEvent($orderId, ['type' => 'order.refunded', 'amount_cents' => $amountCents, 'reason' => $reason]);
        $this->events->log('order.refunded', ['order_id' => $orderId, 'order_no' => $order['order_no'], 'amount_cents' => $amountCents]);
        $this->webhooks->dispatch('order.refunded', $this->publicOrder($order));

        return $order;
    }

    public function cancel(string $orderId, string $reason = ''): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在');
        }
        OrderStateMachine::assert((string) $order['status'], OrderStateMachine::CANCELED);
        $order = $this->orders->update($orderId, ['status' => OrderStateMachine::CANCELED, 'canceled_at' => date('c'), 'failure_reason' => $reason]) ?? $order;
        $this->coupons->releaseForOrder($orderId);
        $this->orders->appendEvent($orderId, ['type' => 'order.canceled', 'reason' => $reason]);

        return $order;
    }

    public function fail(string $orderId, string $reason): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            throw new RuntimeException('订单不存在');
        }
        OrderStateMachine::assert((string) $order['status'], OrderStateMachine::FAILED);
        $order = $this->orders->update($orderId, ['status' => OrderStateMachine::FAILED, 'failure_reason' => $reason]) ?? $order;
        $this->coupons->releaseForOrder($orderId);
        $this->orders->appendEvent($orderId, ['type' => 'order.failed', 'reason' => $reason]);

        return $order;
    }

    /**
     * 对外暴露的订单视图（隐藏 channel_payload 等内部字段）。
     */
    public function publicOrder(array $order): array
    {
        return [
            'order_no' => $order['order_no'],
            'status' => $order['status'],
            'status_label' => OrderStateMachine::label((string) $order['status']),
            'subtotal_cents' => (int) ($order['subtotal_cents'] ?? $order['amount_cents']),
            'discount_cents' => (int) ($order['discount_cents'] ?? 0),
            'coupon_code' => $order['coupon_code'] ?? null,
            'amount_cents' => $order['amount_cents'],
            'amount' => Money::yuan((int) $order['amount_cents']),
            'currency' => $order['currency'] ?? 'CNY',
            'product_name' => $order['product_name'] ?? '',
            'type' => $order['type'] ?? 'one_time',
            'paid_at' => $order['paid_at'] ?? null,
            'delivered_at' => $order['delivered_at'] ?? null,
            'email' => $order['email'] ?? '',
            'external_id' => $order['external_id'] ?? null,
            'tenant' => $order['tenant'] ?? null,
        ];
    }
}
