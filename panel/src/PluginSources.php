<?php
declare(strict_types=1);

namespace App;

/** Looks plugins up on Modrinth, Spigot (via Spiget) and CurseForge. The panel only ever passes the node a download link that came from those APIs. */
final class PluginSources
{
    public const SOURCES = ['modrinth' => 'Modrinth', 'spigot' => 'Spigot', 'curseforge' => 'CurseForge'];
    private const HOSTS = ['cdn.modrinth.com', 'api.spiget.org', 'cdn.spiget.org', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'];
    private const LOADERS = ['paper', 'spigot', 'bukkit', 'purpur', 'folia'];

    public static function curseKey(): string
    {
        return Settings::get('curseforge_key');
    }

    public static function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_OPTIONS => defined("CURLSSLOPT_NATIVE_CA") ? CURLSSLOPT_NATIVE_CA : 0,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: GamePanel/1.0 (plugin browser)'], $headers),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            throw new \RuntimeException($err !== '' ? 'Could not reach the plugin site (' . $err . ').' : 'The plugin site answered with HTTP ' . $code . '.');
        }
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Unexpected answer from the plugin site.');
        }
        return $j;
    }

    private static function clean(string $s, int $max = 160): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1f]+/', ' ', $s)), 0, $max);
    }

    private static function safeUrl(?string $u): ?string
    {
        $h = parse_url((string)$u, PHP_URL_HOST);
        return $u && str_starts_with($u, 'https://') && in_array($h, ['cdn.modrinth.com', 'api.spiget.org', 'cdn.spiget.org', 'media.forgecdn.net', 'edge.forgecdn.net', 'mediafilez.forgecdn.net'], true) ? $u : null;
    }

    /** @return list<array{id:string,name:string,desc:string,icon:?string,downloads:int,author:string}> */
    public static function search(string $source, string $q, string $mc): array
    {
        $out = [];
        if ($source === 'modrinth') {
            $facets = [['project_type:plugin']];
            if ($mc !== '') {
                $facets[] = ['versions:' . $mc];
            }
            $j = self::get('https://api.modrinth.com/v2/search?' . http_build_query(['query' => $q, 'limit' => 24, 'index' => $q === '' ? 'downloads' : 'relevance', 'facets' => json_encode($facets)]));
            foreach ($j['hits'] ?? [] as $h) {
                $out[] = ['id' => (string)$h['project_id'], 'name' => self::clean((string)$h['title'], 80), 'desc' => self::clean((string)$h['description']), 'icon' => self::safeUrl($h['icon_url'] ?? null), 'downloads' => (int)$h['downloads'], 'author' => self::clean((string)($h['author'] ?? ''), 40)];
            }
        } elseif ($source === 'spigot') {
            $url = $q === '' ? 'https://api.spiget.org/v2/resources/free?' : 'https://api.spiget.org/v2/search/resources/' . rawurlencode($q) . '?field=name&';
            $j = self::get($url . http_build_query(['size' => 24, 'sort' => '-downloads', 'fields' => 'id,name,tag,downloads,icon,external,premium']));
            foreach ($j as $h) {
                if (!empty($h['premium']) || !empty($h['external'])) {
                    continue;
                }
                $out[] = ['id' => (string)(int)$h['id'], 'name' => self::clean((string)$h['name'], 80), 'desc' => self::clean((string)($h['tag'] ?? '')), 'icon' => null, 'downloads' => (int)($h['downloads'] ?? 0), 'author' => ''];
            }
        } elseif ($source === 'curseforge') {
            $key = self::curseKey();
            if ($key === '') {
                throw new \RuntimeException('CurseForge needs an API key. An administrator can add one under Settings.');
            }
            $args = ['gameId' => 432, 'classId' => 5, 'pageSize' => 24, 'sortField' => $q === '' ? 6 : 2, 'sortOrder' => 'desc', 'searchFilter' => $q];
            if ($mc !== '') {
                $args['gameVersion'] = $mc;
            }
            $j = self::get('https://api.curseforge.com/v1/mods/search?' . http_build_query($args), ['x-api-key: ' . $key]);
            foreach ($j['data'] ?? [] as $h) {
                $out[] = ['id' => (string)(int)$h['id'], 'name' => self::clean((string)$h['name'], 80), 'desc' => self::clean((string)($h['summary'] ?? '')), 'icon' => self::safeUrl($h['logo']['thumbnailUrl'] ?? null), 'downloads' => (int)($h['downloadCount'] ?? 0), 'author' => self::clean((string)($h['authors'][0]['name'] ?? ''), 40)];
            }
        } else {
            throw new \RuntimeException('Unknown source.');
        }
        return $out;
    }

    /** @return array{url:string,file:string} download link and a safe file name for the plugins folder */
    public static function resolve(string $source, string $id, string $mc): array
    {
        if ($source === 'modrinth' && preg_match('/^[A-Za-z0-9]{8}$/', $id)) {
            $a = ['loaders' => json_encode(self::LOADERS)];
            if ($mc !== '') {
                $a['game_versions'] = json_encode([$mc]);
            }
            $v = self::get('https://api.modrinth.com/v2/project/' . $id . '/version?' . http_build_query($a));
            $v = $v[0] ?? throw new \RuntimeException('No build of that plugin exists for Minecraft ' . $mc . '.');
            $f = null;
            foreach ($v['files'] ?? [] as $c) {
                if (!empty($c['primary'])) {
                    $f = $c;
                }
            }
            $f ??= $v['files'][0] ?? throw new \RuntimeException('That version has no file.');
            return self::done((string)$f['url'], (string)$f['filename']);
        }
        if ($source === 'spigot' && ctype_digit($id)) {
            $r = self::get('https://api.spiget.org/v2/resources/' . $id . '?fields=name,file,external,premium');
            if (!empty($r['premium']) || !empty($r['external']) || ($r['file']['type'] ?? '') === 'external') {
                throw new \RuntimeException('That plugin is paid or hosted elsewhere and cannot be installed automatically.');
            }
            return self::done('https://api.spiget.org/v2/resources/' . $id . '/download', preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$r['name']) . '-' . $id . '.jar');
        }
        if ($source === 'curseforge' && ctype_digit($id)) {
            $q = $mc !== '' ? '?gameVersion=' . rawurlencode($mc) : '';
            $j = self::get('https://api.curseforge.com/v1/mods/' . $id . '/files' . $q, ['x-api-key: ' . self::curseKey()]);
            foreach ($j['data'] ?? [] as $f) {
                if (!empty($f['downloadUrl'])) {
                    return self::done((string)$f['downloadUrl'], (string)$f['fileName']);
                }
            }
            throw new \RuntimeException('The author does not allow downloads outside CurseForge for that plugin.');
        }
        throw new \RuntimeException('Invalid plugin.');
    }

    private static function done(string $url, string $name): array
    {
        $h = parse_url($url, PHP_URL_HOST);
        if (!str_starts_with($url, 'https://') || !in_array($h, self::HOSTS, true)) {
            throw new \RuntimeException('Download link is not on a trusted host.');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($name));
        if (!str_ends_with(strtolower($name), '.jar')) {
            $name .= '.jar';
        }
        return ['url' => $url, 'file' => ltrim($name, '.')];
    }
}
