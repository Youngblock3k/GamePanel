<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Auth;
use App\DB;

final class AdminController extends Controller
{
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    public function overview(): void
    {
        $this->view('admin/overview', [
            'title' => 'Admin overview',
            'counts' => [
                'Users' => DB::val('SELECT COUNT(*) FROM users'),
                'Servers' => DB::val('SELECT COUNT(*) FROM servers'),
                'Nodes' => DB::val('SELECT COUNT(*) FROM nodes'),
                'Locations' => DB::val('SELECT COUNT(*) FROM locations'),
                'Eggs' => DB::val('SELECT COUNT(*) FROM eggs'),
                'Users with 2FA' => DB::val('SELECT COUNT(*) FROM users WHERE totp_enabled = 1'),
            ],
            'recent' => DB::all('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8'),
        ]);
    }

    public function overviewPage(): void
    {
        $nodes = DB::all('SELECT n.id, n.name, n.memory, n.disk,
                COALESCE((SELECT SUM(memory) FROM servers WHERE node_id = n.id), 0) AS used_mem,
                COALESCE((SELECT SUM(disk) FROM servers WHERE node_id = n.id), 0) AS used_disk
            FROM nodes n ORDER BY n.name');
        $this->view('admin/administration', [
            'title' => 'Overview',
            'stats' => [
                'servers' => [(int)DB::val("SELECT COUNT(*) FROM servers WHERE status = 'active'"), (int)DB::val('SELECT COUNT(*) FROM servers')],
                'nodes' => (int)DB::val('SELECT COUNT(*) FROM nodes'),
                'users' => (int)DB::val('SELECT COUNT(*) FROM users'),
                'twofa' => [(int)DB::val('SELECT COUNT(*) FROM users WHERE totp_enabled = 1'), (int)DB::val('SELECT COUNT(*) FROM users')],
            ],
            'nodes' => $nodes,
        ]);
    }

    // ---- Users ----------------------------------------------------------

    public function users(): void
    {
        \App\Roles::ensure();
        $this->view('admin/users', ['title' => 'Users', 'roles' => \App\Roles::all(),
            'users' => DB::all('SELECT u.id, u.username, u.email, u.is_admin, u.disabled, u.totp_enabled, u.created_at, u.role_id, r.name AS role_name, r.color AS role_color FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id')]);
    }

    public function userNew(): void
    {
        \App\Roles::ensure();
        $this->view('admin/user_new', ['title' => 'New user', 'roles' => \App\Roles::all(), 'canGrant' => (bool)$this->user()['is_admin']]);
    }

    public function userCreate(): void
    {
        $username = $this->in('username');
        $email = strtolower($this->in('email'));
        $pw = (string)($_POST['password'] ?? '');
        $err = null;
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,32}$/', $username)) {
            $err = 'Username: 3-32 letters, numbers, . _ -';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            $err = 'Invalid email address.';
        } elseif (DB::one('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email])) {
            $err = 'Username or email already exists.';
        } else {
            $err = Auth::policy($pw, $username);
        }
        if ($err) {
            flash('error', $err);
            redirect('/admin/users/new');
        }
        $full = (bool)$this->user()['is_admin'];
        \App\Roles::ensure();
        $role = $full && $this->in('role_id') !== '' ? DB::one('SELECT id FROM roles WHERE id = ?', [(int)$this->in('role_id')]) : null;
        $id = DB::insert('users', [
            'uuid' => self::uuid4(), 'username' => $username, 'email' => $email, 'password' => Auth::hash($pw),
            'is_admin' => $full && $this->in('is_admin') === '1' ? 1 : 0, 'role_id' => $role['id'] ?? null, 'password_changed_at' => time(), 'created_at' => time(),
        ]);
        Audit::log('admin.user.create', $username, ['id' => $id]);
        flash('ok', "User $username created.");
        redirect('/admin/users');
    }

    public function userAction(): void
    {
        $me = $this->user();
        if (!$this->requirePassword()) {
            redirect('/admin/users');
        }
        $id = (int)$this->in('id');
        $t = DB::one('SELECT * FROM users WHERE id = ?', [$id]);
        $act = $this->in('action');
        if (!$t) {
            flash('error', 'User not found.');
            redirect('/admin/users');
        }
        if (!$me['is_admin'] && ($t['is_admin'] || in_array($act, ['promote', 'demote', 'set_role'], true))) {
            flash('error', 'Only administrators can do that.');
            redirect('/admin/users');
        }
        if ($id === (int)$me['id'] && in_array($act, ['delete', 'disable', 'demote'], true)) {
            flash('error', 'You cannot do that to your own account.');
            redirect('/admin/users');
        }
        switch ($act) {
            case 'promote':
                DB::q('UPDATE users SET is_admin = 1 WHERE id = ?', [$id]);
                break;
            case 'demote':
                DB::q('UPDATE users SET is_admin = 0 WHERE id = ?', [$id]);
                break;
            case 'set_role':
                \App\Roles::ensure();
                $role = $this->in('role_id') !== '' ? DB::one('SELECT id FROM roles WHERE id = ?', [(int)$this->in('role_id')]) : null;
                DB::q('UPDATE users SET role_id = ? WHERE id = ?', [$role['id'] ?? null, $id]);
                break;
            case 'disable':
                DB::q('UPDATE users SET disabled = 1 WHERE id = ?', [$id]);
                DB::q('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
                break;
            case 'enable':
                DB::q('UPDATE users SET disabled = 0 WHERE id = ?', [$id]);
                break;
            case 'reset_2fa':
                DB::q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL WHERE id = ?', [$id]);
                DB::q('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
                break;
            case 'delete':
                if (DB::val('SELECT COUNT(*) FROM servers WHERE owner_id = ?', [$id])) {
                    flash('error', 'Delete or reassign this user\'s servers first.');
                    redirect('/admin/users');
                }
                DB::q('DELETE FROM user_sessions WHERE user_id = ?', [$id]);
                DB::q('DELETE FROM api_keys WHERE user_id = ?', [$id]);
                DB::q('DELETE FROM users WHERE id = ?', [$id]);
                break;
            default:
                flash('error', 'Unknown action.');
                redirect('/admin/users');
        }
        Audit::log('admin.user.' . $act, $t['username']);
        flash('ok', "Done: $act on {$t['username']}.");
        redirect('/admin/users');
    }

    // ---- Locations ------------------------------------------------------

    public function locations(): void
    {
        $this->view('admin/locations', ['title' => 'Locations', 'locations' => DB::all(
            'SELECT l.*, (SELECT COUNT(*) FROM nodes n WHERE n.location_id = l.id) AS nodes FROM locations l ORDER BY l.short')]);
    }

    public function locationNew(): void
    {
        $this->view('admin/location_new', ['title' => 'New location']);
    }

    public function locationCreate(): void
    {
        $short = $this->in('short');
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,60}$/', $short) || DB::one('SELECT id FROM locations WHERE short = ?', [$short])) {
            flash('error', 'Invalid or duplicate location code.');
            redirect('/admin/locations/new');
        }
        DB::insert('locations', ['short' => $short, 'description' => mb_substr($this->in('description'), 0, 191)]);
        Audit::log('admin.location.create', $short);
        redirect('/admin/locations');
    }

    public function locationDelete(): void
    {
        $id = (int)$this->in('id');
        if (DB::val('SELECT COUNT(*) FROM nodes WHERE location_id = ?', [$id])) {
            flash('error', 'Location still has nodes.');
        } else {
            DB::q('DELETE FROM locations WHERE id = ?', [$id]);
            Audit::log('admin.location.delete', (string)$id);
        }
        redirect('/admin/locations');
    }

    // ---- Audit ----------------------------------------------------------

    public function audit(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $rows = DB::all('SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50));
        $this->view('admin/audit', ['title' => 'Audit log', 'rows' => $rows, 'page' => $page]);
    }
}
