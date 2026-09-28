<?php
use App\Core\View;
use App\Services\Access;
use App\Services\Actions;
$canEdit = Access::canEditAction($a);
$isOwner = (int)$a['owner_id'] === uid();
$done = $a['status'] === 'done';
$backLabel = ['dashboard' => 'My dashboard', 'tracker' => 'Action tracker', 'meetings' => 'Meeting'][$from];
$backHref = $from === 'meetings' && $meeting ? 'meetings/' . $meeting['id'] : $from;
$statusPill = $done ? '<span class="pill s-done">Done</span>' : (Actions::isOverdue($a) ? '<span class="pill p-high">Overdue</span>' : '<span class="pill s-neutral">Open</span>');
$blockerCount = count(array_filter($deps, fn($d) => $d['status'] !== 'done'));
?>
<div><a class="btn ghost sm" href="<?= e(path($backHref)) ?>"><?= icon('back', 14) ?><?= e($backLabel) ?></a></div>
<div class="head">
  <div style="min-width:0">
    <div class="row" style="gap:8px"><?= pri_pill($a['priority']) ?><?= $statusPill ?><?php if ($blockerCount && !$done): ?><span class="pill s-pending"><?= icon('link', 12) ?> Waiting on <?= $blockerCount ?></span><?php endif; ?></div>
    <h1 style="margin-top:8px"><?= e($a['title']) ?></h1>
    <p class="muted"><?php if ($meeting): ?>From <a href="<?= e(path('meetings/' . $meeting['id'])) ?>"><?= e($meeting['title']) ?></a> · <?= e(fmt_date($meeting['meeting_date'])) ?><?= $point ? ' · Point: ' . e($point['title']) : '' ?><?php else: ?>Task assigned by <?= e($a['creator']['name']) ?> on <?= e(fmt_local($a['created_at'], 'j M Y')) ?><?php endif; ?></p>
    <?php if ($a['details']): ?><p style="margin:6px 0 0;white-space:pre-wrap"><?= e($a['details']) ?></p><?php endif; ?>
  </div>
  <div class="row">
    <?php if (!$done): ?><button type="button" class="btn" data-open-reminder data-action-id="<?= (int)$a['id'] ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(Actions::effDeadline($a)) ?>"><?= icon('bell', 15) ?>Remind me</button><?php endif; ?>
    <?php if (Access::canNudge($a)): ?><button type="button" class="btn" data-open-nudge data-action-id="<?= (int)$a['id'] ?>" data-owner="<?= e($a['owner']['name']) ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(fmt_date(Actions::effDeadline($a))) ?>" data-overdue="<?= Actions::isOverdue($a) ? '1' : '0' ?>"><?= icon('send', 15) ?>Send reminder</button><?php endif; ?>
    <?php if ($canEdit): ?>
      <form method="post" action="<?= e(path('actions/' . $a['id'] . '/toggle')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>">
        <button class="btn <?= $done ? '' : 'primary' ?>" type="submit"><?= icon('check', 15) ?><?= $done ? 'Reopen' : 'Mark complete' ?></button></form>
    <?php endif; ?>
  </div>
</div>

<div class="panel"><div class="panel-b kv">
  <div><span class="eyebrow">Responsible</span><?= person_chip($a['owner'], 24) ?></div>
  <div><span class="eyebrow">Assigned by</span><?= person_chip($a['creator'], 24) ?></div>
  <div><span class="eyebrow">Deadline</span><span class="mono <?= $a['new_deadline'] ? 'strike' : '' ?>"><?= e(fmt_date($a['deadline'])) ?></span><?php if (!$a['new_deadline']) echo View::partial('partials/due', ['a' => $a]); ?></div>
  <div><span class="eyebrow">New deadline</span>
    <?php if ($done || !$canEdit): ?><span class="mono"><?= e($a['new_deadline'] ? fmt_date($a['new_deadline']) : '—') ?></span>
    <?php else: ?><form method="post" action="<?= e(path('actions/' . $a['id'] . '/deadline')) ?>" class="inline"><?= csrf_field() ?><input type="date" name="new_deadline" class="<?= $a['new_deadline'] ? 'moved' : '' ?>" value="<?= e($a['new_deadline']) ?>" data-autosubmit aria-label="New deadline" style="max-width:170px"></form><?php endif; ?>
    <?php if ($a['new_deadline']) echo View::partial('partials/due', ['a' => $a]); ?></div>
</div></div>

<div class="panel"><div class="panel-h"><h2>Dependencies</h2><span class="faint small">This item can be completed once everything it waits on is done</span></div>
  <div class="panel-b stack">
    <div class="stack" style="gap:6px"><div class="eyebrow">Waiting on (<?= count($deps) ?>)</div>
      <?php if ($deps): ?><div class="deps">
        <?php foreach ($deps as $d): ?><div class="dep"><?= $d['status'] === 'done' ? '<span class="pill s-done">Done</span>' : (Actions::isOverdue($d) ? '<span class="pill p-high">Overdue</span>' : '<span class="pill s-neutral">Open</span>') ?>
          <a class="linkish" href="<?= e(path('actions/' . $d['id'], ['from' => $from])) ?>"><?= e($d['title']) ?></a><?= person_chip(['id' => $d['owner_id'], 'name' => $d['owner_name']]) ?>
          <?php if ($canEdit): ?><form method="post" action="<?= e(path('actions/' . $a['id'] . '/dependency/' . $d['id'] . '/delete')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Remove dependency"><?= icon('x', 14) ?></button></form><?php else: ?><span></span><?php endif; ?></div>
        <?php endforeach; ?></div>
      <?php else: ?><div class="faint small">Not waiting on anything.</div><?php endif; ?>
      <?php if ($canEdit && $cands && !$done): ?>
      <form method="post" action="<?= e(path('actions/' . $a['id'] . '/dependency')) ?>" class="row" style="flex-wrap:nowrap"><?= csrf_field() ?>
        <select name="depends_on" required aria-label="Add a dependency"><option value="">Add something this waits on…</option>
          <?php foreach ($cands as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e(mb_strimwidth($c['title'], 0, 70, '…')) ?> (<?= e($c['owner_name']) ?>)</option><?php endforeach; ?></select>
        <button class="btn sm" type="submit"><?= icon('plus', 14) ?>Add</button></form>
      <?php endif; ?>
    </div>
    <?php if ($blocks): ?><div class="stack" style="gap:6px"><div class="eyebrow">Blocks (<?= count($blocks) ?>)</div><div class="deps">
      <?php foreach ($blocks as $b): ?><div class="dep"><?= $b['status'] === 'done' ? '<span class="pill s-done">Done</span>' : '<span class="pill s-neutral">Open</span>' ?><a class="linkish" href="<?= e(path('actions/' . $b['id'], ['from' => $from])) ?>"><?= e($b['title']) ?></a><?= person_chip(['id' => $b['owner_id'], 'name' => $b['owner_name']]) ?><span></span></div><?php endforeach; ?>
    </div></div><?php endif; ?>
  </div></div>

<?php if ($point && $point['discussion']): ?><div class="point-ctx"><span class="eyebrow">What was discussed</span><p style="margin-top:4px"><?= e($point['discussion']) ?></p></div><?php endif; ?>

<div class="grid2">
  <div class="panel"><div class="panel-h"><h2>Assignee notes</h2><span class="faint small"><?= plural(count($notes), 'note') ?> from <?= e($a['owner']['name']) ?></span></div>
    <div class="panel-b stack">
      <?php if ($isOwner && !$done): ?>
      <form method="post" action="<?= e(path('actions/' . $a['id'] . '/note')) ?>" enctype="multipart/form-data" class="composer drop" id="note-form">
        <?= csrf_field() ?>
        <textarea name="body" id="note-body" placeholder="Progress update, findings or proof of completion" aria-label="Note"></textarea>
        <div class="thumbs" id="note-previews"></div>
        <div class="bar">
          <span class="btn sm filebtn"><?= icon('img', 14) ?>Add images<input type="file" name="images[]" id="note-files" accept="image/jpeg,image/png,image/webp,image/gif" multiple aria-label="Add images"></span>
          <span class="faint small">JPG, PNG, WebP or GIF, up to <?= (int)round(config('uploads.max_bytes', 5242880) / 1048576) ?> MB each</span>
          <button class="btn sm primary" type="submit">Add note</button>
        </div>
      </form>
      <?php elseif (!$isOwner): ?>
        <div class="banner"><div>Only <?= e($a['owner']['name']) ?> can add notes and images here. You can comment on the right.</div></div>
      <?php endif; ?>
      <div class="feed">
        <?php if (!$notes): ?><div class="faint small">No notes yet.</div><?php endif; ?>
        <?php foreach ($notes as $n): ?>
        <div class="note"><?= avatar(['id' => $n['user_id'], 'name' => $n['name']]) ?><div class="body">
          <div class="meta"><?= e($n['name']) ?> · <?= e(fmt_local($n['created_at'])) ?>
            <?php if ((int)$n['user_id'] === uid() || is_admin()): ?> · <form method="post" action="<?= e(path('actions/' . $a['id'] . '/notes/' . $n['id'] . '/delete')) ?>" class="inline"><?= csrf_field() ?><button class="linkish faint" type="submit" data-confirm="Delete this note and its images?">Delete</button></form><?php endif; ?></div>
          <?php if ($n['body']): ?><p><?= e($n['body']) ?></p><?php endif; ?>
          <?php if (!empty($images[(int)$n['id']])): ?><div class="thumbs"><?php foreach ($images[(int)$n['id']] as $imgId): ?><a class="thumb" href="<?= e(path('images/' . $imgId)) ?>" target="_blank" rel="noopener"><img src="<?= e(path('images/' . $imgId)) ?>" alt="Image from <?= e($n['name']) ?>" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
        </div></div>
        <?php endforeach; ?>
      </div>
    </div></div>

  <div class="panel" id="comments"><div class="panel-h"><h2>Comments &amp; history</h2></div>
    <div class="panel-b stack">
      <div class="feed">
        <?php if (!$comments): ?><div class="faint small">No comments yet.</div><?php endif; ?>
        <?php foreach ($comments as $c): ?>
        <div class="note <?= $c['is_system'] ? 'sys' : '' ?>"><?= avatar(['id' => $c['user_id'], 'name' => $c['name']], $c['is_system'] ? 22 : 26) ?><div class="body"><div class="meta"><?= e($c['name']) ?> · <?= e(fmt_local($c['created_at'])) ?></div><p><?= e($c['body']) ?></p></div></div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="<?= e(path('actions/' . $a['id'] . '/comment')) ?>" class="row" style="flex-wrap:nowrap"><?= csrf_field() ?><?= avatar(user()) ?>
        <input type="text" name="body" required maxlength="4000" placeholder="Ask for an update or add context" aria-label="Comment"><button class="btn sm primary" type="submit">Post</button></form>
    </div></div>
</div>
<?php if (is_admin() || (int)$a['created_by'] === uid()): ?>
<div class="row" style="justify-content:flex-end"><form method="post" action="<?= e(path('actions/' . $a['id'] . '/delete')) ?>" class="inline"><?= csrf_field() ?><button class="btn ghost sm" type="submit" data-confirm="Delete this item, its notes and comments? This can’t be undone."><?= icon('x', 14) ?>Delete item</button></form></div>
<?php endif; ?>
<?= View::partial('partials/dialogs') ?>
