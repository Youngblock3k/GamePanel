<?php
declare(strict_types=1);

// Emergency recovery from the server shell:
//   php bin/reset-password.php <username-or-email> [--disable-2fa] [--password=NewPassw0rd...]
if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    fwrite(STDERR, "Usage: php bin/reset-password.php <username-or-email> [--disable-2fa] [--password=...]\n");
    exit(1);
}
define('ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $c): void {
    if (str_starts_with($c, 'App' . chr(92))) {
        $f = ROOT . '/src/' . str_replace(chr(92), '/', substr($c, 4)) . '.php';
        is_file($f) && require $f;
    }
});
require ROOT . '/src/helpers.php';
require ROOT . '/src/App.php';
App\App::$config = require ROOT . '/config/config.php';

$opts = getopt('', ['password::', 'disable-2fa']);
$user = App\DB::one('SELECT * FROM users WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)', [$argv[1], $argv[1]]);
if (!$user) {
    fwrite(STDERR, "No such user.\n");
    exit(1);
}
$pw = $opts['password'] ?? null;
if ($pw === null) {
    echo 'New password for ' . $user['username'] . ': ';
    $pw = trim((string)fgets(STDIN));
}
if ($err = App\Auth::policy($pw, $user['username'])) {
    fwrite(STDERR, "$err\n");
    exit(1);
}
App\DB::q('UPDATE users SET password = ?, password_changed_at = ?, disabled = 0 WHERE id = ?', [App\Auth::hash($pw), time(), $user['id']]);
App\DB::q('DELETE FROM user_sessions WHERE user_id = ?', [$user['id']]);
App\DB::q('DELETE FROM rate_limits WHERE k LIKE ?', ['login:%']);   // clear any lockout
if (isset($opts['disable-2fa'])) {
    App\DB::q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL WHERE id = ?', [$user['id']]);
    echo "Two-factor authentication disabled.\n";
}
echo "Password updated for {$user['username']}; sessions revoked; login lockouts cleared.\n";
