<?php
use App\Core\View;
$u = user();
$h = (int)(new DateTime('now', new DateTimeZone(user_tz())))->format('G');
$greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
$kpi = fn(string $tab, int $n, string $label, bool $alert = false) => '<a class="kpi' . ($alert && $n ? ' alert' : '') . '" href="' . e(path('dashboard', ['tab' => $tab])) . '"><b>' . $n . '</b>' . e($label) . '</a>';
?>
<div class="head">
  <div><div class="eyebrow"><?= e((new DateTime('now', new DateTimeZone(user_tz())))->format('D, j M Y')) ?></div><h1><?= e($greet) ?>, <?= e(explode(' ', $u['name'])[0]) ?></h1></div>
  <div class="kpis">
    <?= $kpi('mine', count($mine), 'on my list') ?><?= $kpi('mine', count($late), 'overdue', true) ?><?= $kpi('mine', count($week), 'due this week') ?><?= $kpi('assigned', count($assigned), 'waiting on others') ?>
    <?php if ($assignedLate): ?><?= $kpi('assigned', count($assignedLate), 'of theirs overdue', true) ?><?php endif; ?>
  </div>
</div>

<div class="home">
  <div class="panel">
    <form method="post" action="<?= e(path('tasks')) ?>" class="qa-bar" id="quick-add">
      <?= csrf_field() ?>
      <input class="qa-text" type="text" name="title" id="qa-text" required maxlength="250" placeholder="Add a to-do<?= is_admin() ? ' or assign a task' : '' ?>, then press Enter" aria-label="What needs doing">
      <select name="owner_id" id="qa-owner" aria-label="Assign to" title="Assign to" data-assign-select>
        <?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === uid() ? 'selected' : '' ?>><?= (int)$m['id'] === uid() ? 'Me' : e($m['name']) . (($m['status'] ?? '') === 'invited' ? ' (invited)' : '') ?></option><?php endforeach; ?>
      </select>
      <input type="date" name="deadline" id="qa-due" value="<?= e(date_add_days(today(), 3)) ?>" required aria-label="Due date" title="Due date">
      <select name="priority" id="qa-pri" aria-label="Priority" title="Priority"><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select>
      <select name="depends_on" id="qa-dep" aria-label="Waits on" title="Waits on another item"><option value="">Waits on: nothing</option>
        <?php foreach ($openAll as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e(mb_strimwidth($o['title'], 0, 42, '…')) ?></option><?php endforeach; ?></select>
      <button class="btn primary icon-only" type="submit" id="qa-submit" aria-label="Add to-do" title="Add to-do" data-icon-self="<?= e(icon('plus', 14)) ?>" data-icon-other="<?= e(icon('send', 15)) ?>"><?= icon('plus', 14) ?></button>
    </form>
    <div class="panel-h">
      <div class="tabs sm">
        <a class="tabbtn <?= $tab === 'mine' ? 'on' : '' ?>" href="<?= e(path('dashboard', ['tab' => 'mine'])) ?>">My to-do (<?= count($mine) ?>)</a>
        <a class="tabbtn <?= $tab === 'assigned' ? 'on' : '' ?>" href="<?= e(path('dashboard', ['tab' => 'assigned'])) ?>">Assigned by me (<?= count($assigned) ?>)</a>
        <a class="tabbtn <?= $tab === 'done' ? 'on' : '' ?>" href="<?= e(path('dashboard', ['tab' => 'done'])) ?>">Completed</a>
      </div>
      <?php if ($tab === 'assigned' && $assignedLate): ?>
        <form method="post" action="<?= e(path('actions/nudge-overdue')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="scope" value="mine"><button class="btn sm" type="submit" data-confirm="Send a reminder email to everyone with overdue items you assigned?"><?= icon('send', 14) ?>Remind overdue (<?= count($assignedLate) ?>)</button></form>
      <?php else: ?><span class="faint small">Includes meeting action items</span><?php endif; ?>
    </div>
    <div class="list">
      <?php if (!$list): ?><div class="empty"><?= ['mine' => 'Nothing on your list. Add a to-do above.', 'assigned' => 'Nothing you assigned is still open.', 'done' => 'Nothing completed yet.'][$tab] ?></div><?php endif; ?>
      <?php foreach ($list as $a) echo View::partial('partials/action-row', ['a' => $a, 'from' => 'dashboard']); ?>
    </div>
  </div>

  <div class="stack home-side">
    <div class="panel rem-panel">
      <div class="panel-h"><h2><?= icon('bell', 15) ?> My reminders</h2><button type="button" class="btn sm primary icon-only" style="width:28px;height:28px" data-open-reminder aria-label="New reminder" title="New reminder"><?= icon('plus', 14) ?></button></div>
      <div>
        <?php if (!$reminders): ?><div class="empty">No reminders. Use the bell on any item.</div><?php endif; ?>
        <?php foreach ($reminders as $r) echo View::partial('partials/reminder-row', ['r' => $r]); ?>
      </div>
      <?php if ($remCount > count($reminders)): ?><div class="panel-h" style="border-top:1px solid var(--line);border-bottom:0"><span class="faint small">+<?= $remCount - count($reminders) ?> more</span></div><?php endif; ?>
    </div>

    <div class="panel"><div class="panel-h"><h2>Upcoming meetings</h2><a class="btn sm ghost" href="<?= e(path('meetings')) ?>">All</a></div>
      <div class="list">
        <?php if (!$upcoming): ?><div class="empty">No meetings scheduled.</div><?php endif; ?>
        <?php foreach ($upcoming as $m): $t = strtotime($m['meeting_date'] . ' 00:00:00 UTC'); ?>
        <div class="li"><div class="datebox sm"><span><?= gmdate('D', $t) ?></span><b><?= gmdate('j', $t) ?></b></div>
          <div style="min-width:0"><a class="linkish title" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= e($m['title']) ?></a><div class="meta"><span class="mono"><?= e(fmt_time($m['start_time'])) ?></span><span><?= e(platform_label($m['platform'])) ?></span></div></div>
          <a class="icon-btn" href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener" aria-label="Print invite" title="Print invite"><?= icon('print', 15) ?></a></div>
        <?php endforeach; ?>
      </div></div>

    <?php if ($toFinish): ?>
    <div class="panel"><div class="panel-h"><h2>Minutes to finish</h2></div>
      <div class="list">
        <?php foreach ($toFinish as $m): $needMom = $m['status'] === 'completed' && (int)$m['blank_points'] === 0; ?>
        <div class="li"><span></span>
          <div style="min-width:0"><div class="title"><?= e($m['title']) ?></div><div class="meta"><span><?= e(fmt_date($m['meeting_date'], false)) ?></span>
            <span class="pill s-pending"><?= $m['status'] === 'scheduled' ? 'Meeting over? Add minutes' : ((int)$m['blank_points'] ? 'Minutes incomplete' : 'MoM not sent') ?></span></div></div>
          <a class="btn sm <?= $needMom ? '' : 'primary' ?>" href="<?= e(path('meetings/' . $m['id'] . ($needMom ? '/mom' : ''))) ?>"><?= $needMom ? 'Send MoM' : 'Add minutes' ?></a></div>
        <?php endforeach; ?>
      </div></div>
    <?php endif; ?>
  </div>
</div>
<?= View::partial('partials/dialogs') ?>
