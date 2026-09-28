<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Actions;

final class ReportController
{
    public function index(): void
    {
        $period = in_array($_GET['period'] ?? '', ['30', '90', 'all'], true) ? $_GET['period'] : '90';
        $today = today();
        $since = $period === 'all' ? '1970-01-01' : date_add_days($today, -(int)$period);

        $meetings = DB::all("SELECT m.*, u.name AS organizer_name,
                (SELECT COUNT(*) FROM meeting_invitees i WHERE i.meeting_id = m.id) AS invited,
                (SELECT COUNT(*) FROM meeting_invitees i WHERE i.meeting_id = m.id AND i.attended = 1) AS attended,
                (SELECT COUNT(*) FROM agenda_items ai WHERE ai.meeting_id = m.id AND (ai.discussion IS NULL OR ai.discussion = '')) AS blank_points
              FROM meetings m JOIN users u ON u.id = m.organizer_id
             WHERE m.org_id = ? AND m.meeting_date BETWEEN ? AND ? AND m.status <> 'cancelled' ORDER BY m.meeting_date DESC", [org_id(), $since, $today]);
        $acts = DB::all("SELECT a.*, u.name AS owner_name FROM actions a JOIN users u ON u.id = a.owner_id LEFT JOIN meetings m ON m.id = a.meeting_id
             WHERE a.org_id = ? AND ((a.meeting_id IS NOT NULL AND m.meeting_date BETWEEN ? AND ?) OR (a.meeting_id IS NULL AND DATE(a.created_at) BETWEEN ? AND ?))",
            [org_id(), $since, $today, $since, $today]);

        $held = array_filter($meetings, fn($m) => $m['status'] === 'completed');
        $fullMinutes = array_filter($held, fn($m) => (int)$m['blank_points'] === 0);
        $done = array_filter($acts, fn($a) => $a['status'] === 'done');
        $onTime = array_filter($done, fn($a) => substr((string)$a['done_at'], 0, 10) <= $a['deadline']);
        $late = array_filter($acts, fn($a) => Actions::isOverdue($a));
        $moved = array_filter($acts, fn($a) => !empty($a['new_deadline']));
        $pct = fn($a, $b) => $b ? (int)round($a / $b * 100) : 0;

        $per = [];
        foreach ($acts as $a) {
            $o = (int)$a['owner_id'];
            $per[$o] ??= ['id' => $o, 'name' => $a['owner_name'], 'total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0, 'ontime' => 0, 'moved' => 0, 'slipDays' => 0, 'lateDone' => 0];
            $p = &$per[$o];
            $p['total']++;
            if ($a['status'] === 'done') {
                $p['done']++;
                $doneDay = substr((string)$a['done_at'], 0, 10);
                if ($doneDay <= $a['deadline']) $p['ontime']++; else { $p['lateDone']++; $p['slipDays'] += days_between($doneDay, $a['deadline']); }
            } elseif (Actions::isOverdue($a)) $p['overdue']++;
            else $p['open']++;
            if ($a['new_deadline']) $p['moved']++;
            unset($p);
        }
        usort($per, fn($x, $y) => $y['total'] <=> $x['total']);

        // Created vs completed per week, last 8 weeks (weeks start Monday)
        $start = new \DateTime($today);
        $start->modify('-' . ((int)$start->format('N') - 1) . ' days')->modify('-7 weeks');
        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $a = (clone $start)->modify("+$i weeks")->format('Y-m-d');
            $b = date_add_days($a, 6);
            $weeks[] = [
                'label' => fmt_date($a, false), 'range' => fmt_date($a, false) . ' – ' . fmt_date($b, false),
                'created' => (int)DB::value('SELECT COUNT(*) FROM actions WHERE org_id = ? AND DATE(created_at) BETWEEN ? AND ?', [org_id(), $a, $b]),
                'completed' => (int)DB::value('SELECT COUNT(*) FROM actions WHERE org_id = ? AND done_at IS NOT NULL AND DATE(done_at) BETWEEN ? AND ?', [org_id(), $a, $b]),
            ];
        }
        echo view('reports/index', compact('period', 'meetings', 'acts', 'held', 'fullMinutes', 'done', 'onTime', 'late', 'moved', 'per', 'weeks', 'pct') + ['title' => 'Reports']);
    }

    public function export(): void
    {
        $rows = DB::all("SELECT a.*, u.name AS owner_name, c.name AS creator_name, m.title AS meeting_title, m.meeting_date,
                  (SELECT COUNT(*) FROM action_notes n WHERE n.action_id = a.id) AS notes,
                  (SELECT COUNT(*) FROM action_comments k WHERE k.action_id = a.id AND k.is_system = 0) AS comments
               FROM actions a JOIN users u ON u.id = a.owner_id JOIN users c ON c.id = a.created_by LEFT JOIN meetings m ON m.id = a.meeting_id
              WHERE a.org_id = ? ORDER BY a.created_at DESC", [org_id()]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="action-items-' . today() . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Action item', 'Source', 'Date', 'Responsible', 'Assigned by', 'Priority', 'Deadline', 'New deadline', 'Status', 'Completed on', 'Overdue days', 'Notes', 'Comments']);
        foreach ($rows as $a) {
            $over = Actions::isOverdue($a) ? days_between(today(), Actions::effDeadline($a)) : '';
            $safe = fn($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v; // block spreadsheet formula injection
            fputcsv($out, array_map($safe, [$a['title'], $a['meeting_title'] ?: 'Task', $a['meeting_date'] ?: substr($a['created_at'], 0, 10), $a['owner_name'], $a['creator_name'], ucfirst($a['priority']),
                $a['deadline'], $a['new_deadline'], $a['status'] === 'done' ? 'Done' : ($over !== '' ? 'Overdue' : 'Open'), $a['done_at'] ? substr($a['done_at'], 0, 10) : '', $over, $a['notes'], $a['comments']]));
        }
        fclose($out);
        exit;
    }
}
