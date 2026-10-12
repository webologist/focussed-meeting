<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Access;
use App\Services\Actions;

final class DashboardController
{
    public function index(): void
    {
        $tab = in_array($_GET['tab'] ?? '', ['mine', 'approve', 'assigned', 'all', 'done'], true) ? $_GET['tab'] : 'mine';
        $today = today();
        $base = "SELECT a.*, u.name AS owner_name, c.name AS creator_name, m.title AS meeting_title, p.name AS project_name,
                        COALESCE(m.organizer_id, a.created_by) AS approver_id, ap.name AS approver_name,
                        (SELECT COUNT(*) FROM action_notes n WHERE n.action_id = a.id) AS notes_count,
                        (SELECT COUNT(*) FROM action_dependencies d JOIN actions b ON b.id = d.depends_on_id WHERE d.action_id = a.id AND b.status <> 'done') AS blockers
                   FROM actions a JOIN users u ON u.id = a.owner_id JOIN users c ON c.id = a.created_by
                   LEFT JOIN meetings m ON m.id = a.meeting_id LEFT JOIN projects p ON p.id = a.project_id
                   JOIN users ap ON ap.id = COALESCE(m.organizer_id, a.created_by)
                  WHERE a.org_id = :org ";
        $byDue = "ORDER BY COALESCE(a.new_deadline, a.deadline), FIELD(a.priority,'high','medium','low')";
        $mine = DB::all($base . "AND a.owner_id = :me AND a.status <> 'done' $byDue", ['org' => org_id(), 'me' => uid()]);
        $toApprove = DB::all($base . "AND a.status = 'review' AND COALESCE(m.organizer_id, a.created_by) = :me $byDue", ['org' => org_id(), 'me' => uid()]);
        $assigned = DB::all($base . "AND a.created_by = :me AND a.owner_id <> :me2 AND a.status <> 'done' $byDue", ['org' => org_id(), 'me' => uid(), 'me2' => uid()]);
        $allMine = $tab === 'all' ? DB::all($base . "AND a.owner_id = :me ORDER BY FIELD(a.status,'open','progress','review','done'), COALESCE(a.new_deadline, a.deadline) LIMIT 300", ['org' => org_id(), 'me' => uid()]) : [];
        $done = $tab === 'done' ? DB::all($base . "AND (a.owner_id = :me OR a.created_by = :me2) AND a.status = 'done' ORDER BY a.done_at DESC LIMIT 30", ['org' => org_id(), 'me' => uid(), 'me2' => uid()]) : [];
        $list = ['mine' => $mine, 'approve' => $toApprove, 'assigned' => $assigned, 'all' => $allMine, 'done' => $done][$tab];

        $late = array_filter($mine, fn($a) => Actions::isOverdue($a));
        $week = array_filter($mine, fn($a) => Actions::isActive($a) && !Actions::isOverdue($a) && days_between(Actions::effDeadline($a), $today) <= 7);
        $assignedLate = array_filter($assigned, fn($a) => Actions::isOverdue($a));
        $myTotal = (int)DB::value('SELECT COUNT(*) FROM actions WHERE org_id = ? AND owner_id = ?', [org_id(), uid()]);

        [$scope, $sp] = Access::actionScope('a');
        $openAll = DB::all("SELECT a.id, a.title FROM actions a WHERE a.status <> 'done' AND $scope ORDER BY a.title LIMIT 300", $sp);

        $reminders = DB::all('SELECT r.*, a.title AS action_title FROM reminders r LEFT JOIN actions a ON a.id = r.action_id WHERE r.user_id = ? AND r.fired_at IS NULL ORDER BY r.remind_at LIMIT 6', [uid()]);
        $remCount = (int)DB::value('SELECT COUNT(*) FROM reminders WHERE user_id = ? AND fired_at IS NULL', [uid()]);

        $upcoming = DB::all("SELECT m.* FROM meetings m JOIN meeting_invitees i ON i.meeting_id = m.id AND i.user_id = ?
                             WHERE m.org_id = ? AND m.status = 'scheduled' AND m.meeting_date >= ? ORDER BY m.meeting_date, m.start_time LIMIT 4", [uid(), org_id(), $today]);
        $toFinish = DB::all("SELECT m.*, (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND " . \App\Services\Meetings::ON_AGENDA . " AND (ai.discussion IS NULL OR ai.discussion = '')) AS blank_points,
                                    (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND ai.request_status = 'pending') AS pending_requests
                               FROM meetings m WHERE m.org_id = ? AND m.organizer_id = ? AND m.status <> 'cancelled'
                                AND ((m.status = 'scheduled' AND m.meeting_date < ?) OR (m.status = 'completed' AND m.mom_sent_at IS NULL)
                                     OR EXISTS (SELECT 1 FROM agenda_items ai WHERE ai.meeting_id = m.id AND ai.request_status = 'pending'))
                           ORDER BY m.meeting_date DESC LIMIT 5", [org_id(), uid(), $today]);

        $members = is_admin() ? Access::members() : [uid() => user()];
        // Current user first ("Me"), then everyone else alphabetically
        $members = [uid() => $members[uid()] ?? user()] + array_filter($members, fn($m) => (int)$m['id'] !== uid() && ($m['status'] ?? 'active') !== 'disabled');
        $projects = Access::projects();

        echo view('dashboard/index', compact('tab', 'list', 'mine', 'toApprove', 'assigned', 'myTotal', 'late', 'week', 'assignedLate', 'openAll', 'reminders', 'remCount', 'upcoming', 'toFinish', 'members', 'projects') + ['title' => 'My dashboard']);
    }
}
