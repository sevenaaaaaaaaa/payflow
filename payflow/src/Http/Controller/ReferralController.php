<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\Arr;
use PayFlow\Support\Money;
use PayFlow\Support\View;
use RuntimeException;

/**
 * 推荐链接跳转 + 推荐人自助（余额/提现）。
 */
final class ReferralController
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * /r/{code}：记录点击、写归因 Cookie、跳转到目标页。
     */
    public function click(Request $request): Response
    {
        $code = (string) $request->param('code', '');
        $referral = $this->app->referralService->trackClick($code);
        if ($referral === null) {
            return Response::html(View::render('error', ['code' => 404, 'message' => '推荐链接无效']), 404);
        }

        $cookieName = (string) Arr::get($this->app->config, 'referral.cookie_name', 'pf_ref');
        $days = max(1, (int) Arr::get($this->app->config, 'referral.cookie_days', 30));
        setcookie($cookieName, (string) $referral['code'], [
            'expires' => time() + $days * 86400,
            'path' => pf_base_path() !== '' ? pf_base_path() . '/' : '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        ]);

        $to = (string) ($request->query['to'] ?? '');
        if ($to === '' || !str_starts_with($to, '/') || str_starts_with($to, '//')) {
            $to = pf_url('/store');
        }

        return Response::redirect($to);
    }

    /**
     * /partner?token=...：推荐人自助看余额、申请提现。
     */
    public function partner(Request $request): Response
    {
        $referral = $this->app->referralService->resolvePartnerToken((string) ($request->query['token'] ?? ''));
        if ($referral === null) {
            return Response::html(View::render('error', ['code' => 403, 'message' => '链接无效或已过期']), 403);
        }

        $flash = null;
        if ($request->method === 'POST') {
            try {
                $this->app->referralService->requestPayout(
                    $referral,
                    Money::toCents($request->string('amount', '0')),
                    $request->string('method', 'manual'),
                    $request->string('account'),
                    $request->string('note'),
                );
                return Response::redirect(pf_url('/partner?token=' . rawurlencode((string) $request->query['token']) . '&ok=1'));
            } catch (RuntimeException $e) {
                $flash = $e->getMessage();
            }
        }

        $dashboard = $this->app->referralService->dashboard($referral);
        $dashboard['token'] = (string) ($request->query['token'] ?? '');
        $dashboard['flash'] = $flash;
        $dashboard['ok'] = ($request->query['ok'] ?? '') === '1';
        $dashboard['link'] = $this->app->referralService->link($referral);
        $dashboard['methods'] = (array) Arr::get($this->app->config, 'payout.methods', ['manual']);
        $dashboard['min'] = (int) Arr::get($this->app->config, 'payout.min_amount_cents', 0);

        return Response::html(View::render('partner', $dashboard));
    }
}
