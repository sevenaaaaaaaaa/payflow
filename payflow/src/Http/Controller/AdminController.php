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
        if ($request->method === 'POST' && !$this->auth->verifyCsrf($request)) {
            return Response::html(View::render('error', ['code' => 419, 'message' => '表单已过期，请刷新后重试']), 419);
        }
        $this->logAdminEvent('admin.logout', ['ip' => $request->ip()]);
        $this->auth->logout();

        return Response::redirect(pf_url('/'));
    }

    private function attemptLogin(Request $request): Response
    {
        $ip = $request->ip();
        $user = $request->string('username');

        $locked = $this->app->loginThrottle->lockedSeconds($user, $ip);
        if ($locked > 0) {
            $this->logAdminEvent('admin.login.locked', ['username' => $user, 'ip' => $ip, 'remaining' => $locked]);

            return $this->loginPage('尝试次数过多，请 ' . (int) ceil($locked / 60) . ' 分钟后再试');
        }

        if ($this->auth->login($request)) {
            $this->app->loginThrottle->clear($user, $ip);
            $this->logAdminEvent('admin.login.success', ['username' => $user, 'ip' => $ip]);
            $next = $this->auth->pullNext();

            return Response::redirect($next ?? pf_url('/'));
        }

        $state = $this->app->loginThrottle->failure($user, $ip);
        $this->logAdminEvent('admin.login.failed', ['username' => $user, 'ip' => $ip, 'locked' => $state['locked']]);
        $msg = $state['locked']
            ? '尝试次数过多，账号已锁定 ' . (int) \PayFlow\Support\Arr::get($this->app->config, 'admin.login_lock_minutes', 15) . ' 分钟'
            : '用户名或密码不正确';

        return $this->loginPage($msg);
    }

    private function logAdminEvent(string $type, array $payload): void
    {
        try {
            $this->app->events->log($type, $payload);
        } catch (\Throwable $e) {
            error_log('[PayFlow][audit] ' . $e->getMessage());
        }
    }

    private function loginPage(?string $error = null): Response
    {
        return Response::html(View::render('admin/login', [
            'error' => $error,
            'configured' => $this->auth->configured(),
            'lockMinutes' => (int) \PayFlow\Support\Arr::get($this->app->config, 'admin.login_lock_minutes', 15),
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
            'subscriptions' => $this->app->subscriptions->query([], 10, 0, 'created_at', 'desc'),
        ]));
    }

    /**
     * 子页（商品/订单/客户）未登录 → 回入口（入口会就地显示登录表单）。
     */
    /**
     * @return array{0:list<array>,1:int,2:int,3:int}
     */
    private function paginate(\PayFlow\Store\Repository $repo, Request $request, int $perPage = 50): array
    {
        $total = $repo->countWhere([]);
        $page = max(1, $request->int('page', 1));
        $perPage = max(1, $perPage);
        $rows = $repo->query([], $perPage, ($page - 1) * $perPage, 'created_at', 'desc');

        return [$rows, $total, $page, $perPage];
    }

    private function guard(Request $request): ?Response
    {
        if (!$this->auth->attempt($request)) {
            $this->auth->rememberNext($request->path);

            return Response::redirect(pf_url('/'));
        }
        if ($request->method === 'POST' && !$this->auth->verifyCsrf($request)) {
            return Response::html(View::render('error', ['code' => 419, 'message' => '表单已过期，请刷新后重试']), 419);
        }

        return null;
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
        return Response::html(View::render('admin/products', [
            'products' => $this->app->products->query([], 0, 0, 'created_at', 'desc'),
        ]));
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

        [$licenses, $total, $page, $perPage] = $this->paginate($this->app->licenses, $request);

        return Response::html(View::render('admin/licenses', [
            'licenses' => $licenses, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
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
        $mode = $request->string('mode', 'live') === 'test' ? 'test' : 'live';
        $this->app->apiKeys->insert([
            'name' => $request->string('name', '未命名密钥'),
            'mode' => $mode,
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
        [$invoices, $total, $page, $perPage] = $this->paginate($this->app->invoices, $request);
        $rows = [];
        foreach ($invoices as $invoice) {
            $rows[] = ['invoice' => $invoice, 'url' => $this->app->invoiceService->urlFor($invoice)];
        }

        return Response::html(View::render('admin/invoices', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]));
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

        [$deliveries, $total, $page, $perPage] = $this->paginate($this->app->webhookDeliveries, $request);

        return Response::html(View::render('admin/webhooks', [
            'deliveries' => $deliveries, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'targets' => $this->app->webhooks->targets(),
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
        $perPage = 50;
        $page = max(1, $request->int('page', 1));
        $fields = ['type', 'payload'];
        if ($q === '') {
            $total = $this->app->events->countWhere([]);
            $events = $this->app->events->query([], $perPage, ($page - 1) * $perPage, 'created_at', 'desc');
        } else {
            $total = $this->app->events->searchCount($fields, $q);
            $events = $this->app->events->search($fields, $q, $perPage, ($page - 1) * $perPage, 'created_at', 'desc');
        }

        return Response::html(View::render('admin/audit', [
            'events' => $events,
            'q' => $q,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ]));
    }

    // ── 临时支付链接 ──

    public function paymentLinks(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        [$links, $total, $page, $perPage] = $this->paginate($this->app->paymentLinks, $request);
        $rows = [];
        foreach ($links as $link) {
            $rows[] = ['link' => $link, 'url' => $this->app->paymentLinkService->urlFor($link)];
        }

        return Response::html(View::render('admin/payment-links', [
            'rows' => $rows,
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
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

        [$vouchers, $total, $page, $perPage] = $this->paginate($this->app->vouchers, $request);

        return Response::html(View::render('admin/vouchers', [
            'vouchers' => $vouchers,
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
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
        if ($q === '') {
            $total = $this->app->orders->countWhere([]);
            $page = max(1, $forcePage ?? $request->int('page', 1));
            $slice = $this->app->orders->query([], $perPage, ($page - 1) * $perPage, 'created_at', 'desc');

            return [$slice, $total, $page, $perPage, ''];
        }
        $fields = ['order_no', 'email', 'product_name', 'status', 'coupon_code', 'referral_code'];
        $total = $this->app->orders->searchCount($fields, $q);
        $page = max(1, $forcePage ?? $request->int('page', 1));
        $perPage = max(1, $perPage);
        $slice = $this->app->orders->search($fields, $q, $perPage, ($page - 1) * $perPage, 'created_at', 'desc');

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
        [$customers, $total, $page, $perPage] = $this->paginate($this->app->customers, $request);

        return Response::html(View::render('admin/customers', [
            'customers' => $customers, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]));
    }

    public function subscriptions(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $subs = $this->app->subscriptions->query([], 0, 0, 'created_at', 'desc');

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
            'rate_limits_pruned' => $this->app->rateLimiter->prune(),
            'login_attempts_pruned' => $this->app->loginThrottle->prune(7),
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

        [$coupons, $total, $page, $perPage] = $this->paginate($this->app->coupons, $request);

        return Response::html(View::render('admin/coupons', [
            'coupons' => $coupons, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
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
        [$referrals, $total, $page, $perPage] = $this->paginate($this->app->referrals, $request);
        $rows = [];
        foreach ($referrals as $referral) {
            $summary = $this->app->commissionService->summary((string) $referral['id']);
            $rows[] = [
                'referral' => $referral,
                'summary' => $summary,
                'withdrawable' => $this->app->referralService->withdrawable((string) $referral['id']),
                'link' => $this->app->referralService->link($referral),
                'partner_url' => $this->app->baseUrl('partner?token=' . rawurlencode($this->app->referralService->partnerToken($referral))),
            ];
        }

        return Response::html(View::render('admin/referrals', [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
        ]));
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

    /**
     * 佣金结算报表（按时间范围汇总 + 分推荐人 + 明细）。
     */
    public function commissions(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        [$from, $to, $conditions] = $this->commissionRange($request);
        $summary = ['pending' => 0, 'available' => 0, 'paid' => 0, 'reversed' => 0];
        foreach ($this->app->commissions->aggregate($conditions, 'status', 'amount_cents') as $row) {
            if (array_key_exists((string) $row['key'], $summary)) {
                $summary[(string) $row['key']] = $row['sum'];
            }
        }
        $byReferrer = [];
        foreach ($this->app->commissions->aggregate($conditions, 'referrer_email', 'amount_cents') as $row) {
            $byReferrer[] = ['email' => (string) $row['key'], 'count' => $row['count'], 'amount' => $row['sum']];
        }

        return Response::html(View::render('admin/commissions', [
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'total' => array_sum($summary),
            'byReferrer' => $byReferrer,
            'detail' => $this->app->commissions->queryConditions($conditions, 500, 0, 'created_at', 'desc'),
        ]));
    }

    /**
     * 佣金对账单导出（CSV）。
     */
    public function commissionsExport(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        [$from, $to, $conditions] = $this->commissionRange($request);
        $rows = $this->app->commissions->queryConditions($conditions, 20000, 0, 'created_at', 'asc');
        $lines = ['时间,订单号,推荐人,订单金额,比例,佣金,状态,可提现时间'];
        foreach ($rows as $c) {
            $lines[] = implode(',', array_map(static fn ($v): string => '"' . str_replace('"', '""', (string) $v) . '"', [
                $c['created_at'] ?? '',
                $c['order_no'] ?? '',
                $c['referrer_email'] ?? '',
                number_format(((int) ($c['base_amount_cents'] ?? 0)) / 100, 2, '.', ''),
                round(((float) ($c['rate'] ?? 0)) * 100, 2) . '%',
                number_format(((int) ($c['amount_cents'] ?? 0)) / 100, 2, '.', ''),
                $c['status'] ?? '',
                $c['available_at'] ?? '',
            ]));
        }

        return new Response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="payflow-commissions-' . $from . '_' . $to . '.csv"',
        ]);
    }

    /**
     * @return array{0:string,1:string,2:list<array{field:string,op:string,value:mixed}>}
     */
    private function commissionRange(Request $request): array
    {
        $from = (string) ($request->query['from'] ?? date('Y-m-d', time() - 30 * 86400));
        $to = (string) ($request->query['to'] ?? date('Y-m-d'));
        $conditions = [
            ['field' => 'created_at', 'op' => '>=', 'value' => $from . 'T00:00:00'],
            ['field' => 'created_at', 'op' => '<=', 'value' => $to . 'T23:59:59'],
        ];

        return [$from, $to, $conditions];
    }

    public function payouts(Request $request): Response
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        [$payouts, $total, $page, $perPage] = $this->paginate($this->app->payouts, $request);

        return Response::html(View::render('admin/payouts', [
            'commissions' => $this->app->commissions->recent(100),
            'payouts' => $payouts, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
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
