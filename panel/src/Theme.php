<?php
declare(strict_types=1);

namespace App;

/** Admin-chosen look: colours, corner radius, button/card style, density and font. Output is a generated stylesheet (/theme.css) made only of validated values. */
final class Theme
{
    /** name => [label, background, panel, text, accent] */
    public const PRESETS = [
        'graphite' => ['Graphite (default)', '#131314', '#1b1c1e', '#dcd9d2', '#d9a441'],
        'slate' => ['Slate', '#0f1417', '#161d21', '#d3dcd8', '#4f9f86'],
        'midnight' => ['Midnight', '#0b1020', '#121a30', '#d5dcf0', '#6b8cff'],
        'ember' => ['Ember', '#161210', '#201915', '#e8dccf', '#d9822b'],
        'plum' => ['Plum', '#14101a', '#1d1726', '#e3d9ee', '#a87bd6'],
        'paper' => ['Paper', '#e8e3d3', '#f6f2e6', '#1f1c17', '#2f6b4a'],
    ];

    /** The option values a look is made of. */
    public const OPTIONS = [
        'btn' => ['solid' => 'Solid', 'soft' => 'Tinted', 'outline' => 'Outline'],
        'card' => ['flat' => 'Flat', 'line' => 'Outlined', 'raised' => 'Raised block'],
        'dens' => ['compact' => 'Compact', 'cozy' => 'Cozy', 'roomy' => 'Roomy'],
        'font' => ['sans' => 'System sans', 'grotesk' => 'Narrow grotesk', 'mono' => 'Monospace'],
    ];

    /** The three ready-made looks: key => [label, blurb, btn, card, dens, font, caps, radius] */
    public const LOOKS = [
        'quiet' => ['Quiet', 'Calm and flat. Tinted buttons, thin lines.', 'soft', 'line', 'cozy', 'sans', 0, 10],
        'terminal' => ['Terminal', 'Dense and sharp. Monospace, outline buttons.', 'outline', 'line', 'compact', 'mono', 1, 2],
        'block' => ['Block', 'Chunky edges and offset shadows.', 'solid', 'raised', 'roomy', 'grotesk', 1, 12],
    ];

    /** Themes any user can pick for themselves: key => [label, bg, panel, text, accent, btn, card, dens, font, caps, radius]. */
    public const THEMES = [
        'arix' => ['Arix', '#0c0b1a', '#15142b', '#e4e2f5', '#6a4cf5', 'solid', 'line', 'cozy', 'sans', 0, 8],
        'graphite' => ['Graphite', '#131314', '#1b1c1e', '#dcd9d2', '#d9a441', 'soft', 'line', 'cozy', 'sans', 0, 10],
        'midnight' => ['Midnight terminal', '#0b1020', '#121a30', '#d5dcf0', '#6b8cff', 'outline', 'line', 'compact', 'mono', 1, 2],
        'ember' => ['Ember block', '#161210', '#201915', '#e8dccf', '#d9822b', 'solid', 'raised', 'roomy', 'grotesk', 1, 12],
        'forest' => ['Forest', '#0f1714', '#16211c', '#d6e2da', '#4fa37f', 'soft', 'flat', 'cozy', 'sans', 0, 14],
        'paper' => ['Paper (light)', '#e8e3d3', '#f6f2e6', '#1f1c17', '#2f6b4a', 'soft', 'line', 'cozy', 'sans', 0, 10],
    ];

    /** The signed-in user's own theme key, or '' to follow the panel's look. */
    public static function userTheme(): string
    {
        static $k = null;
        if ($k === null) {
            $k = '';
            try {
                $u = Auth::user();
                $v = $u ? Settings::get('utheme:' . (int)$u['id'], '') : '';
                $k = isset(self::THEMES[$v]) ? $v : '';
            } catch (\Throwable) {
                $k = '';
            }
        }
        return $k;
    }

    private const FONTS = [
        'sans' => 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
        'grotesk' => '"Bahnschrift","Franklin Gothic Medium","Arial Narrow",system-ui,sans-serif',
        'mono' => 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
    ];

    public static function values(bool $withUser = true): array
    {
        $key = Settings::get('theme_preset', 'graphite');
        $key = isset(self::PRESETS[$key]) ? $key : 'graphite';
        $p = self::PRESETS[$key];
        $hex = static fn(string $k, string $d): string => preg_match('/^#[0-9a-f]{6}$/', $v = Settings::get($k, '')) ? $v : $d;
        $lk = Settings::get('ui_look', 'quiet');
        $lk = isset(self::LOOKS[$lk]) || $lk === 'custom' ? $lk : 'quiet';
        $d = self::LOOKS[$lk] ?? self::LOOKS['quiet'];
        $opt = static fn(string $k, string $def): string => isset(self::OPTIONS[$k][$v = Settings::get('ui_' . $k, '')]) ? $v : $def;
        $radius = max(0, min(32, (int)Settings::get('theme_radius', (string)$d[7])));
        $ut = $withUser ? self::userTheme() : '';
        if ($ut !== '') {
            return self::fromTheme($ut);
        }
        return [
            'preset' => $key, 'look' => $lk, 'theme' => '',
            'bg' => $hex('theme_bg', $p[1]), 'panel' => $hex('theme_panel', $p[2]),
            'text' => $hex('theme_text', $p[3]), 'accent' => $hex('theme_accent', $p[4]),
            'btn' => $opt('btn', $d[2]), 'card' => $opt('card', $d[3]), 'dens' => $opt('dens', $d[4]), 'font' => $opt('font', $d[5]),
            'caps' => Settings::get('ui_caps', (string)$d[6]) === '1' ? 1 : 0,
            'radius' => $radius,
            'corners' => array_map(static fn(string $k): int => max(0, min(32, (int)Settings::get('theme_r_' . $k, (string)$radius))), ['tl', 'tr', 'br', 'bl']),
        ];
    }

    /** The full values array for one of the built-in themes. */
    public static function fromTheme(string $k): array
    {
        $t = self::THEMES[$k];
        return ['preset' => 'graphite', 'look' => 'custom', 'theme' => $k, 'bg' => $t[1], 'panel' => $t[2], 'text' => $t[3], 'accent' => $t[4],
            'btn' => $t[5], 'card' => $t[6], 'dens' => $t[7], 'font' => $t[8], 'caps' => $t[9], 'radius' => $t[10], 'corners' => [$t[10], $t[10], $t[10], $t[10]]];
    }

    /** Cards for the theme page: the panel's own look first, then each built-in theme. */
    public static function cards(): array
    {
        $blurb = ['arix' => 'Deep indigo, solid buttons, tidy 8 px corners.', 'graphite' => 'Warm grey with amber, calm tinted buttons.', 'midnight' => 'Dense monospace with sharp outline buttons.',
            'ember' => 'Chunky edges and offset shadows.', 'forest' => 'Soft green, flat and roomy.', 'paper' => 'A light theme for bright rooms.'];
        $out = [['key' => '', 'label' => 'Panel default', 'blurb' => "Follows the look the administrator chose.", 'vars' => self::varString(self::values(false))]];
        foreach (self::THEMES as $k => $t) {
            $out[] = ['key' => $k, 'label' => $t[0], 'blurb' => $blurb[$k] ?? '', 'vars' => self::varString(self::fromTheme($k))];
        }
        return $out;
    }

    public static function version(): string
    {
        return substr(md5(json_encode(self::values())), 0, 8);
    }

    private static function rgb(string $h): array
    {
        return [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))];
    }

    private static function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(static fn($v) => max(0, min(255, (int)round($v))), $c));
    }

    public static function mix(string $a, string $b, float $t): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);
        return self::hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
    }

    public static function luminance(string $h): float
    {
        $f = static fn(float $v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = self::rgb($h);
        return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Colours that would make text or the copyright footer unreadable are rejected. */
    public static function validate(array $v): ?string
    {
        foreach (['bg', 'panel', 'text', 'accent'] as $k) {
            if (!preg_match('/^#[0-9a-f]{6}$/', $v[$k])) {
                return 'Colours must look like #1a2b3c.';
            }
        }
        if (self::contrast($v['text'], $v['bg']) < 4.5 || self::contrast($v['text'], $v['panel']) < 4.5) {
            return 'Text colour needs more contrast against the background and panels (at least 4.5:1).';
        }
        if (self::contrast($v['accent'], $v['bg']) < 2.2) {
            return 'Accent colour is too close to the background.';
        }
        return null;
    }

    public static function varString(array $v): string
    {
        $dark = self::luminance($v['bg']) < 0.4;
        $panel2 = self::mix($v['panel'], $v['text'], 0.07);
        $line = self::mix($v['panel'], $v['text'], 0.16);
        $edge = self::mix($v['panel'], $v['text'], $dark ? 0.26 : 0.55);
        $ink = $dark ? self::mix($v['bg'], '#000000', 0.6) : self::mix($v['text'], $v['bg'], 0.15);
        $muted = self::mix($v['text'], $v['bg'], 0.42);
        $onAccent = self::contrast($v['accent'], '#0d1613') >= self::contrast($v['accent'], '#f4f7f5') ? '#0d1613' : '#f4f7f5';
        $cs = $v['corners'];
        $big = implode('px ', $cs) . 'px';
        $mid = implode('px ', array_map(static fn($x) => (int)round($x * 0.7), $cs)) . 'px';
        $sm = implode('px ', array_map(static fn($x) => (int)round($x * 0.5), $cs)) . 'px';
        $pill = max($cs) > 0 ? '99px' : '0';
        $acc = $v['accent'];
        $tint = self::mix($v['panel'], $acc, $dark ? 0.2 : 0.16);
        $raised = $v['card'] === 'raised';
        $btn = match ($v['btn']) {
            'soft' => ['bg' => $tint, 'fg' => $v['text'], 'bd' => self::mix($v['panel'], $acc, 0.5), 'bw' => '1px'],
            'outline' => ['bg' => 'transparent', 'fg' => $v['text'], 'bd' => $acc, 'bw' => '1.5px'],
            default => ['bg' => $acc, 'fg' => $onAccent, 'bd' => $raised ? $ink : self::mix($acc, '#000000', 0.2), 'bw' => $raised ? '2px' : '1px'],
        };
        [$cbw, $csh] = match ($v['card']) {
            'flat' => ['0px', 'none'],
            'raised' => ['2px', "4px 4px 0 $ink"],
            default => ['1px', 'none'],
        };
        $pad = ['compact' => ['11px 14px', '7px'], 'cozy' => ['18px 20px', '10px'], 'roomy' => ['24px 26px', '13px']][$v['dens']];
        $btnSh = $raised && $v['btn'] === 'solid' ? "2px 2px 0 $ink" : 'none';
        $caps = $v['caps'] ? 'uppercase' : 'none';
        $capsLs = $v['caps'] ? '.06em' : '0';
        $vars = "--bg:{$v['bg']};--panel:{$v['panel']};--panel2:$panel2;--line:$line;--edge:$edge;--ink:$ink;--text:{$v['text']};--muted:$muted;--accent:$acc;--accent2:$acc;--on-accent:$onAccent;--radius:{$cs[0]}px;"
            . "--rb:$big;--rm:$mid;--rs:$sm;--pill:$pill;--font:" . self::FONTS[$v['font']] . ";--cbw:$cbw;--csh:$csh;--cpad:{$pad[0]};--rowpad:{$pad[1]};"
            . "--btn-bg:{$btn['bg']};--btn-fg:{$btn['fg']};--btn-bd:{$btn['bd']};--btn-bw:{$btn['bw']};--btn-sh:$btnSh;--tint:$tint;"
            . "--caps:$caps;--caps-ls:$capsLs;";
        return $vars;
    }

    public static function css(): string
    {
        $v = self::values();
        $onAccent = self::contrast($v['accent'], '#0d1613') >= self::contrast($v['accent'], '#f4f7f5') ? '#0d1613' : '#f4f7f5';
        return ':root{' . self::varString($v) . "}body{background:{$v['bg']};color:{$v['text']}}select option:checked{background:{$v['accent']};color:$onAccent}";
    }

    public static function serve(): never
    {
        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        header('Vary: Cookie');
        echo self::css();
        exit;
    }
}
