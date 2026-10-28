<?php
declare(strict_types=1);

namespace App;

final class Security
{
    public static function boot(): void
    {
        $https = self::isHttps();
        header_remove('X-Powered-By');
        $csp = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data: https://cdn.modrinth.com https://media.forgecdn.net; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";
        if (Captcha::enabled() && in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ['/login', '/forgot'], true)) {
            // The reCAPTCHA widget needs Google's script/frames (and injects styles); relaxed ONLY on login/forgot.
            $csp = "default-src 'none'; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; "
                . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; "
                . "frame-src https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; frame-ancestors 'none'; base-uri 'none'";
        }
        header('Content-Security-Policy: ' . $csp);
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cache-Control: no-store');
        if ($https) {
            header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '2592000');
        $sessDir = ROOT . '/storage/sessions';
        is_dir($sessDir) || @mkdir($sessDir, 0700, true);
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir); // kept out of the OS temp cleaner so a reload never logs you out
        }
        session_name($https ? '__Host-gp_sid' : 'gp_sid');
        session_set_cookie_params([
            'lifetime' => 2592000, 'path' => '/', 'secure' => $https,
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        // Token-authenticated API calls are stateless: no cookie, no session.
        if (!str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            session_start();
        }
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::trustedProxy() && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    private static function trustedProxy(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', cfg('trusted_proxies', []), true);
    }

    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::trustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $cand = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            if (filter_var($cand, FILTER_VALIDATE_IP)) {
                return $cand;
            }
        }
        return $ip;
    }

    // ---- CSRF -----------------------------------------------------------

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(): void
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!is_string($sent) || !hash_equals(self::csrf(), $sent)) {
            throw new HttpException('Invalid or missing CSRF token', 419);
        }
        // Defence in depth: Origin must match Host when the browser sends it.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            $originHost = (string)parse_url($origin, PHP_URL_HOST);
            $host = (string)preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '');
            if (strcasecmp($originHost, $host) !== 0) {
                throw new HttpException('Cross-origin request blocked', 403);
            }
        }
    }

    // ---- Encryption at rest (libsodium secretbox) -----------------------

    private static function key(): string
    {
        $k = base64_decode((string)cfg('app_key'), true);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Invalid app_key in config');
        }
        return $k;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if ($raw === false || strlen($raw) <= $n) {
            throw new \RuntimeException('Corrupt ciphertext');
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), self::key());
        if ($plain === false) {
            throw new \RuntimeException('Decryption failed');
        }
        return $plain;
    }

    /** Keyed hash for low-value secrets such as recovery codes. */
    public static function hmac(string $v): string
    {
        return hash_hmac('sha256', $v, self::key());
    }

    // ---- Rate limiting --------------------------------------------------

    /** Returns false once $max hits are used inside the window. */
    public static function hit(string $key, int $max, int $window): bool
    {
        $now = time();
        $row = DB::one('SELECT hits, reset_at FROM rate_limits WHERE k = ?', [$key]);
        if (!$row || (int)$row['reset_at'] < $now) {
            DB::q('REPLACE INTO rate_limits (k, hits, reset_at) VALUES (?, 1, ?)', [$key, $now + $window]);
            return true;
        }
        DB::q('UPDATE rate_limits SET hits = hits + 1 WHERE k = ?', [$key]);
        return (int)$row['hits'] + 1 <= $max;
    }

    public static function clearHits(string $key): void
    {
        DB::q('DELETE FROM rate_limits WHERE k = ?', [$key]);
    }
}
