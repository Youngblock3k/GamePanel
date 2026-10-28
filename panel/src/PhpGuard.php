<?php
declare(strict_types=1);

namespace App;

/**
 * Flags PHP builds that predate the October 2026 "php2shell" fixes (CVE-2026-109618 / CVE-2026-109619).
 * Distro builds (e.g. Ubuntu) backport fixes without changing the version number, so this is advisory.
 */
final class PhpGuard
{
    private const PATCHED = ['8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'];

    /** @return array{ok:bool,msg:string} */
    public static function status(): array
    {
        $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $min = self::PATCHED[$branch] ?? null;
        if ($min === null) {
            return ['ok' => false, 'msg' => "PHP $branch receives no security fixes for CVE-2026-109618/109619. Upgrade to 8.3 or newer."];
        }
        if (version_compare(PHP_VERSION, $min, '<')) {
            return ['ok' => false, 'msg' => 'PHP ' . PHP_VERSION . " is older than $min, the first release fixing CVE-2026-109618/109619. Update PHP now (if this is a distro package, confirm the backport with its changelog)."];
        }
        return ['ok' => true, 'msg' => 'PHP ' . PHP_VERSION . ' includes the CVE-2026-109618/109619 fixes.'];
    }
}
