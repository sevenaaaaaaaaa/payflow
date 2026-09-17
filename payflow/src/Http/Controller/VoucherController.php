<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;
use RuntimeException;

/**
 * 兑换券：/redeem 输入兑换码免费领取商品。
 */
final class VoucherController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        return Response::html(View::render('redeem', [
            'code' => (string) ($request->query['code'] ?? ''),
            'error' => null,
        ]));
    }

    public function redeem(Request $request): Response
    {
        $code = (string) $request->string('code');
        $email = (string) $request->string('email');
        $name = (string) $request->string('name');

        try {
            $order = $this->app->voucherService->redeem($code, $email, $name);

            return Response::redirect($this->app->baseUrl('d/' . $order['token']));
        } catch (RuntimeException $e) {
            return Response::html(View::render('redeem', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]), 422);
        }
    }
}
