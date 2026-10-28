<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Auth;
use App\DB;
use App\Security;
use App\Totp;

final class AccountController extends Controller
{
    public function index(): void
    {
        $this->view('account/index', ['title' => 'Settings']);
    }

    public function themePage(): void
    {
        $this->view('theme', ['title' => 'Theme', 'cards' => \App\Theme::cards(), 'cur' => \App\Theme::userTheme()]);
    }

    /** Each user picks their own theme from the presets; '' follows the panel's look. */
    public function themeSave(): void
    {
        $u = $this->user();
        $t = $this->in('theme');
        if ($t === '' || $t === 'default') {
            \App\Settings::forget('utheme:' . (int)$u['id']);
        } elseif (isset(\App\Theme::THEMES[$t])) {
            \App\Settings::set('utheme:' . (int)$u['id'], $t);
        }
        $back = $this->in('back', '/');
        redirect(preg_match('#^/[A-Za-z0-9/_\-?=&.]*$#', $back) && !str_starts_with($back, '//') ? $back : '/');
    }

    public function avatarSave(): void
    {
        $u = $this->user();
        if (!\App\Avatar::allowed($u)) {
            flash('error', 'Profile pictures are disabled by an administrator.');
            redirect('/account');
        }
        if ($this->in('remove') === '1') {
            \App\Avatar::remove($u['username']);
            flash('ok', 'Profile picture removed.');
            redirect('/account');
        }
        $err = \App\Avatar::store($u['username'], $_FILES['avatar'] ?? []);
        $err ? flash('error', $err) : flash('ok', 'Profile picture saved.');
        redirect('/account');
    }

    public function avatarShow(array $p): void
    {
        \App\Avatar::serve((string)$p['name']);
    }

    public function password(): void
    {
        $u = $this->user();
        if (!$this->requirePassword()) {
            redirect('/account');
        }
        $new = (string)($_POST['new_password'] ?? '');
        if ($new !== (string)($_POST['confirm_password'] ?? '')) {
            flash('error', 'Passwords do not match.');
            redirect('/account');
        }
        if ($err = Auth::policy($new, $u['username'])) {
            flash('error', $err);
            redirect('/account');
        }
        DB::q('UPDATE users SET password = ?, password_changed_at = ? WHERE id = ?', [Auth::hash($new), time(), $u['id']]);
        Auth::revokeOtherSessions((int)$u['id'], (int)$u['session_id']);
        Audit::log('account.password_changed', $u['username']);
        flash('ok', 'Password changed. All other sessions were signed out.');
        redirect('/account');
    }

    public function activity(): void
    {
        $rows = DB::all('SELECT action, target, ip, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$this->user()['id']]);
        $this->view('account/activity', ['title' => 'Activity', 'rows' => $rows]);
    }

    public function security(): void
    {
        $u = $this->user();
        $sessions = DB::all('SELECT id, ip, ua, created_at, last_seen FROM user_sessions WHERE user_id = ? ORDER BY last_seen DESC', [$u['id']]);
        $setup = null;
        if (!empty($_SESSION['totp_setup'])) {
            $secret = $_SESSION['totp_setup'];
            $setup = ['secret' => $secret, 'uri' => Totp::uri($secret, $u['email'])];
        }
        $codes = $_SESSION['new_recovery'] ?? null;
        unset($_SESSION['new_recovery']);
        $this->view('account/security', ['title' => 'Two-factor', 'sessions' => $sessions, 'setup' => $setup, 'codes' => $codes]);
    }

    public function twoFaStart(): void
    {
        if ($this->user()['totp_enabled']) {
            redirect('/account/security');
        }
        $_SESSION['totp_setup'] = Totp::generateSecret();
        redirect('/account/security');
    }

    public function twoFaEnable(): void
    {
        $u = $this->user();
        $secret = $_SESSION['totp_setup'] ?? null;
        if (!$secret || !Security::hit('2fa-setup:' . $u['id'], 10, 900)) {
            redirect('/account/security');
        }
        $step = Totp::verify($secret, $this->in('code'), 0);
        if ($step === null) {
            flash('error', 'That code is not valid. Check your authenticator and try again.');
            redirect('/account/security');
        }
        DB::q('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?', [Security::encrypt($secret), $step, $u['id']]);
        unset($_SESSION['totp_setup']);
        $_SESSION['new_recovery'] = Auth::newRecoveryCodes((int)$u['id']);
        Audit::log('account.2fa_enabled', $u['username']);
        flash('ok', 'Two-factor authentication enabled. Save your recovery codes now.');
        redirect('/account/security');
    }

    public function twoFaDisable(): void
    {
        $u = $this->user();
        if (!$this->requirePassword()) {
            redirect('/account/security');
        }
        if (Auth::twoFaRequired($u)) {
            flash('error', 'Two-factor authentication is required and cannot be disabled.');
            redirect('/account/security');
        }
        DB::q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL WHERE id = ?', [$u['id']]);
        Audit::log('account.2fa_disabled', $u['username']);
        flash('ok', 'Two-factor authentication disabled.');
        redirect('/account/security');
    }

    public function recoveryRegen(): void
    {
        $u = $this->user();
        if (!$u['totp_enabled'] || !$this->requirePassword()) {
            redirect('/account/security');
        }
        $_SESSION['new_recovery'] = Auth::newRecoveryCodes((int)$u['id']);
        Audit::log('account.recovery_regenerated', $u['username']);
        redirect('/account/security');
    }

    public function revokeSessions(): void
    {
        $u = $this->user();
        Auth::revokeOtherSessions((int)$u['id'], (int)$u['session_id']);
        Audit::log('account.sessions_revoked', $u['username']);
        flash('ok', 'All other sessions were signed out.');
        redirect('/account/security');
    }

    // ---- API keys -------------------------------------------------------

    public function apiKeys(): void
    {
        $u = $this->user();
        $keys = DB::all('SELECT id, identifier, memo, allowed_ips, last_used, created_at FROM api_keys WHERE user_id = ? ORDER BY id DESC', [$u['id']]);
        $new = $_SESSION['new_api_key'] ?? null;
        unset($_SESSION['new_api_key']);
        $this->view('account/api', ['title' => 'API keys', 'keys' => $keys, 'newKey' => $new]);
    }

    public function apiCreate(): void
    {
        $u = $this->user();
        if (!$this->requirePassword()) {
            redirect('/account/api');
        }
        $memo = mb_substr($this->in('memo'), 0, 100) ?: 'API key';
        $ips = array_filter(array_map('trim', explode(',', $this->in('allowed_ips'))));
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                flash('error', 'Invalid IP in allow-list: ' . $ip);
                redirect('/account/api');
            }
        }
        if ((int)DB::val('SELECT COUNT(*) FROM api_keys WHERE user_id = ?', [$u['id']]) >= 10) {
            flash('error', 'Maximum of 10 API keys.');
            redirect('/account/api');
        }
        $ident = bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(24));
        DB::insert('api_keys', [
            'user_id' => $u['id'], 'identifier' => $ident, 'token_hash' => hash('sha256', $secret),
            'memo' => $memo, 'allowed_ips' => $ips ? implode(',', $ips) : null, 'created_at' => time(),
        ]);
        $_SESSION['new_api_key'] = 'gp_' . $ident . '.' . $secret;
        Audit::log('account.api_key_created', $ident);
        redirect('/account/api');
    }

    public function apiDelete(): void
    {
        $u = $this->user();
        DB::q('DELETE FROM api_keys WHERE id = ? AND user_id = ?', [(int)$this->in('id'), $u['id']]);
        Audit::log('account.api_key_deleted', $this->in('id'));
        flash('ok', 'API key deleted.');
        redirect('/account/api');
    }
}
