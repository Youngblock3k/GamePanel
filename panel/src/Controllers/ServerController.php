<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DaemonClient;
use App\DaemonException;
use App\DB;
use App\ServerService;

final class ServerController extends Controller
{
    public function index(): void
    {
        $u = $this->user();
        $sql = 'SELECT s.uuid, s.name, s.status, s.memory, s.cpu, s.disk, a.ip, a.port, n.fqdn, n.name AS node_name
                FROM servers s JOIN allocations a ON a.id = s.allocation_id JOIN nodes n ON n.id = s.node_id';
        $servers = $u['is_admin'] && ($_GET['all'] ?? '') === '1'
            ? DB::all($sql . ' ORDER BY s.name')
            : DB::all($sql . ' WHERE s.owner_id = ? OR s.id IN (SELECT server_id FROM server_subusers WHERE user_id = ?) ORDER BY s.name', [$u['id'], $u['id']]);
        $this->view('servers/index', ['title' => 'Servers', 'servers' => $servers, 'showAll' => ($_GET['all'] ?? '') === '1']);
    }

    public function activity(array $p): void
    {
        $s = $this->server($p);
        $rows = DB::all('SELECT a.action, a.created_at, a.meta, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE a.target = ? ORDER BY a.id DESC LIMIT 60', [$s['uuid']]);
        $this->view('servers/activity', ['title' => $s['name'] . ' – Activity', 's' => $s, 'tab' => 'activity', 'rows' => $rows]);
    }

    public function console(array $p): void
    {
        $s = $this->server($p);
        $this->view('servers/console', ['title' => $s['name'], 's' => $s, 'tab' => 'console']);
    }

    /** Polled by the dashboard/console for live state + resource usage. */
    public function state(array $p): void
    {
        $s = $this->server($p);
        try {
            $node = ServerService::node($s);
            $info = DaemonClient::ok($node, 'GET', '/api/servers/' . $s['uuid']);
            $install = $info['install']['status'] ?? 'done';
            if ($s['status'] === 'installing' && $install !== 'installing') {
                DB::q('UPDATE servers SET status = ? WHERE id = ?', [$install === 'done' ? 'active' : 'install_failed', $s['id']]);
                $s['status'] = $install === 'done' ? 'active' : 'install_failed';
            }
            $stats = $info['state'] === 'running' || $info['state'] === 'exited' || $info['state'] === 'created'
                ? DaemonClient::ok($node, 'GET', '/api/servers/' . $s['uuid'] . '/stats?disk=' . (int)$s['disk'], null, 12)
                : ['state' => $info['state'], 'cpu' => 0, 'mem' => 0, 'mem_limit' => $s['memory'], 'rx' => 0, 'tx' => 0, 'disk' => 0, 'disk_limit' => $s['disk']];
            $this->json($stats + ['status' => $s['status'], 'install_error' => $info['install']['error'] ?? null]);
        } catch (DaemonException $e) {
            $this->json(['state' => 'offline', 'status' => $s['status'], 'error' => $e->getMessage()]);
        }
    }

    public function logs(array $p): void
    {
        $s = $this->server($p);
        try {
            $this->json(DaemonClient::ok(ServerService::node($s), 'GET', '/api/servers/' . $s['uuid'] . '/logs'));
        } catch (DaemonException $e) {
            $this->json(['logs' => '', 'error' => $e->getMessage()]);
        }
    }

    public function power(array $p): void
    {
        $s = $this->server($p);
        $action = $this->in('action');
        if ($s['status'] !== 'active') {
            $this->json(['error' => 'Server is ' . $s['status'] . '.'], 409);
        }
        if (!in_array($action, ['start', 'stop', 'restart', 'kill'], true)) {
            $this->json(['error' => 'Invalid action'], 422);
        }
        if (!\App\Security::hit('power:' . $this->user()['id'], 30, 60)) {
            $this->json(['error' => 'Slow down'], 429);
        }
        try {
            DaemonClient::ok(ServerService::node($s), 'POST', '/api/servers/' . $s['uuid'] . '/power',
                ['action' => $action, 'disk' => (int)$s['disk']], 60);
            Audit::log('server.power.' . $action, $s['uuid']);
            $this->json(['ok' => true]);
        } catch (DaemonException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    public function command(array $p): void
    {
        $s = $this->server($p);
        if (!\App\Security::hit('cmd:' . $this->user()['id'], 120, 60)) {
            $this->json(['error' => 'Slow down'], 429);
        }
        try {
            DaemonClient::ok(ServerService::node($s), 'POST', '/api/servers/' . $s['uuid'] . '/command', ['command' => $this->in('command')]);
            Audit::log('server.command', $s['uuid']);
            $this->json(['ok' => true]);
        } catch (DaemonException $e) {
            $this->json(['error' => $e->getMessage()], 502);
        }
    }

    public function settings(array $p): void
    {
        $s = $this->server($p);
        $this->view('servers/settings', [
            'title' => $s['name'] . ' – Settings', 's' => $s, 'tab' => 'settings',
            'vars' => json_decode($s['variables'], true) ?: [],
            'env' => json_decode($s['env'], true) ?: [],
        ]);
    }

    public function settingsSave(array $p): void
    {
        $s = $this->server($p);
        $u = $this->user();
        $name = mb_substr($this->in('name'), 0, 100);
        if ($name === '') {
            flash('error', 'Name is required.');
            redirect('/server/' . $s['uuid'] . '/settings');
        }
        [$env, $err] = ServerService::validateEnv($s, $_POST['env'] ?? [], json_decode($s['env'], true) ?: [], (bool)$u['is_admin']);
        if ($err) {
            flash('error', $err);
            redirect('/server/' . $s['uuid'] . '/settings');
        }
        $changedEnv = $env !== (json_decode($s['env'], true) ?: []);
        DB::q('UPDATE servers SET name = ?, description = ?, env = ? WHERE id = ?',
            [$name, mb_substr($this->in('description'), 0, 255), json_encode($env), $s['id']]);
        if ($changedEnv) {
            $s['env'] = json_encode($env);
            try {
                DaemonClient::ok(ServerService::node($s), 'PUT', '/api/servers/' . $s['uuid'], ['server' => ServerService::payload($s)], 60);
            } catch (DaemonException $e) {
                flash('error', 'Saved, but could not apply to the node: ' . $e->getMessage() . ' (stop the server first).');
                redirect('/server/' . $s['uuid'] . '/settings');
            }
        }
        Audit::log('server.settings', $s['uuid']);
        flash('ok', 'Settings saved.');
        redirect('/server/' . $s['uuid'] . '/settings');
    }
}
