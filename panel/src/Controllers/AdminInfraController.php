<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\DaemonClient;
use App\DaemonException;
use App\DB;
use App\Security;
use App\ServerService;

final class AdminInfraController extends Controller
{
    // ---- Nodes ----------------------------------------------------------

    public function nodes(): void
    {
        \App\NodeSchema::ensure();
        $this->view('admin/nodes', [
            'title' => 'Nodes',
            'nodes' => DB::all('SELECT n.*, l.short AS location, (SELECT COUNT(*) FROM servers s WHERE s.node_id = n.id) AS servers FROM nodes n JOIN locations l ON l.id = n.location_id ORDER BY n.name'),
            'locations' => DB::all('SELECT id, short FROM locations ORDER BY short'),
        ]);
    }

    public function nodeNew(): void
    {
        \App\NodeSchema::ensure();
        $this->view('admin/node_new', ['title' => 'New node', 'locations' => DB::all('SELECT id, short FROM locations ORDER BY short')]);
    }

    public function nodeCreate(): void
    {
        \App\NodeSchema::ensure();
        $fqdn = strtolower($this->in('fqdn'));
        $scheme = $this->in('scheme') === 'http' ? 'http' : 'https';
        $loc = (int)$this->in('location_id');
        $err = null;
        if (!preg_match('/^(?=.{1,190}$)([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $fqdn) && !filter_var($fqdn, FILTER_VALIDATE_IP)) {
            $err = 'Invalid FQDN / IP.';
        } elseif ($scheme === 'http' && !in_array($fqdn, ['localhost', '127.0.0.1'], true) && !cfg('allow_insecure_daemon', false)) {
            $err = 'Plain HTTP is only allowed for localhost. Use HTTPS (secrets and console data would otherwise travel unencrypted).';
        } elseif (!DB::one('SELECT id FROM locations WHERE id = ?', [$loc])) {
            $err = 'Choose a location.';
        } elseif ($this->in('name') === '' || (int)$this->in('memory') < 128 || (int)$this->in('disk') < 128) {
            $err = 'Name, memory and disk are required.';
        }
        if ($err) {
            flash('error', $err);
            redirect('/admin/nodes/new');
        }
        $secret = bin2hex(random_bytes(32));
        $id = DB::insert('nodes', [
            'uuid' => AdminController::uuid4(), 'name' => mb_substr($this->in('name'), 0, 100), 'location_id' => $loc,
            'fqdn' => $fqdn, 'scheme' => $scheme, 'daemon_port' => max(1, min(65535, (int)$this->in('daemon_port', '8443'))),
            'daemon_secret' => Security::encrypt($secret), 'memory' => (int)$this->in('memory'), 'disk' => (int)$this->in('disk'), 'created_at' => time(),
            'description' => mb_substr($this->in('description'), 0, 255), 'maintenance' => $this->in('maintenance') === '1' ? 1 : 0,
            'max_servers' => max(0, min(1000, (int)$this->in('max_servers'))),
            'mem_over' => max(0, min(100, (int)$this->in('mem_over'))), 'disk_over' => max(0, min(100, (int)$this->in('disk_over'))),
        ]);
        // Optional starting pool of game ports, created together with the node.
        $pip = $this->in('pool_ip');
        if ($pip !== '' && filter_var($pip, FILTER_VALIDATE_IP) && preg_match('/^(\\d{1,5})(?:-(\\d{1,5}))?$/', $this->in('pool_ports'), $pm)) {
            $from = (int)$pm[1];
            $to = (int)($pm[2] ?? $pm[1]);
            if ($from >= 1024 && $to <= 65535 && $to >= $from && $to - $from < 100) {
                for ($port = $from; $port <= $to; $port++) {
                    DB::insert('allocations', ['node_id' => $id, 'ip' => $pip, 'port' => $port]);
                }
            }
        }
        $_SESSION['new_node_secret'] = $secret; // shown exactly once
        Audit::log('admin.node.create', $fqdn);
        redirect('/admin/nodes/' . $id);
    }

    public function nodeShow(array $p): void
    {
        \App\NodeSchema::ensure();
        $node = DB::one('SELECT n.*, l.short AS location FROM nodes n JOIN locations l ON l.id = n.location_id WHERE n.id = ?', [(int)$p['id']]);
        if (!$node) {
            throw new \App\HttpException('Node not found', 404);
        }
        $tab = (string)($_GET['tab'] ?? 'overview');
        $tab = in_array($tab, ['overview', 'allocations', 'servers', 'settings', 'config'], true) ? $tab : 'overview';
        $secret = $_SESSION['new_node_secret'] ?? null;
        unset($_SESSION['new_node_secret']);
        if ($secret) {
            $tab = 'config';
        }
        $sys = null;
        $sysErr = null;
        try {
            $sys = DaemonClient::ok($node, 'GET', '/api/system', null, 5);
        } catch (DaemonException $e) {
            $sysErr = $e->getMessage();
        }
        $this->view('admin/node', [
            'title' => $node['name'], 'node' => $node, 'secret' => $secret, 'sys' => $sys, 'sysErr' => $sysErr, 'tab' => $tab,
            'allocations' => DB::all('SELECT a.*, s.name AS server_name, s.uuid AS server_uuid FROM allocations a LEFT JOIN servers s ON s.id = a.server_id WHERE a.node_id = ? ORDER BY a.port', [$node['id']]),
            'servers' => DB::all('SELECT s.id, s.name, s.uuid, s.memory, s.disk, s.status, u.username, a.port FROM servers s JOIN users u ON u.id = s.owner_id JOIN allocations a ON a.id = s.allocation_id WHERE s.node_id = ? ORDER BY s.name', [$node['id']]),
            'locations' => DB::all('SELECT id, short FROM locations ORDER BY short'),
            'used' => DB::one('SELECT COALESCE(SUM(memory),0) AS mem, COALESCE(SUM(disk),0) AS disk FROM servers WHERE node_id = ?', [$node['id']]),
            'panelIp' => (string)($_SERVER['SERVER_ADDR'] ?? ''),
        ]);
    }

    /** One click: register the machine the panel runs on, with measured memory/disk and a single starting port. */
    public function nodeLocal(): void
    {
        \App\NodeSchema::ensure();
        $existing = DB::one("SELECT id FROM nodes WHERE fqdn = '127.0.0.1' ORDER BY id LIMIT 1");
        if ($existing) {
            flash('ok', 'This machine is already a node.');
            redirect('/admin/nodes/' . (int)$existing['id']);
        }
        $mem = 4096;
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $m)) {
            $mem = max(1024, (int)($m[1] / 1024) - 1024);
        }
        $free = @disk_free_space(ROOT);
        $disk = $free ? max(2048, (int)($free / 1048576 * 0.8)) : 50000;
        $lid = DB::one("SELECT id FROM locations WHERE short = 'local'")['id'] ?? DB::insert('locations', ['short' => 'local', 'description' => 'This machine']);
        $secret = bin2hex(random_bytes(32));
        $id = DB::insert('nodes', [
            'uuid' => AdminController::uuid4(), 'name' => 'localhost', 'location_id' => $lid, 'fqdn' => '127.0.0.1', 'scheme' => 'http', 'daemon_port' => 8443,
            'daemon_secret' => Security::encrypt($secret), 'memory' => $mem, 'disk' => $disk, 'created_at' => time(), 'description' => 'The machine the panel runs on',
        ]);
        DB::insert('allocations', ['node_id' => $id, 'ip' => '0.0.0.0', 'port' => 25565]);
        $_SESSION['new_node_secret'] = $secret;
        Audit::log('admin.node.create', 'localhost');
        flash('ok', 'Localhost node created. Install the daemon with the command below.');
        redirect('/admin/nodes/' . $id);
    }

    public function nodeUpdate(array $p): void
    {
        \App\NodeSchema::ensure();
        $n = DB::one('SELECT * FROM nodes WHERE id = ?', [(int)$p['id']]);
        $back = '/admin/nodes/' . (int)$p['id'] . '?tab=settings';
        if (!$n) {
            throw new \App\HttpException('Node not found', 404);
        }
        $fqdn = $this->in('fqdn');
        $scheme = $this->in('scheme') === 'http' ? 'http' : 'https';
        $port = (int)$this->in('daemon_port');
        $mem = (int)$this->in('memory');
        $disk = (int)$this->in('disk');
        $loc = DB::one('SELECT id FROM locations WHERE id = ?', [(int)$this->in('location_id')]);
        $err = null;
        if ($this->in('name') === '' || mb_strlen($this->in('name')) > 100 || !$loc) {
            $err = 'Give the node a name and a location.';
        } elseif (!preg_match('/^[A-Za-z0-9.\-]{1,191}$/', $fqdn) || $port < 1 || $port > 65535) {
            $err = 'Check the address and port.';
        } elseif ($scheme === 'http' && !in_array($fqdn, ['localhost', '127.0.0.1'], true)) {
            $err = 'Plain http is only allowed for localhost.';
        } elseif ($mem < 128 || $disk < 128) {
            $err = 'Memory and disk must be at least 128 MB.';
        }
        if ($err) {
            flash('error', $err);
            redirect($back);
        }
        DB::q('UPDATE nodes SET name = ?, description = ?, location_id = ?, fqdn = ?, scheme = ?, daemon_port = ?, memory = ?, disk = ?, mem_over = ?, disk_over = ?, max_servers = ? WHERE id = ?', [
            mb_substr($this->in('name'), 0, 100), mb_substr($this->in('description'), 0, 255), $loc['id'], $fqdn, $scheme, $port, $mem, $disk,
            max(0, min(100, (int)$this->in('mem_over'))), max(0, min(100, (int)$this->in('disk_over'))), max(0, (int)$this->in('max_servers')), $n['id'],
        ]);
        Audit::log('admin.node.update', (string)$n['id']);
        flash('ok', 'Node settings saved.');
        redirect($back);
    }

    public function nodeRotate(array $p): void
    {
        $id = (int)$p['id'];
        if (!$this->requirePassword()) {
            redirect('/admin/nodes/' . $id . '?tab=config');
        }
        $secret = bin2hex(random_bytes(32));
        DB::q('UPDATE nodes SET daemon_secret = ? WHERE id = ?', [Security::encrypt($secret), $id]);
        $_SESSION['new_node_secret'] = $secret;
        Audit::log('admin.node.rotate_secret', (string)$id);
        flash('ok', 'New daemon secret generated. Update the node with it.');
        redirect('/admin/nodes/' . $id);
    }

    public function allocationDelete(array $p): void
    {
        $nid = (int)$p['id'];
        $a = DB::one('SELECT * FROM allocations WHERE id = ? AND node_id = ?', [(int)$p['aid'], $nid]);
        if ($a && $a['server_id'] === null) {
            DB::q('DELETE FROM allocations WHERE id = ?', [$a['id']]);
            Audit::log('admin.allocation.delete', $a['ip'] . ':' . $a['port']);
            flash('ok', 'Port removed.');
        } else {
            flash('error', 'That port is in use by a server and cannot be removed.');
        }
        redirect('/admin/nodes/' . $nid . '?tab=allocations');
    }

    public function allocationsAdd(array $p): void
    {
        $nid = (int)$p['id'];
        $ip = $this->in('ip');
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $this->in('ports'), $m)) {
            flash('error', 'Enter a valid IP and a port or range like 25565-25570.');
            redirect('/admin/nodes/' . $nid);
        }
        $from = (int)$m[1];
        $to = (int)($m[2] ?? $m[1]);
        if ($from < 1024 || $to > 65535 || $to < $from || $to - $from >= 100) {
            flash('error', 'Ports must be 1024-65535, at most 100 at a time.');
            redirect('/admin/nodes/' . $nid . '?tab=allocations');
        }
        for ($port = $from; $port <= $to; $port++) {
            if (!DB::one('SELECT id FROM allocations WHERE node_id = ? AND ip = ? AND port = ?', [$nid, $ip, $port])) {
                DB::insert('allocations', ['node_id' => $nid, 'ip' => $ip, 'port' => $port]);
            }
        }
        Audit::log('admin.allocations.add', "$ip:$from-$to");
        redirect('/admin/nodes/' . $nid . '?tab=allocations');
    }

    public function nodeMaintenance(array $p): void
    {
        \App\NodeSchema::ensure();
        $n = DB::one('SELECT id, maintenance FROM nodes WHERE id = ?', [(int)$p['id']]);
        if ($n) {
            DB::q('UPDATE nodes SET maintenance = ? WHERE id = ?', [$n['maintenance'] ? 0 : 1, $n['id']]);
            Audit::log('admin.node.maintenance', (string)$n['id']);
            flash('ok', $n['maintenance'] ? 'Maintenance mode off.' : 'Maintenance mode on: no new servers will be placed here.');
        }
        redirect('/admin/nodes/' . (int)$p['id']);
    }

    public function nodeDelete(array $p): void
    {
        $nid = (int)$p['id'];
        if (!$this->requirePassword()) {
            redirect('/admin/nodes/' . $nid);
        }
        if (DB::val('SELECT COUNT(*) FROM servers WHERE node_id = ?', [$nid])) {
            flash('error', 'Node still has servers.');
            redirect('/admin/nodes/' . $nid);
        }
        DB::q('DELETE FROM allocations WHERE node_id = ?', [$nid]);
        DB::q('DELETE FROM nodes WHERE id = ?', [$nid]);
        Audit::log('admin.node.delete', (string)$nid);
        redirect('/admin/nodes');
    }

    // ---- Servers --------------------------------------------------------

    public function servers(): void
    {
        $this->view('admin/servers', [
            'title' => 'Servers',
            'servers' => DB::all('SELECT s.uuid, s.id, s.name, s.status, s.memory, s.disk, u.username, n.name AS node_name, a.ip, a.port, n.fqdn, e.name AS egg_name
                FROM servers s JOIN users u ON u.id = s.owner_id JOIN nodes n ON n.id = s.node_id JOIN allocations a ON a.id = s.allocation_id JOIN eggs e ON e.id = s.egg_id ORDER BY s.id DESC'),
            'users' => DB::all('SELECT id, username FROM users WHERE disabled = 0 ORDER BY username'),
            'nodes' => DB::all('SELECT id, name FROM nodes ORDER BY name'),
            'eggs' => DB::all('SELECT id, name, variables FROM eggs ORDER BY name'),
            'free' => DB::all('SELECT id, node_id, ip, port FROM allocations WHERE server_id IS NULL ORDER BY node_id, port'),
        ]);
    }

    public function checkVersion(): void
    {
        $err = \App\MinecraftVersions::check($this->in('type'), $this->in('v'));
        $this->json($err ? ['ok' => false, 'error' => $err] : ['ok' => true]);
    }

    public function serverNew(): void
    {
        \App\NodeSchema::ensure();
        $nodes = DB::all('SELECT n.id, n.name, n.memory, n.disk, n.maintenance, n.mem_over, n.disk_over, l.short AS loc,
                COALESCE((SELECT SUM(memory) FROM servers WHERE node_id = n.id), 0) AS used_mem,
                COALESCE((SELECT SUM(disk) FROM servers WHERE node_id = n.id), 0) AS used_disk
            FROM nodes n LEFT JOIN locations l ON l.id = n.location_id ORDER BY n.name');
        $this->view('admin/server_new', [
            'title' => 'New server',
            'users' => DB::all('SELECT id, username FROM users WHERE disabled = 0 ORDER BY username'),
            'nodes' => $nodes,
            'eggs' => DB::all('SELECT id, name, preset_key, category, description, variables FROM eggs ORDER BY category, name'),
            'free' => DB::all('SELECT id, node_id, ip, port FROM allocations WHERE server_id IS NULL ORDER BY node_id, port'),
        ]);
    }

    /** Picks the port for a new server: a listed free port, the first free one, or a custom number (created on the fly). */
    private function pickAllocation(array $node): array
    {
        $mode = $this->in('allocation_id', 'auto');
        if ($mode !== 'auto' && $mode !== 'custom') {
            return [DB::one('SELECT * FROM allocations WHERE id = ? AND node_id = ? AND server_id IS NULL', [(int)$mode, $node['id']]), null];
        }
        $ip = (string)(DB::val('SELECT ip FROM allocations WHERE node_id = ? ORDER BY id LIMIT 1', [$node['id']]) ?: '0.0.0.0');
        if ($mode === 'auto') {
            $a = DB::one('SELECT * FROM allocations WHERE node_id = ? AND server_id IS NULL ORDER BY port LIMIT 1', [$node['id']]);
            if ($a) {
                return [$a, null];
            }
            $port = max(25565, 1 + (int)DB::val('SELECT COALESCE(MAX(port), 25564) FROM allocations WHERE node_id = ?', [$node['id']]));
        } else {
            $port = (int)$this->in('custom_port');
            if ($port < 1024 || $port > 65535) {
                return [null, 'Custom port must be between 1024 and 65535.'];
            }
        }
        $a = DB::one('SELECT * FROM allocations WHERE node_id = ? AND port = ? ORDER BY id LIMIT 1', [$node['id'], $port]);
        if ($a && $a['server_id'] !== null) {
            return [null, 'Port ' . $port . ' is already used by another server on that node.'];
        }
        if (!$a) {
            $id = DB::insert('allocations', ['node_id' => $node['id'], 'ip' => $ip, 'port' => $port, 'server_id' => null]);
            $a = DB::one('SELECT * FROM allocations WHERE id = ?', [$id]);
        }
        return [$a, null];
    }

    public function serverCreate(): void
    {
        \App\NodeSchema::ensure();
        $owner = DB::one('SELECT * FROM users WHERE id = ? AND disabled = 0', [(int)$this->in('owner_id')]);
        $node = DB::one('SELECT * FROM nodes WHERE id = ?', [(int)$this->in('node_id')]);
        $egg = DB::one('SELECT * FROM eggs WHERE id = ?', [(int)$this->in('egg_id')]);
        $alloc = null;
        $portErr = null;
        if ($node) {
            [$alloc, $portErr] = $this->pickAllocation($node);
        }
        $mem = (int)$this->in('memory');
        $cpu = (int)$this->in('cpu');
        $disk = (int)$this->in('disk');
        $name = mb_substr($this->in('name'), 0, 100);
        $err = null;
        if ($portErr) {
            $err = $portErr;
        } elseif (!$owner || !$node || !$egg || !$alloc || $name === '') {
            $err = 'Please fill in a name, owner, game and node.';
        } elseif ($mem < 64 || $disk < 128 || $cpu < 0 || $cpu > 6400) {
            $err = 'Memory ≥ 64 MB, disk ≥ 128 MB, CPU 0-6400 (%).';
        } else {
            $used = DB::one('SELECT COUNT(*) AS c, COALESCE(SUM(memory),0) AS m, COALESCE(SUM(disk),0) AS d FROM servers WHERE node_id = ?', [$node['id']]);
            $capM = $node['memory'] * (100 + (int)($node['mem_over'] ?? 0)) / 100;
            $capD = $node['disk'] * (100 + (int)($node['disk_over'] ?? 0)) / 100;
            if (!empty($node['maintenance'])) {
                $err = 'That node is in maintenance mode.';
            } elseif (!empty($node['max_servers']) && $used['c'] >= $node['max_servers']) {
                $err = 'That node has reached its server limit.';
            } elseif ($used['m'] + $mem > $capM || $used['d'] + $disk > $capD) {
                $err = 'Node does not have enough unallocated memory/disk.';
            }
        }
        $env = [];
        if (!$err) {
            [$env, $err] = ServerService::validateEnv($egg, $_POST['env'] ?? [], [], true);
        }
        if ($err) {
            flash('error', $err);
            redirect('/admin/servers/new');
        }

        $uuid = AdminController::uuid4();
        $id = DB::insert('servers', [
            'uuid' => $uuid, 'name' => $name, 'description' => mb_substr($this->in('description'), 0, 255), 'owner_id' => $owner['id'],
            'node_id' => $node['id'], 'egg_id' => $egg['id'], 'allocation_id' => $alloc['id'], 'memory' => $mem, 'cpu' => $cpu,
            'disk' => $disk, 'env' => json_encode($env), 'status' => 'installing', 'created_at' => time(), 'oom_kill' => $this->in('oom_kill') === '1' ? 1 : 0,
        ]);
        DB::q('UPDATE allocations SET server_id = ? WHERE id = ?', [$id, $alloc['id']]);

        $s = ServerService::find($uuid, ['is_admin' => 1, 'id' => 0]);
        try {
            DaemonClient::ok($node, 'POST', '/api/servers', ['server' => ServerService::payload($s), 'install' => ServerService::installPayload($s)], 30);
        } catch (DaemonException $e) {
            DB::q('UPDATE allocations SET server_id = NULL WHERE id = ?', [$alloc['id']]);
            DB::q('DELETE FROM servers WHERE id = ?', [$id]);
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'Node unreachable')) {
                $msg = 'The daemon on "' . $node['name'] . '" (' . $node['fqdn'] . ':' . (int)$node['daemon_port'] . ') is not running. Install it from the node\'s Configuration tab'
                    . (in_array($node['fqdn'], ['127.0.0.1', 'localhost'], true) ? ', or for testing without Docker start the simulator: php -S 127.0.0.1:8443 daemon/dev/mock.php' : '') . '.';
            }
            flash('error', $msg);
            redirect('/admin/servers/new');
        }
        Audit::log('admin.server.create', $uuid, ['name' => $name]);
        flash('ok', 'Server created and installing on the node.');
        redirect('/admin/servers');
    }

    public function serverAction(): void
    {
        if (!$this->requirePassword()) {
            redirect('/admin/servers');
        }
        $s = ServerService::find($this->in('uuid'), $this->user());
        $act = $this->in('action');
        if ($act === 'suspend' || $act === 'unsuspend') {
            DB::q('UPDATE servers SET status = ? WHERE id = ?', [$act === 'suspend' ? 'suspended' : 'active', $s['id']]);
            if ($act === 'suspend') {
                try {
                    DaemonClient::ok(ServerService::node($s), 'POST', '/api/servers/' . $s['uuid'] . '/power', ['action' => 'kill'], 20);
                } catch (DaemonException) {
                }
            }
        } elseif ($act === 'delete') {
            try {
                DaemonClient::ok(ServerService::node($s), 'DELETE', '/api/servers/' . $s['uuid'], null, 60);
            } catch (DaemonException $e) {
                if ($this->in('force') !== '1') {
                    flash('error', 'Node error: ' . $e->getMessage() . ' — tick "force" to remove it from the panel anyway.');
                    redirect('/admin/servers');
                }
            }
            DB::q('UPDATE allocations SET server_id = NULL WHERE server_id = ?', [$s['id']]);
            DB::q('DELETE FROM servers WHERE id = ?', [$s['id']]);
        } else {
            flash('error', 'Unknown action.');
            redirect('/admin/servers');
        }
        Audit::log('admin.server.' . $act, $s['uuid']);
        flash('ok', "Server $act done.");
        redirect('/admin/servers');
    }
}
