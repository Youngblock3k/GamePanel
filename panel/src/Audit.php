<?php
declare(strict_types=1);

namespace App;

final class Audit
{
    public static function log(string $action, string $target = '', array $meta = [], ?int $userId = null): void
    {
        $userId ??= Auth::user()['id'] ?? null;
        DB::insert('audit_log', [
            'user_id' => $userId,
            'action' => $action,
            'target' => mb_substr($target, 0, 191),
            'ip' => Security::ip(),
            'meta' => $meta ? json_encode($meta) : null,
            'created_at' => time(),
        ]);
    }
}
