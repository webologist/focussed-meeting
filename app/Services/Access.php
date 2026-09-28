<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Tenant scoping and role rules in one place.
 * Admin: everything in the company. Participant: own work, meetings they are invited to.
 * The organiser of a meeting can manage that meeting even as a participant.
 */
final class Access
{
    /** Load a meeting in the current company that the user may see, or abort 404. */
    public static function meeting(int $id): array
    {
        $m = DB::one('SELECT m.*, u.name AS organizer_name, u.email AS organizer_email FROM meetings m JOIN users u ON u.id = m.organizer_id WHERE m.id = ? AND m.org_id = ?', [$id, org_id()]);
        if (!$m || !self::canSeeMeeting($m)) abort(404);
        return $m;
    }

    public static function canSeeMeeting(array $m): bool
    {
        if (is_admin() || (int)$m['organizer_id'] === uid()) return true;
        return (bool)DB::value('SELECT 1 FROM meeting_invitees WHERE meeting_id = ? AND user_id = ?', [$m['id'], uid()]);
    }

    public static function canManageMeeting(array $m): bool { return is_admin() || (int)$m['organizer_id'] === uid(); }

    /** SQL fragment restricting actions (alias a) to those the user may see. */
    public static function actionScope(string $alias = 'a'): array
    {
        if (is_admin()) return ["$alias.org_id = :scope_org", ['scope_org' => org_id()]];
        return ["$alias.org_id = :scope_org AND ($alias.owner_id = :scope_u1 OR $alias.created_by = :scope_u2 OR ($alias.meeting_id IS NOT NULL AND EXISTS (SELECT 1 FROM meeting_invitees mi WHERE mi.meeting_id = $alias.meeting_id AND mi.user_id = :scope_u3)))",
            ['scope_org' => org_id(), 'scope_u1' => uid(), 'scope_u2' => uid(), 'scope_u3' => uid()]];
    }

    public static function action(int $id): array
    {
        [$scope, $params] = self::actionScope('a');
        $a = DB::one("SELECT a.* FROM actions a WHERE a.id = :id AND $scope", ['id' => $id] + $params);
        if (!$a) abort(404);
        return $a;
    }

    public static function canEditAction(array $a): bool { return is_admin() || (int)$a['owner_id'] === uid() || (int)$a['created_by'] === uid(); }

    public static function canNudge(array $a): bool
    {
        return (int)$a['owner_id'] !== uid() && $a['status'] !== 'done' && (is_admin() || (int)$a['created_by'] === uid());
    }

    /** Members of the current company (active + invited) keyed by id. */
    public static function members(bool $includeInvited = true): array
    {
        $rows = DB::all('SELECT id, name, email, phone, role, status, title FROM users WHERE org_id = ? AND status ' . ($includeInvited ? "IN ('active','invited')" : "= 'active'") . ' ORDER BY name', [org_id()]);
        $out = [];
        foreach ($rows as $r) $out[(int)$r['id']] = $r;
        return $out;
    }

    public static function member(int $id): ?array
    {
        return DB::one('SELECT id, name, email, phone, role, status FROM users WHERE id = ? AND org_id = ?', [$id, org_id()]);
    }
}
