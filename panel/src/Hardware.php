<?php
declare(strict_types=1);

namespace App;

/** What the machine the panel runs on is made of (CPU, RAM, GPU, OS). Read from /proc and /sys on Linux, so no shell is needed. */
final class Hardware
{
    public static function local(): array
    {
        $cache = ROOT . '/storage/hardware.json';
        if (is_file($cache) && filemtime($cache) > time() - 600 && ($c = json_decode((string)file_get_contents($cache), true))) {
            return $c;
        }
        $h = PHP_OS_FAMILY === 'Windows' ? self::windows() : self::linux();
        @file_put_contents($cache, json_encode($h));
        return $h;
    }

    private static function linux(): array
    {
        $h = ['cpu' => '', 'cores' => 0, 'ram_mb' => 0, 'gpus' => [], 'os' => ''];
        if (is_readable('/proc/cpuinfo')) {
            $c = (string)file_get_contents('/proc/cpuinfo');
            $h['cores'] = (int)preg_match_all('/^processor\s*:/m', $c);
            $h['cpu'] = preg_match('/^model name\s*:\s*(.+)$/m', $c, $m) ? trim($m[1]) : '';
        }
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+)/', (string)file_get_contents('/proc/meminfo'), $m)) {
            $h['ram_mb'] = (int)($m[1] / 1024);
        }
        if (is_readable('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)/m', (string)file_get_contents('/etc/os-release'), $m)) {
            $h['os'] = $m[1];
        }
        $h['gpus'] = self::gpus();
        return $h;
    }

    /** Display adapters from /sys/class/drm, named through the PCI id database when it is installed. */
    public static function gpus(): array
    {
        $out = [];
        foreach (glob('/sys/class/drm/card[0-9]*/device') ?: [] as $dev) {
            $ven = strtolower(trim((string)@file_get_contents($dev . '/vendor')));
            $did = strtolower(trim((string)@file_get_contents($dev . '/device')));
            if (!preg_match('/^0x[0-9a-f]{4}$/', $ven) || !preg_match('/^0x[0-9a-f]{4}$/', $did)) {
                continue;
            }
            $ven = substr($ven, 2);
            $did = substr($did, 2);
            $name = self::pciName($ven, $did) ?: strtoupper("$ven:$did");
            $out[$name] = $name;
        }
        return array_values($out);
    }

    private static function pciName(string $ven, string $did): string
    {
        foreach (['/usr/share/hwdata/pci.ids', '/usr/share/misc/pci.ids'] as $f) {
            if (!is_readable($f)) {
                continue;
            }
            $in = false;
            $vname = '';
            foreach (new \SplFileObject($f) as $line) {
                $line = rtrim((string)$line, "\r\n");
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                if ($line[0] !== "\t") {
                    if ($in) {
                        return $vname;
                    }
                    if (str_starts_with($line, $ven . '  ')) {
                        $in = true;
                        $vname = trim(substr($line, 6));
                    }
                } elseif ($in && $line[1] !== "\t" && str_starts_with(substr($line, 1), $did . '  ')) {
                    return trim(preg_replace('/\s*\[.*$/', '', $vname) . ' ' . trim(substr($line, 7)));
                }
            }
            return $vname;
        }
        return '';
    }

    private static function windows(): array
    {
        $h = ['cpu' => '', 'cores' => 0, 'ram_mb' => 0, 'gpus' => [], 'os' => PHP_OS];
        if (!function_exists('shell_exec')) {
            return $h;
        }
        $ps = '$c=Get-CimInstance Win32_Processor|Select-Object -First 1;$m=Get-CimInstance Win32_ComputerSystem;$o=Get-CimInstance Win32_OperatingSystem;'
            . '$g=@(Get-CimInstance Win32_VideoController|ForEach-Object{$_.Name});'
            . '@{cpu=$c.Name;cores=[int]$c.NumberOfLogicalProcessors;ram=[int64]$m.TotalPhysicalMemory;os=$o.Caption;gpus=$g}|ConvertTo-Json -Compress';
        $raw = @shell_exec('powershell -NoProfile -NonInteractive -Command "' . str_replace('"', '\"', $ps) . '" 2>NUL');
        $j = $raw ? json_decode($raw, true) : null;
        if (is_array($j)) {
            $h = ['cpu' => trim((string)($j['cpu'] ?? '')), 'cores' => (int)($j['cores'] ?? 0), 'ram_mb' => (int)(($j['ram'] ?? 0) / 1048576),
                'gpus' => array_values(array_filter((array)($j['gpus'] ?? []), 'is_string')), 'os' => trim((string)($j['os'] ?? ''))];
        }
        return $h;
    }
}
