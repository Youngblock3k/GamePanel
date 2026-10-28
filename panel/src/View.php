<?php
declare(strict_types=1);

namespace App;

final class View
{
    /** $name is always a hard-coded view path, never user input. */
    public static function render(string $name, array $data = []): void
    {
        $layout = $data['layout'] ?? (str_starts_with((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/admin') ? 'admin' : 'app');
        $user = Auth::user();
        extract($data, EXTR_SKIP);
        ob_start();
        require ROOT . '/views/' . $name . '.php';
        $content = ob_get_clean();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        require ROOT . '/views/layouts/' . $layout . '.php';
    }
}
