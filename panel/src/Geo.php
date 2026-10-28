<?php
declare(strict_types=1);

namespace App;

/** Country for an IP. Private ranges are labelled locally; public lookups are opt-in (Admin > Settings) and cached. */
final class Geo
{
    /** @return array{0:string,1:string} [country code, flag emoji] */
    public static function lookup(string $ip): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['LAN', "\u{1F3E0}"];
        }
        if (Settings::get('geo_lookup', '0') !== '1') {
            return ['--', "\u{1F310}"];
        }
        $key = 'geo:' . $ip;
        $cached = Settings::get($key, '');
        if ($cached === '') {
            $cached = self::fetch($ip);
            Settings::set($key, $cached);
        }
        return self::flag($cached);
    }

    private static function fetch(string $ip): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country_code', false, $ctx);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        $cc = is_array($j) && !empty($j['success']) ? strtoupper((string)($j['country_code'] ?? '')) : '';
        return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '--';
    }

    /** @return array{0:string,1:string} */
    private static function flag(string $cc): array
    {
        if (!preg_match('/^[A-Z]{2}$/', $cc)) {
            return ['--', "\u{1F310}"];
        }
        $f = '';
        foreach (str_split($cc) as $c) {
            $f .= mb_chr(0x1F1E6 + ord($c) - 65);
        }
        return [$cc, $f];
    }
}
