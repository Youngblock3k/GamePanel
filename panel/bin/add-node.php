<?php
declare(strict_types=1);

// Usage: php bin/add-node.php --name=local --fqdn=127.0.0.1 --scheme=http --memory=8192 --disk=100000 --pool-ip=0.0.0.0 --pool=25565-25575
// Creates a location (if needed), a node and a port pool. Prints SECRET=<daemon secret> as the last line.
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
$DB = 'App' . chr(92) . 'DB';
$SEC = 'App' . chr(92) . 'Security';
$AC = 'App' . chr(92) . 'Controllers' . chr(92) . 'AdminController';
$NS = 'App' . chr(92) . 'NodeSchema';
$NS::ensure();

$o = getopt('', ['name::', 'fqdn::', 'scheme::', 'port::', 'memory::', 'disk::', 'location::', 'pool-ip::', 'pool::']);
$name = (string)($o['name'] ?? 'local');
$fqdn = (string)($o['fqdn'] ?? '127.0.0.1');
$scheme = ($o['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
$loc = (string)($o['location'] ?? 'local');
if ($scheme === 'http' && !in_array($fqdn, ['localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "Plain http is only allowed for localhost.\n");
    exit(1);
}
$lid = $DB::one('SELECT id FROM locations WHERE short = ?', [$loc])['id'] ?? $DB::insert('locations', ['short' => $loc, 'description' => 'This server']);
if ($DB::one('SELECT id FROM nodes WHERE name = ?', [$name])) {
    fwrite(STDERR, "A node called $name already exists.\n");
    exit(1);
}
$secret = bin2hex(random_bytes(32));
$id = $DB::insert('nodes', [
    'uuid' => $AC::uuid4(), 'name' => $name, 'location_id' => $lid, 'fqdn' => $fqdn, 'scheme' => $scheme,
    'daemon_port' => (int)($o['port'] ?? 8443), 'daemon_secret' => $SEC::encrypt($secret),
    'memory' => max(128, (int)($o['memory'] ?? 4096)), 'disk' => max(128, (int)($o['disk'] ?? 50000)), 'created_at' => time(),
    'description' => 'Installed together with the panel',
]);
$ip = (string)($o['pool-ip'] ?? '0.0.0.0');
if (preg_match('/^(\\d{1,5})(?:-(\\d{1,5}))?$/', (string)($o['pool'] ?? ''), $m) && filter_var($ip, FILTER_VALIDATE_IP)) {
    for ($p = (int)$m[1]; $p <= (int)($m[2] ?? $m[1]) && $p <= (int)$m[1] + 99; $p++) {
        $DB::insert('allocations', ['node_id' => $id, 'ip' => $ip, 'port' => $p]);
    }
}
echo "SECRET=$secret\n";
