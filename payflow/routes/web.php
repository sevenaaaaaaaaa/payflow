<?php

declare(strict_types=1);

use PayFlow\Application;
use PayFlow\Http\AdminAuth;
use PayFlow\Http\Controller\AdminController;
use PayFlow\Http\Controller\AssetController;
use PayFlow\Http\Controller\CheckoutController;
use PayFlow\Http\Controller\DeliveryController;
use PayFlow\Http\Controller\NotifyController;
use PayFlow\Http\Controller\ApiController;
use PayFlow\Http\Controller\InvoiceController;
use PayFlow\Http\Controller\PaymentController;
use PayFlow\Http\Controller\PaymentLinkController;
use PayFlow\Http\Controller\ReferralController;
use PayFlow\Http\Controller\StorefrontController;
use PayFlow\Http\Controller\VoucherController;
use PayFlow\Http\Router;

/**
 * 路由表。返回一个注册闭包，由 index.php 调用。
 */
return static function (Router $router, Application $app): void {
    $assets = new AssetController(PAYFLOW_ROOT . '/public/assets');
    $auth = new AdminAuth($app->config);
    $storefront = new StorefrontController($app);
    $checkout = new CheckoutController($app);
    $payment = new PaymentController($app, $auth);
    $delivery = new DeliveryController($app);
    $notify = new NotifyController($app);
    $admin = new AdminController($app, $auth);
    $referral = new ReferralController($app);
    $invoice = new InvoiceController($app);
    $api = new ApiController($app);
    $paymentLink = new PaymentLinkController($app);
    $voucher = new VoucherController($app);

    // ── 结账 SDK / 设计资产 ──
    $router->get('/checkout.js', fn ($r) => $assets->serve($r, 'checkout.js'));
    $router->get('/checkout.css', fn ($r) => $assets->serve($r, 'checkout.css'));
    $router->get('/assets/{file}', fn ($r) => $assets->serve($r, (string) $r->param('file')));

    // ── 应用入口 = 后台（/payflow 独占后台；未登录就地显示登录表单）──
    $router->any('/', fn ($r) => $admin->home($r));

    // ── 演示店铺（demo，非主站产品/能力页）──
    $router->get('/store', fn ($r) => $storefront->home($r));

    // ── 结账 ──
    $router->get('/checkout', fn ($r) => $checkout->page($r));
    $router->post('/api/checkout', fn ($r) => $checkout->create($r));
    $router->get('/api/coupon/validate', fn ($r) => $checkout->coupon($r));

    // ── 支付与订单 ──
    $router->get('/pay/{token}', fn ($r) => $payment->pay($r));
    $router->get('/return/{token}', fn ($r) => $payment->returned($r));
    $router->get('/api/orders/{token}', fn ($r) => $payment->status($r));
    $router->post('/api/orders/{token}/confirm', fn ($r) => $payment->confirm($r));

    // ── 交付与权益 ──
    $router->get('/d/{token}', fn ($r) => $delivery->show($r));
    $router->get('/download/{token}', fn ($r) => $delivery->download($r));
    $router->get('/api/entitlement', fn ($r) => $delivery->check($r));

    // ── 推荐裂变（公开）──
    $router->get('/r/{code}', fn ($r) => $referral->click($r));
    $router->any('/partner', fn ($r) => $referral->partner($r));

    // ── 发票（签名链接）──
    $router->get('/invoice/{token}', fn ($r) => $invoice->show($r));

    // ── 临时支付链接（公开）──
    $router->get('/l/{token}', fn ($r) => $paymentLink->show($r));
    $router->post('/api/link/checkout', fn ($r) => $paymentLink->submit($r));

    // ── 兑换券（公开）──
    $router->get('/redeem', fn ($r) => $voucher->show($r));
    $router->post('/redeem', fn ($r) => $voucher->redeem($r));

    // ── 对外 API v1（API Key 鉴权）──
    $router->get('/api/v1/meta', fn ($r) => $api->meta($r));
    $router->get('/api/v1/version', fn ($r) => $api->version($r));
    $router->get('/api/v1/events', fn ($r) => $api->events($r));
    $router->post('/api/v1/events', fn ($r) => $api->eventsIngest($r));
    $router->get('/api/v1/products', fn ($r) => $api->products($r));
    $router->post('/api/v1/checkout', fn ($r) => $api->checkout($r));
    $router->get('/api/v1/orders/{orderNo}', fn ($r) => $api->order($r));
    $router->any('/api/v1/coupons/validate', fn ($r) => $api->couponValidate($r));
    $router->get('/api/v1/analytics/summary', fn ($r) => $api->analytics($r));
    $router->any('/api/v1/licenses/validate', fn ($r) => $api->licenseValidate($r));

    // ── 支付回调 ──
    $router->post('/notify/alipay', fn ($r) => $notify->alipay($r));
    $router->post('/notify/wechat', fn ($r) => $notify->wechat($r));
    $router->post('/notify/crypto', fn ($r) => $notify->crypto($r));

    // ── 管理后台 ──
    $router->any('/admin/login', fn ($r) => $admin->login($r));
    $router->post('/admin/logout', fn ($r) => $admin->logout($r));
    $router->get('/admin', fn ($r) => $admin->dashboard($r));
    $router->get('/admin/products', fn ($r) => $admin->products($r));
    $router->get('/admin/products/new', fn ($r) => $admin->productForm($r));
    $router->post('/admin/products/new', fn ($r) => $admin->productSave($r));
    $router->get('/admin/products/{id}/edit', fn ($r) => $admin->productForm($r));
    $router->post('/admin/products/{id}/edit', fn ($r) => $admin->productSave($r));
    $router->post('/admin/products/{id}/delete', fn ($r) => $admin->productDelete($r));
    $router->get('/admin/products/{id}/assets', fn ($r) => $admin->productAssets($r));
    $router->post('/admin/products/{id}/assets', fn ($r) => $admin->productAssetUpload($r));
    $router->post('/admin/products/{id}/assets/{asset}/delete', fn ($r) => $admin->productAssetDelete($r));
    $router->get('/admin/licenses', fn ($r) => $admin->licenses($r));

    // ── 开放能力 / 看板 / 审计 ──
    $router->get('/admin/api-keys', fn ($r) => $admin->apiKeys($r));
    $router->post('/admin/api-keys/new', fn ($r) => $admin->apiKeyCreate($r));
    $router->post('/admin/api-keys/{id}/revoke', fn ($r) => $admin->apiKeyRevoke($r));
    $router->get('/admin/invoices', fn ($r) => $admin->invoices($r));
    $router->get('/admin/analytics', fn ($r) => $admin->analytics($r));
    $router->get('/admin/webhooks', fn ($r) => $admin->webhooks($r));
    $router->post('/admin/webhooks/{id}/resend', fn ($r) => $admin->webhookResend($r));
    $router->get('/admin/audit', fn ($r) => $admin->audit($r));

    // ── 临时支付链接 / 发卡 / 兑换券 ──
    $router->get('/admin/payment-links', fn ($r) => $admin->paymentLinks($r));
    $router->post('/admin/payment-links/new', fn ($r) => $admin->paymentLinkCreate($r));
    $router->post('/admin/payment-links/{id}/toggle', fn ($r) => $admin->paymentLinkToggle($r));
    $router->get('/admin/products/{id}/cards', fn ($r) => $admin->productCards($r));
    $router->post('/admin/products/{id}/cards/import', fn ($r) => $admin->cardImport($r));
    $router->post('/admin/products/{id}/cards/{card}/delete', fn ($r) => $admin->cardDelete($r));
    $router->get('/admin/vouchers', fn ($r) => $admin->vouchers($r));
    $router->post('/admin/vouchers/generate', fn ($r) => $admin->voucherGenerate($r));
    $router->post('/admin/vouchers/{id}/toggle', fn ($r) => $admin->voucherToggle($r));
    $router->get('/admin/orders', fn ($r) => $admin->orders($r));
    $router->get('/admin/orders/export', fn ($r) => $admin->ordersExport($r));
    $router->post('/admin/orders/{id}/confirm', fn ($r) => $admin->orderConfirm($r));
    $router->post('/admin/orders/{id}/fail', fn ($r) => $admin->orderFail($r));
    $router->post('/admin/orders/{id}/refund', fn ($r) => $admin->orderRefund($r));
    $router->get('/admin/customers', fn ($r) => $admin->customers($r));
    $router->get('/admin/subscriptions', fn ($r) => $admin->subscriptions($r));
    $router->post('/admin/subscriptions/{id}/cancel', fn ($r) => $admin->subscriptionCancel($r));
    $router->post('/admin/cron/run', fn ($r) => $admin->cronRun($r));

    // ── 优惠券 ──
    $router->get('/admin/coupons', fn ($r) => $admin->coupons($r));
    $router->get('/admin/coupons/new', fn ($r) => $admin->couponForm($r));
    $router->post('/admin/coupons/new', fn ($r) => $admin->couponSave($r));
    $router->get('/admin/coupons/{id}/edit', fn ($r) => $admin->couponForm($r));
    $router->post('/admin/coupons/{id}/edit', fn ($r) => $admin->couponSave($r));
    $router->post('/admin/coupons/{id}/delete', fn ($r) => $admin->couponDelete($r));

    // ── 推荐 / 佣金 / 提现 ──
    $router->get('/admin/referrals', fn ($r) => $admin->referrals($r));
    $router->post('/admin/referrals/new', fn ($r) => $admin->referralCreate($r));
    $router->get('/admin/commissions', fn ($r) => $admin->commissions($r));
    $router->get('/admin/commissions/export', fn ($r) => $admin->commissionsExport($r));
    $router->get('/admin/payouts', fn ($r) => $admin->payouts($r));
    $router->post('/admin/payouts/{id}/{action}', fn ($r) => $admin->payoutAction($r));
};
