<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Application;
use PayFlow\Http\Request;
use PayFlow\Http\Response;
use PayFlow\Support\View;

/**
 * 演示店铺（/payflow/store）。
 *
 * 注意：这不是主站的产品/能力页，只是本应用的 demo 站——用于本地/演示时
 * 直观看到商品与嵌入效果。线上入口 /payflow 仍是后台。
 */
final class StorefrontController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function home(Request $request): Response
    {
        $products = $this->app->products->active();
        $stats = $this->app->orders->stats();

        return Response::html(View::render('home', [
            'products' => $products,
            'stats' => $stats,
            'channels' => $this->app->channels->enabled(),
            'baseUrl' => $this->app->config['app']['base_url'] ?? '',
        ]));
    }
}
