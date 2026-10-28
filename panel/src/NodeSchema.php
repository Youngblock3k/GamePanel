<?php
declare(strict_types=1);

namespace App;

/** Adds the extra node columns to databases created before they existed. */
final class NodeSchema
{
    private static bool $done = false;

    public static function ensure(): void
    {
        if (self::$done) {
            return;
        }
        self::$done = true;
        try {
            DB::q('SELECT oom_kill FROM servers LIMIT 1');
        } catch (\Throwable) {
            try {
                DB::q('ALTER TABLE servers ADD COLUMN oom_kill INTEGER NOT NULL DEFAULT 1');
            } catch (\Throwable) {
                // already there
            }
        }
        try {
            DB::q('SELECT mem_over FROM nodes LIMIT 1');
            return;
        } catch (\Throwable) {
            // columns missing: add them
        }
        foreach ([
            "description VARCHAR(255) NOT NULL DEFAULT ''", 'maintenance INTEGER NOT NULL DEFAULT 0', 'max_servers INTEGER NOT NULL DEFAULT 0',
            'mem_over INTEGER NOT NULL DEFAULT 0', 'disk_over INTEGER NOT NULL DEFAULT 0',
        ] as $col) {
            try {
                DB::q('ALTER TABLE nodes ADD COLUMN ' . $col);
            } catch (\Throwable) {
                // already there
            }
        }
    }
}
