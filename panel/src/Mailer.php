<?php
declare(strict_types=1);

namespace App;

/**
 * Minimal SMTP sender (AUTH LOGIN, STARTTLS or implicit TLS, certificate verification on).
 * With no 'mail_host' configured, messages are written to storage/outbox/ instead, which is
 * how you read reset links while testing on localhost.
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $from = str_replace(["\r", "\n"], '', (string)cfg('mail_from', 'no-reply@localhost'));

        if (!cfg('mail_host')) {
            return self::outbox($to, $subject, $body);
        }
        try {
            return self::smtp($from, $to, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[mail] ' . $e->getMessage());
            return false;
        }
    }

    private static function outbox(string $to, string $subject, string $body): bool
    {
        $dir = ROOT . '/storage/outbox';
        is_dir($dir) || mkdir($dir, 0750, true);
        $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.txt';
        return file_put_contents($file, "To: $to\nSubject: $subject\n\n$body\n") !== false;
    }

    private static function smtp(string $from, string $to, string $subject, string $body): bool
    {
        $host = (string)cfg('mail_host');
        $port = (int)cfg('mail_port', 587);
        $secure = (string)cfg('mail_secure', 'tls'); // tls (STARTTLS) | ssl (implicit) | none
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 10);
        $read = function () use ($fp): string {
            $out = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $out .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $out;
        };
        $cmd = function (string $c, string $expect) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!str_starts_with($r, $expect)) {
                throw new \RuntimeException('SMTP error after command: ' . trim($r));
            }
            return $r;
        };
        $read();
        $cmd('EHLO vorlane', '250');
        if ($secure === 'tls') {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS negotiation failed');
            }
            $cmd('EHLO vorlane', '250');
        }
        if (cfg('mail_user')) {
            $cmd('AUTH LOGIN', '334');
            $cmd(base64_encode((string)cfg('mail_user')), '334');
            $cmd(base64_encode((string)cfg('mail_pass')), '235');
        }
        $cmd('MAIL FROM:<' . $from . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '250');
        $cmd('DATA', '354');
        $msg = "From: $from\r\nTo: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r')
            . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
        $msg = str_replace("\n", "\r\n", $msg);
        $cmd($msg . "\r\n.", '250');
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    }
}
