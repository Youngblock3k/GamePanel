<?php
declare(strict_types=1);

namespace App\Ui;

/** Resolves content-hashed bundles produced by bin/build.php. */
final class Assets
{
    private static ?array $manifest = null;

    private static function m(): array
    {
        return self::$manifest ??= (json_decode((string)@file_get_contents(ROOT . '/build/manifest.json'), true) ?: []);
    }

    public static function css(): string
    {
        return '/assets/' . (self::m()['css'] ?? 'missing.css');
    }

    public static function js(): string
    {
        return '/assets/' . (self::m()['js'] ?? 'missing.js');
    }

    /** Banner class and emblem symbol for a game (own artwork; official logos are trademarks). */
    public static function game(array $egg): array
    {
        $key = (string)($egg['preset_key'] ?? '');
        $map = ['minecraft_paper' => ['gb-mc', 'a-mc'], 'rust' => ['gb-rust', 's-rust'], 'cs2' => ['gb-cs', 's-counterstrike'], 'nodejs' => ['gb-node', 's-nodedotjs'],
            'python' => ['gb-py', 's-python'], 'java' => ['gb-java', 'a-cup'], 'hytale' => ['gb-hy', 'a-hy']];
        return $map[$key] ?? ['gb-x' . (crc32((string)($egg['name'] ?? '')) % 4), 'g-pad'];
    }

    /** Initials + one of eight gradient classes (no inline styles — CSP forbids them). */
    public static function avatar(string $name): array
    {
        return [strtoupper(mb_substr($name, 0, 1)), 'av-' . (crc32($name) % 8)];
    }
}
