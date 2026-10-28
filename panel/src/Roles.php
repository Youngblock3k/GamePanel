<?php
declare(strict_types=1);

namespace App;

/** Roles give a non-administrator access to chosen sections and actions of the admin panel. Full administrators (users.is_admin) can do everything and are the only ones who manage roles. */
final class Roles
{
    /** Every permission: key => [label, help]. A key without a dot opens a section; "section.action" allows one action inside it. */
    public const PERMS = [
        'monitoring' => ['View monitoring', 'Node and server health, hardware and alerts.'],
        'servers' => ['View servers', 'The server list, and every server\'s page.'],
        'servers.create' => ['Create servers', 'Provision new servers.'],
        'servers.suspend' => ['Suspend servers', 'Suspend and unsuspend servers.'],
        'servers.delete' => ['Delete servers', 'Permanently remove servers.'],
        'servers.databases' => ['Manage server databases', 'Create, rotate and delete databases.'],
        'nodes' => ['View nodes', 'Node list, capacity and ports.'],
        'nodes.create' => ['Add nodes', 'Register new nodes, including localhost.'],
        'nodes.edit' => ['Edit nodes', 'Settings, maintenance mode and ports.'],
        'nodes.secret' => ['Rotate node secrets', 'Create new daemon secrets and install commands.'],
        'nodes.delete' => ['Delete nodes', 'Remove nodes with no servers.'],
        'locations' => ['View locations', 'The location list.'],
        'locations.edit' => ['Edit locations', 'Create and remove locations.'],
        'eggs' => ['View eggs', 'The egg catalogue and exports.'],
        'eggs.edit' => ['Edit eggs', 'Create, import, edit, clone and delete eggs.'],
        'databases' => ['View database hosts', 'The list of database hosts.'],
        'databases.manage' => ['Manage database hosts', 'Add and remove database hosts.'],
        'users' => ['View users', 'The account list.'],
        'users.create' => ['Create users', 'Add ordinary accounts (never administrators).'],
        'users.manage' => ['Manage users', 'Disable, enable, reset 2FA and delete ordinary accounts.'],
        'audit' => ['Read the audit log', 'Everything that happened and who did it.'],
        'settings' => ['View settings', 'The panel settings page.'],
        'settings.edit' => ['Change settings', 'Two-factor rules and other options.'],
        'branding' => ['View branding', 'Name, logo and default look.'],
        'branding.edit' => ['Change branding', 'Edit name, logo and look for everyone.'],
    ];

    /** Groups shown on the role form. */
    public const GROUPS = [
        'Monitoring' => ['monitoring'],
        'Servers' => ['servers', 'servers.create', 'servers.suspend', 'servers.delete', 'servers.databases'],
        'Nodes' => ['nodes', 'nodes.create', 'nodes.edit', 'nodes.secret', 'nodes.delete'],
        'Locations' => ['locations', 'locations.edit'],
        'Eggs' => ['eggs', 'eggs.edit'],
        'Database hosts' => ['databases', 'databases.manage'],
        'Users' => ['users', 'users.create', 'users.manage'],
        'Audit' => ['audit'],
        'Panel' => ['settings', 'settings.edit', 'branding', 'branding.edit'],
    ];

    /** Starting points shown on the role form. */
    public const PRESETS = [
        'Read only' => ['monitoring', 'servers', 'nodes', 'locations', 'eggs', 'databases', 'users', 'audit'],
        'Support' => ['monitoring', 'servers', 'servers.suspend', 'servers.databases', 'audit'],
        'Node operator' => ['monitoring', 'nodes', 'nodes.create', 'nodes.edit', 'nodes.secret', 'locations', 'locations.edit'],
        'Moderator' => ['monitoring', 'servers', 'servers.suspend', 'users', 'users.manage', 'audit'],
        'Designer' => ['branding', 'branding.edit'],
    ];

    public const COLORS = ['#e0a458', '#d7746a', '#d96aa8', '#a87bd6', '#6b8cff', '#4fa8c9', '#4fa37f', '#8fbf5a', '#9aa0a6'];

    private static bool $done = false;
    private static array $cache = [];

    public static function ensure(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;
        try {
            DB::q('SELECT role_id FROM users LIMIT 1');
            DB::q('SELECT 1 FROM roles LIMIT 1');
        } catch (\Throwable) {
            $pk = DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
            DB::pdo()->exec("CREATE TABLE IF NOT EXISTS roles ($pk, name VARCHAR(60) NOT NULL UNIQUE, description VARCHAR(191) NOT NULL DEFAULT '', perms TEXT NOT NULL, created_at INTEGER NOT NULL)");
            try {
                DB::q('ALTER TABLE users ADD COLUMN role_id INTEGER NULL');
            } catch (\Throwable) {
                // already there
            }
        }
        try {
            DB::q('SELECT color FROM roles LIMIT 1');
        } catch (\Throwable) {
            try {
                DB::q("ALTER TABLE roles ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#9aa0a6'");
            } catch (\Throwable) {
                // already there
            }
        }
    }

    public static function validColor(string $c): bool
    {
        return (bool)preg_match('/^#[0-9a-f]{6}$/', $c);
    }

    public static function all(): array
    {
        self::ensure();
        return DB::all('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS members FROM roles r ORDER BY r.name');
    }

    /** Permissions of a user: everything for administrators, the role's list otherwise. */
    public static function perms(?array $u): array
    {
        if (!$u) {
            return [];
        }
        if (!empty($u['is_admin'])) {
            return array_keys(self::PERMS);
        }
        if (empty($u['role_id'])) {
            return [];
        }
        self::ensure();
        $id = (int)$u['role_id'];
        if (!isset(self::$cache[$id])) {
            $r = DB::one('SELECT perms FROM roles WHERE id = ?', [$id]);
            $p = $r ? json_decode((string)$r['perms'], true) : [];
            self::$cache[$id] = array_values(array_intersect(is_array($p) ? $p : [], array_keys(self::PERMS)));
        }
        return self::$cache[$id];
    }

    public static function can(?array $u, string $perm): bool
    {
        return in_array($perm, self::perms($u), true);
    }

    /** Whether the user may open the admin area at all (administrator, or at least one permission). */
    public static function inAdmin(?array $u): bool
    {
        return $u && (!empty($u['is_admin']) || self::perms($u) !== []);
    }

    /** Which permission a /admin/... path needs. null = any admin-area user, 'full' = administrators only. */
    public static function sectionFor(string $path): ?string
    {
        $seg = explode('/', trim(substr($path, strlen('/admin')), '/'))[0] ?? '';
        return match ($seg) {
            '', 'overview', 'theme' => null,
            'roles' => 'full',
            'servers', 'monitoring', 'nodes', 'locations', 'eggs', 'users', 'audit', 'settings', 'branding', 'databases' => $seg,
            default => 'full',
        };
    }

    /** The extra action permission a request needs on top of seeing the section, if any. */
    public static function actionFor(string $method, string $path): ?string
    {
        $rules = [
            ['GET', '#^/admin/servers/new$#', 'servers.create'], ['POST', '#^/admin/servers$#', 'servers.create'],
            ['POST', '#^/admin/servers/\d+/databases#', 'servers.databases'],
            ['GET', '#^/admin/nodes/new$#', 'nodes.create'], ['POST', '#^/admin/nodes(/local)?$#', 'nodes.create'],
            ['POST', '#^/admin/nodes/[^/]+/rotate$#', 'nodes.secret'], ['POST', '#^/admin/nodes/[^/]+/delete$#', 'nodes.delete'],
            ['POST', '#^/admin/nodes/[^/]+/(update|maintenance|allocations)#', 'nodes.edit'],
            ['GET', '#^/admin/locations/new$#', 'locations.edit'], ['POST', '#^/admin/locations#', 'locations.edit'],
            ['POST', '#^/admin/eggs#', 'eggs.edit'],
            ['GET', '#^/admin/databases/new$#', 'databases.manage'], ['POST', '#^/admin/databases/hosts#', 'databases.manage'],
            ['GET', '#^/admin/users/new$#', 'users.create'], ['POST', '#^/admin/users$#', 'users.create'], ['POST', '#^/admin/users/action$#', 'users.manage'],
            ['POST', '#^/admin/settings#', 'settings.edit'], ['POST', '#^/admin/branding#', 'branding.edit'],
        ];
        if ($method === 'POST' && $path === '/admin/servers/action') {
            return ($_POST['action'] ?? '') === 'delete' ? 'servers.delete' : 'servers.suspend';
        }
        foreach ($rules as [$m, $re, $perm]) {
            if ($m === $method && preg_match($re, $path)) {
                return $perm;
            }
        }
        return null;
    }

    public static function roleName(?int $id): string
    {
        if (!$id) {
            return '';
        }
        self::ensure();
        return (string)(DB::val('SELECT name FROM roles WHERE id = ?', [$id]) ?: '');
    }
}
