<?php
declare(strict_types=1);

namespace App;

/**
 * Small QR Code encoder (byte mode, error-correction level M, versions 1-10) used for the 2FA enrolment code.
 * Renders to an inline SVG so no image library, external service or CSP exception is needed.
 */
final class Qr
{
    /** version => [data codewords, EC codewords per block, [[blocks, data codewords per block], ...]] (level M) */
    private const SPEC = [
        1 => [16, 10, [[1, 16]]], 2 => [28, 16, [[1, 28]]], 3 => [44, 26, [[1, 44]]], 4 => [64, 18, [[2, 32]]],
        5 => [86, 24, [[2, 43]]], 6 => [108, 16, [[4, 27]]], 7 => [124, 18, [[4, 31]]],
        8 => [154, 22, [[2, 38], [2, 39]]], 9 => [182, 22, [[3, 36], [2, 37]]], 10 => [216, 26, [[4, 43], [1, 44]]],
    ];
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50]];

    private static array $exp = [];
    private static array $log = [];

    private static function gf(): void
    {
        if (self::$exp) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    private static function rs(array $data, int $ec): array
    {
        $gen = [1];
        for ($i = 0; $i < $ec; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $c) {
                $next[$j] ^= $c;
                $next[$j + 1] ^= self::mul($c, self::$exp[$i]);
            }
            $gen = $next;
        }
        $res = array_fill(0, $ec, 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($res);
            $res[] = 0;
            if ($factor !== 0) {
                for ($i = 0; $i < $ec; $i++) {
                    $res[$i] ^= self::mul($gen[$i + 1], $factor);
                }
            }
        }
        return $res;
    }

    /** @return bool[][] [row][col], true = dark. $mask null = pick the best by penalty score. */
    public static function matrix(string $data, ?int $mask = null): array
    {
        self::gf();
        $len = strlen($data);
        $ver = 0;
        foreach (self::SPEC as $v => [$dcw]) {
            if (4 + ($v <= 9 ? 8 : 16) + 8 * $len <= 8 * $dcw) {
                $ver = $v;
                break;
            }
        }
        if (!$ver) {
            throw new \InvalidArgumentException('Data too long for the built-in QR encoder');
        }
        [$dcw, $ecLen, $groups] = self::SPEC[$ver];

        // ---- data bits
        $bits = '0100' . str_pad(decbin($len), $ver <= 9 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $dcw * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $cw = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($cw) < $dcw; $pad++) {
            $cw[] = $pad % 2 === 0 ? 0xEC : 0x11;
        }

        // ---- blocks + error correction, interleaved
        $blocks = [];
        $ecs = [];
        $pos = 0;
        foreach ($groups as [$n, $per]) {
            for ($i = 0; $i < $n; $i++) {
                $b = array_slice($cw, $pos, $per);
                $pos += $per;
                $blocks[] = $b;
                $ecs[] = self::rs($b, $ecLen);
            }
        }
        $final = [];
        $maxLen = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) {
                    $final[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($ecs as $e) {
                $final[] = $e[$i];
            }
        }
        $stream = '';
        foreach ($final as $c) {
            $stream .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        }

        // ---- function patterns
        $size = 17 + 4 * $ver;
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $fn = array_fill(0, $size, array_fill(0, $size, false));
        $set = function (int $x, int $y, bool $dark) use (&$m, &$fn, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $m[$y][$x] = $dark;
                $fn[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $d = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $d !== 2 && $d !== 4);
                }
            }
        }
        $al = self::ALIGN[$ver];
        $n = count($al);
        foreach ($al as $i => $ay) {
            foreach ($al as $j => $ax) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $format = function (int $maskId) use ($set, $size): void {
            $d = $maskId;                         // level M = 00, then 3 mask bits
            $rem = $d;
            for ($i = 0; $i < 10; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
            }
            $bits = (($d << 10) | $rem) ^ 0x5412;
            $b = fn(int $i): bool => (($bits >> $i) & 1) === 1;
            for ($i = 0; $i <= 5; $i++) {
                $set(8, $i, $b($i));
            }
            $set(8, 7, $b(6));
            $set(8, 8, $b(7));
            $set(7, 8, $b(8));
            for ($i = 9; $i < 15; $i++) {
                $set(14 - $i, 8, $b($i));
            }
            for ($i = 0; $i < 8; $i++) {
                $set($size - 1 - $i, 8, $b($i));
            }
            for ($i = 8; $i < 15; $i++) {
                $set(8, $size - 15 + $i, $b($i));
            }
            $set(8, $size - 8, true);
        };
        $format(0); // reserve the area
        if ($ver >= 7) {
            $rem = $ver;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($ver << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $size - 11 + $i % 3;
                $bb = intdiv($i, 3);
                $set($a, $bb, $bit);
                $set($bb, $a, $bit);
            }
        }

        // ---- place data (zig-zag, right to left)
        $i = 0;
        $total = strlen($stream);
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $y = ((($right + 1) & 2) === 0) ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $i < $total) {
                        $m[$y][$x] = $stream[$i] === '1';
                        $i++;
                    }
                }
            }
        }

        // ---- mask
        $apply = function (int $k) use ($m, $fn, $size): array {
            $o = $m;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if ($fn[$y][$x]) {
                        continue;
                    }
                    $inv = match ($k) {
                        0 => ($x + $y) % 2 === 0, 1 => $y % 2 === 0, 2 => $x % 3 === 0, 3 => ($x + $y) % 3 === 0,
                        4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0, 5 => $x * $y % 2 + $x * $y % 3 === 0,
                        6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0, default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                    };
                    if ($inv) {
                        $o[$y][$x] = !$o[$y][$x];
                    }
                }
            }
            return $o;
        };
        if ($mask === null) {
            $best = PHP_INT_MAX;
            foreach (range(0, 7) as $k) {
                $try = $apply($k);
                $score = self::penalty($try, $size);
                if ($score < $best) {
                    $best = $score;
                    $mask = $k;
                }
            }
        }
        $m = $apply($mask);
        $format($mask); // writes into $m through the captured reference
        return $m;
    }

    private static function penalty(array $m, int $size): int
    {
        $p = 0;
        $dark = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($dir = 0; $dir < 2; $dir++) {
                $run = 1;
                for ($x = 1; $x < $size; $x++) {
                    $a = $dir ? $m[$x][$y] : $m[$y][$x];
                    $b = $dir ? $m[$x - 1][$y] : $m[$y][$x - 1];
                    if ($a === $b) {
                        $run++;
                        if ($run === 5) {
                            $p += 3;
                        } elseif ($run > 5) {
                            $p++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
            for ($x = 0; $x < $size; $x++) {
                $dark += $m[$y][$x] ? 1 : 0;
                if ($x > 0 && $y > 0 && $m[$y][$x] === $m[$y][$x - 1] && $m[$y][$x] === $m[$y - 1][$x] && $m[$y][$x] === $m[$y - 1][$x - 1]) {
                    $p += 3;
                }
            }
        }
        return $p + (int)(abs($dark * 20 - $size * $size * 10) / ($size * $size)) * 10;
    }

    public static function svg(string $data, int $border = 4): string
    {
        $m = self::matrix($data);
        $n = count($m);
        $d = '';
        foreach ($m as $y => $row) {
            $x = 0;
            while ($x < $n) {
                if ($row[$x]) {
                    $s = $x;
                    while ($x < $n && $row[$x]) {
                        $x++;
                    }
                    $d .= 'M' . ($s + $border) . ' ' . ($y + $border) . 'h' . ($x - $s) . 'v1h-' . ($x - $s) . 'z';
                } else {
                    $x++;
                }
            }
        }
        $t = $n + 2 * $border;
        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" shape-rendering="crispEdges" role="img" aria-label="Two-factor QR code">'
            . '<rect width="' . $t . '" height="' . $t . '" fill="#fff"/><path d="' . $d . '" fill="#000"/></svg>';
    }
}
