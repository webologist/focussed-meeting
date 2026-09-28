<?php
use App\Core\View;
use App\Services\Access;
use App\Services\Actions;
$q = fn(array $over) => path('tracker', array_filter(array_merge(['q' => $quick, 'owner' => $owner ?: null, 'priority' => $pri ?: null], $over), fn($v) => $v !== null && $v !== ''));
$stat = fn(string $k, int $n, string $label, bool $alert = false) => '<a class="stat' . ($quick === $k ? ' on' : '') . ($alert && $n ? ' alert' : '') . '" href="' . e($q(['q' => $k])) . '" style="text-decoration:none;color:inherit"><b>' . $n . '</b><span>' . e($label) . '</span></a>';
?>
<div class="head">
  <div><div class="eyebrow">Follow-up</div><h1>Action tracker</h1><p class="muted">Every action item and task<?= is_admin() ? '' : ' you’re involved in' ?>, with owner, deadline, dependencies and status.</p></div>
  <div class="row">
    <?php if ($lateNudge): ?>
      <form method="post" action="<?= e(path('actions/nudge-overdue')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Send reminder emails to everyone with overdue items?"><?= icon('send', 15) ?>Remind overdue owners (<?= $lateNudge ?>)</button></form>
    <?php endif; ?>
    <?php if (is_admin()): ?><a class="btn primary" href="<?= e(path('dashboard')) ?>#quick-add"><?= icon('plus', 14) ?>Assign a task</a><?php endif; ?>
  </div>
</div>
<div class="stats">
  <?= $stat('open', $counts['open'], 'Open items') ?><?= $stat('overdue', $counts['overdue'], 'Overdue', true) ?><?= $stat('high', $counts['high'], 'High priority, open') ?><?= $stat('week', $counts['week'], 'Due in next 7 days') ?>
</div>
<div class="panel">
  <form class="panel-h" method="get" action="<?= e(path('tracker')) ?>">
    <div class="filters">
      <input type="hidden" name="q" value="<?= e($quick === 'all' ? 'all' : $quick) ?>">
      <select name="owner" data-autosubmit aria-label="Filter by responsible person"><option value="">All people</option>
        <?php foreach ($owners as $id => $name): ?><option value="<?= (int)$id ?>" <?= $owner === $id ? 'selected' : '' ?>><?= e($name) ?><?= $id === uid() ? ' (you)' : '' ?></option><?php endforeach; ?></select>
      <select name="priority" data-autosubmit aria-label="Filter by priority"><option value="">Any priority</option>
        <?php foreach (['high', 'medium', 'low'] as $p): ?><option value="<?= $p ?>" <?= $pri === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select>
      <a class="btn sm <?= $quick === 'all' ? 'primary' : '' ?>" href="<?= e($q(['q' => $quick === 'all' ? 'open' : 'all'])) ?>"><?= $quick === 'all' ? 'Hide completed' : 'Include completed' ?></a>
    </div>
    <span class="faint small"><?= plural(count($list), 'item') ?></span>
  </form>
  <?php if (!$list): ?><div class="empty">Nothing matches these filters.</div><?php else: ?>
  <div class="tbl-wrap"><table>
    <thead><tr><th style="width:34px"></th><th>Action item</th><th>Responsible</th><th>Priority</th><th>Deadline</th><th>New deadline</th><th>Notes · Comments</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): $canEdit = Access::canEditAction($a); $done = $a['status'] === 'done'; ?>
      <tr class="item <?= $done ? 'done' : '' ?>">
        <td><form method="post" action="<?= e(path('actions/' . $a['id'] . '/toggle')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>"><label class="check"><input type="checkbox" data-autosubmit <?= $done ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> aria-label="Mark complete"></label></form></td>
        <td style="min-width:220px"><a class="linkish title" href="<?= e(path('actions/' . $a['id'], ['from' => 'tracker'])) ?>"><?= e($a['title']) ?></a>
          <div class="sub"><?php if ($a['meeting_id']): ?><a href="<?= e(path('meetings/' . $a['meeting_id'])) ?>" style="color:inherit"><?= e($a['meeting_title']) ?></a> · <?= e(fmt_date($a['meeting_date'], false)) ?><?php else: ?>Task from <?= e($a['creator_name']) ?> · <?= e(fmt_local($a['created_at'], 'j M')) ?><?php endif; ?></div>
          <?php if ((int)$a['blockers'] && !$done): ?><div style="margin-top:4px"><span class="pill s-pending"><?= icon('link', 12) ?> Waiting on <?= (int)$a['blockers'] ?></span></div><?php endif; ?></td>
        <td><?= person_chip(['id' => $a['owner_id'], 'name' => $a['owner_name']]) ?></td>
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
