<?php
declare(strict_types=1);

// Release build: php bin/build.php [--key=/path/to/seal.key]
// Bundles resources/{css,js}/* into content-hashed files and (with --key) signs the integrity seal.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
$opts = getopt('', ['key::']);

function cat(string $dir, string $ext): string
{
    $files = glob($dir . '/*.' . $ext) ?: [];
    sort($files);
    return implode("\n", array_map('file_get_contents', $files));
}

$css = cat($root . '/resources/css', 'css');
$css = preg_replace('#/\*.*?\*/#s', '', $css);
$css = preg_replace('/\s+/', ' ', $css);
$css = trim(preg_replace('/\s*([{};:,>])\s*/', '$1', $css));

$js = [];
foreach (explode("\n", cat($root . '/resources/js', 'js')) as $line) {
    $t = trim($line);
    if ($t !== '' && !str_starts_with($t, '//')) {
        $js[] = $t;
    }
}
$js = implode("\n", $js);

foreach (glob($root . '/public/assets/vl-*') ?: [] as $old) {
    unlink($old);
}
$cssName = 'vl-' . substr(hash('sha256', $css), 0, 10) . '.css';
$jsName = 'vl-' . substr(hash('sha256', $js), 0, 10) . '.js';
file_put_contents($root . '/public/assets/' . $cssName, $css);
file_put_contents($root . '/public/assets/' . $jsName, $js);
file_put_contents($root . '/build/manifest.json', json_encode(['css' => $cssName, 'js' => $jsName]));
echo "Built $cssName, $jsName\n";

if (empty($opts['key'])) {
    echo "Not signed (no --key). The panel will refuse to start until the seal is signed.\n";
    exit(0);
}

// Seal every file that defines the look and the copyright notice.
$protected = [];
foreach (['resources', 'views', 'src/Brand', 'src/Ui', 'public/assets', 'build'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if ($f->isFile() && $rel !== 'build/seal.json' && !str_ends_with($rel, '.bak')) {
            $protected[$rel] = hash_file('sha256', $f->getPathname());
        }
    }
}
ksort($protected);
$sk = base64_decode(trim((string)file_get_contents($opts['key'])), true);
$sig = sodium_crypto_sign_detached(json_encode($protected, JSON_UNESCAPED_SLASHES), $sk);
file_put_contents($root . '/build/seal.json', json_encode(['files' => $protected, 'sig' => base64_encode($sig)], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
echo 'Signed ' . count($protected) . " files.\n";
