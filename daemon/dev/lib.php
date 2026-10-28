<?php
declare(strict_types=1);

// Shared helpers of the local (no-Docker) daemon. Reuses the real daemon's file jail and downloader.
require_once __DIR__ . '/../src/Server.php';
require_once __DIR__ . '/../src/Files.php';

const RUNTIME = __DIR__ . '/runtime';

function sdir(string $id): string
{
    if (!preg_match('/^[a-f0-9\-]{36}$/', $id)) {
        throw new \Wings\HttpError('Invalid server id', 400);
    }
    return RUNTIME . '/' . $id;
}

function jget(string $f): ?array
{
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : null;
}

function meta_get(string $id): ?array
{
    return jget(sdir($id) . '/meta.json');
}

function meta_set(string $id, array $m): void
{
    file_put_contents(sdir($id) . '/meta.json', json_encode($m), LOCK_EX);
}

/** Supervisor-owned state; a stale heartbeat means the supervisor is gone, so the server is not running. */
function sup_state(string $id): array
{
    $s = jget(sdir($id) . '/sup.json') ?? ['running' => false];
    if (!empty($s['running']) && time() - (int)($s['alive'] ?? 0) > 20) {
        $s['running'] = false;
    }
    return $s;
}

function queue_cmd(string $id, string $cmd): void
{
    file_put_contents(sdir($id) . '/cmd.queue', $cmd . "\n", FILE_APPEND | LOCK_EX);
}

function sup_kill(string $id): void
{
    $s = jget(sdir($id) . '/sup.json') ?? [];
    @unlink(sdir($id) . '/restart.flag');
    if (!empty($s['pid'])) {
        exec('taskkill /PID ' . (int)$s['pid'] . ' /T /F >NUL 2>&1');
    }
}

/** Starts `php runner.php <mode> <uuid>` fully detached from this request. */
function spawn(string $mode, string $id, string $extra = ''): void
{
    sdir($id);
    $ca = (string)ini_get('curl.cainfo');
    if ($extra !== '') {
        file_put_contents(sdir($id) . '/install.json', $extra);
    }
    $cmd = 'start "" /B ' . escapeshellarg(PHP_BINARY) . ($ca !== '' ? ' -d curl.cainfo=' . escapeshellarg($ca) : '')
        . ' ' . escapeshellarg(__DIR__ . '/runner.php') . ' ' . $mode . ' ' . $id . ' >NUL 2>&1';
    pclose(popen('cmd /c ' . $cmd, 'r'));
}
