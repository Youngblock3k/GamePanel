<?php
declare(strict_types=1);

namespace App;

final class HttpException extends \RuntimeException
{
}

final class App
{
    public static array $config = [];

    public static function run(): void
    {
        $file = ROOT . '/config/config.php';
        if (!is_file($file)) {
            http_response_code(503);
            exit('Panel is not installed. Run: php bin/install.php');
        }
        self::$config = require $file;

        try {
            Brand\Seal::check();
            Security::boot();
            $router = new Router();
            require ROOT . '/src/routes.php';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
        } catch (HttpException $e) {
            self::fail($e->getCode() ?: 500, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('[panel] ' . $e);
            self::fail(500, cfg('debug') ? $e->getMessage() : 'Internal server error');
        }
    }

    public static function fail(int $code, string $msg): never
    {
        http_response_code($code);
        $wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
        if (str_starts_with($msg, 'Integrity check failed')) {
            // Never run (possibly modified) templates when the seal is broken.
            header('Content-Type: text/plain; charset=utf-8');
            echo "Error $code\n$msg\n";
            exit;
        }
        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode(['error' => $msg]);
        } else {
            try {
                View::render('error', ['title' => 'Error ' . $code, 'code' => $code, 'msg' => $msg, 'layout' => 'guest']);
            } catch (\Throwable) {
                // e.g. the integrity seal failed, so the branded layout itself must not render
                header('Content-Type: text/plain; charset=utf-8');
                echo "Error $code\n$msg\n";
            }
        }
        exit;
    }
}

final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, string $handler, array $mw = []): void
    {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[A-Za-z0-9\-]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler, $mw];
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/') ?: '/';
        // Flood guard: one address may make 600 dynamic requests a minute (assets are served by the web server and not counted).
        if (!str_starts_with($path, '/assets/') && !Security::hit('req:' . Security::ip(), 600, 60)) {
            header('Retry-After: 60');
            throw new HttpException('Too many requests. Slow down and try again in a minute.', 429);
        }
        foreach ($this->routes as [$m, $re, $handler, $mw]) {
            if ($m !== $method || !preg_match($re, $path, $mt)) {
                continue;
            }
            $params = array_filter($mt, 'is_string', ARRAY_FILTER_USE_KEY);
            if (!in_array('api', $mw, true) && !in_array($method, ['GET', 'HEAD'], true)) {
                Security::verifyCsrf();
            }
            foreach ($mw as $w) {
                Middleware::run($w);
            }
            [$class, $fn] = explode('@', $handler);
            $class = 'App\\Controllers\\' . $class;
            (new $class())->$fn($params);
            return;
        }
        throw new HttpException('Not found', 404);
    }
}

final class Middleware
{
    public static function run(string $name): void
    {
        switch ($name) {
            case 'guest':
                if (Auth::user()) {
                    redirect('/');
                }
                break;
            case 'auth':
                if (!Auth::user()) {
                    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
                        throw new HttpException('Session expired', 401);
                    }
                    redirect('/login');
                }
                $u = Auth::user();
                $pp = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                if ($u && Auth::twoFaRequired($u) && !$u['totp_enabled'] && !str_starts_with($pp, '/account') && $pp !== '/logout') {
                    flash('error', 'Two-factor authentication is required. Set it up to continue.');
                    redirect('/account/security');
                }
                break;
            case 'admin':
                $u = Auth::user();
                if (!$u) {
                    redirect('/login');
                }
                if (!Roles::inAdmin($u)) {
                    throw new HttpException('Forbidden', 403);
                }
                $sec = Roles::sectionFor((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
                $act = Roles::actionFor($_SERVER['REQUEST_METHOD'] ?? 'GET', (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
                if ($act !== null && !Roles::can($u, $act)) {
                    throw new HttpException('Your role does not allow that action.', 403);
                }
                if (($sec === 'full' && !$u['is_admin']) || ($sec !== null && $sec !== 'full' && !Roles::can($u, $sec))) {
                    throw new HttpException('Forbidden', 403);
                }
                if (Auth::twoFaRequired($u) && !$u['totp_enabled'] && parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) !== '/admin/settings') {
                    flash('error', 'Two-factor authentication must be enabled first.');
                    redirect('/account/security');
                }
                break;
        }
    }
}
