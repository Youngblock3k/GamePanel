<?php
declare(strict_types=1);

// Background installer: php bin/worker.php <uuid>   (spawned by the daemon, never by users)
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(1);
}
require __DIR__ . '/../src/Docker.php';
require __DIR__ . '/../src/Files.php';
require __DIR__ . '/../src/Server.php';

use Wings\Docker;
use Wings\Server;

$cfg = require __DIR__ . '/../config.php';
$uuid = $argv[1];
Server::name($uuid); // validates format
$jobFile = $cfg['state_dir'] . "/job_$uuid.json";
$statusFile = $cfg['state_dir'] . "/status_$uuid.json";
$job = json_decode((string)file_get_contents($jobFile), true);
unlink($jobFile);

try {
    Server::provision(new Docker($cfg['docker_socket']), $cfg, $job);
    file_put_contents($statusFile, json_encode(['status' => 'done']));
} catch (Throwable $e) {
    file_put_contents($statusFile, json_encode(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1500)]));
}
