<?php

declare(strict_types=1);

namespace PayFlow\Support;

use RuntimeException;

/**
 * 极简 HTTP 客户端（优先 curl，降级 stream）。零外部依赖。
 */
final class HttpClient
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    public static function request(string $method, string $url, string $body = '', array $headers = [], int $timeout = 30): array
    {
        if (function_exists('curl_init')) {
            return self::viaCurl($method, $url, $body, $headers, $timeout);
        }

        return self::viaStream($method, $url, $body, $headers, $timeout);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private static function viaCurl(string $method, string $url, string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);

            throw new RuntimeException("HTTP 请求失败: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => (string) $response];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string}
     */
    private static function viaStream(string $method, string $url, string $body, array $headers, int $timeout): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException("HTTP 请求失败: {$url}");
        }
        $rawHeaders = implode("\n", $http_response_header ?? []);
        preg_match('#HTTP/\S+\s+(\d+)#', $rawHeaders, $m);

        return ['status' => (int) ($m[1] ?? 0), 'body' => $response];
    }
}
