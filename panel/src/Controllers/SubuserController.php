<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DB;
use App\ServerService;

/** Lets an owner share a server with other accounts, each with only the permissions ticked. */
final class SubuserController extends Controller
{
    public function page(array $p): void
    {
        $s = $this->server($p);
        $this->view('servers/subusers', [
            'title' => $s['name'] . ' – Users', 's' => $s, 'tab' => 'subusers',
            'subs' => DB::all('SELECT su.id, su.perms, u.username, u.email FROM server_subusers su JOIN users u ON u.id = su.user_id WHERE su.server_id = ? ORDER BY u.username', [$s['id']]),
        ]);
    }

    public function add(array $p): void
    {
        $s = $this->server($p);
        $who = $this->in('who');
        $u = $who === '' ? null : DB::one('SELECT id, username FROM users WHERE (username = ? OR email = ?) AND disabled = 0', [$who, $who]);
        $perms = array_values(array_intersect(array_keys(ServerService::SUBPERMS), array_filter((array)($_POST['perms'] ?? []), 'is_string')));
        if (!$u || (int)$u['id'] === (int)$s['owner_id']) {
            flash('error', 'No other active account matches that username or email.');
        } elseif (!$perms) {
            flash('error', 'Tick at least one permission.');
        } else {
            DB::q('DELETE FROM server_subusers WHERE server_id = ? AND user_id = ?', [$s['id'], $u['id']]);
            DB::insert('server_subusers', ['server_id' => $s['id'], 'user_id' => $u['id'], 'perms' => json_encode($perms), 'created_at' => time()]);
            Audit::log('server.subuser.set', $s['uuid'], ['user' => $u['username']]);
            flash('ok', $u['username'] . ' now has access.');
        }
        redirect('/server/' . $s['uuid'] . '/subusers');
    }

    public function remove(array $p): void
    {
        $s = $this->server($p);
        DB::q('DELETE FROM server_subusers WHERE id = ? AND server_id = ?', [(int)($p['id'] ?? 0), $s['id']]);
        Audit::log('server.subuser.remove', $s['uuid']);
        flash('ok', 'Access removed.');
        redirect('/server/' . $s['uuid'] . '/subusers');
    }
}
