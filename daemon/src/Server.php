<?php
declare(strict_types=1);

namespace Wings;

final class HttpError extends \RuntimeException
{
    public function __construct(string $msg, int $code = 400)
    {
        parent::__construct($msg, $code);
    }
}

/** Container lifecycle for one game server. All container names are gp_<uuid>. */
final class Server
{
    public static function validate(array $p): void
    {
        $ok = isset($p['uuid'], $p['image'], $p['startup'], $p['env'], $p['memory'], $p['cpu'], $p['disk'], $p['ip'], $p['port'])
            && is_string($p['uuid']) && preg_match('/^[a-f0-9\-]{36}$/', $p['uuid'])
            && is_string($p['image']) && preg_match('#^[a-z0-9][a-z0-9._/\-:@]{0,190}$#i', $p['image'])
            && is_string($p['startup']) && strlen($p['startup']) <= 1000
            && is_array($p['env'])
            && is_int($p['memory']) && $p['memory'] >= 32 && $p['memory'] <= 1048576
            && is_int($p['cpu']) && $p['cpu'] >= 0 && $p['cpu'] <= 6400
            && is_int($p['disk']) && $p['disk'] >= 0
            && filter_var($p['ip'], FILTER_VALIDATE_IP)
            && is_int($p['port']) && $p['port'] >= 1 && $p['port'] <= 65535;
        if (!$ok) {
            throw new HttpError('Invalid server definition', 422);
        }
        foreach ($p['env'] as $k => $v) {
            if (!preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', (string)$k) || !preg_match("/^[A-Za-z0-9 _.,:!+&()'\\-]{0,64}$/", (string)$v)) {
                throw new HttpError('Invalid environment', 422);
            }
        }
    }

    public static function name(string $uuid): string
    {
        if (!preg_match('/^[a-f0-9\-]{36}$/', $uuid)) {
            throw new HttpError('Invalid server id', 400);
        }
        return 'gp_' . $uuid;
    }

    public static function dir(array $cfg, string $uuid): string
    {
        self::name($uuid);
        return rtrim($cfg['data_dir'], '/') . '/' . $uuid;
    }

    /** Hardened container: no capabilities, no privilege gain, read-only rootfs, limited pids/memory/cpu. */
    public static function createContainer(Docker $d, array $cfg, array $p): void
    {
        $name = self::name($p['uuid']);
        $dir = self::dir($cfg, $p['uuid']);
        $d->ensureNetwork($cfg['network'], $cfg['network_subnet'] ?? '172.30.0.0/16');
        $env = ['HOME=/home/container', 'TZ=UTC'];
        foreach ($p['env'] as $k => $v) {
            $env[] = "$k=$v";
        }
        $port = (string)$p['port'];
        $binding = [['HostIp' => $p['ip'], 'HostPort' => $port]];
        $d->json('POST', '/containers/create?name=' . $name, [
            'Image' => $p['image'],
            'Hostname' => 'gamepanel',
            'User' => $cfg['container_uid'] . ':' . $cfg['container_gid'],
            'Tty' => true,
            'OpenStdin' => true,
            'StdinOnce' => false,
            'WorkingDir' => '/home/container',
            'Env' => $env,
            'Entrypoint' => ['/bin/sh', '-c'],
            'Cmd' => [$p['startup']],
            'ExposedPorts' => [$port . '/tcp' => new \stdClass(), $port . '/udp' => new \stdClass()],
            'Labels' => ['gamepanel' => '1', 'gamepanel.stop' => $p['stop_command'] ?? ''],
            'HostConfig' => [
                'Binds' => [$dir . ':/home/container:rw'],
                'PortBindings' => [$port . '/tcp' => $binding, $port . '/udp' => $binding],
                // --- isolation: the game process can only see its own folder, PIDs, IPC and network namespace ---
                'Privileged' => false,
                'Init' => true,
                'IpcMode' => 'private',
                'PidMode' => '',
                'UsernsMode' => '',
                'Dns' => ['1.1.1.1', '9.9.9.9'],
                'ShmSize' => 64 * 1048576,
                'OomScoreAdj' => 500,
                'Memory' => $p['memory'] * 1048576,
                'MemorySwap' => $p['memory'] * 1048576,
                // OOM killer on (default): going over the RAM limit stops the server. Off: the kernel is told not to kill it.
                'OomKillDisable' => empty($p['oom_kill']) && array_key_exists('oom_kill', $p),
                'NanoCpus' => $p['cpu'] > 0 ? (int)($p['cpu'] * 10000000) : 0,
                'PidsLimit' => 512,
                'CapDrop' => ['ALL'],
                'SecurityOpt' => ['no-new-privileges:true'],
                'ReadonlyRootfs' => true,
                'Tmpfs' => ['/tmp' => 'rw,nosuid,size=128m'],
                'NetworkMode' => $cfg['network'],
                'RestartPolicy' => ['Name' => 'no'],
                'LogConfig' => ['Type' => 'json-file', 'Config' => ['max-size' => '10m', 'max-file' => '1']],
                'Ulimits' => [['Name' => 'nofile', 'Soft' => 4096, 'Hard' => 4096]],
            ],
        ]);
    }

    public static function removeContainer(Docker $d, string $uuid): void
    {
        $d->raw('DELETE', '/containers/' . self::name($uuid) . '?force=true&v=true');
    }

    public static function state(Docker $d, string $uuid): array
    {
        $i = $d->inspect(self::name($uuid));
        if (!$i) {
            return ['state' => 'missing'];
        }
        return ['state' => $i['State']['Status'] ?? 'unknown', 'started_at' => $i['State']['StartedAt'] ?? null];
    }

    public static function stats(Docker $d, array $cfg, string $uuid, int $diskLimitMb): array
    {
        $st = self::state($d, $uuid);
        $out = $st + ['cpu' => 0.0, 'mem' => 0, 'mem_limit' => 0, 'rx' => 0, 'tx' => 0, 'disk' => 0, 'disk_limit' => $diskLimitMb];
        $out['disk'] = (int)(Files::dirSize(self::dir($cfg, $uuid)) / 1048576);
        if ($st['state'] !== 'running') {
            return $out;
        }
        [$code, $raw] = $d->raw('GET', '/containers/' . self::name($uuid) . '/stats?stream=false', null, 10);
        $s = $code === 200 ? json_decode($raw, true) : null;
        if (!$s) {
            return $out;
        }
        $cpuD = ($s['cpu_stats']['cpu_usage']['total_usage'] ?? 0) - ($s['precpu_stats']['cpu_usage']['total_usage'] ?? 0);
        $sysD = ($s['cpu_stats']['system_cpu_usage'] ?? 0) - ($s['precpu_stats']['system_cpu_usage'] ?? 0);
        $cpus = $s['cpu_stats']['online_cpus'] ?? 1;
        $out['cpu'] = $sysD > 0 ? round($cpuD / $sysD * $cpus * 100, 1) : 0.0;
        $cache = $s['memory_stats']['stats']['inactive_file'] ?? ($s['memory_stats']['stats']['cache'] ?? 0);
        $out['mem'] = (int)((($s['memory_stats']['usage'] ?? 0) - $cache) / 1048576);
        $out['mem_limit'] = (int)(($s['memory_stats']['limit'] ?? 0) / 1048576);
        foreach ($s['networks'] ?? [] as $n) {
            $out['rx'] += $n['rx_bytes'] ?? 0;
            $out['tx'] += $n['tx_bytes'] ?? 0;
        }
        return $out;
    }

    public static function sendCommand(string $uuid, string $command): void
    {
        $command = str_replace(["\r", "\n", "\0"], '', $command);
        if ($command === '' || strlen($command) > 512) {
            throw new HttpError('Invalid command', 422);
        }
        // Array form => no shell involved, so no injection into the daemon host.
        $proc = proc_open(['docker', 'attach', '--sig-proxy=false', self::name($uuid)],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new HttpError('Could not attach to container', 500);
        }
        fwrite($pipes[0], $command . "\n");
        usleep(300000);
        fclose($pipes[0]);
        proc_terminate($proc);
        proc_close($proc);
    }

    public static function power(Docker $d, array $cfg, string $uuid, string $action, int $diskLimitMb): void
    {
        $name = self::name($uuid);
        $i = $d->inspect($name) ?? throw new HttpError('Container does not exist yet', 409);
        if ($action === 'kill') {
            $d->raw('POST', "/containers/$name/kill");
            return;
        }
        if (in_array($action, ['stop', 'restart'], true) && ($i['State']['Running'] ?? false)) {
            $stopCmd = $i['Config']['Labels']['gamepanel.stop'] ?? '';
            if ($stopCmd !== '') {
                try {
                    self::sendCommand($uuid, $stopCmd);
                } catch (\Throwable) {
                }
            }
            $d->raw('POST', "/containers/$name/stop?t=30", null, 45);
        }
        if (in_array($action, ['start', 'restart'], true) && !($d->inspect($name)['State']['Running'] ?? false)) {
            if ($diskLimitMb > 0 && Files::dirSize(self::dir($cfg, $uuid)) / 1048576 > $diskLimitMb) {
                throw new HttpError('Disk limit exceeded; free up space before starting', 507);
            }
            [$code, $raw] = $d->raw('POST', "/containers/$name/start");
            if ($code >= 400) {
                throw new HttpError('Start failed: ' . (json_decode($raw, true)['message'] ?? $raw), 500);
            }
        }
    }

    /** Run by the background worker: pull, install, create container. */
    public static function provision(Docker $d, array $cfg, array $job): void
    {
        $p = $job['server'];
        $dir = self::dir($cfg, $p['uuid']);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new \RuntimeException('Cannot create data directory');
        }
        if (!empty($job['install'])) {
            $inst = $job['install'];
            $d->pull($inst['image']);
            $env = [];
            foreach ($p['env'] as $k => $v) {
                $env[] = "$k=$v";
            }
            $c = $d->json('POST', '/containers/create', [
                'Image' => $inst['image'],
                'User' => $cfg['container_uid'] . ':' . $cfg['container_gid'],
                'Entrypoint' => ['/bin/sh', '-c'],
                'Cmd' => [$inst['script']],
                'WorkingDir' => '/mnt/server',
                'Env' => $env,
                'HostConfig' => [
                    'Binds' => [$dir . ':/mnt/server:rw'],
                    'Memory' => 2048 * 1048576,
                    'MemorySwap' => 2048 * 1048576,
                    'PidsLimit' => 512,
                    'Privileged' => false,
                    'IpcMode' => 'private',
                    'CapDrop' => ['ALL'],
                    'SecurityOpt' => ['no-new-privileges:true'],
                ],
            ]);
            $id = $c['Id'];
            $d->raw('POST', "/containers/$id/start");
            $w = json_decode($d->raw('POST', "/containers/$id/wait", null, 10800)[1], true);
            $log = substr($d->raw('GET', "/containers/$id/logs?stdout=1&stderr=1&tail=60")[1], -1500);
            $d->raw('DELETE', "/containers/$id?force=true&v=true");
            if (($w['StatusCode'] ?? 1) !== 0) {
                throw new \RuntimeException('Install script failed: ' . preg_replace('/[^\x20-\x7e\n]/', '', $log));
            }
        }
        $d->pull($p['image']);
        self::removeContainer($d, $p['uuid']);
        self::createContainer($d, $cfg, $p);
    }
}
