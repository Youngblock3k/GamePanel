<?php
declare(strict_types=1);

namespace App;

/** Google reCAPTCHA v2 ("I'm not a robot") verification. Fails closed when enabled. */
final class Captcha
{
    public static function enabled(): bool
    {
        return cfg('recaptcha_site', '') !== '' && cfg('recaptcha_secret', '') !== '';
    }

    public static function verify(string $response): bool
    {
        if (!self::enabled()) {
            return true;
        }
        if ($response === '' || strlen($response) > 4096) {
            return false;
        }
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'secret' => cfg('recaptcha_secret'),
                'response' => $response,
                'remoteip' => Security::ip(),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($json) && ($json['success'] ?? false) === true;
    }
}
