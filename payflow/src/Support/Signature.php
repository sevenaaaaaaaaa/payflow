<?php

declare(strict_types=1);

namespace PayFlow\Support;

/**
 * HMAC 签名：签名下载链接、API 出站/入站校验、结账令牌。
 *
 * 密钥取 config.secret（bootstrap 注入 PF_SECRET）；留空时回退到后台密码哈希，
 * 保证零配置也不至于用固定串。
 */
final class Signature
{
    public static function secret(): string
    {
        $secret = (string) ($GLOBALS['PF_SECRET'] ?? '');

        return $secret !== '' ? $secret : 'payflow-insecure-fallback';
    }

    /**
     * 把负载打包成带签名的令牌：base64url(json).base64url(hmac)
     */
    public static function encode(array $payload): string
    {
        $b64 = self::b64urlEncode((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sig = self::b64urlEncode(hash_hmac('sha256', $b64, self::secret(), true));

        return $b64 . '.' . $sig;
    }

    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$b64, $sig] = $parts;
        $expected = self::b64urlEncode(hash_hmac('sha256', $b64, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = self::b64urlDecode($b64);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    public static function hmac(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    public static function verifyHmac(string $body, string $signature): bool
    {
        return hash_equals(self::hmac($body), $signature);
    }

    public static function randomSecret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    private static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $encoded): string
    {
        return (string) base64_decode(strtr($encoded, '-_', '+/'), true);
    }
}
