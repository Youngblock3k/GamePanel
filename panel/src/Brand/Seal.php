<?php
declare(strict_types=1);

namespace App\Brand;

use App\HttpException;

/**
 * Brand constants and release-integrity check.
 *
 * Every UI file (views, resources, built assets, this brand module) is listed with its SHA-256
 * in build/seal.json, which is signed with the author's Ed25519 private key (kept offline).
 * Only the public key lives here, so the seal can be verified but not re-created without the key.
 */
final class Seal
{
    public const NAME = 'Game Panel';
    public const VERSION = '1.0.0';
    public const SINCE = 2026;

    private const PUBLIC_KEY = 'f92+Tb756/gpz5AGGxW3ugaWv3QDJlJfEYM8391Df60=';

    private static bool $checked = false;

    public static function check(): void
    {
        if (self::$checked) {
            return;
        }
        if (!self::verify()) {
            // Developer machines only: the offline signing key sits next to the app and the config opts in. Never true on a release install.
            $key = ROOT . '/../.seal/seal.key';
            if (\cfg('dev_autoseal', false) && is_file($key) && self::resign($key) && self::verify()) {
                self::$checked = true;
                return;
            }
            throw new HttpException(
                'Integrity check failed: the ' . self::NAME . ' interface or copyright notice has been modified. Restore the original release files.',
                503
            );
        }
        self::$checked = true;
    }

    private static function resign(string $key): bool
    {
        $lock = @fopen(ROOT . '/storage/seal.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            return false;
        }
        if (self::verify()) { // another request already re-signed while we waited
            return true;
        }
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/bin/build.php') . ' --key=' . escapeshellarg($key);
        exec($cmd . ' 2>&1', $out, $rc);
        return $rc === 0;
    }

    private static function verify(): bool
    {
        $file = ROOT . '/build/seal.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data) || !isset($data['files'], $data['sig']) || !is_array($data['files'])) {
            return false;
        }
        $canonical = json_encode($data['files'], JSON_UNESCAPED_SLASHES);
        $sig = base64_decode((string)$data['sig'], true);
        if ($sig === false || !sodium_crypto_sign_verify_detached($sig, $canonical, base64_decode(self::PUBLIC_KEY))) {
            return false;
        }
        foreach ($data['files'] as $rel => $hash) {
            $path = ROOT . '/' . $rel;
            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) {
                return false;
            }
        }
        return true;
    }

    public static function footer(): string
    {
        self::check();
        $years = self::SINCE . (date('Y') > self::SINCE ? '–' . date('Y') : '');
        $holder = htmlspecialchars((string)\cfg('copyright_holder', self::NAME), ENT_QUOTES, 'UTF-8');
        return '<footer class="vl-foot"><span>© ' . $years . ' ' . $holder . '. All rights reserved.</span>'
            . '<span class="vl-foot-sep">·</span><span>' . self::NAME . ' v' . self::VERSION . '</span></footer>';
    }
}
