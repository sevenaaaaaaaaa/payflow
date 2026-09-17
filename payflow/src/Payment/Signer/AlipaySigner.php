<?php

declare(strict_types=1);

namespace PayFlow\Payment\Signer;

use RuntimeException;

/**
 * 支付宝 RSA2（SHA256withRSA）签名与验签。
 */
final class AlipaySigner
{
    /**
     * 组装待签名字符串：剔除 sign/sign_type 与空值，按键名升序，k=v&k=v。
     */
    public static function buildContent(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, static fn ($v): bool => $v !== '' && $v !== null);
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return implode('&', $pairs);
    }

    public static function sign(array $params, string $privateKey): string
    {
        $key = self::normalizeKey($privateKey, 'PRIVATE');
        $signature = '';
        $ok = openssl_sign(self::buildContent($params), $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('支付宝签名失败（私钥格式错误？）');
        }

        return base64_encode($signature);
    }

    public static function verify(array $params, string $publicKey): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }
        $key = self::normalizeKey($publicKey, 'PUBLIC');

        return openssl_verify(self::buildContent($params), base64_decode($sign, true) ?: '', $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * 兼容纯 base64（无 PEM 头）与应用私钥/支付宝公钥。
     */
    private static function normalizeKey(string $key, string $type): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new RuntimeException('密钥为空');
        }
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        $body = chunk_split(preg_replace('/\s+/', '', $key) ?? '', 64, "\n");

        return "-----BEGIN {$type} KEY-----\n{$body}-----END {$type} KEY-----\n";
    }
}
