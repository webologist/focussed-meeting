<?php
// Completion checkbox for list rows. Expects $a with approver_id and approver_name.
// Owner: opens the "Mark as complete?" dialog (goes to the host for approval). Host: approves or reopens.
use App\Services\Access;
$canEdit = Access::canEditAction($a);
$approverId = (int)($a['approver_id'] ?? Access::approverId($a));
$canApprove = is_admin() || $approverId === uid();
$attrs = 'data-action-id="' . (int)$a['id'] . '" data-title="' . e($a['title']) . '"';
?>
<?php if ($a['status'] === 'done'): ?>
  <?php if ($canApprove): ?>
    <form method="post" action="<?= e(path('actions/' . $a['id'] . '/toggle')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="done" value="0">
      <label class="check" title="Reopen"><input type="checkbox" checked data-autosubmit data-confirm="Reopen this item? It goes back to the person responsible." aria-label="Reopen"></label></form>
  <?php else: ?><label class="check" title="Approved"><input type="checkbox" checked disabled aria-label="Done"></label><?php endif; ?>
<?php elseif ($a['status'] === 'review'): ?>
  <?php if ($canApprove): ?>
    <label class="check review" title="Review and approve"><input type="checkbox" data-review <?= $attrs ?> data-owner="<?= e($a['owner_name'] ?? '') ?>" aria-label="Review and approve"></label>
  <?php else: ?><label class="check review" title="Waiting for approval"><input type="checkbox" checked disabled aria-label="Waiting for approval"></label><?php endif; ?>
<?php else: ?>
  <label class="check" title="<?= $canEdit ? 'Mark complete' : '' ?>"><input type="checkbox" <?= $canEdit ? 'data-complete ' . $attrs . ' data-approver="' . e($approverId === uid() ? '' : ($a['approver_name'] ?? '')) . '"' : 'disabled' ?> aria-label="Mark complete"></label>
<?php endif; ?>
