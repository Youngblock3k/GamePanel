<?php
declare(strict_types=1);

namespace App;

/** Tiny key/value store (table is created on first use, so existing installs upgrade themselves). */
final class Settings
{
    private static ?array $cache = null;

    private static function load(): array
    {
        if (self::$cache === null) {
            DB::pdo()->exec('CREATE TABLE IF NOT EXISTS settings (k VARCHAR(191) NOT NULL PRIMARY KEY, v TEXT NOT NULL)');
            self::$cache = array_column(DB::all('SELECT k, v FROM settings'), 'v', 'k');
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::load()[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        self::load();
        DB::q('REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
        self::$cache[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::load();
        DB::q('DELETE FROM settings WHERE k = ?', [$key]);
        unset(self::$cache[$key]);
    }
}
