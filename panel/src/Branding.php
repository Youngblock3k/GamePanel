<?php
declare(strict_types=1);

namespace App;

/**
 * Admin-customisable brand for the top-left corner ONLY (name + logo).
 * The footer copyright and the page <title> stay fixed and are covered by the integrity seal.
 */
final class Branding
{
    public const MAX_BYTES = 524288;   // 512 KB
    public const MAX_DIM = 2000;

    public static function name(): string
    {
        return Settings::get('brand_name', Brand\Seal::NAME);
    }

    private static function logoFile(): ?string
    {
        $f = Settings::get('brand_logo');
        return preg_match('/^logo-[a-f0-9]{16}\.(png|jpg|webp)$/', $f) && is_file(self::dir() . '/' . $f) ? $f : null;
    }

    public static function logoUrl(): ?string
    {
        $f = self::logoFile();
        return $f ? '/brand/logo?v=' . substr($f, 5, 8) : null;
    }

    public static function dir(): string
    {
        $d = ROOT . '/storage/branding';
        is_dir($d) || mkdir($d, 0750, true);
        return $d;
    }

    public static function validName(string $n): bool
    {
        return (bool)preg_match('/^[\p{L}\p{N} .&_\-]{1,32}$/u', $n);
    }

    /** Validate and store an uploaded logo. Returns an error string or null. */
    public static function storeLogo(array $file): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return 'Upload failed. Choose a PNG, JPEG or WebP under 512 KB.';
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'Logo must be 512 KB or smaller.';
        }
        $raw = (string)file_get_contents($file['tmp_name']);
        // Decide the type from magic bytes only (never from the client's file name or MIME header). SVG is refused on purpose: it can carry scripts.
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
        if (!$info || $info[0] < 16 || $info[1] < 16 || $info[0] > self::MAX_DIM || $info[1] > self::MAX_DIM) {
            return 'Image must be between 16 and ' . self::MAX_DIM . ' pixels on each side.';
        }
        // Re-encode when GD is available: strips metadata and any payload hidden in the file.
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw))) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            ob_start();
            imagepng($img);
            $raw = (string)ob_get_clean();
            $ext = 'png';
        }
        $name = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (file_put_contents(self::dir() . '/' . $name, $raw) === false) {
            return 'Could not save the logo (is storage/ writable?).';
        }
        self::removeLogo();
        Settings::set('brand_logo', $name);
        return null;
    }

    public static function removeLogo(): void
    {
        if ($f = self::logoFile()) {
            @unlink(self::dir() . '/' . $f);
        }
        Settings::forget('brand_logo');
    }

    /** Stream the stored logo with a fixed content type. */
    public static function serve(): never
    {
        $f = self::logoFile();
        $path = $f ? self::dir() . '/' . $f : null;
        if (!$path || !is_file($path)) {
            throw new HttpException('No logo', 404);
        }
        $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][pathinfo($f, PATHINFO_EXTENSION)];
        header('Content-Type: ' . $type);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
