<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\AdminAuth;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\Money;
use PayFlow\Support\View;

final class AdminController
{
    public function __construct(private readonly Application $app, private readonly AdminAuth $auth)
    {
    }

    /**
     * 应用入口：已登录显示概览，未登录就地显示登录表单（不跳转）。
     * POST = 提交登录。
     */
    public function home(Request $request): Response
    {
        if ($request->method === 'POST') {
            return $this->attemptLogin($request);
        }
        if (!$this->auth->attempt($request)) {
            return $this->loginPage();
        }

        return $this->dashboardView();
    }

    /**
     * 兼容 /admin/login：GET 归一到入口；POST 处理登录。
     */
    public function login(Request $request): Response
    {
        if ($request->method === 'POST') {
            return $this->attemptLogin($request);
        }

        return $this->auth->attempt($request)
            ? Response::redirect(pf_url('/'))
            : $this->loginPage();
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return Response::redirect(pf_url('/'));
    }

    private function attemptLogin(Request $request): Response
    {
        if ($this->auth->login($request)) {
            return Response::redirect(pf_url('/'));
        }

        return $this->loginPage('用户名或密码不正确');
    }

    private function loginPage(?string $error = null): Response
    {
        return Response::html(View::render('admin/login', [
            'error' => $error,
            'configured' => $this->auth->configured(),
        ]), $error === null ? 200 : 401);
    }

    private function dashboardView(): Response
    {
        return Response::html(View::render('admin/dashboard', [
            'stats' => $this->app->orders->stats(),
            'orders' => $this->app->orders->recent(10),
            'events' => $this->app->events->recent(15),
            'productCount' => $this->app->products->count(),
            'customerCount' => $this->app->customers->count(),
            'subscriptions' => array_slice(array_values($this->app->subscriptions->all()), 0, 10),
        ]));
    }

    /**
     * 子页（商品/订单/客户）未登录 → 回入口（入口会就地显示登录表单）。
     */
    private function guard(Request $request): ?Response
    {
        return $this->auth->attempt($request) ? null : Response::redirect(pf_url('/'));
    }

    public function dashboard(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return $this->dashboardView();
    }

    public function products(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $products = array_values($this->app->products->all());
        usort($products, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return Response::html(View::render('admin/products', ['products' => $products]));
    }

    public function productForm(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $product = $id !== '' ? $this->app->products->find($id) : null;

        return Response::html(View::render('admin/product-form', [
            'product' => $product,
            'baseUrl' => $this->app->baseUrl(),
        ]));
    }

    public function productSave(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $input = $this->productInput($request);

        if ($id !== '') {
            $this->app->products->edit($id, $input);
        } else {
            $this->app->products->create($input);
        }

        return Response::redirect(pf_url('/admin/products'));
    }

    public function productDelete(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $this->app->products->delete((string) $request->param('id', ''));

        return Response::redirect(pf_url('/admin/products'));
    }

    public function productAssets(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $product = $this->app->products->find($id);
        if ($product === null) {
            return Response::redirect(pf_url('/admin/products'));
        }

        return Response::html(View::render('admin/product-assets', [
            'product' => $product,
            'assets' => $this->app->deliveryService->assetsForProduct($id),
            'flash' => ($request->query['ok'] ?? '') === '1' ? '上传成功' : (string) ($request->query['err'] ?? ''),
        ]));
    }

    public function productAssetUpload(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        try {
            if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
                throw new \RuntimeException('请选择文件');
            }
            $this->app->deliveryService->storeUpload(
                $id,
                $_FILES['file'],
                $request->string('title'),
                $request->int('download_limit'),
            );

            return Response::redirect(pf_url('/admin/products/' . $id . '/assets?ok=1'));
        } catch (\Throwable $e) {
            return Response::redirect(pf_url('/admin/products/' . $id . '/assets?err=' . rawurlencode($e->getMessage())));
        }
    }

    public function productAssetDelete(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $this->app->deliveryService->deleteAsset((string) $request->param('asset', ''));

        return Response::redirect(pf_url('/admin/products/' . $id . '/assets'));
    }

    public function licenses(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return Response::html(View::render('admin/licenses', [
            'licenses' => $this->app->licenses->recent(),
        ]));
    }

    // ── 开放能力：API Key ──

    public function apiKeys(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $newKey = $_SESSION['pf_new_key'] ?? null;
        unset($_SESSION['pf_new_key']);

        return Response::html(View::render('admin/api-keys', [
            'keys' => $this->app->apiKeys->recent(),
            'newKey' => $newKey,
        ]));
    }

    public function apiKeyCreate(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $keyId = (string) \PayFlow\Support\Arr::get($this->app->config, 'api.key_prefix', 'pfk_') . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        $this->app->apiKeys->insert([
            'name' => $request->string('name', '未命名密钥'),
            'key_id' => $keyId,
            'secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
            'secret_signing' => $secret,
            'scopes' => ['*'],
            'active' => true,
            'requests' => 0,
        ]);
        $this->auth->bootSession();
        $_SESSION['pf_new_key'] = ['key_id' => $keyId, 'secret' => $secret];

        return Response::redirect(pf_url('/admin/api-keys'));
    }

    public function apiKeyRevoke(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $this->app->apiKeys->update((string) $request->param('id', ''), ['active' => false]);

        return Response::redirect(pf_url('/admin/api-keys'));
    }

    // ── 发票 ──

    public function invoices(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $rows = [];
        foreach ($this->app->invoices->recent() as $invoice) {
            $rows[] = ['invoice' => $invoice, 'url' => $this->app->invoiceService->urlFor($invoice)];
        }

        return Response::html(View::render('admin/invoices', ['rows' => $rows]));
    }

    // ── 看板 ──

    public function analytics(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $days = max(1, $request->int('days', 30));

        return Response::html(View::render('admin/analytics', [
            'summary' => $this->app->analyticsService->summary($days),
            'series' => $this->app->analyticsService->series(min($days, 30)),
            'days' => $days,
        ]));
    }

    // ── Webhook 投递 ──

    public function webhooks(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return Response::html(View::render('admin/webhooks', [
            'deliveries' => $this->app->webhookDeliveries->recent(100),
            'configured' => (bool) \PayFlow\Support\Arr::get($this->app->config, 'webhooks.order.enabled', false),
        ]));
    }

    public function webhookResend(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $this->app->webhooks->resend((string) $request->param('id', ''));

        return Response::redirect(pf_url('/admin/webhooks'));
    }

    // ── 审计 ──

    public function audit(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $q = strtolower($request->string('q'));

        return Response::html(View::render('admin/audit', [
            'events' => $this->app->events->recent(300),
            'q' => $q,
        ]));
    }

    // ── 临时支付链接 ──

    public function paymentLinks(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $rows = [];
        foreach ($this->app->paymentLinks->recent() as $link) {
            $rows[] = ['link' => $link, 'url' => $this->app->paymentLinkService->urlFor($link)];
        }

        return Response::html(View::render('admin/payment-links', [
            'rows' => $rows,
            'products' => $this->app->products->active(),
        ]));
    }

    public function paymentLinkCreate(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $expiryDays = max(1, $request->int('expiry_days', 7));
        $this->app->paymentLinks->create([
            'title' => $request->string('title', '支付链接'),
            'description' => $request->string('description'),
            'amount_cents' => Money::toCents($request->string('amount', '0')),
            'product_id' => $request->string('product_id'),
            'allow_coupon' => $request->bool('allow_coupon'),
            'max_uses' => $request->int('max_uses'),
            'expires_at' => date('c', time() + $expiryDays * 86400),
            'active' => true,
        ]);

        return Response::redirect(pf_url('/admin/payment-links'));
    }

    public function paymentLinkToggle(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $link = $this->app->paymentLinks->find((string) $request->param('id', ''));
        if ($link !== null) {
            $this->app->paymentLinks->update((string) $link['id'], ['active' => !($link['active'] ?? true)]);
        }

        return Response::redirect(pf_url('/admin/payment-links'));
    }

    // ── 发卡系统 ──

    public function productCards(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $product = $this->app->products->find($id);
        if ($product === null) {
            return Response::redirect(pf_url('/admin/products'));
        }
        $cards = $this->app->cards->forProduct($id);
        usort($cards, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return Response::html(View::render('admin/product-cards', [
            'product' => $product,
            'cards' => array_slice($cards, 0, 200),
            'stats' => $this->app->cards->stats($id),
            'flash' => (string) ($request->query['ok'] ?? ''),
        ]));
    }

    public function cardImport(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $batch = date('Ymd-His');
        $count = 0;
        foreach (preg_split('/\r\n|\n/', $request->string('codes')) ?: [] as $line) {
            $code = trim($line);
            if ($code === '') {
                continue;
            }
            $this->app->cards->insert([
                'product_id' => $id,
                'code' => $code,
                'status' => 'available',
                'batch' => $batch,
            ]);
            $count++;
        }

        return Response::redirect(pf_url('/admin/products/' . $id . '/cards?ok=' . rawurlencode("已导入 {$count} 张")));
    }

    public function cardDelete(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $this->app->cards->delete((string) $request->param('card', ''));

        return Response::redirect(pf_url('/admin/products/' . $id . '/cards'));
    }

    // ── 兑换券 ──

    public function vouchers(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return Response::html(View::render('admin/vouchers', [
            'vouchers' => $this->app->vouchers->recent(),
            'products' => $this->app->products->active(),
            'flash' => (string) ($request->query['ok'] ?? ''),
        ]));
    }

    public function voucherGenerate(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $productId = $request->string('product_id');
        $count = max(1, min(500, $request->int('count', 10)));
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->string('prefix', 'GIFT')) ?: 'GIFT');
        $maxUses = max(1, $request->int('max_uses', 1));
        $days = $request->int('expiry_days', 0);
        $batch = date('Ymd-His');

        for ($i = 0; $i < $count; $i++) {
            do {
                $code = $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
            } while ($this->app->vouchers->findByCode($code) !== null);
            $this->app->vouchers->insert([
                'product_id' => $productId,
                'code' => $code,
                'kind' => 'free',
                'max_uses' => $maxUses,
                'used_count' => 0,
                'expires_at' => $days > 0 ? date('c', time() + $days * 86400) : null,
                'active' => true,
                'batch' => $batch,
            ]);
        }

        return Response::redirect(pf_url('/admin/vouchers?ok=' . rawurlencode("已生成 {$count} 张兑换券")));
    }

    public function voucherToggle(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $voucher = $this->app->vouchers->find((string) $request->param('id', ''));
        if ($voucher !== null) {
            $this->app->vouchers->update((string) $voucher['id'], ['active' => !($voucher['active'] ?? true)]);
        }

        return Response::redirect(pf_url('/admin/vouchers'));
    }

    public function orders(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        [$orders, $total, $page, $perPage, $q] = $this->filteredOrders($request);

        return Response::html(View::render('admin/orders', [
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'q' => $q,
        ]));
    }

    public function ordersExport(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        [$orders] = $this->filteredOrders($request, 10000, 1);
        $lines = ["order_no,email,product,type,status,subtotal,discount,amount,channel,coupon,referral,created_at,paid_at"];
        foreach ($orders as $o) {
            $lines[] = implode(',', array_map(static function ($v): string {
                return '"' . str_replace('"', '""', (string) $v) . '"';
            }, [
                $o['order_no'] ?? '', $o['email'] ?? '', $o['product_name'] ?? '', $o['type'] ?? '',
                $o['status'] ?? '', number_format(((int) ($o['subtotal_cents'] ?? $o['amount_cents'])) / 100, 2, '.', ''),
                number_format(((int) ($o['discount_cents'] ?? 0)) / 100, 2, '.', ''),
                number_format(((int) $o['amount_cents']) / 100, 2, '.', ''),
                $o['channel'] ?? '', $o['coupon_code'] ?? '', $o['referral_code'] ?? '',
                $o['created_at'] ?? '', $o['paid_at'] ?? '',
            ]));
        }

        return new Response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="payflow-orders-' . date('Ymd-His') . '.csv"',
        ]);
    }

    /**
     * @return array{0:list<array>,1:int,2:int,3:int,4:string}
     */
    private function filteredOrders(Request $request, int $perPage = 50, ?int $forcePage = null): array
    {
        $q = strtolower($request->string('q'));
        $orders = array_values($this->app->orders->all());
        if ($q !== '') {
            $orders = array_filter($orders, static function (array $o) use ($q): bool {
                foreach (['order_no', 'email', 'product_name', 'status', 'coupon_code', 'referral_code'] as $field) {
                    if (str_contains(strtolower((string) ($o[$field] ?? '')), $q)) {
                        return true;
                    }
                }
                return false;
            });
            $orders = array_values($orders);
        }
        usort($orders, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        $total = count($orders);
        $page = max(1, $forcePage ?? $request->int('page', 1));
        $perPage = max(1, $perPage);
        $slice = array_slice($orders, ($page - 1) * $perPage, $perPage);

        return [$slice, $total, $page, $perPage, $q];
    }

    public function orderConfirm(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $order = $this->app->orders->find((string) $request->param('id', ''));
        if ($order !== null && !\PayFlow\Domain\OrderStateMachine::isPaidLike((string) $order['status'])) {
            try {
                $this->app->orderService->markPaid((string) $order['id'], 'MANUAL-' . $order['order_no'], (int) $order['amount_cents'], ['source' => 'admin']);
            } catch (\Throwable $e) {
                error_log('[PayFlow][admin] confirm failed: ' . $e->getMessage());
            }
        }

        return Response::redirect(pf_url('/admin/orders'));
    }

    public function orderFail(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $order = $this->app->orders->find((string) $request->param('id', ''));
        if ($order !== null && \PayFlow\Domain\OrderStateMachine::can((string) $order['status'], \PayFlow\Domain\OrderStateMachine::FAILED)) {
            $this->app->orderService->fail((string) $order['id'], $request->string('reason', 'admin'));
        }

        return Response::redirect(pf_url('/admin/orders'));
    }

    public function orderRefund(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $order = $this->app->orders->find((string) $request->param('id', ''));
        if ($order !== null && \PayFlow\Domain\OrderStateMachine::can((string) $order['status'], \PayFlow\Domain\OrderStateMachine::REFUNDED)) {
            try {
                $this->app->orderService->refund((string) $order['id'], (int) $order['amount_cents'], $request->string('reason', 'admin refund'));
            } catch (\Throwable $e) {
                error_log('[PayFlow][admin] refund failed: ' . $e->getMessage());
            }
        }

        return Response::redirect(pf_url('/admin/orders'));
    }

    public function customers(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $customers = array_values($this->app->customers->all());
        usort($customers, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return Response::html(View::render('admin/customers', ['customers' => $customers]));
    }

    public function subscriptions(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $subs = array_values($this->app->subscriptions->all());
        usort($subs, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return Response::html(View::render('admin/subscriptions', [
            'subscriptions' => $subs,
            'stats' => $this->app->subscriptions->stats(),
        ]));
    }

    public function subscriptionCancel(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $this->app->subscriptionService->cancel((string) $request->param('id', ''), 'admin');

        return Response::redirect(pf_url('/admin/subscriptions'));
    }

    /**
     * 手动跑一次定时任务（订阅续费/提醒 + 佣金解冻 + Webhook 重试）。
     */
    public function cronRun(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $report = $this->runScheduledTasks();

        return Response::html(View::render('admin/cron', ['report' => $report]));
    }

    public function runScheduledTasks(): array
    {
        return [
            'subscription_reminders' => $this->app->subscriptionService->remindExpiring(),
            'subscription_due' => $this->app->subscriptionService->renewDue(),
            'commissions_matured' => $this->app->commissionService->mature(),
            'webhooks_retried' => $this->app->webhooks->retryDue(),
            'events_pruned' => $this->app->events->prune(
                (int) \PayFlow\Support\Arr::get($this->app->config, 'maintenance.events_retention_days', 180),
                (int) \PayFlow\Support\Arr::get($this->app->config, 'maintenance.events_max_rows', 50000),
            ),
        ];
    }

    // ── 优惠券 ──

    public function coupons(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return Response::html(View::render('admin/coupons', [
            'coupons' => $this->app->coupons->recent(),
        ]));
    }

    public function couponForm(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');

        return Response::html(View::render('admin/coupon-form', [
            'coupon' => $id !== '' ? $this->app->coupons->find($id) : null,
        ]));
    }

    public function couponSave(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        $input = $this->couponInput($request);
        if ($id !== '') {
            $this->app->coupons->edit($id, $input);
        } else {
            $this->app->coupons->create($input);
        }

        return Response::redirect(pf_url('/admin/coupons'));
    }

    public function couponDelete(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $this->app->coupons->delete((string) $request->param('id', ''));

        return Response::redirect(pf_url('/admin/coupons'));
    }

    private function couponInput(Request $request): array
    {
        $type = $request->string('type', 'percent');

        return [
            'code' => $request->string('code'),
            'description' => $request->string('description'),
            'type' => $type,
            'value' => $type === 'fixed' ? Money::toCents($request->string('value', '0')) : $request->int('value'),
            'min_amount_cents' => Money::toCents($request->string('min_amount', '0')),
            'max_redemptions' => $request->int('max_redemptions'),
            'per_customer_limit' => $request->int('per_customer_limit'),
            'starts_at' => $request->string('starts_at'),
            'expires_at' => $request->string('expires_at'),
            'applies_to' => 'all',
            'active' => $request->bool('active', true),
        ];
    }

    // ── 推荐 / 佣金 / 提现 ──

    public function referrals(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $rows = [];
        foreach ($this->app->referrals->recent() as $referral) {
            $summary = $this->app->commissionService->summary((string) $referral['id']);
            $rows[] = [
                'referral' => $referral,
                'summary' => $summary,
                'withdrawable' => $this->app->referralService->withdrawable((string) $referral['id']),
                'link' => $this->app->referralService->link($referral),
                'partner_url' => $this->app->baseUrl('partner?token=' . rawurlencode($this->app->referralService->partnerToken($referral))),
            ];
        }

        return Response::html(View::render('admin/referrals', ['rows' => $rows]));
    }

    public function referralCreate(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $email = $request->string('email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->app->referralService->findOrCreate($email, $request->string('name'));
        }

        return Response::redirect(pf_url('/admin/referrals'));
    }

    public function payouts(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        return Response::html(View::render('admin/payouts', [
            'commissions' => $this->app->commissions->recent(),
            'payouts' => $this->app->payouts->recent(),
        ]));
    }

    public function payoutAction(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $id = (string) $request->param('id', '');
        switch ((string) $request->param('action', '')) {
            case 'approve':
                $this->app->referralService->approvePayout($id);
                break;
            case 'reject':
                $this->app->referralService->rejectPayout($id, $request->string('reason', '不符合提现要求'));
                break;
            case 'pay':
                $this->app->referralService->payPayout($id, $request->string('transfer_no'));
                break;
        }

        return Response::redirect(pf_url('/admin/payouts'));
    }

    private function productInput(Request $request): array
    {
        $items = [];
        foreach (preg_split('/\r\n|\n/', $request->string('items')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$title, $url] = array_pad(explode('|', $line, 2), 2, '');
            $items[] = ['title' => trim($title), 'url' => trim($url !== '' ? $url : $title)];
        }

        return [
            'name' => $request->string('name'),
            'slug' => $request->string('slug'),
            'description' => $request->string('description'),
            'type' => $request->string('type', 'one_time'),
            'amount_cents' => Money::toCents($request->string('amount', '0')),
            'currency' => $request->string('currency', 'CNY'),
            'interval' => $request->string('interval', 'month'),
            'interval_count' => max(1, $request->int('interval_count', 1)),
            'trial_days' => max(0, $request->int('trial_days', 0)),
            'entitlement' => [
                'kind' => $request->string('entitlement_kind', 'content'),
                'membership_level' => $request->string('membership_level', 'member'),
                'duration_days' => max(0, $request->int('duration_days', 0)),
                'items' => $items,
            ],
            'active' => $request->bool('active', true),
            'sort' => $request->int('sort', 100),
            'card_enabled' => $request->bool('card_enabled'),
        ];
    }
}
