<?php
declare(strict_types=1);

namespace Wings;

/** Server file operations, jailed to a single server's data directory. */
final class Files
{
    public const MAX_READ = 2097152;   // 2 MB editable
    public const MAX_WRITE = 5242880;  // 5 MB per save

    /**
     * Resolve a user-supplied relative path inside $base.
     * ".." is rejected outright and every existing component must not be a symlink,
     * so neither traversal nor container-planted symlinks can escape the jail.
     */
    public static function resolve(string $base, string $rel): string
    {
        if (str_contains($rel, "\0")) {
            throw new HttpError('Invalid path', 400);
        }
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $rel)) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                throw new HttpError('Invalid path', 400);
            }
            $parts[] = $p;
        }
        $cur = $base;
        foreach ($parts as $p) {
            $cur .= '/' . $p;
            if (is_link($cur)) {
                throw new HttpError('Symlinks are not permitted', 403);
            }
        }
        return $cur;
    }

    public static function list(string $dir): array
    {
        if (!is_dir($dir)) {
            throw new HttpError('Not a directory', 404);
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || is_link($dir . '/' . $name)) {
                continue;
            }
            $p = $dir . '/' . $name;
            $isDir = is_dir($p);
            $out[] = ['name' => $name, 'dir' => $isDir, 'size' => $isDir ? 0 : (int)filesize($p), 'mtime' => (int)filemtime($p)];
        }
        usort($out, fn($a, $b) => [$b['dir'], strtolower($a['name'])] <=> [$a['dir'], strtolower($b['name'])]);
        return $out;
    }

    public static function dirSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $size += $f->getSize();
            }
        }
        return $size;
    }

    /**
     * Download an https URL into $target. SSRF-safe: https:443 only, public IPv4 only (checked and then
     * pinned with CURLOPT_RESOLVE so DNS can't change between check and fetch), redirects re-validated,
     * hard size cap, written to a temp file and renamed on success.
     */
    public static function pull(string $url, string $target, int $maxBytes): int
    {
        set_time_limit(0);
        $tmp = $target . '.part';
        for ($hop = 0; $hop < 4; $hop++) {
            $u = parse_url($url);
            if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || (isset($u['port']) && $u['port'] !== 443)) {
                throw new HttpError('Only plain https:// URLs on port 443 are allowed', 422);
            }
            $ips = gethostbynamel($u['host']) ?: [];
            if (!$ips) {
                throw new HttpError('Could not resolve host', 422);
            }
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new HttpError('That address is not publicly routable', 422);
                }
            }
            $fh = fopen($tmp, 'wb');
            $written = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE => [$u['host'] . ':443:' . $ips[0]],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 3600,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'GamePanel-Daemon/1.0',
                CURLOPT_HEADER => false,
                CURLOPT_WRITEFUNCTION => function ($c, $data) use ($fh, &$written, $maxBytes) {
                    if (curl_getinfo($c, CURLINFO_RESPONSE_CODE) >= 300 && curl_getinfo($c, CURLINFO_RESPONSE_CODE) < 400) {
                        return strlen($data); // redirect body: discard
                    }
                    $written += strlen($data);
                    return $written > $maxBytes ? 0 : (int)fwrite($fh, $data);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($code >= 300 && $code < 400 && $loc) {
                $url = $loc;
                continue;
            }
            if ($ok === false || $code !== 200) {
                @unlink($tmp);
                throw new HttpError($written > $maxBytes ? 'File exceeds the size limit' : 'Download failed' . ($err ? ': ' . $err : " (HTTP $code)"), 502);
            }
            rename($tmp, $target);
            return $written;
        }
        @unlink($tmp);
        throw new HttpError('Too many redirects', 422);
    }

    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                self::remove($path . '/' . $n);
            }
        }
        rmdir($path);
    }
}
