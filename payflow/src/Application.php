<?php

declare(strict_types=1);

namespace PayFlow;

use PayFlow\Domain\CustomerRepository;
use PayFlow\Domain\ApiKeyRepository;
use PayFlow\Domain\AssetRepository;
use PayFlow\Domain\CardRepository;
use PayFlow\Domain\CommissionRepository;
use PayFlow\Domain\CouponRepository;
use PayFlow\Domain\DownloadRepository;
use PayFlow\Domain\EntitlementRepository;
use PayFlow\Domain\EventRepository;
use PayFlow\Domain\InvoiceRepository;
use PayFlow\Domain\LicenseRepository;
use PayFlow\Domain\OrderRepository;
use PayFlow\Domain\PaymentLinkRepository;
use PayFlow\Domain\PayoutRepository;
use PayFlow\Domain\ProductRepository;
use PayFlow\Domain\RedemptionRepository;
use PayFlow\Domain\ReferralRepository;
use PayFlow\Domain\SubscriptionRepository;
use PayFlow\Domain\VoucherRepository;
use PayFlow\Domain\WebhookDeliveryRepository;
use PayFlow\Payment\ChannelManager;
use PayFlow\Service\AnalyticsService;
use PayFlow\Service\ApiAuth;
use PayFlow\Service\CommissionService;
use PayFlow\Service\CouponService;
use PayFlow\Service\DeliveryService;
use PayFlow\Service\EntitlementService;
use PayFlow\Service\InvoiceService;
use PayFlow\Service\Mailer;
use PayFlow\Service\Notifier;
use PayFlow\Service\OrderService;
use PayFlow\Service\PaymentLinkService;
use PayFlow\Service\ReferralService;
use PayFlow\Service\SubscriptionService;
use PayFlow\Service\VoucherService;
use PayFlow\Service\WebhookDispatcher;

/**
 * 应用容器：集中装配仓储与服务，供 HTTP 层与 CLI 共用。
 */
final class Application
{
    public readonly ProductRepository $products;
    public readonly OrderRepository $orders;
    public readonly CustomerRepository $customers;
    public readonly SubscriptionRepository $subscriptions;
    public readonly EntitlementRepository $entitlements;
    public readonly EventRepository $events;
    public readonly CouponRepository $coupons;
    public readonly RedemptionRepository $redemptions;
    public readonly ReferralRepository $referrals;
    public readonly CommissionRepository $commissions;
    public readonly PayoutRepository $payouts;
    public readonly AssetRepository $assets;
    public readonly LicenseRepository $licenses;
    public readonly DownloadRepository $downloads;
    public readonly ApiKeyRepository $apiKeys;
    public readonly WebhookDeliveryRepository $webhookDeliveries;
    public readonly InvoiceRepository $invoices;
    public readonly PaymentLinkRepository $paymentLinks;
    public readonly CardRepository $cards;
    public readonly VoucherRepository $vouchers;
    public readonly ChannelManager $channels;
    public readonly EntitlementService $entitlementService;
    public readonly CouponService $couponService;
    public readonly CommissionService $commissionService;
    public readonly ReferralService $referralService;
    public readonly DeliveryService $deliveryService;
    public readonly ApiAuth $apiAuth;
    public readonly InvoiceService $invoiceService;
    public readonly AnalyticsService $analyticsService;
    public readonly PaymentLinkService $paymentLinkService;
    public readonly VoucherService $voucherService;
    public readonly Mailer $mailer;
    public readonly Notifier $notifier;
    public readonly WebhookDispatcher $webhooks;
    public readonly OrderService $orderService;
    public readonly SubscriptionService $subscriptionService;

    public function __construct(public readonly array $config)
    {
        $dataDir = (string) $config['data_dir'];
        $baseUrl = (string) ($config['app']['base_url'] ?? '');

        $this->products = new ProductRepository($dataDir);
        $this->orders = new OrderRepository($dataDir);
        $this->customers = new CustomerRepository($dataDir);
        $this->subscriptions = new SubscriptionRepository($dataDir);
        $this->entitlements = new EntitlementRepository($dataDir);
        $this->events = new EventRepository($dataDir);
        $this->coupons = new CouponRepository($dataDir);
        $this->redemptions = new RedemptionRepository($dataDir);
        $this->referrals = new ReferralRepository($dataDir);
        $this->commissions = new CommissionRepository($dataDir);
        $this->payouts = new PayoutRepository($dataDir);
        $this->assets = new AssetRepository($dataDir);
        $this->licenses = new LicenseRepository($dataDir);
        $this->downloads = new DownloadRepository($dataDir);
        $this->apiKeys = new ApiKeyRepository($dataDir);
        $this->webhookDeliveries = new WebhookDeliveryRepository($dataDir);
        $this->invoices = new InvoiceRepository($dataDir);
        $this->paymentLinks = new PaymentLinkRepository($dataDir);
        $this->cards = new CardRepository($dataDir);
        $this->vouchers = new VoucherRepository($dataDir);

        $this->channels = new ChannelManager($config['channels'] ?? [], $baseUrl);
        $this->entitlementService = new EntitlementService($this->entitlements, $this->customers, $baseUrl);
        $this->couponService = new CouponService($this->coupons, $this->redemptions);
        $this->mailer = new Mailer($config['mail'] ?? []);
        $this->notifier = new Notifier($this->mailer, $config['mail'] ?? []);
        $this->webhooks = new WebhookDispatcher($config['webhooks'] ?? [], $this->webhookDeliveries);
        $this->commissionService = new CommissionService($config, $this->commissions, $this->referrals, $this->notifier, $this->webhooks, $this->events);
        $this->referralService = new ReferralService($config, $this->referrals, $this->commissions, $this->payouts, $this->commissionService, $this->notifier, $this->events, $this->webhooks);
        $this->deliveryService = new DeliveryService($config, $this->assets, $this->licenses, $this->downloads, $this->products, $this->events, $baseUrl, $this->cards);
        $this->apiAuth = new ApiAuth($this->apiKeys, $config);
        $this->invoiceService = new InvoiceService($config, $this->invoices, $this->events, $baseUrl);
        $this->analyticsService = new AnalyticsService($this->orders, $this->subscriptions, $this->commissions, $this->customers);
        $this->paymentLinkService = new PaymentLinkService($this->paymentLinks, $this->products, $this->events, $baseUrl);

        $this->orderService = new OrderService(
            $config,
            $this->products,
            $this->orders,
            $this->customers,
            $this->events,
            $this->subscriptions,
            $this->entitlementService,
            $this->channels,
            $this->notifier,
            $this->webhooks,
            $this->couponService,
            $this->referralService,
            $this->deliveryService,
            $this->invoiceService,
        );

        $this->subscriptionService = new SubscriptionService(
            $config,
            $this->subscriptions,
            $this->orders,
            $this->products,
            $this->entitlementService,
            $this->orderService,
            $this->notifier,
            $this->webhooks,
            $this->events,
            $baseUrl,
        );

        $this->voucherService = new VoucherService($this->vouchers, $this->orderService);
    }

    public function baseUrl(string $path = ''): string
    {
        return rtrim((string) ($this->config['app']['base_url'] ?? ''), '/') . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
