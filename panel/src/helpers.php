<?php
declare(strict_types=1);

function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cfg(string $key, mixed $default = null): mixed
{
    return App\App::$config[$key] ?? $default;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Security::csrf()) . '">';
}

/** Internal redirects only (blocks open redirects). */
function redirect(string $to): never
{
    if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\')) {
        $to = '/';
    }
    header('Location: ' . $to, true, 303);
    exit;
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = [$type, $msg];
}

function fmt_mb(int|float $mb): string
{
    return $mb >= 1024 ? round($mb / 1024, 1) . ' GB' : round($mb) . ' MB';
}

function fmt_time(?int $ts): string
{
    return $ts ? gmdate('Y-m-d H:i', $ts) . ' UTC' : 'never';
}

/** IP address with a country flag; the address is blurred until hovered. */
function ip_chip(string $ip): string
{
    [$code, $flag] = App\Geo::lookup($ip);
    return '<span class="ipchip" title="' . e($code) . '"><span class="flag">' . $flag . '</span><span class="ipcode">' . e($code) . '</span><span class="ipval">' . e($ip) . '</span></span>';
}

/** Timestamp rendered in the viewer's own time zone by the page script (UTC text is the no-JS fallback). */
function time_tag(?int $ts): string
{
    if (!$ts) {
        return '<span class="muted">never</span>';
    }
    return '<time class="lt" data-ts="' . $ts . '" datetime="' . gmdate('c', $ts) . '">' . e(fmt_time($ts)) . '</time>';
}

/** Small avatar + username. */
function user_chip(?string $name): string
{
    if ($name === null || $name === '') {
        return '<span class="uchip muted">System</span>';
    }
    return '<span class="uchip">' . avatar_html($name, 'sm') . e($name) . '</span>';
}

/** Avatar: uploaded picture if the account has one, otherwise the first letter of the username. */
function avatar_html(string $username, string $size = ''): string
{
    [$ini, $cls] = App\Ui\Assets::avatar($username);
    $url = App\Avatar::url($username);
    return '<span class="avatar ' . $size . ' ' . $cls . '">' . ($url ? '<img src="' . e($url) . '" alt="">' : e($ini)) . '</span>';
}


/** Ring chart: $pct 0-100, coloured by how full it is. Pure SVG attributes, so the strict CSP is fine. */
function donut(float $pct, string $label, string $sub = '', ?string $center = null): string
{
    $p = max(0.0, min(100.0, $pct));
    $cls = $p >= 90 ? 'dn-bad' : ($p >= 75 ? 'dn-warn' : 'dn-ok');
    $c = e($center ?? (string)round($p) . '%');
    return '<figure class="donut"><svg viewBox="0 0 36 36" role="img" aria-label="' . e($label) . ' ' . round($p) . ' percent"><circle class="dn-bg" cx="18" cy="18" r="15.915"/>'
        . '<circle class="dn-fg ' . $cls . '" cx="18" cy="18" r="15.915" stroke-dasharray="' . round($p, 2) . ' ' . round(100 - $p, 2) . '" transform="rotate(-90 18 18)"/>'
        . '<text x="18" y="20.3" text-anchor="middle" class="dn-t">' . $c . '</text></svg>'
        . '<figcaption><b>' . e($label) . '</b>' . ($sub !== '' ? '<small>' . e($sub) . '</small>' : '') . '</figcaption></figure>';
}
