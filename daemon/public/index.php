<?php
declare(strict_types=1);

namespace Wings;

require __DIR__ . '/../src/Docker.php';
require __DIR__ . '/../src/Files.php';
require __DIR__ . '/../src/Server.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfg = require __DIR__ . '/../config.php';
if (!is_dir($cfg['state_dir'])) {
    @mkdir($cfg['state_dir'], 0750, true);
}

function respond(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Verify IP allow-list, timestamp window, single-use nonce and HMAC signature. */
function authenticate(array $cfg, string $method, string $body): void
{
    if ($cfg['allowed_ips'] && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $cfg['allowed_ips'], true)) {
        respond(['error' => 'Forbidden'], 403);
    }
    $ts = $_SERVER['HTTP_X_GP_TS'] ?? '';
    $nonce = $_SERVER['HTTP_X_GP_NONCE'] ?? '';
    $sig = $_SERVER['HTTP_X_GP_SIG'] ?? '';
    if (!ctype_digit($ts) || abs(time() - (int)$ts) > 30 || !preg_match('/^[a-f0-9]{24}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        respond(['error' => 'Unauthorized'], 401);
    }
    $expected = hash_hmac('sha256', implode("\n", [$ts, $nonce, $method, $_SERVER['REQUEST_URI'], hash('sha256', $body)]), $cfg['secret']);
    if (!hash_equals($expected, $sig)) {
        respond(['error' => 'Unauthorized'], 401);
    }
    $nf = $cfg['state_dir'] . '/nonce_' . $nonce;
    if (file_exists($nf)) {
        respond(['error' => 'Replay detected'], 401);
    }
    touch($nf);
    if (random_int(1, 20) === 1) {
        foreach (glob($cfg['state_dir'] . '/nonce_*') ?: [] as $f) {
            if (filemtime($f) < time() - 120) {
                @unlink($f);
            }
        }
    }
}

if (($cfg['secret'] ?? '') === '' || strlen($cfg['secret']) < 32 || str_starts_with($cfg['secret'], 'PASTE')) {
    respond(['error' => 'Daemon secret not configured'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input') ?: '';
authenticate($cfg, $method, $body);
$in = $body !== '' ? (json_decode($body, true) ?? []) : [];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
/** Display adapters from /sys/class/drm, named through the PCI id database when it is installed. */
function host_gpus(): array
{
    $out = [];
    foreach (glob('/sys/class/drm/card[0-9]*/device') ?: [] as $dev) {
        $ven = strtolower(trim((string)@file_get_contents($dev . '/vendor')));
        $did = strtolower(trim((string)@file_get_contents($dev . '/device')));
        if (!preg_match('/^0x[0-9a-f]{4}$/', $ven) || !preg_match('/^0x[0-9a-f]{4}$/', $did)) {
            continue;
        }
        $ven = substr($ven, 2);
        $did = substr($did, 2);
        $name = strtoupper("$ven:$did");
        foreach (['/usr/share/hwdata/pci.ids', '/usr/share/misc/pci.ids'] as $f) {
            if (!is_readable($f)) {
                continue;
            }
            $in = false;
            $vname = '';
            foreach (new SplFileObject($f) as $line) {
                $line = rtrim((string)$line, "\r\n");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if ($line[0] !== "\t") {
                    if ($in) {
                        break;
                    }
                    if (str_starts_with($line, $ven . '  ')) {
                        $in = true;
                        $vname = trim(substr($line, 6));
                    }
                } elseif ($in && $line[1] !== "\t" && str_starts_with(substr($line, 1), $did . '  ')) {
                    $name = trim(preg_replace('/\s*\[.*$/', '', $vname) . ' ' . trim(substr($line, 7)));
                    break;
                }
            }
            break;
        }
        $out[$name] = $name;
    }
    return array_values($out);
}

/** Memory, CPU count and uptime of the host, read from /proc (empty on systems without it). */
function host_stats(): array
{
    $o = [];
    if (is_readable('/proc/meminfo')) {
        $m = file_get_contents('/proc/meminfo');
        foreach (['MemTotal' => 'mem_total_mb', 'MemAvailable' => 'mem_avail_mb'] as $k => $key) {
            if (preg_match('/^' . $k . ':\s+(\d+)/m', $m, $x)) {
                $o[$key] = (int)($x[1] / 1024);
            }
        }
    }
    if (is_readable('/proc/uptime')) {
        $o['uptime_s'] = (int)explode(' ', (string)file_get_contents('/proc/uptime'))[0];
    }
    if (is_readable('/proc/cpuinfo')) {
        $o['cpus'] = max(1, preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo')));
    }
    if (is_readable('/proc/cpuinfo') && preg_match('/^model name\s*:\s*(.+)$/m', (string)file_get_contents('/proc/cpuinfo'), $x)) {
        $o['cpu'] = trim($x[1]);
    }
    $o['gpus'] = host_gpus();
    if (is_readable('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)/m', (string)file_get_contents('/etc/os-release'), $x)) {
        $o['os'] = $x[1];
    }
    return $o;
}

$docker = new Docker($cfg['docker_socket']);

try {
    if ($method === 'GET' && $path === '/api/system') {
        respond([
            'version' => '1.0.0',
            'docker' => $docker->json('GET', '/version')['Version'] ?? 'unknown',
            'disk_free_mb' => (int)(disk_free_space($cfg['data_dir']) / 1048576),
            'load' => function_exists('sys_getloadavg') ? sys_getloadavg() : [],
            'disk_total_mb' => (int)(disk_total_space($cfg['data_dir']) / 1048576),
            'host' => host_stats(),
            'running' => count(array_filter($docker->json('GET', '/containers/json') ?: [], static fn($c) => str_starts_with(ltrim($c['Names'][0] ?? '', '/'), 'gp_'))),
        ]);
    }

    if ($method === 'POST' && $path === '/api/servers') {
        $p = $in['server'] ?? [];
        Server::validate($p);
        $statusFile = $cfg['state_dir'] . '/status_' . $p['uuid'] . '.json';
        file_put_contents($statusFile, json_encode(['status' => 'installing']));
        file_put_contents($cfg['state_dir'] . '/job_' . $p['uuid'] . '.json', json_encode(['server' => $p, 'install' => $in['install'] ?? null]));
        // Detached background worker. PHP_BINARY would be php-fpm here, so use the CLI binary from config.
        // uuid is regex-validated and every argument is escaped.
        exec(escapeshellarg($cfg['php_cli'] ?? '/usr/bin/php') . ' ' . escapeshellarg(__DIR__ . '/../bin/worker.php') . ' '
            . escapeshellarg($p['uuid']) . ' > /dev/null 2>&1 &');
        respond(['status' => 'installing'], 202);
    }

    if (preg_match('#^/api/servers/([a-f0-9\-]{36})(?:/([a-z/]+))?$#', $path, $m)) {
        $uuid = $m[1];
        $sub = $m[2] ?? '';
        $base = Server::dir($cfg, $uuid);

        if ($sub === '' && $method === 'GET') {
            $sf = $cfg['state_dir'] . "/status_$uuid.json";
            $install = is_file($sf) ? json_decode((string)file_get_contents($sf), true) : ['status' => 'done'];
            respond(Server::state($docker, $uuid) + ['install' => $install]);
        }
        if ($sub === '' && $method === 'PUT') { // recreate container (e.g. after env/limits change)
            $p = $in['server'] ?? [];
            Server::validate($p);
            if ((Server::state($docker, $uuid)['state'] ?? '') === 'running') {
                respond(['error' => 'Stop the server before applying changes'], 409);
            }
            Server::removeContainer($docker, $uuid);
            Server::createContainer($docker, $cfg, $p);
            respond(['ok' => true]);
        }
        if ($sub === '' && $method === 'DELETE') {
            Server::removeContainer($docker, $uuid);
            if (is_dir($base)) {
                Files::remove($base);
            }
            foreach (['status', 'job'] as $pre) {
                @unlink($cfg['state_dir'] . "/{$pre}_$uuid.json");
            }
            respond(['ok' => true]);
        }
        if ($sub === 'stats' && $method === 'GET') {
            respond(Server::stats($docker, $cfg, $uuid, (int)($_GET['disk'] ?? 0)));
        }
        if ($sub === 'power' && $method === 'POST') {
            $a = $in['action'] ?? '';
            if (!in_array($a, ['start', 'stop', 'restart', 'kill'], true)) {
                respond(['error' => 'Invalid action'], 422);
            }
            Server::power($docker, $cfg, $uuid, $a, (int)($in['disk'] ?? 0));
            respond(['ok' => true]);
        }
        if ($sub === 'logs' && $method === 'GET') {
            [$code, $raw] = $docker->raw('GET', '/containers/' . Server::name($uuid) . '/logs?stdout=1&stderr=1&tail=300');
            respond(['logs' => $code === 200 ? mb_convert_encoding(substr($raw, -200000), 'UTF-8', 'UTF-8') : '']);
        }
        if ($sub === 'command' && $method === 'POST') {
            Server::sendCommand($uuid, (string)($in['command'] ?? ''));
            respond(['ok' => true]);
        }

        // ---- files -------------------------------------------------------
        if (str_starts_with($sub, 'files/')) {
            if (!is_dir($base)) {
                respond(['error' => 'Server data not found'], 404);
            }
            $op = substr($sub, 6);
            $rel = (string)($method === 'GET' ? ($_GET['path'] ?? '') : ($in['path'] ?? ''));
            $target = Files::resolve($base, $rel);

            switch ($method . ' ' . $op) {
                case 'GET list':
                    respond(['files' => Files::list($target)]);
                case 'GET read':
                    if (!is_file($target)) {
                        respond(['error' => 'Not a file'], 404);
                    }
                    if (filesize($target) > Files::MAX_READ) {
                        respond(['error' => 'File too large to edit (2 MB max)'], 413);
                    }
                    $c = (string)file_get_contents($target);
                    if (str_contains($c, "\0")) {
                        respond(['error' => 'Binary files cannot be edited'], 415);
                    }
                    respond(['content' => $c]);
                case 'POST write':
                    $c = (string)($in['content'] ?? '');
                    if (strlen($c) > Files::MAX_WRITE) {
                        respond(['error' => 'Content too large'], 413);
                    }
                    if ($target === $base || is_dir($target)) {
                        respond(['error' => 'Invalid target'], 422);
                    }
                    if (!is_dir(dirname($target))) {
                        respond(['error' => 'Parent directory missing'], 404);
                    }
                    file_put_contents($target, $c, LOCK_EX);
                    respond(['ok' => true]);
                case 'POST pull':
                    if ($target === $base || is_dir($target) || file_exists($target) || !is_dir(dirname($target))) {
                        respond(['error' => 'Choose a new file name in an existing folder'], 409);
                    }
                    $limit = min(4294967296, max(0, (int)($in['disk'] ?? 0) * 1048576 - Files::dirSize($base)));
                    if ($limit <= 0) {
                        respond(['error' => 'No disk space left for this server'], 507);
                    }
                    respond(['ok' => true, 'bytes' => Files::pull((string)($in['url'] ?? ''), $target, $limit)]);
                case 'POST mkdir':
                    if ($target === $base || file_exists($target)) {
                        respond(['error' => 'Already exists'], 409);
                    }
                    mkdir($target, 0750);
                    respond(['ok' => true]);
                case 'POST delete':
                    if ($target === $base || !file_exists($target)) {
                        respond(['error' => 'Not found'], 404);
                    }
                    Files::remove($target);
                    respond(['ok' => true]);
                case 'POST rename':
                    $to = Files::resolve($base, (string)($in['to'] ?? ''));
                    if ($target === $base || $to === $base || !file_exists($target) || file_exists($to)) {
                        respond(['error' => 'Invalid rename'], 409);
                    }
                    rename($target, $to);
                    respond(['ok' => true]);
            }
        }
    }
    respond(['error' => 'Not found'], 404);
} catch (HttpError $e) {
    respond(['error' => $e->getMessage()], $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log('[daemon] ' . $e);
    respond(['error' => 'Internal error'], 500);
}
