<?php
declare(strict_types=1);

namespace App;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30s) with replay protection. */
final class Totp
{
    private const ALPHA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::b32(random_bytes(20));
    }

    private static function b32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHA[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function unb32(string $s): string
    {
        $bits = '';
        foreach (str_split($s) as $c) {
            $p = strpos(self::ALPHA, $c);
            if ($p === false) {
                return '';
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    private static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::unb32($secret), true);
        $o = ord($h[19]) & 15;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step (store it as last_step) or null. */
    public static function verify(string $secret, string $input, int $lastStep): ?int
    {
        $input = preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{6}$/', $input)) {
            return null;
        }
        $now = intdiv(time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            $step = $now + $i;
            if ($step > $lastStep && hash_equals(self::code($secret, $step), $input)) {
                return $step;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account): string
    {
        $issuer = (string)cfg('app_name', 'GamePanel');
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }
}
