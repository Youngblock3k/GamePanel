<?php
declare(strict_types=1);

namespace App;

/** MariaDB/MySQL servers that per-game-server databases are created on. Identifiers are generated here and never taken from the request verbatim. */
final class DbHosts
{
    private static bool $done = false;

    public static function ensure(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;
        try {
            DB::q('SELECT 1 FROM server_dbs LIMIT 1');
            return;
        } catch (\Throwable) {
            // tables missing: create them
        }
        $pk = DB::pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
        DB::pdo()->exec("CREATE TABLE IF NOT EXISTS db_hosts ($pk, name VARCHAR(100) NOT NULL, host VARCHAR(191) NOT NULL, port INTEGER NOT NULL DEFAULT 3306, admin_user VARCHAR(100) NOT NULL, admin_pass TEXT NOT NULL, max_dbs INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)");
        DB::pdo()->exec("CREATE TABLE IF NOT EXISTS server_dbs ($pk, server_id INTEGER NOT NULL, host_id INTEGER NOT NULL, db_name VARCHAR(64) NOT NULL UNIQUE, db_user VARCHAR(32) NOT NULL UNIQUE, db_pass TEXT NOT NULL, remote VARCHAR(64) NOT NULL DEFAULT '%', created_at INTEGER NOT NULL)");
    }

    /** Opens an admin connection to a database host. Throws \RuntimeException with a short readable message. */
    public static function connect(string $host, int $port, string $user, string $pass): \PDO
    {
        if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('The PHP pdo_mysql extension is not enabled on this panel (Ubuntu: apt install php-mysql, then restart php-fpm).');
        }
        try {
            return new \PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException) {
            throw new \RuntimeException('Could not connect to ' . $host . ':' . $port . ' with those credentials.');
        }
    }

    public static function open(array $h): \PDO
    {
        return self::connect($h['host'], (int)$h['port'], $h['admin_user'], Security::decrypt($h['admin_pass']));
    }

    public static function password(): string
    {
        $a = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $o = '';
        for ($i = 0; $i < 24; $i++) {
            $o .= $a[random_int(0, strlen($a) - 1)];
        }
        return $o;
    }

    public static function validRemote(string $r): bool
    {
        return $r === '%' || filter_var($r, FILTER_VALIDATE_IP) !== false;
    }

    /** Creates database + user and returns the stored row's fields (plain password included, once). */
    public static function createFor(array $host, int $serverId, string $label, string $remote): array
    {
        $db = 's' . $serverId . '_' . $label;
        $user = 'u' . $serverId . '_' . bin2hex(random_bytes(4));
        $pass = self::password();
        $pdo = self::open($host);
        $q = $pdo->quote($user);
        $r = $pdo->quote($remote);
        try {
            $pdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4');
            $pdo->exec("CREATE USER $q@$r IDENTIFIED BY " . $pdo->quote($pass));
            $pdo->exec('GRANT ALL PRIVILEGES ON `' . $db . "`.* TO $q@$r");
        } catch (\PDOException) {
            try {
                $pdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
                $pdo->exec("DROP USER IF EXISTS $q@$r");
            } catch (\PDOException) {
                // best effort clean-up
            }
            throw new \RuntimeException('The database host refused to create it (does the name already exist, or lack privileges?).');
        }
        return ['db_name' => $db, 'db_user' => $user, 'db_pass' => $pass];
    }

    public static function rotate(array $host, array $row): string
    {
        $pass = self::password();
        $pdo = self::open($host);
        $pdo->exec('ALTER USER ' . $pdo->quote($row['db_user']) . '@' . $pdo->quote($row['remote']) . ' IDENTIFIED BY ' . $pdo->quote($pass));
        return $pass;
    }

    public static function drop(array $host, array $row): void
    {
        $pdo = self::open($host);
        $pdo->exec('DROP DATABASE IF EXISTS `' . $row['db_name'] . '`');
        $pdo->exec('DROP USER IF EXISTS ' . $pdo->quote($row['db_user']) . '@' . $pdo->quote($row['remote']));
    }
}
