<?php
declare(strict_types=1);

/**
 * LOCAL daemon for testing the panel on a machine without Docker (for example Windows).
 * It speaks the same API as the real daemon but runs servers as ordinary processes in daemon/dev/runtime/.
 * Installs the Minecraft jar for real, starts a real `java` process, streams its console and takes commands.
 * Needs Java on PATH for Minecraft eggs.   Start:  php -S 127.0.0.1:8443 daemon/dev/mock.php
 * It accepts requests from 127.0.0.1 only and does not check the signature, so never expose it.
 * No container isolation and no RAM/CPU limits here: that is what the real daemon with Docker is for.
 */
require __DIR__ . '/lib.php';

use Wings\Files;
use Wings\HttpError;

header('Content-Type: application/json');
if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    http_response_code(403);
    exit('{"error":"Forbidden"}');
}
$out = static function (mixed $d, int $c = 200): never {
    http_response_code($c);
    echo json_encode($d);
    exit;
};
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$now = time();

try {
    if ($method === 'GET' && $path === '/api/system') {
        $java = trim((string)@shell_exec('java -version 2>&1'));
        $out(['version' => '1.0.0-local', 'docker' => 'local processes (no Docker)', 'disk_free_mb' => (int)(disk_free_space(RUNTIME) / 1048576),
            'disk_total_mb' => (int)(disk_total_space(RUNTIME) / 1048576), 'load' => [0.1, 0.1, 0.1], 'running' => count(array_filter(glob(RUNTIME . '/*', GLOB_ONLYDIR) ?: [], static fn($d) => sup_state(basename($d))['running'])),
            'host' => ['cpus' => (int)(getenv('NUMBER_OF_PROCESSORS') ?: 4), 'os' => 'Local · ' . (strtok($java, "\n") ?: 'Java not found')]]);
    }
    if ($method === 'POST' && $path === '/api/servers') {
        $p = $in['server'] ?? [];
        if (!preg_match('/^[a-f0-9\-]{36}$/', (string)($p['uuid'] ?? ''))) {
            $out(['error' => 'Invalid server'], 422);
        }
        $d = sdir($p['uuid']);
        @mkdir($d . '/files', 0777, true);
        meta_set($p['uuid'], ['server' => $p, 'install' => ['status' => 'installing']]);
        file_put_contents($d . '/console.log', "[daemon] Server created, installing…\n");
        spawn('install', $p['uuid'], is_array($in['install'] ?? null) ? json_encode($in['install']) : '');
        $out(['status' => 'installing'], 202);
    }
    if (preg_match('#^/api/servers/([a-f0-9\-]{36})(?:/([a-z/]+))?$#', $path, $m)) {
        $id = $m[1];
        $sub = $m[2] ?? '';
        $d = sdir($id);
        if ($method === 'DELETE' && $sub === '') {
            sup_kill($id);
            usleep(600000);
            if (is_dir($d)) {
                Files::remove($d);
            }
            $out(['ok' => true]);
        }
        $meta = meta_get($id);
        if (!$meta) {
            $out(['state' => 'missing'], $sub === '' && $method === 'GET' ? 200 : 404);
        }
        $sup = sup_state($id);
        if ($sub === '' && $method === 'GET') {
            $out(['state' => $sup['running'] ? 'running' : 'exited', 'started_at' => $sup['started'] ?? null, 'install' => $meta['install']]);
        }
        if ($sub === '' && $method === 'PUT') {
            if ($sup['running']) {
                $out(['error' => 'Stop the server before applying changes'], 409);
            }
            $meta['server'] = $in['server'] ?? $meta['server'];
            meta_set($id, $meta);
            $out(['ok' => true]);
        }
        if ($sub === 'stats') {
            $out(['state' => $sup['running'] ? 'running' : 'exited', 'cpu' => $sup['running'] ? (float)($sup['cpu'] ?? 0) : 0.0, 'mem' => $sup['running'] ? (int)($sup['mem'] ?? 0) : 0,
                'mem_limit' => (int)($meta['server']['memory'] ?? 0), 'rx' => 0, 'tx' => 0, 'disk' => (int)(Files::dirSize($d . '/files') / 1048576), 'disk_limit' => (int)($_GET['disk'] ?? 0)]);
        }
        if ($sub === 'power' && $method === 'POST') {
            $a = $in['action'] ?? '';
            if (!in_array($a, ['start', 'stop', 'restart', 'kill'], true)) {
                $out(['error' => 'Invalid action'], 422);
            }
            if ($meta['install']['status'] === 'installing') {
                $out(['error' => 'Still installing, wait for the install to finish'], 409);
            }
            if ($meta['install']['status'] === 'failed') {
                $out(['error' => 'Install failed, see the console'], 409);
            }
            if ($a === 'kill') {
                sup_kill($id);
            } elseif ($a === 'stop') {
                if ($sup['running']) {
                    $stop = (string)($meta['server']['stop_command'] ?? '');
                    $stop !== '' ? queue_cmd($id, $stop) : sup_kill($id);
                }
            } elseif ($a === 'restart' && $sup['running']) {
                touch($d . '/restart.flag');
                $stop = (string)($meta['server']['stop_command'] ?? '');
                $stop !== '' ? queue_cmd($id, $stop) : sup_kill($id);
            } elseif (!$sup['running']) {
                @unlink($d . '/restart.flag');
                file_put_contents($d . '/sup.json', json_encode(['running' => true, 'alive' => $now, 'started' => $now]));
                spawn('supervise', $id);
            }
            $out(['ok' => true]);
        }
        if ($sub === 'logs') {
            $f = $d . '/console.log';
            $raw = is_file($f) ? (string)file_get_contents($f) : '';
            $out(['logs' => mb_convert_encoding(substr($raw, -200000), 'UTF-8', 'UTF-8')]);
        }
        if ($sub === 'command' && $method === 'POST') {
            if (!$sup['running']) {
                $out(['error' => 'Server is not running'], 409);
            }
            queue_cmd($id, str_replace(["\r", "\n", "\0"], '', (string)($in['command'] ?? '')));
            $out(['ok' => true]);
        }
        if (str_starts_with($sub, 'files/')) {
            $base = $d . '/files';
            $op = substr($sub, 6);
            $rel = (string)($method === 'GET' ? ($_GET['path'] ?? '') : ($in['path'] ?? ''));
            $target = Files::resolve($base, $rel);
            switch ($method . ' ' . $op) {
                case 'GET list':
                    $out(['files' => Files::list($target)]);
                case 'GET read':
                    if (!is_file($target)) {
                        $out(['error' => 'Not a file'], 404);
                    }
                    if (filesize($target) > Files::MAX_READ) {
                        $out(['error' => 'File too large to edit (2 MB max)'], 413);
                    }
                    $c = (string)file_get_contents($target);
                    str_contains($c, "\0") && $out(['error' => 'Binary files cannot be edited'], 415);
                    $out(['content' => $c]);
                case 'POST write':
                    if ($target === $base || is_dir($target) || !is_dir(dirname($target))) {
                        $out(['error' => 'Invalid target'], 422);
                    }
                    file_put_contents($target, (string)($in['content'] ?? ''), LOCK_EX);
                    $out(['ok' => true]);
                case 'POST pull':
                    if ($target === $base || is_dir($target) || file_exists($target) || !is_dir(dirname($target))) {
                        $out(['error' => 'Choose a new file name in an existing folder'], 409);
                    }
                    $out(['ok' => true, 'bytes' => Files::pull((string)($in['url'] ?? ''), $target, 1073741824)]);
                case 'POST mkdir':
                    if ($target === $base || file_exists($target)) {
                        $out(['error' => 'Already exists'], 409);
                    }
                    mkdir($target, 0777);
                    $out(['ok' => true]);
                case 'POST delete':
                    if ($target === $base || !file_exists($target)) {
                        $out(['error' => 'Not found'], 404);
                    }
                    Files::remove($target);
                    $out(['ok' => true]);
                case 'POST rename':
                    $to = Files::resolve($base, (string)($in['to'] ?? ''));
                    if ($target === $base || $to === $base || !file_exists($target) || file_exists($to)) {
                        $out(['error' => 'Invalid rename'], 409);
                    }
                    rename($target, $to);
                    $out(['ok' => true]);
            }
        }
    }
    $out(['error' => 'Not found'], 404);
} catch (HttpError $e) {
    $out(['error' => $e->getMessage()], $e->getCode() ?: 400);
}
