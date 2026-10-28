<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Auth;
use App\Brand\Seal;
use App\Captcha;
use App\DB;
use App\Mailer;
use App\Security;

/** "Forgot password": single-use, 1-hour tokens, stored only as SHA-256 hashes. */
final class PasswordController extends Controller
{
    private const TTL = 3600;

    private static function ensureTable(): void
    {
        DB::pdo()->exec('CREATE TABLE IF NOT EXISTS password_resets (id ' . (cfg('db')['driver'] === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY')
            . ', user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)');
    }

    public function forgotForm(): void
    {
        $this->view('auth/forgot', ['title' => 'Forgot password', 'layout' => 'guest']);
    }

    public function forgot(): void
    {
        self::ensureTable();
        $generic = 'If an account with that e-mail exists, a reset link is on its way. It is valid for 1 hour.';
        if (!Captcha::verify((string)($_POST['g-recaptcha-response'] ?? ''))) {
            flash('error', 'Please complete the "I\'m not a robot" check.');
            redirect('/forgot');
        }
        $email = strtolower($this->in('email'));
        // Same answer whether or not the account exists, and throttled per IP and per address.
        if (!Security::hit('forgot:ip:' . Security::ip(), 6, 900) || !Security::hit('forgot:acct:' . hash('sha256', $email), 3, 900)) {
            flash('ok', $generic);
            redirect('/login');
        }
        $user = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? DB::one('SELECT * FROM users WHERE LOWER(email) = ? AND disabled = 0', [$email]) : null;
        if ($user) {
            $base = rtrim((string)cfg('app_url', ''), '/');
            if ($base === '') {
                error_log('[reset] app_url is not set in config.php; cannot build a reset link');
            } else {
                DB::q('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0', [$user['id']]);
                $token = bin2hex(random_bytes(32));
                DB::insert('password_resets', ['user_id' => $user['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => time() + self::TTL, 'used' => 0, 'created_at' => time()]);
                Mailer::send($user['email'], Seal::NAME . ' password reset',
                    "Hello {$user['username']},\n\nSomeone asked to reset the password for your " . Seal::NAME . " account.\n"
                    . "Open this link within 1 hour to choose a new password:\n\n$base/reset/$token\n\n"
                    . "If you did not ask for this, ignore this e-mail; your password stays the same.\n");
                Audit::log('auth.reset_requested', $user['username'], [], (int)$user['id']);
            }
        }
        flash('ok', $generic);
        redirect('/login');
    }

    private function token(array $p): ?array
    {
        self::ensureTable();
        $row = DB::one('SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > ?', [hash('sha256', (string)($p['token'] ?? '')), time()]);
        return $row ? (DB::one('SELECT * FROM users WHERE id = ? AND disabled = 0', [$row['user_id']]) ? $row : null) : null;
    }

    public function resetForm(array $p): void
    {
        if (!$this->token($p)) {
            flash('error', 'That reset link is invalid or has expired. Request a new one.');
            redirect('/forgot');
        }
        $this->view('auth/reset', ['title' => 'Choose a new password', 'layout' => 'guest', 'token' => $p['token']]);
    }

    public function reset(array $p): void
    {
        if (!Security::hit('reset:ip:' . Security::ip(), 10, 900)) {
            flash('error', 'Too many attempts. Try again later.');
            redirect('/login');
        }
        $row = $this->token($p);
        if (!$row) {
            flash('error', 'That reset link is invalid or has expired. Request a new one.');
            redirect('/forgot');
        }
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$row['user_id']]);
        $pw = (string)($_POST['new_password'] ?? '');
        $err = $pw !== (string)($_POST['confirm_password'] ?? '') ? 'Passwords do not match.' : Auth::policy($pw, $user['username']);
        if ($err) {
            flash('error', $err);
            redirect('/reset/' . $p['token']);
        }
        DB::q('UPDATE users SET password = ?, password_changed_at = ? WHERE id = ?', [Auth::hash($pw), time(), $user['id']]);
        DB::q('UPDATE password_resets SET used = 1 WHERE user_id = ?', [$user['id']]);
        DB::q('DELETE FROM user_sessions WHERE user_id = ?', [$user['id']]);   // sign out everywhere
        DB::q('DELETE FROM rate_limits WHERE k = ?', ['login:acct:' . hash('sha256', strtolower($user['username']))]);
        Audit::log('auth.password_reset', $user['username'], [], (int)$user['id']);
        flash('ok', 'Password changed. Sign in with your new password' . ($user['totp_enabled'] ? ' (your two-factor code is still required).' : '.'));
        redirect('/login');
    }
}
