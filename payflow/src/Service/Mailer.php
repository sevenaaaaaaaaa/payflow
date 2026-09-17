<?php

declare(strict_types=1);

namespace PayFlow\Service;

use RuntimeException;

/**
 * 零依赖 SMTP 直发邮件（对应 H1「SMTP 直发」）。
 *
 * 支持 ssl（465，隐式 TLS）与 tls（587，STARTTLS）；AUTH LOGIN。
 * mail.enabled=false 时静默跳过（本地联调不打扰）。
 */
final class Mailer
{
    public function __construct(private readonly array $config = [])
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if (($this->config['transport'] ?? 'smtp') === 'mail') {
            return $this->viaMail($to, $subject, $html);
        }

        try {
            $this->viaSmtp($to, $subject, $html, $text);

            return true;
        } catch (RuntimeException $e) {
            error_log('[PayFlow][mail] ' . $e->getMessage());

            return false;
        }
    }

    private function viaMail(string $to, string $subject, string $html): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName() . ' <' . $this->fromEmail() . '>',
        ];
        $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($to, $subjectEncoded, $html, implode("\r\n", $headers));
    }

    private function viaSmtp(string $to, string $subject, string $html, string $text): void
    {
        $host = (string) $this->config['host'];
        $port = (int) ($this->config['port'] ?? 465);
        $secure = (string) ($this->config['secure'] ?? 'ssl');
        $timeout = 20;

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if ($socket === false) {
            throw new RuntimeException("SMTP 连接失败: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, '220');
            $this->command($socket, 'EHLO ' . $this->clientHost(), '250');

            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS 握手失败');
                }
                $this->command($socket, 'EHLO ' . $this->clientHost(), '250');
            }

            $username = (string) ($this->config['username'] ?? '');
            $password = (string) ($this->config['password'] ?? '');
            if ($username !== '') {
                $this->command($socket, 'AUTH LOGIN', '334');
                $this->command($socket, base64_encode($username), '334');
                $this->command($socket, base64_encode($password), '235');
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromEmail() . '>', '250');
            $this->command($socket, 'RCPT TO:<' . $to . '>', '250');
            $this->command($socket, 'DATA', '354');

            fwrite($socket, $this->buildMessage($to, $subject, $html, $text));
            $this->expect($socket, '250');
            $this->command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'pf-' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->fromName() . ' <' . $this->fromEmail() . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text !== '' ? $text : strip_tags($html)))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
    }

    /** @param resource $socket */
    private function command($socket, string $command, string $expected): string
    {
        fwrite($socket, $command . "\r\n");

        return $this->expect($socket, $expected);
    }

    /** @param resource $socket */
    private function expect($socket, string $code): string
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if (!str_starts_with(trim($response), $code)) {
            throw new RuntimeException("SMTP 应答异常（期望 {$code}）: " . trim($response));
        }

        return $response;
    }

    private function fromEmail(): string
    {
        return (string) ($this->config['from_email'] ?? 'no-reply@localhost');
    }

    private function fromName(): string
    {
        return (string) ($this->config['from_name'] ?? 'PayFlow');
    }

    private function clientHost(): string
    {
        return parse_url((string) ($this->config['from_email'] ?? 'localhost'), PHP_URL_HOST) ?: 'localhost';
    }
}
