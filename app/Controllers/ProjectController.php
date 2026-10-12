<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Audit;

/** Projects group meetings and tasks. Anyone in the company can start one; admins and the creator can edit or archive it. */
final class ProjectController
{
    public function index(): void
    {
        $projects = DB::all("SELECT p.*, u.name AS creator_name,
                (SELECT COUNT(*) FROM meetings m WHERE m.project_id = p.id AND m.status <> 'cancelled') AS meetings,
                (SELECT COUNT(*) FROM meetings m WHERE m.project_id = p.id AND m.status = 'scheduled' AND m.meeting_date >= :today) AS upcoming,
                (SELECT COUNT(*) FROM actions a WHERE a.project_id = p.id AND a.status <> 'done') AS open_tasks,
                (SELECT COUNT(*) FROM actions a WHERE a.project_id = p.id AND a.status = 'done') AS done_tasks,
                (SELECT MIN(m.meeting_date) FROM meetings m WHERE m.project_id = p.id AND m.status = 'scheduled' AND m.meeting_date >= :today2) AS next_meeting
              FROM projects p LEFT JOIN users u ON u.id = p.created_by WHERE p.org_id = :org ORDER BY p.status, p.name",
            ['org' => org_id(), 'today' => today(), 'today2' => today()]);
        echo view('projects/index', ['projects' => $projects, 'title' => 'Projects']);
    }

    public function store(): void
    {
        $name = mb_substr((string)input('name'), 0, 120);
        if (mb_strlen($name) < 2) { flash('error', 'Give the project a name.'); redirect('projects'); }
        if (DB::value('SELECT 1 FROM projects WHERE org_id = ? AND name = ?', [org_id(), $name])) { flash('error', 'A project with that name already exists.'); redirect('projects'); }
        $id = DB::insert('projects', ['org_id' => org_id(), 'name' => $name, 'description' => mb_substr((string)input('description'), 0, 500) ?: null, 'created_by' => uid(), 'created_at' => now_utc()]);
        Audit::log('project.created', 'project', $id);
        flash('success', 'Project created. Pick it when you plan a meeting or add a task.');
        redirect(input('then') === 'plan' ? 'meetings?plan=1&project_id=' . $id : 'projects');
    }

    public function update(int $id): void
    {
        $p = DB::one('SELECT * FROM projects WHERE id = ? AND org_id = ?', [$id, org_id()]);
        if (!$p) abort(404);
        if (!is_admin() && (int)$p['created_by'] !== uid()) abort(403, 'Only admins or the person who created the project can change it.');
        $data = ['updated_at' => now_utc()];
        if (in_array(input('status'), ['active', 'archived'], true)) $data['status'] = input('status');
        if (($name = mb_substr((string)input('name'), 0, 120)) !== '') {
            if (DB::value('SELECT 1 FROM projects WHERE org_id = ? AND name = ? AND id <> ?', [org_id(), $name, $id])) { flash('error', 'A project with that name already exists.'); redirect('projects'); }
            $data['name'] = $name;
            $data['description'] = mb_substr((string)input('description'), 0, 500) ?: null;
        }
        DB::update('projects', $data, ['id' => $id]);
        Audit::log('project.updated', 'project', $id, array_diff_key($data, ['updated_at' => 1]));
        flash('success', isset($data['status']) ? ($data['status'] === 'archived' ? 'Project archived.' : 'Project restored.') : 'Project saved.');
        redirect('projects');
    }
}
