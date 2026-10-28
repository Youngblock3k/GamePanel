<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DaemonClient;
use App\DaemonException;
use App\DB;

final class MonitoringController extends Controller
{
    public function page(): void
    {
        \App\NodeSchema::ensure();
        $nodes = DB::all('SELECT n.*, l.short AS location,
                (SELECT COUNT(*) FROM servers s WHERE s.node_id = n.id) AS servers,
                COALESCE((SELECT SUM(memory) FROM servers WHERE node_id = n.id), 0) AS used_mem,
                COALESCE((SELECT SUM(disk) FROM servers WHERE node_id = n.id), 0) AS used_disk
            FROM nodes n JOIN locations l ON l.id = n.location_id ORDER BY n.name');
        $alerts = [];
        $online = 0;
        $running = 0;
        foreach ($nodes as &$n) {
            $n['sys'] = null;
            try {
                $n['sys'] = DaemonClient::ok($n, 'GET', '/api/system', null, 3);
                $online++;
                $running += (int)($n['sys']['running'] ?? 0);
            } catch (DaemonException) {
                $alerts[] = ['bad', $n['name'] . ' is not reachable.'];
            }
            $capM = $n['memory'] * (100 + (int)$n['mem_over']) / 100;
            $capD = $n['disk'] * (100 + (int)$n['disk_over']) / 100;
            if ($n['used_mem'] > $capM) {
                $alerts[] = ['warn', $n['name'] . ': memory is allocated past its limit (' . fmt_mb($n['used_mem']) . ' of ' . fmt_mb($capM) . ').'];
            }
            if ($n['used_disk'] > $capD) {
                $alerts[] = ['warn', $n['name'] . ': disk is allocated past its limit.'];
            } elseif ($capD > 0 && ($capD - $n['used_disk']) / $capD < 0.1) {
                $alerts[] = ['warn', $n['name'] . ': less than 10% of its disk is left to allocate.'];
            }
            if (!empty($n['maintenance'])) {
                $alerts[] = ['dim', $n['name'] . ' is in maintenance mode.'];
            }
        }
        unset($n);
        $rows = [];
        foreach (DB::all('SELECT s.id, s.uuid, s.name, s.memory, s.cpu, s.disk, s.status, s.node_id, a.port FROM servers s JOIN allocations a ON a.id = s.allocation_id ORDER BY s.name LIMIT 60') as $sv) {
            $node = null;
            foreach ($nodes as $nn) {
                if ((int)$nn['id'] === (int)$sv['node_id']) {
                    $node = $nn;
                }
            }
            $sv['res'] = null;
            if ($node && $node['sys'] && count(array_filter($rows, static fn($r) => $r['res'] !== null && $r['node_id'] === $sv['node_id'])) < 12) {
                try {
                    $sv['res'] = DaemonClient::ok($node, 'GET', '/api/servers/' . $sv['uuid'] . '/stats?disk=' . (int)$sv['disk'], null, 4);
                } catch (DaemonException) {
                    $sv['res'] = null;
                }
            }
            $sv['node_cores'] = (int)($node['sys']['host']['cpus'] ?? 1);
            $rows[] = $sv;
        }
        $admins = (int)DB::val('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND totp_enabled = 0');
        if ($admins > 0) {
            $alerts[] = ['warn', $admins . ' administrator account(s) have no two-factor authentication.'];
        }
        $failed = (int)DB::val("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'auth.%fail%' AND created_at > ?", [time() - 86400]);
        $php = \App\PhpGuard::status();
        if (!$php['ok']) {
            $alerts[] = ['bad', $php['msg']];
        }
        $mem = $load = $up = null;
        if (is_readable('/proc/meminfo')) {
            $m = (string)file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $m, $a) && preg_match('/MemAvailable:\s+(\d+)/', $m, $b)) {
                $mem = [(int)(($a[1] - $b[1]) / 1024), (int)($a[1] / 1024)];
            }
        }
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
        }
        if (is_readable('/proc/uptime')) {
            $up = (int)explode(' ', (string)file_get_contents('/proc/uptime'))[0];
        }
        $dir = dirname(__DIR__, 2);
        $this->view('admin/monitoring', [
            'title' => 'Monitoring', 'nodes' => $nodes, 'srv' => $rows, 'hw' => \App\Hardware::local(), 'alerts' => $alerts, 'online' => $online, 'running' => $running,
            'servers' => (int)DB::val('SELECT COUNT(*) FROM servers'), 'suspended' => (int)DB::val("SELECT COUNT(*) FROM servers WHERE status = 'suspended'"),
            'failed' => $failed, 'users' => (int)DB::val('SELECT COUNT(*) FROM users'), 'dbs' => $this->countDbs(),
            'panel' => [
                'php' => PHP_VERSION, 'db' => DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME), 'mem' => $mem, 'load' => $load, 'up' => $up,
                'disk' => [(int)((disk_total_space($dir) - disk_free_space($dir)) / 1048576), (int)(disk_total_space($dir) / 1048576)],
                'version' => \App\Brand\Seal::VERSION,
            ],
        ]);
    }

    private function countDbs(): int
    {
        try {
            return (int)DB::val('SELECT COUNT(*) FROM server_dbs');
        } catch (\Throwable) {
            return 0;
        }
    }
}
