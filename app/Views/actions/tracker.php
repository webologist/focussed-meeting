<?php
use App\Core\View;
use App\Services\Access;
use App\Services\Actions;
$state = ['q' => $quick, 'owner' => $owner ?: null, 'priority' => $pri ?: null, 'project' => $project ?: null, 'sort' => $sort !== 'deadline' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null];
$q = fn(array $over) => path('tracker', array_filter(array_merge($state, $over), fn($v) => $v !== null && $v !== ''));
$stat = fn(string $k, int $n, string $label, bool $alert = false) => '<a class="stat' . ($quick === $k ? ' on' : '') . ($alert && $n ? ' alert' : '') . '" href="' . e($q(['q' => $k])) . '" style="text-decoration:none;color:inherit"><b>' . $n . '</b><span>' . e($label) . '</span></a>';
// Sortable column header: click again to reverse
$th = function (string $key, string $label) use ($q, $sort, $dir) {
    $on = $sort === $key;
    $next = $on && $dir === 'asc' ? 'desc' : 'asc';
    return '<th aria-sort="' . ($on ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none') . '"><a class="sort' . ($on ? ' on' : '') . '" href="' . e($q(['sort' => $key === 'deadline' && $next === 'asc' ? null : $key, 'dir' => $next === 'desc' ? 'desc' : null])) . '">' . e($label) . ($on ? ($dir === 'asc' ? ' ↑' : ' ↓') : '') . '</a></th>';
};
$views = ['open' => 'Not done yet', 'review' => 'Awaiting my approval', 'overdue' => 'Overdue', 'week' => 'Due in 7 days', 'high' => 'High priority', 'done' => 'Done', 'all' => 'Everything'];
?>
<div class="head">
  <div><div class="eyebrow">Follow-up</div><h1>All tasks</h1><p class="muted">Every action item and task<?= is_admin() ? ' in the company' : ' you’re involved in' ?>. Sort by clicking a column: assignee, status, priority or deadline.</p></div>
  <div class="row">
    <?php if ($lateNudge): ?>
      <form method="post" action="<?= e(path('actions/nudge-overdue')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Send reminder emails to everyone with overdue items?"><?= icon('send', 15) ?>Remind overdue owners (<?= $lateNudge ?>)</button></form>
    <?php endif; ?>
    <a class="btn primary" href="<?= e(path('dashboard')) ?>#quick-add"><?= icon('plus', 14) ?><?= is_admin() ? 'Assign a task' : 'Add a to-do' ?></a>
  </div>
</div>
<div class="stats five">
  <?= $stat('open', $counts['open'], 'Not done yet') ?><?= $stat('overdue', $counts['overdue'], 'Overdue', true) ?><?= $stat('review', $counts['review'], 'Awaiting my approval', true) ?><?= $stat('week', $counts['week'], 'Due in next 7 days') ?><?= $stat('all', $counts['all'], 'All tasks') ?>
</div>
<div class="panel">
  <form class="panel-h" method="get" action="<?= e(path('tracker')) ?>">
    <div class="filters">
      <?php if ($sort !== 'deadline'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
      <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir" value="<?= e($dir) ?>"><?php endif; ?>
      <select name="q" data-autosubmit aria-label="Show"><?php foreach ($views as $k => $l): ?><option value="<?= $k ?>" <?= $quick === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
      <select name="owner" data-autosubmit aria-label="Filter by assignee"><option value="">All assignees</option>
        <?php if (isset($owners[uid()])): ?><option value="<?= uid() ?>" <?= $owner === uid() ? 'selected' : '' ?>>Only mine</option><?php endif; ?>
        <?php foreach ($owners as $id => $name): if ($id === uid()) continue; ?><option value="<?= (int)$id ?>" <?= $owner === $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select>
      <select name="priority" data-autosubmit aria-label="Filter by priority"><option value="">Any priority</option>
        <?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>" <?= $pri === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select>
      <?php if ($projects): ?><select name="project" data-autosubmit aria-label="Filter by project"><option value="">All projects</option>
        <?php foreach ($projects as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $project === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?><?= $pr['status'] === 'archived' ? ' (archived)' : '' ?></option><?php endforeach; ?></select><?php endif; ?>
      <select aria-label="Sort by" onchange="location.href=this.value">
        <?php foreach (['deadline' => 'Sort: deadline', 'assignee' => 'Sort: assignee', 'status' => 'Sort: status', 'priority' => 'Sort: priority', 'title' => 'Sort: name'] as $k => $l): ?><option value="<?= e($q(['sort' => $k === 'deadline' ? null : $k, 'dir' => null])) ?>" <?= $sort === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </div>
    <span class="faint small"><?= plural(count($list), 'item') ?></span>
  </form>
  <?php if (!$list): ?><div class="empty">Nothing matches these filters.</div><?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th style="width:34px"></th><?= $th('title', 'Task') ?><?= $th('assignee', 'Assignee') ?><?= $th('status', 'Status') ?><?= $th('priority', 'Priority') ?><?= $th('deadline', 'Deadline') ?><th>New deadline</th><th>Notes · Comments</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): $canEdit = Access::canEditAction($a); $done = $a['status'] === 'done'; ?>
      <tr class="item <?= $done ? 'done' : '' ?>">
        <td><?= View::partial('partials/task-check', ['a' => $a]) ?></td>
        <td style="min-width:220px"><a class="linkish title" href="<?= e(path('actions/' . $a['id'], ['from' => 'tracker'])) ?>"><?= e($a['title']) ?></a>
          <div class="sub"><?php if ($a['project_name']): ?><a href="<?= e($q(['project' => $a['project_id']])) ?>" style="color:inherit"><?= e($a['project_name']) ?></a> · <?php endif; ?><?php if ($a['meeting_id']): ?><a href="<?= e(path('meetings/' . $a['meeting_id'])) ?>" style="color:inherit"><?= e($a['meeting_title']) ?></a> · <?= e(fmt_date($a['meeting_date'], false)) ?><?php else: ?>Task from <?= e($a['creator_name']) ?> · <?= e(fmt_local($a['created_at'], 'j M')) ?><?php endif; ?></div>
          <?php if ((int)$a['blockers'] && !$done): ?><div style="margin-top:4px"><span class="pill s-pending"><?= icon('link', 12) ?> Waiting on <?= (int)$a['blockers'] ?></span></div><?php endif; ?></td>
        <td><?= person_chip(['id' => $a['owner_id'], 'name' => $a['owner_name']]) ?></td>
        <td><?php if (!$done && $a['status'] !== 'review' && (int)$a['owner_id'] === uid()): ?>
          <form method="post" action="<?= e(path('actions/' . $a['id'] . '/status')) ?>" class="inline"><?= csrf_field() ?>
            <select name="status" class="status-select" data-autosubmit aria-label="Status"><option value="open" <?= $a['status'] === 'open' ? 'selected' : '' ?>>To do</option><option value="progress" <?= $a['status'] === 'progress' ? 'selected' : '' ?>>In progress</option></select></form>
          <?php if (Actions::isOverdue($a)): ?><span class="late">Overdue</span><?php endif; ?>
          <?php else: ?><?= Actions::statusPill($a) ?><?php endif; ?></td>
        <td><?= pri_pill($a['priority']) ?></td>
        <td class="mono" style="white-space:nowrap"><span class="<?= $a['new_deadline'] ? 'strike' : '' ?>"><?= e(fmt_date($a['deadline'])) ?></span><?php if (!$a['new_deadline']) echo View::partial('partials/due', ['a' => $a]); ?></td>
        <td><?php if ($done || !$canEdit): ?><span class="mono"><?= e($a['new_deadline'] ? fmt_date($a['new_deadline']) : '—') ?></span>
          <?php else: ?><form method="post" action="<?= e(path('actions/' . $a['id'] . '/deadline')) ?>" class="inline"><?= csrf_field() ?><input type="date" name="new_deadline" class="<?= $a['new_deadline'] ? 'moved' : '' ?>" value="<?= e($a['new_deadline']) ?>" data-autosubmit aria-label="New deadline"></form><?php endif; ?>
          <?php if ($a['new_deadline']) echo View::partial('partials/due', ['a' => $a]); ?></td>
        <td><div class="counts">
          <a class="cbtn" href="<?= e(path('actions/' . $a['id'], ['from' => 'tracker'])) ?>" title="Assignee notes and images"><?= icon('note', 13) ?><?= (int)$a['notes_count'] ?><?php if ((int)$a['images_count']): ?> · <?= icon('img', 13) ?><?= (int)$a['images_count'] ?><?php endif; ?></a>
          <a class="cbtn" href="<?= e(path('actions/' . $a['id'], ['from' => 'tracker'])) ?>#comments" title="Comments"><?= icon('cmt', 13) ?><?= (int)$a['comments_count'] ?></a>
          <?php if (!$done): ?>
            <?php if (Access::canNudge($a)): ?><button type="button" class="cbtn" data-open-nudge data-action-id="<?= (int)$a['id'] ?>" data-owner="<?= e($a['owner_name']) ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(fmt_date(Actions::effDeadline($a))) ?>" data-overdue="<?= Actions::isOverdue($a) ? '1' : '0' ?>" title="Send reminder to <?= e($a['owner_name']) ?>"><?= icon('send', 13) ?></button>
            <?php else: ?><button type="button" class="cbtn" data-open-reminder data-action-id="<?= (int)$a['id'] ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(Actions::effDeadline($a)) ?>" title="Remind me"><?= icon('bell', 13) ?></button><?php endif; ?>
          <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?= View::partial('partials/dialogs') ?>
