<?php

declare(strict_types=1);

namespace PayFlow\Http\Controller;

use PayFlow\Http\Request;
use PayFlow\Http\Response;

/**
 * 静态资产：设计令牌 / 共享模块 / 结账 SDK。
 *
 * 生产环境可改走 Cloudflare R2（见 docs/CLOUDFLARE.md），此控制器保证本地与
 * 源站部署开箱可用。
 */
final class AssetController
{
    private const MIME = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'woff2' => 'font/woff2',
    ];

    public function __construct(private readonly string $assetDir)
    {
    }

    public function serve(Request $request, string $file): Response
    {
        $file = str_replace(['..', "\0"], '', $file);
        $path = $this->assetDir . '/' . $file;
        if (!is_file($path)) {
            return Response::text('Not found', 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME[$ext] ?? 'application/octet-stream';
        $body = (string) file_get_contents($path);

        return new Response($body, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
