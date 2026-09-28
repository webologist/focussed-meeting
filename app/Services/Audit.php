<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Audit
{
    public static function log(string $event, ?string $entity = null, ?int $entityId = null, array $meta = []): void
    {
        if (!org_id()) return;
        DB::insert('audit_log', [
            'org_id' => org_id(), 'user_id' => uid() ?: null, 'event' => $event, 'entity' => $entity, 'entity_id' => $entityId,
            'meta' => $meta ? mb_substr(json_encode($meta), 0, 990) : null, 'ip' => client_ip(), 'created_at' => now_utc(),
        ]);
    }
}
