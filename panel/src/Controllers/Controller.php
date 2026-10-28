<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\HttpException;
use App\ServerService;
use App\View;

abstract class Controller
{
    protected function view(string $name, array $data = []): void
    {
        View::render($name, $data);
    }

    protected function json(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function in(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_string($v) ? trim($v) : $default;
    }

    protected function user(): array
    {
        return Auth::user() ?? throw new HttpException('Unauthenticated', 401);
    }

    protected function server(array $p): array
    {
        $s = ServerService::find($p['uuid'] ?? '', $this->user());
        $need = ServerService::needs((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), $_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($need === 'owner' ? !$s['is_owner'] : !in_array($need, $s['sub_perms'], true)) {
            throw new HttpException('Your access to this server does not include that.', 403);
        }
        return $s;
    }

    /** Re-authentication gate for sensitive actions. */
    protected function requirePassword(string $field = 'current_password'): bool
    {
        $u = $this->user();
        $ok = Auth::checkPassword($u, $_POST[$field] ?? '');
        if (!$ok) {
            \App\Audit::log('auth.reauth_failed', $u['username']);
            flash('error', 'Current password is incorrect.');
        }
        return $ok;
    }
}
