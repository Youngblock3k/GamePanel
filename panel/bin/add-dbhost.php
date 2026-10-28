<?php
declare(strict_types=1);

// Usage: php bin/add-dbhost.php --name=Local --host=127.0.0.1 --user=gp_dbadmin --pass=... [--port=3306]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'App' . chr(92))) {
        $f = ROOT . '/src/' . str_replace(chr(92), '/', substr($c, 4)) . '.php';
        is_file($f) && require $f;
    }
});
require ROOT . '/src/helpers.php';
require ROOT . '/src/App.php';
$cfg = 'App' . chr(92) . 'App';
$cfg::$config = require ROOT . '/config/config.php';
$H = 'App' . chr(92) . 'DbHosts';
$DB = 'App' . chr(92) . 'DB';
$SEC = 'App' . chr(92) . 'Security';
$H::ensure();
$o = getopt('', ['name::', 'host::', 'user::', 'pass::', 'port::']);
$host = (string)($o['host'] ?? '127.0.0.1');
$port = (int)($o['port'] ?? 3306);
try {
    $H::connect($host, $port, (string)($o['user'] ?? ''), (string)($o['pass'] ?? ''));
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$DB::insert('db_hosts', ['name' => (string)($o['name'] ?? 'Local'), 'host' => $host, 'port' => $port, 'admin_user' => (string)$o['user'],
    'admin_pass' => $SEC::encrypt((string)$o['pass']), 'max_dbs' => 0, 'created_at' => time()]);
echo "Database host registered.\n";
