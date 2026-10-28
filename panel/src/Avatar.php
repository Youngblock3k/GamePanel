<?php
declare(strict_types=1);

namespace App;

/** Optional profile picture per account (validated image, stored outside the web root). Default is the first letter of the username. */
final class Avatar
{
    private const MAX_BYTES = 1048576;

    private static function key(string $username): string
    {
        return 'avatar:' . strtolower($username);
    }

    private static function dir(): string
    {
        $d = ROOT . '/storage/avatars';
        is_dir($d) || mkdir($d, 0750, true);
        return $d;
    }

    public static function file(string $username): ?string
    {
        $f = Settings::get(self::key($username), '');
        return preg_match('/^av-[a-f0-9]{24}\.(png|jpg|webp)$/', $f) && is_file(self::dir() . '/' . $f) ? $f : null;
    }

    public static function url(string $username): ?string
    {
        $f = self::file($username);
        return $f ? '/avatar/' . rawurlencode($username) . '?v=' . substr($f, 3, 8) : null;
    }

    public static function allowed(array $user): bool
    {
        return (bool)$user['is_admin'] || Settings::get('avatar_uploads', '1') === '1';
    }

    public static function store(string $username, array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return 'Upload failed. Choose a PNG, JPEG or WebP under 1 MB.';
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'Picture must be 1 MB or smaller.';
        }
        $raw = (string)file_get_contents($file['tmp_name']);
        if (str_starts_with($raw, "\x89PNG\r\n\x1a\n")) {
            $ext = 'png';
        } elseif (str_starts_with($raw, "\xFF\xD8\xFF")) {
            $ext = 'jpg';
        } elseif (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') {
            $ext = 'webp';
        } else {
            return 'Only PNG, JPEG or WebP images are accepted.';
        }
        $info = @getimagesizefromstring($raw);
        if (!$info || $info[0] < 32 || $info[1] < 32 || $info[0] > 2048 || $info[1] > 2048) {
            return 'Picture must be between 32 and 2048 pixels on each side.';
        }
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw))) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            ob_start();
            imagepng($img);
            $raw = (string)ob_get_clean();
            $ext = 'png';
        }
        $name = 'av-' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents(self::dir() . '/' . $name, $raw) === false) {
            return 'Could not save the picture (is storage/ writable?).';
        }
        self::remove($username);
        Settings::set(self::key($username), $name);
        return null;
    }

    public static function remove(string $username): void
    {
        if ($f = self::file($username)) {
            @unlink(self::dir() . '/' . $f);
        }
        Settings::forget(self::key($username));
    }

    public static function serve(string $username): never
    {
        $f = self::file($username);
        if (!$f) {
            http_response_code(404);
            exit;
        }
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
        header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        readfile(self::dir() . '/' . $f);
        exit;
    }
}
