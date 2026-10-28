<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    /** 2FA enforcement mode: '0' off, '1' administrators only, '2' every account. */
    public static function twoFaMode(): string
    {
        $m = Settings::get('require_2fa', '');
        return in_array($m, ['0', '1', '2'], true) ? $m : (cfg('require_admin_2fa', true) ? '1' : '0');
    }

    public static function twoFaRequired(array $u): bool
    {
        $m = self::twoFaMode();
        return $m === '2' || ($m === '1' && (bool)$u['is_admin']);
    }

    private const IDLE = 604800;     // 7 days idle timeout
    private const ABSOLUTE = 2592000; // 30 days hard limit
    private static ?array $user = null;
    private static bool $loaded = false;

    // ---- Passwords ------------------------------------------------------

    public static function hash(string $pw): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        return password_hash($pw, $algo);
    }

    /** Returns an error string or null when the password is acceptable. */
    public static function policy(string $pw, string $username = ''): ?string
    {
        if (mb_strlen($pw) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (mb_strlen($pw) > 200) {
            return 'Password is too long.';
        }
        if ($username !== '' && stripos($pw, $username) !== false) {
            return 'Password must not contain your username.';
        }
        if (!preg_match('/[a-z]/', $pw) || !preg_match('/[A-Z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Password needs upper-case, lower-case and a number.';
        }
        return null;
    }

    // ---- Login flow -----------------------------------------------------

    /** @return string ok | 2fa | fail | locked */
    public static function attempt(string $login, string $password): string
    {
        $login = trim($login);
        $ip = Security::ip();
        $acctKey = 'login:acct:' . hash('sha256', strtolower($login));
        if (!Security::hit('login:ip:' . $ip, 60, 900) || !Security::hit($acctKey, 8, 900)) {
            Audit::log('auth.locked', $login, [], null);
            return 'locked';
        }

        $user = DB::one('SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?)', [$login, $login]);
        // Always run a verify so timing doesn't reveal whether the account exists.
        $hash = $user['password'] ?? self::dummyHash();
        $valid = password_verify($password, $hash) && $user && !$user['disabled'];
        if (!$valid) {
            Audit::log('auth.failed', $login, [], $user['id'] ?? null);
            return 'fail';
        }
        if (password_needs_rehash($user['password'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            DB::q('UPDATE users SET password = ? WHERE id = ?', [self::hash($password), $user['id']]);
        }
        Security::clearHits($acctKey);

        if ($user['totp_enabled']) {
            session_regenerate_id(true);
            $_SESSION['pending'] = ['uid' => (int)$user['id'], 'exp' => time() + 300, 'tries' => 0];
            return '2fa';
        }
        self::complete($user);
        return 'ok';
    }

    private static function dummyHash(): string
    {
        static $h = null;
        return $h ??= self::hash(bin2hex(random_bytes(8)));
    }

    public static function pendingUser(): ?array
    {
        $p = $_SESSION['pending'] ?? null;
        if (!$p || $p['exp'] < time()) {
            unset($_SESSION['pending']);
            return null;
        }
        return DB::one('SELECT * FROM users WHERE id = ? AND disabled = 0', [$p['uid']]);
    }

    public static function verifySecondFactor(string $code): bool
    {
        $user = self::pendingUser();
        if (!$user || ++$_SESSION['pending']['tries'] > 5 || !Security::hit('2fa:' . $user['id'], 10, 900)) {
            unset($_SESSION['pending']);
            return false;
        }
        $ok = false;
        $step = Totp::verify(Security::decrypt($user['totp_secret']), $code, (int)$user['totp_last_step']);
        if ($step !== null) {
            DB::q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
            $ok = true;
        } elseif (self::consumeRecoveryCode($user, $code)) {
            Audit::log('auth.recovery_code_used', $user['username'], [], (int)$user['id']);
            $ok = true;
        }
        if (!$ok) {
            Audit::log('auth.2fa_failed', $user['username'], [], (int)$user['id']);
            return false;
        }
        unset($_SESSION['pending']);
        self::complete($user);
        return true;
    }

    private static function complete(array $user): void
    {
        session_regenerate_id(true);
        $token = bin2hex(random_bytes(32));
        $now = time();
        DB::insert('user_sessions', [
            'user_id' => $user['id'],
            'token_hash' => hash('sha256', $token),
            'ip' => Security::ip(),
            'ua' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'created_at' => $now,
            'last_seen' => $now,
        ]);
        $_SESSION['uid'] = (int)$user['id'];
        $_SESSION['stoken'] = $token;
        $_SESSION['ua'] = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Audit::log('auth.login', $user['username'], [], (int)$user['id']);
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['stoken'])) {
            DB::q('DELETE FROM user_sessions WHERE token_hash = ?', [hash('sha256', $_SESSION['stoken'])]);
        }
        Audit::log('auth.logout');
        $_SESSION = [];
        session_destroy();
        self::$user = null;
    }

    // ---- Current user ---------------------------------------------------

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        if (empty($_SESSION['uid']) || empty($_SESSION['stoken'])) {
            return null;
        }
        $now = time();
        $s = DB::one('SELECT * FROM user_sessions WHERE token_hash = ? AND user_id = ?',
            [hash('sha256', $_SESSION['stoken']), $_SESSION['uid']]);
        $uaOk = hash_equals($_SESSION['ua'] ?? '', hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!$s || !$uaOk || $now - (int)$s['last_seen'] > self::IDLE || $now - (int)$s['created_at'] > self::ABSOLUTE) {
            if ($s) {
                DB::q('DELETE FROM user_sessions WHERE id = ?', [$s['id']]);
            }
            $_SESSION = [];
            return null;
        }
        $u = DB::one('SELECT * FROM users WHERE id = ? AND disabled = 0', [$_SESSION['uid']]);
        if (!$u) {
            return null;
        }
        if ($now - (int)$s['last_seen'] > 60) {
            DB::q('UPDATE user_sessions SET last_seen = ? WHERE id = ?', [$now, $s['id']]);
        }
        $u['session_id'] = (int)$s['id'];
        return self::$user = $u;
    }

    public static function revokeOtherSessions(int $userId, int $keepId): void
    {
        DB::q('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?', [$userId, $keepId]);
    }

    // ---- Two-factor helpers ---------------------------------------------

    /** @return string[] plain codes (shown once). */
    public static function newRecoveryCodes(int $userId): array
    {
        $plain = $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $c = strtolower(bin2hex(random_bytes(5)));
            $c = substr($c, 0, 5) . '-' . substr($c, 5);
            $plain[] = $c;
            $hashes[] = Security::hmac($c);
        }
        DB::q('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode($hashes), $userId]);
        return $plain;
    }

    private static function consumeRecoveryCode(array $user, string $input): bool
    {
        $input = strtolower(trim($input));
        if (!preg_match('/^[a-f0-9]{5}-[a-f0-9]{5}$/', $input)) {
            return false;
        }
        $list = json_decode((string)$user['recovery_codes'], true) ?: [];
        $h = Security::hmac($input);
        foreach ($list as $i => $stored) {
            if (hash_equals($stored, $h)) {
                unset($list[$i]);
                DB::q('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode(array_values($list)), $user['id']]);
                return true;
            }
        }
        return false;
    }

    public static function checkPassword(array $user, string $pw): bool
    {
        return password_verify($pw, $user['password']);
    }
}
