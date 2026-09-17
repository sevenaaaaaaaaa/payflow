<?php

declare(strict_types=1);

namespace PayFlow\Payment\Signer;

use RuntimeException;

/**
 * 微信支付 APIv3 签名、验签与回调报文解密。
 */
final class WechatSigner
{
    /**
     * @param array<string,string> $headers 需含 Authorization 相关头
     */
    public static function authorization(
        string $method,
        string $pathWithQuery,
        string $body,
        string $mchId,
        string $serialNo,
        string $privateKey,
        ?string $nonce = null,
        ?int $timestamp = null,
    ): string {
        $nonce ??= bin2hex(random_bytes(16));
        $timestamp ??= time();
        $message = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $signature = self::sign($message, $privateKey);

        return sprintf(
            'WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $mchId,
            $nonce,
            $timestamp,
            $serialNo,
            $signature,
        );
    }

    public static function sign(string $message, string $privateKey): string
    {
        $signature = '';
        $ok = openssl_sign($message, $signature, self::normalize($privateKey), OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('微信支付签名失败（商户私钥格式错误？）');
        }

        return base64_encode($signature);
    }

    /**
     * 验签回调：message = timestamp\nnonce\nbody\n
     */
    public static function verifyNotify(string $timestamp, string $nonce, string $body, string $signature, string $platformCert): bool
    {
        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        return openssl_verify($message, base64_decode($signature, true) ?: '', self::normalize($platformCert), OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * AES-256-GCM 解密回调 resource。
     */
    public static function decryptResource(string $ciphertextBase64, string $nonce, string $associatedData, string $apiV3Key): string
    {
        $ciphertext = base64_decode($ciphertextBase64, true);
        if ($ciphertext === false || strlen($ciphertext) < 16) {
            throw new RuntimeException('微信回调密文非法');
        }
        $tag = substr($ciphertext, -16);
        $encrypted = substr($ciphertext, 0, -16);
        $plain = openssl_decrypt($encrypted, 'aes-256-gcm', $apiV3Key, OPENSSL_RAW_DATA, $nonce, $tag, $associatedData);
        if ($plain === false) {
            throw new RuntimeException('微信回调解密失败（APIv3 密钥错误？）');
        }

        return $plain;
    }

    private static function normalize(string $key): string
    {
        $key = trim($key);
        if ($key !== '' && str_contains($key, '-----BEGIN')) {
            return $key;
        }
        if ($key === '') {
            throw new RuntimeException('密钥为空');
        }

        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PRIVATE KEY-----\n";
    }
}
