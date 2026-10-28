<?php
declare(strict_types=1);

// Usage: php bin/install.php        (CLI only; refuses to overwrite an existing install)
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!extension_loaded('sodium') || !extension_loaded('pdo') || !extension_loaded('curl')) {
    fwrite(STDERR, "Required PHP extensions: sodium, pdo (+driver), curl.\n");
    exit(1);
}

$root = dirname(__DIR__);
$cfgFile = $root . '/config/config.php';
if (is_file($cfgFile)) {
    fwrite(STDERR, "Already installed ($cfgFile exists). Delete it deliberately to reinstall.\n");
    exit(1);
}

function ask(string $q, string $default = '', bool $hidden = false, string $env = ''): string
{
    if ($env !== '' && getenv($env) !== false) {
        return (string)getenv($env); // unattended install
    }
    echo $q . ($default !== '' ? " [$default]" : '') . ': ';
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $v = trim((string)fgets(STDIN));
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
        echo "\n";
    }
    return $v === '' ? $default : $v;
}

echo "== GamePanel installer ==\n";
$driver = strtolower(ask('Database (sqlite/mysql)', 'sqlite', false, 'VL_DB'));
$db = ['driver' => $driver];
if ($driver === 'sqlite') {
    @mkdir($root . '/storage', 0750, true);
    $db['path'] = $root . '/storage/panel.sqlite';
} elseif ($driver === 'mysql') {
    $db += ['host' => ask('DB host', '127.0.0.1', false, 'VL_DB_HOST'), 'port' => (int)ask('DB port', '3306'), 'name' => ask('DB name', 'vorlane', false, 'VL_DB_NAME'),
        'user' => ask('DB user', 'vorlane', false, 'VL_DB_USER'), 'pass' => ask('DB password', '', true, 'VL_DB_PASS')];
} else {
    exit("Unknown driver.\n");
}

$config = [
    'app_name' => ask('Panel name', 'Game Panel', false, 'VL_NAME'),
    'app_key' => base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
    'db' => $db,
    'debug' => false,
    'copyright_holder' => ask('Copyright holder shown in the footer', 'Game Panel', false, 'VL_HOLDER'),
    'recaptcha_site' => ask('Google reCAPTCHA v2 site key (blank to skip)', '', false, 'VL_RECAPTCHA_SITE'),
    'recaptcha_secret' => ask('Google reCAPTCHA v2 secret key', '', true, 'VL_RECAPTCHA_SECRET'),
    'app_url' => ask('Public URL of this panel (used in reset e-mails)', 'http://localhost:8080', false, 'VL_URL'),
    'mail_host' => ask('SMTP host for password-reset e-mails (blank = save e-mails to storage/outbox)', '', false, 'VL_MAIL_HOST'),
    'mail_port' => (int)ask('SMTP port', '587', false, 'VL_MAIL_PORT'),
    'mail_secure' => ask('SMTP security (tls/ssl/none)', 'tls', false, 'VL_MAIL_SECURE'),
    'mail_user' => ask('SMTP username (blank for none)', '', false, 'VL_MAIL_USER'),
    'mail_pass' => ask('SMTP password', '', true, 'VL_MAIL_PASS'),
    'mail_from' => ask('From address', 'no-reply@localhost', false, 'VL_MAIL_FROM'),
    'require_admin_2fa' => true,
    'trusted_proxies' => [],          // add your reverse proxy IP to trust X-Forwarded-*
    'allow_insecure_daemon' => false, // keep false in production
];
@mkdir($root . '/config', 0750, true);
file_put_contents($cfgFile, "<?php\nreturn " . var_export($config, true) . ";\n");
chmod($cfgFile, 0600);

define('ROOT', $root);
spl_autoload_register(static function (string $c) use ($root): void {
    if (str_starts_with($c, 'App\\')) {
        $f = $root . '/src/' . str_replace('\\', '/', substr($c, 4)) . '.php';
        is_file($f) && require $f;
    }
});
require $root . '/src/helpers.php';
require $root . '/src/App.php'; // defines App, Router, Middleware, HttpException
App\App::$config = $config;

// Schema
$pk = $driver === 'sqlite' ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
$sql = str_replace(["\r\n", '{PK}'], ["\n", $pk], (string)file_get_contents($root . '/database/schema.sql'));
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
    App\DB::pdo()->exec($stmt);
}

// Admin user
echo "\n-- Create the first administrator --\n";
$username = ask('Username', 'admin', false, 'VL_ADMIN_USER');
$email = ask('Email', '', false, 'VL_ADMIN_EMAIL');
do {
    $pw = ask('Password (12+ chars, upper/lower/number)', '', true, 'VL_ADMIN_PASS');
    $err = App\Auth::policy($pw, $username);
    $err && print("  $err\n");
} while ($err);
App\DB::insert('users', [
    'uuid' => App\Controllers\AdminController::uuid4(), 'username' => $username, 'email' => strtolower($email),
    'password' => App\Auth::hash($pw), 'is_admin' => 1, 'password_changed_at' => time(), 'created_at' => time(),
]);

// Egg presets (Node.js, Python, Minecraft, Java, Rust, CS2, Hytale)
foreach (array_keys(App\EggPresets::all()) as $k) {
    App\EggPresets::install($k);
}

echo "\nInstalled. Start the panel for local testing with:\n  php -S 127.0.0.1:8080 -t public public/index.php\nThen sign in and enable 2FA (required for admins).\n";
