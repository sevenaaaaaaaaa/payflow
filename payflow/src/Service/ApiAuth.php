<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\ApiKeyRepository;
use PayFlow\Http\Request;
use PayFlow\Support\Arr;

/**
 * 对外 API 鉴权。
 *
 * 方式一（推荐，HMAC）：
 *   X-PF-Key: <key_id>
 *   X-PF-Timestamp: <unix 秒>
 *   X-PF-Signature: HMAC-SHA256(secret, timestamp . "\n" . METHOD . "\n" . path . "\n" . body)
 *
 * 方式二（简单，Bearer）：
 *   Authorization: Bearer <key_id>.<secret>
 *
 * 时间戳容差 5 分钟，防重放。
 */
final class ApiAuth
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly array $config = [],
    ) {
    }

    public function enabled(): bool
    {
        return (bool) Arr::get($this->config, 'api.enabled', true);
    }

    /**
     * @return array{key:array}|array{error:string}
     */
    public function authenticate(Request $request): array
    {
        if (!$this->enabled()) {
            return ['error' => 'API 未启用'];
        }

        $bearer = (string) ($request->header('Authorization', '') ?? '');
        if (str_starts_with($bearer, 'Bearer ')) {
            return $this->viaBearer(substr($bearer, 7));
        }

        return $this->viaHmac($request);
    }

    private function viaBearer(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return ['error' => '令牌格式错误'];
        }
        [$keyId, $secret] = $parts;
        $key = $this->keys->findByKeyId($keyId);
        if ($key === null || ($key['active'] ?? true) !== true) {
            return ['error' => '密钥无效'];
        }
        if (!password_verify($secret, (string) $key['secret_hash'])) {
            return ['error' => '密钥无效'];
        }
        $this->keys->touch((string) $key['id']);

        return ['key' => $key];
    }

    private function viaHmac(Request $request): array
    {
        $keyId = (string) ($request->header('X-PF-Key', '') ?? '');
        $timestamp = (string) ($request->header('X-PF-Timestamp', '') ?? '');
        $signature = (string) ($request->header('X-PF-Signature', '') ?? '');
        if ($keyId === '' || $timestamp === '' || $signature === '') {
            return ['error' => '缺少鉴权头'];
        }
        if (abs(time() - (int) $timestamp) > 300) {
            return ['error' => '时间戳过期'];
        }
        $key = $this->keys->findByKeyId($keyId);
        if ($key === null || ($key['active'] ?? true) !== true) {
            return ['error' => '密钥无效'];
        }
        // HMAC 模式使用明文 secret（存于受保护的 data/，见 docs/API.md）
        $signing = (string) ($key['secret_signing'] ?? '');
        if ($signing === '') {
            return ['error' => '该密钥不支持 HMAC 模式'];
        }
        $message = $timestamp . "\n" . $request->method . "\n" . $request->path . "\n" . $request->rawBody();
        $expected = hash_hmac('sha256', $message, $signing);
        if (!hash_equals($expected, $signature)) {
            return ['error' => '签名校验失败'];
        }
        $this->keys->touch((string) $key['id']);

        return ['key' => $key];
    }
}
