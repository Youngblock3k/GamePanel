<?php
declare(strict_types=1);

// Background jobs of the local daemon:  php runner.php install <uuid>   |   php runner.php supervise <uuid>
require __DIR__ . '/lib.php';

use Wings\Files;

if (PHP_SAPI !== 'cli' || !isset($argv[1], $argv[2])) {
    exit(1);
}
[$mode, $id] = [$argv[1], $argv[2]];
$d = sdir($id);
$log = $d . '/console.log';
$say = static fn(string $m) => file_put_contents($log, $m . "\n", FILE_APPEND);
set_time_limit(0);

function http_json(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'GamePanel-local-daemon']);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        throw new RuntimeException($err ?: "HTTP $code from $url");
    }
    return json_decode((string)$raw, true) ?: [];
}

function mc_url(string $type, string $v): string
{
    switch ($type) {
        case 'paper':
            $u = http_json("https://fill.papermc.io/v3/projects/paper/versions/$v/builds/latest")['downloads']['server:default']['url'] ?? '';
            break;
        case 'purpur':
            $u = "https://api.purpurmc.org/v2/purpur/$v/latest/download";
            break;
        case 'fabric':
            $l = array_values(array_filter(http_json('https://meta.fabricmc.net/v2/versions/loader'), static fn($x) => !empty($x['stable'])))[0]['version'] ?? '';
            $i = array_values(array_filter(http_json('https://meta.fabricmc.net/v2/versions/installer'), static fn($x) => !empty($x['stable'])))[0]['version'] ?? '';
            $u = "https://meta.fabricmc.net/v2/versions/loader/$v/$l/$i/server/jar";
            break;
        default:
            $url = '';
            foreach (http_json('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json')['versions'] ?? [] as $x) {
                if (($x['id'] ?? '') === $v) {
                    $url = $x['url'];
                }
            }
            $u = $url === '' ? '' : (http_json($url)['downloads']['server']['url'] ?? '');
    }
    if ($u === '') {
        throw new RuntimeException("No $type download exists for Minecraft $v");
    }
    return $u;
}

if ($mode === 'install') {
    $meta = meta_get($id);
    $files = $d . '/files';
    $fail = static function (string $m) use ($id, $meta, $say): never {
        $say("[install] FAILED: $m");
        $meta['install'] = ['status' => 'failed', 'error' => $m];
        meta_set($id, $meta);
        exit(1);
    };
    try {
        $env = $meta['server']['env'] ?? [];
        if (isset($env['MC_VERSION'], $env['SERVER_TYPE'])) {
            $say("[install] Minecraft {$env['SERVER_TYPE']} {$env['MC_VERSION']}: looking up the download…");
            $url = mc_url($env['SERVER_TYPE'], $env['MC_VERSION']);
            $say('[install] Downloading server.jar from ' . parse_url($url, PHP_URL_HOST) . '…');
            @unlink($files . '/server.jar');
            $bytes = Files::pull($url, $files . '/server.jar', 2147483648);
            $say('[install] server.jar downloaded (' . round($bytes / 1048576, 1) . ' MB).');
            if (($env['ACCEPT_EULA'] ?? '') === 'true') {
                file_put_contents($files . '/eula.txt', "eula=true\n");
                $say('[install] EULA accepted.');
            } else {
                $say('[install] EULA not accepted yet: set "Accept the Minecraft EULA" in Settings or the server will stop on start.');
            }
            if (!is_file($files . '/server.properties')) {
                $props = ['motd' => 'MOTD', 'max-players' => 'MAX_PLAYERS', 'gamemode' => 'GAMEMODE', 'difficulty' => 'DIFFICULTY', 'online-mode' => 'ONLINE_MODE', 'pvp' => 'PVP',
                    'white-list' => 'WHITELIST', 'enable-command-block' => 'COMMAND_BLOCKS', 'view-distance' => 'VIEW_DISTANCE', 'level-seed' => 'LEVEL_SEED'];
                $txt = '';
                foreach ($props as $k => $e) {
                    $txt .= $k . '=' . ($env[$e] ?? '') . "\n";
                }
                file_put_contents($files . '/server.properties', $txt . 'server-port=' . (int)$meta['server']['port'] . "\n");
            }
        } elseif (is_file($d . '/install.json')) {
            $job = json_decode((string)file_get_contents($d . '/install.json'), true);
            $bash = 'C:\\Program Files\\Git\\bin\\bash.exe';
            if (!is_file($bash)) {
                throw new RuntimeException('Installing this egg needs Git Bash (or Docker via the real daemon).');
            }
            $say('[install] Running the egg install script with Git Bash…');
            $p = proc_open([$bash, '-c', (string)$job['script']], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $files, array_merge(getenv(), $meta['server']['env'] ?? []));
            fclose($pipes[0]);
            if (proc_close($p) !== 0) {
                throw new RuntimeException('The install script failed.');
            }
        }
        $say('[install] Done. Press Start.');
        $meta['install'] = ['status' => 'done'];
        meta_set($id, $meta);
    } catch (Throwable $e) {
        $fail($e->getMessage());
    }
    exit(0);
}

if ($mode === 'supervise') {
    $meta = meta_get($id);
    $sf = $d . '/sup.json';
    $cores = max(1, (int)(getenv('NUMBER_OF_PROCESSORS') ?: 4));
    do {
        $meta = meta_get($id);
        $srv = $meta['server'];
        $say('[daemon] Starting: ' . $srv['startup']);
        $proc = proc_open($srv['startup'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $d . '/files', array_merge(getenv(), $srv['env'] ?? []));
        if (!is_resource($proc)) {
            $say('[daemon] Could not start the process.');
            break;
        }
        $pid = (int)proc_get_status($proc)['pid'];
        $started = time();
        $lastS = 0;
        $prevCpu = null;
        $mem = 0;
        $cpu = 0.0;
        while (true) {
            $st = proc_get_status($proc);
            $q = $d . '/cmd.queue';
            if (is_file($q)) {
                $tmp = $q . '.' . getmypid();
                if (@rename($q, $tmp)) {
                    @fwrite($pipes[0], (string)file_get_contents($tmp));
                    @fflush($pipes[0]);
                    @unlink($tmp);
                }
            }
            if (time() - $lastS >= 3) {
                $lastS = time();
                if ($st['running']) {
                    $o = trim((string)@shell_exec('powershell -NoProfile -Command "$c=Get-CimInstance Win32_Process -Filter \'ParentProcessId=' . $pid . '\' | ? { $_.Name -like \'java*\' } | Select -First 1; $t=if($c){Get-Process -Id $c.ProcessId -EA 0}else{Get-Process -Id ' . $pid . ' -EA 0}; if($t){\'{0} {1}\' -f $t.WorkingSet64,$t.CPU}"'));
                    if (preg_match('/^(\d+) ([\d.,]+)$/', $o, $mm)) {
                        $mem = (int)($mm[1] / 1048576);
                        $c = (float)str_replace(',', '.', $mm[2]);
                        $cpu = $prevCpu === null ? 0.0 : round(max(0, ($c - $prevCpu[0]) / max(1, microtime(true) - $prevCpu[1]) * 100 / $cores), 1);
                        $prevCpu = [$c, microtime(true)];
                    }
                }
                file_put_contents($sf, json_encode(['running' => true, 'alive' => time(), 'pid' => $pid, 'started' => $started, 'mem' => $mem, 'cpu' => $cpu]), LOCK_EX);
            }
            if (!$st['running']) {
                break;
            }
            usleep(300000);
        }
        @proc_close($proc);
        $say('[daemon] Process exited.');
        $again = is_file($d . '/restart.flag');
        @unlink($d . '/restart.flag');
    } while ($again);
    file_put_contents($sf, json_encode(['running' => false, 'alive' => time()]), LOCK_EX);
    exit(0);
}
