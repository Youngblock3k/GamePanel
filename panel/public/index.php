<?php
declare(strict_types=1);

// Let the PHP built-in server serve static assets directly.
if (PHP_SAPI === 'cli-server') {
    $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($p !== '/' && is_file(__DIR__ . $p) && !str_ends_with($p, '.php')) {
        return false;
    }
}

define('ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require ROOT . '/src/helpers.php';
App\App::run();
