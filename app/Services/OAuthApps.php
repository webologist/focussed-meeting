<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;

/**
 * Which OAuth app (client ID and secret) to use for Google or Microsoft sign-in.
 * A company can register its own app in Integrations; otherwise the platform app from config.php is used.
 */
final class OAuthApps
{
    /** @return array{client_id:string, client_secret:string, tenant:string, source:string}|null */
    public static function get(int $orgId, string $provider): ?array
    {
        $row = $orgId ? DB::one('SELECT * FROM oauth_clients WHERE org_id = ? AND provider = ?', [$orgId, $provider]) : null;
        if ($row) {
            return ['client_id' => $row['client_id'], 'client_secret' => Crypto::decrypt($row['client_secret_enc']), 'tenant' => $row['tenant'] ?: 'common', 'source' => 'company'];
        }
        $id = (string)config("$provider.client_id");
        $secret = (string)config("$provider.client_secret");
        if ($id === '' || $secret === '') return null;
        return ['client_id' => $id, 'client_secret' => $secret, 'tenant' => (string)config("$provider.tenant", 'common'), 'source' => 'platform'];
    }

    public static function companyApp(int $orgId, string $provider): ?array
    {
        return DB::one('SELECT id, client_id, tenant, updated_at, created_at FROM oauth_clients WHERE org_id = ? AND provider = ?', [$orgId, $provider]);
    }

    public static function save(int $orgId, string $provider, string $clientId, string $secret, ?string $tenant, int $userId): void
    {
        DB::run('INSERT INTO oauth_clients (org_id, provider, client_id, client_secret_enc, tenant, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), client_secret_enc = VALUES(client_secret_enc), tenant = VALUES(tenant), updated_at = UTC_TIMESTAMP()',
            [$orgId, $provider, $clientId, Crypto::encrypt($secret), $tenant ?: null, $userId]);
    }

    public static function remove(int $orgId, string $provider): void
    {
        DB::delete('oauth_clients', ['org_id' => $orgId, 'provider' => $provider]);
    }
}
