<?php
declare(strict_types=1);

namespace App;

final class ServerService
{
    private const SAFE_VALUE = '/^[A-Za-z0-9_.:\-]{0,64}$/';
    /** For variables the startup command never uses (they only reach the container as environment): spaces and light punctuation are fine. */
    private const SAFE_TEXT = "/^[A-Za-z0-9 _.,:!+&()'\\-]{0,64}$/";

    /** What a member of the team may do on a server. */
    public const SUBPERMS = [
        'console' => 'Console', 'power' => 'Start / stop', 'files' => 'Files', 'plugins' => 'Plugins', 'settings' => 'Settings',
    ];

    private static bool $subDone = false;

    public static function ensureSubusers(): void
    {
        if (self::$subDone) {
            return;
        }
        self::$subDone = true;
        try {
            DB::q('SELECT 1 FROM server_subusers LIMIT 1');
        } catch (\Throwable) {
            $pk = DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
            DB::pdo()->exec("CREATE TABLE IF NOT EXISTS server_subusers ($pk, server_id INTEGER NOT NULL, user_id INTEGER NOT NULL, perms TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE (server_id, user_id))");
        }
    }

    /** The address players use: the node's own host name or IP plus the server port. */
    public static function addr(string $fqdn, $port): string
    {
        return $fqdn . ':' . (int)$port;
    }

    /** Which sub-permission a /server/{uuid}/... request needs ('owner' = owner or admin only). */
    public static function needs(string $path, string $method): string
    {
        $seg = explode('/', trim($path, '/'))[2] ?? '';
        return match ($seg) {
            '' , 'state', 'logs', 'command', 'activity' => 'console',
            'power' => 'power',
            'files' => 'files',
            'plugins' => 'plugins',
            'settings' => 'settings',
            default => 'owner',
        };
    }

    public static function find(string $uuid, array $user): array
    {
        self::ensureSubusers();
        $s = DB::one(
            'SELECT s.*, a.ip AS a_ip, a.port AS a_port, e.name AS egg_name, e.docker_image, e.startup,
                    e.stop_command, e.variables, e.install_image, e.install_script,
                    n.name AS node_name, e.preset_key, n.fqdn, n.scheme, n.daemon_port, n.daemon_secret,
                    u.username AS owner_name
             FROM servers s
             JOIN allocations a ON a.id = s.allocation_id
             JOIN eggs e ON e.id = s.egg_id
             JOIN nodes n ON n.id = s.node_id
             JOIN users u ON u.id = s.owner_id
             WHERE s.uuid = ?',
            [$uuid]
        );
        // Same 404 for "missing" and "not yours" so UUIDs can't be probed.
        $perms = array_keys(self::SUBPERMS);
        $owner = $s && ($user['is_admin'] || (int)$s['owner_id'] === (int)$user['id']);
        if ($s && !$owner) {
            $row = DB::one('SELECT perms FROM server_subusers WHERE server_id = ? AND user_id = ?', [$s['id'], $user['id']]);
            $perms = $row ? array_values(array_intersect(array_keys(self::SUBPERMS), json_decode((string)$row['perms'], true) ?: [])) : null;
        }
        if (!$s || $perms === null) {
            throw new HttpException('Server not found', 404);
        }
        $s['is_owner'] = $owner;
        $s['sub_perms'] = $perms;
        $s['addr'] = self::addr($s['fqdn'], $s['a_port']);
        return $s;
    }

    public static function node(array $s): array
    {
        return ['scheme' => $s['scheme'], 'fqdn' => $s['fqdn'], 'daemon_port' => $s['daemon_port'], 'daemon_secret' => $s['daemon_secret']];
    }

    /** Validate submitted variable values against the egg definition. */
    public static function validateEnv(array $egg, array $input, array $current, bool $isAdmin): array
    {
        $vars = json_decode($egg['variables'], true) ?: [];
        $env = [];
        foreach ($vars as $v) {
            $k = $v['key'];
            $val = $current[$k] ?? ($v['default'] ?? '');
            $canEdit = $isAdmin || !empty($v['editable']);
            if ($canEdit && isset($input[$k]) && is_string($input[$k])) {
                $val = trim($input[$k]);
            }
            $inStartup = str_contains((string)$egg['startup'], '{{' . $k . '}}');
            if (!preg_match($inStartup ? self::SAFE_VALUE : self::SAFE_TEXT, $val)) {
                return [[], "Invalid value for {$v['name']} (" . ($inStartup ? 'letters, numbers and . _ : - only' : 'letters, numbers, spaces and . , : ! + & ( ) - only') . ').'];
            }
            if (!empty($v['regex']) && @preg_match('/' . str_replace('/', '\/', $v['regex']) . '/', $val) !== 1) {
                return [[], "Value for {$v['name']} does not match the required format."];
            }
            $env[$k] = $val;
        }
        if (isset($env['MC_VERSION'], $env['SERVER_TYPE']) && ($bad = MinecraftVersions::check($env['SERVER_TYPE'], $env['MC_VERSION']))) {
            return [[], $bad];
        }
        return [$env, null];
    }

    public static function payload(array $s): array
    {
        $env = json_decode($s['env'], true) ?: [];
        $map = $env + [
            'SERVER_MEMORY' => (string)$s['memory'],
            // JVM heap: leave ~15% headroom for metaspace/threads so the kernel OOM-killer isn't triggered.
            'SERVER_MEMORY_JVM' => (string)max(64, (int)floor($s['memory'] * 0.85)),
            'SERVER_PORT' => (string)$s['a_port'],
            'SERVER_IP' => $s['a_ip'],
        ];
        $startup = preg_replace_callback('/\{\{([A-Z0-9_]+)\}\}/', fn($m) => $map[$m[1]] ?? '', $s['startup']);
        return [
            'uuid' => $s['uuid'],
            'image' => $s['docker_image'],
            'startup' => $startup,
            'stop_command' => $s['stop_command'],
            'env' => $map,
            'memory' => (int)$s['memory'],
            'oom_kill' => (int)($s['oom_kill'] ?? 1),
            'cpu' => (int)$s['cpu'],
            'disk' => (int)$s['disk'],
            'ip' => $s['a_ip'],
            'port' => (int)$s['a_port'],
        ];
    }

    public static function installPayload(array $s): ?array
    {
        if ($s['install_script'] === '') {
            return null;
        }
        return ['image' => $s['install_image'] ?: 'alpine:3.19', 'script' => $s['install_script']];
    }
}
