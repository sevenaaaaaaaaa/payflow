<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;

final class InvoiceController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $invoice = $this->app->invoiceService->resolveToken((string) $request->param('token', ''));
        if ($invoice === null) {
            return Response::html(View::render('error', ['code' => 403, 'message' => '发票链接无效或已过期']), 403);
        }
        $order = $this->app->orders->find((string) $invoice['order_id']);

        return Response::html(View::render('invoice', [
            'invoice' => $invoice,
            'order' => $order !== null ? $this->app->orderService->publicOrder($order) : null,
        ]));
    }
}
