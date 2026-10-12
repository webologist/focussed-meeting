<?php
// One action/to-do row for list views. Expects $a with owner_name, creator_name, meeting_title, notes_count, blockers, approver_id, approver_name; $from (back link section).
use App\Core\View;
use App\Services\Actions;
use App\Services\Access;
$nudge = Access::canNudge($a);
$done = $a['status'] === 'done';
?>
<div class="li">
  <?= View::partial('partials/task-check', ['a' => $a]) ?>
  <div style="min-width:0">
    <a class="linkish title <?= $done ? 'strike' : '' ?>" href="<?= e(path('actions/' . $a['id'], ['from' => $from])) ?>"><?= e($a['title']) ?></a>
    <div class="meta"><?= pri_pill($a['priority']) ?>
      <?php if ($a['status'] !== 'open' || Actions::isOverdue($a)): ?><?= Actions::statusPill($a) ?><?php endif; ?>
      <?php if ((int)$a['blockers'] > 0 && !$done): ?><span class="pill s-pending"><?= icon('link', 12) ?> Waiting on <?= (int)$a['blockers'] ?></span><?php endif; ?>
      <?php if (!empty($a['project_name'])): ?><span class="pill s-project"><?= e($a['project_name']) ?></span><?php endif; ?>
      <span><?= e($a['meeting_title'] ?: 'Task') ?></span>
      <?php if ((int)$a['owner_id'] !== uid()): ?><span class="person"><?= avatar(['id' => $a['owner_id'], 'name' => $a['owner_name']], 18) ?><?= e($a['owner_name']) ?></span>
      <?php elseif ((int)$a['created_by'] !== uid()): ?><span>from <?= e($a['creator_name']) ?></span><?php endif; ?>
      <?php if ($a['status'] === 'review' && (int)($a['approver_id'] ?? 0) !== uid()): ?><span>with <?= e($a['approver_name'] ?? 'the host') ?> for approval</span><?php endif; ?>
      <?php if ((int)$a['notes_count'] > 0): ?><span><?= icon('note', 12) ?> <?= (int)$a['notes_count'] ?></span><?php endif; ?>
    </div>
  </div>
  <div class="row" style="gap:8px;flex-wrap:nowrap">
    <div style="text-align:right"><span class="mono small"><?= e(fmt_date(Actions::effDeadline($a), false)) ?></span><?= View::partial('partials/due', ['a' => $a]) ?></div>
    <?php if (!$done): ?>
    <div class="li-tools">
      <button type="button" class="icon-btn" data-open-reminder data-action-id="<?= (int)$a['id'] ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(Actions::effDeadline($a)) ?>" aria-label="Remind me" title="Remind me"><?= icon('bell', 15) ?></button>
      <?php if ($nudge): ?><button type="button" class="icon-btn" data-open-nudge data-action-id="<?= (int)$a['id'] ?>" data-owner="<?= e($a['owner_name']) ?>" data-text="<?= e($a['title']) ?>" data-due="<?= e(fmt_date(Actions::effDeadline($a))) ?>" data-overdue="<?= Actions::isOverdue($a) ? '1' : '0' ?>" aria-label="Send reminder to <?= e($a['owner_name']) ?>" title="Send reminder to <?= e($a['owner_name']) ?>"><?= icon('send', 15) ?></button><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
