<?php
declare(strict_types=1);

namespace App;

/** Real, currently published Minecraft versions per server type, so a typo is caught before a server is created. */
final class MinecraftVersions
{
    private const TYPES = ['paper', 'purpur', 'fabric', 'vanilla'];

    /** @return list<string>|null newest first; null when the list could not be fetched */
    public static function list(string $type): ?array
    {
        if (!in_array($type, self::TYPES, true)) {
            return null;
        }
        $key = 'mcv:' . $type;
        $hit = json_decode(Settings::get($key), true);
        if (is_array($hit) && ($hit['t'] ?? 0) > time() - 6 * 3600 && !empty($hit['v'])) {
            return $hit['v'];
        }
        try {
            $v = match ($type) {
                'paper' => array_merge(...array_values(PluginSources::get('https://fill.papermc.io/v3/projects/paper')['versions'] ?? [[]])),
                'purpur' => PluginSources::get('https://api.purpurmc.org/v2/purpur')['versions'] ?? [],
                'fabric' => array_column(array_filter(PluginSources::get('https://meta.fabricmc.net/v2/versions/game'), static fn($g) => !empty($g['stable'])), 'version'),
                'vanilla' => array_column(array_filter(PluginSources::get('https://piston-meta.mojang.com/mc/game/version_manifest_v2.json')['versions'] ?? [], static fn($g) => ($g['type'] ?? '') === 'release'), 'id'),
            };
        } catch (\Throwable) {
            return is_array($hit) ? ($hit['v'] ?? null) : null;
        }
        $v = array_values(array_filter(array_map('strval', $v), static fn($x) => preg_match('/^(1|2\d)\.\d{1,2}(\.\d{1,2})?$/', $x)));
        if ($type === 'purpur') {
            $v = array_reverse($v);
        }
        if ($v) {
            Settings::set($key, json_encode(['t' => time(), 'v' => $v]));
        }
        return $v ?: null;
    }

    /** Error text when the version does not exist for that type, null when fine (or when it cannot be checked offline). */
    public static function check(string $type, string $version): ?string
    {
        $all = self::list($type);
        if ($all === null || in_array($version, $all, true)) {
            return null;
        }
        $label = ['paper' => 'Paper', 'purpur' => 'Purpur', 'fabric' => 'Fabric', 'vanilla' => 'Vanilla'][$type] ?? $type;
        return "Minecraft $version does not exist for $label. Latest are: " . implode(', ', array_slice($all, 0, 5)) . '.';
    }
}
