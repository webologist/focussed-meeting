<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;

/** Per-company integration settings. Secrets are encrypted at rest. */
final class Integrations
{
    public static function get(int $orgId, string $provider): ?array
    {
        $row = DB::one('SELECT * FROM integrations WHERE org_id = ? AND provider = ?', [$orgId, $provider]);
        if (!$row) return null;
        $row['config'] = Crypto::decryptArray($row['config_enc']);
        unset($row['config_enc']);
        return $row;
    }

    public static function all(int $orgId): array
    {
        $out = [];
        foreach (DB::all('SELECT provider, account_label, status, last_error, updated_at, created_at FROM integrations WHERE org_id = ?', [$orgId]) as $r) {
            $out[$r['provider']] = $r;
        }
        return $out;
    }

    public static function save(int $orgId, string $provider, array $config, ?string $label, int $userId): void
    {
        $enc = Crypto::encryptArray($config);
        DB::run('INSERT INTO integrations (org_id, provider, account_label, config_enc, status, last_error, connected_by, created_at, updated_at)
                 VALUES (?,?,?,?, "connected", NULL, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE account_label = VALUES(account_label), config_enc = VALUES(config_enc), status = "connected",
                   last_error = NULL, connected_by = VALUES(connected_by), updated_at = UTC_TIMESTAMP()',
            [$orgId, $provider, $label, $enc, $userId]);
    }

    /** Update stored config without touching label (e.g. refreshed OAuth tokens). */
    public static function updateConfig(int $orgId, string $provider, array $config): void
    {
        DB::run('UPDATE integrations SET config_enc = ?, updated_at = UTC_TIMESTAMP() WHERE org_id = ? AND provider = ?',
            [Crypto::encryptArray($config), $orgId, $provider]);
    }

    public static function markError(int $orgId, string $provider, string $error): void
    {
        DB::run('UPDATE integrations SET status = "error", last_error = ?, updated_at = UTC_TIMESTAMP() WHERE org_id = ? AND provider = ?',
            [mb_substr($error, 0, 490), $orgId, $provider]);
    }

    public static function remove(int $orgId, string $provider): void
    {
        DB::delete('integrations', ['org_id' => $orgId, 'provider' => $provider]);
    }

    public static function connected(int $orgId, string $provider): bool
    {
        return (bool)DB::value('SELECT 1 FROM integrations WHERE org_id = ? AND provider = ?', [$orgId, $provider]);
    }
}
