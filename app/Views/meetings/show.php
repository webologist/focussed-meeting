<?php
use App\Services\Meetings;
use App\Services\Actions;
[$label, $cls] = Meetings::status($m);
$isOver = $m['status'] === 'completed';
$where = Meetings::where($m);
$total = array_sum(array_map(fn($p) => (int)$p['minutes_allotted'], $agenda));
$rsvpLabels = ['pending' => 'No reply', 'yes' => 'Going', 'no' => 'Not going', 'maybe' => 'Maybe'];
?>
<div><a class="btn ghost sm" href="<?= e(path('meetings')) ?>"><?= icon('back', 14) ?>All meetings</a></div>
<div class="head">
  <div style="min-width:0">
    <div class="row" style="gap:8px"><div class="eyebrow"><?= e(fmt_date($m['meeting_date'])) ?> · <?= e(fmt_time($m['start_time'])) ?> · <?= e(platform_label($m['platform'])) ?></div><span class="pill <?= $cls ?>"><?= e($label) ?></span></div>
    <h1 style="margin-top:4px"><?= e($m['title']) ?></h1>
    <p class="muted" style="overflow-wrap:anywhere"><?= preg_match('~^https?://~', $where) ? '<a href="' . e($where) . '" target="_blank" rel="noopener">' . e($where) . '</a>' : e($where) ?> · Organised by <?= e($m['organizer_name']) ?> · <?= (int)$m['duration_min'] ?> min · <?= e($m['timezone']) ?></p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener"><?= icon('print', 15) ?>Print invite</a>
    <a class="btn" href="<?= e(path('meetings/' . $m['id'] . '/ics')) ?>"><?= icon('cal', 15) ?>Add to calendar</a>
    <?php if ($isOver): ?>
      <a class="btn primary" href="<?= e(path('meetings/' . $m['id'] . '/mom')) ?>"><?= icon('doc', 15) ?>Minutes of meeting</a>
    <?php elseif ($m['status'] === 'scheduled' && $canManage): ?>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/resend')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit">Resend</button></form>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/cancel')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Cancel this meeting and notify all invitees?">Cancel meeting</button></form>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/complete')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary" type="submit">Meeting is over, add minutes</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($m['status'] === 'scheduled'): ?>
<div class="grid2">
  <div class="stack">
    <div class="banner"><div>Minutes and action items open once the meeting is over<?= $canManage ? '' : '. The organiser marks it as over' ?>. Invitees get an <b>Add minutes</b> button in their invite.</div></div>
    <?php if ($isInvitee && (int)$m['organizer_id'] !== uid()): ?>
    <div class="panel"><div class="panel-b row" style="justify-content:space-between">
      <span>Are you going? <span class="faint">Your reply: <?= e($rsvpLabels[$myRsvp] ?? 'No reply') ?></span></span>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/rsvp')) ?>" class="row" style="gap:6px"><?= csrf_field() ?>
        <?php foreach (['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'] as $k => $l): ?><button class="btn sm <?= $myRsvp === $k ? 'primary' : '' ?>" name="rsvp" value="<?= $k ?>" type="submit"><?= $l ?></button><?php endforeach; ?></form>
    </div></div>
    <?php endif; ?>
    <div class="panel"><div class="panel-h"><h2>Agenda</h2><span class="faint small mono"><?= $total ?> / <?= (int)$m['duration_min'] ?> min</span></div>
      <div class="panel-b"><ol class="ag-view">
        <?php foreach ($agenda as $p): ?>
        <li><b style="font-weight:600"><?= e($p['title']) ?></b>
          <?php if ($p['presenter_name'] || $p['minutes_allotted']): ?><span class="tag"><?= $p['presenter_name'] ? icon('mic', 12) . ' ' . e($p['presenter_name']) : '' ?><?= $p['presenter_name'] && $p['minutes_allotted'] ? ' · ' : '' ?><?= $p['minutes_allotted'] ? icon('clock', 12) . ' ' . (int)$p['minutes_allotted'] . ' min' : '' ?></span><?php endif; ?>
          <?php if ($p['details']): ?><div class="d"><?= e($p['details']) ?></div><?php endif; ?>
          <?php if ($p['subpoints']): ?><ul><?php foreach ($p['subpoints'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol></div></div>
  </div>
  <div class="panel"><div class="panel-h"><h2>Invitees</h2><span class="faint small">Sent via <?= e(implode(' + ', array_filter([$m['sent_email'] ? 'Email' : null, $m['sent_whatsapp'] ? 'WhatsApp' : null]))) ?></span></div>
    <div class="list"><?php foreach ($invitees as $i): ?>
      <div class="li"><?= avatar($i) ?><div style="min-width:0"><div class="title"><?= e($i['name']) ?><?= (int)$i['id'] === uid() ? ' (you)' : '' ?></div><div class="meta"><span class="mono"><?= e($i['email']) ?></span><?= $i['status'] === 'invited' ? '<span class="pill s-pending">Not registered yet</span>' : '' ?></div></div>
        <span class="pill <?= $i['rsvp'] === 'yes' ? 's-done' : ($i['rsvp'] === 'no' ? 'p-high' : 's-neutral') ?>"><?= e((int)$i['id'] === (int)$m['organizer_id'] ? 'Organiser' : $rsvpLabels[$i['rsvp']]) ?></span></div>
    <?php endforeach; ?></div></div>
</div>

<?php elseif ($m['status'] === 'cancelled'): ?>
<div class="banner warn"><div>This meeting was cancelled. Invitees were notified.</div></div>

<?php else: ?>
<form method="post" action="<?= e(path('meetings/' . $m['id'] . '/minutes')) ?>" id="minutes-form" class="stack">
  <?= csrf_field() ?>
  <div class="panel"><div class="panel-h"><h2>Attendance</h2><span class="faint small"><?= count(array_filter($invitees, fn($i) => $i['attended'])) ?> of <?= count($invitees) ?> present<?= $canManage ? ' · tap to toggle' : '' ?></span></div>
    <div class="panel-b"><div class="attend">
      <?php if ($canManage): ?><input type="hidden" name="attendance_submitted" value="1"><?php endif; ?>
      <?php foreach ($invitees as $i): ?><label class="<?= $i['attended'] ? '' : 'off' ?>"><input type="checkbox" name="attended[]" value="<?= (int)$i['id'] ?>" <?= $i['attended'] ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>><?= avatar($i) ?><?= e($i['name']) ?></label><?php endforeach; ?>
    </div></div></div>

  <div class="stack" id="points">
  <?php foreach ($agenda as $n => $p): $pa = $byPoint[(int)$p['id']] ?? []; ?>
    <div class="point" id="point-<?= (int)$p['id'] ?>">
      <div class="point-h"><span class="point-n"><?= str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) ?></span><h3><?= e($p['title']) ?></h3>
        <?php if ($p['presenter_name']): ?><span class="tag"><?= icon('mic', 12) ?> <?= e($p['presenter_name']) ?></span><?php endif; ?>
        <?php if ($p['minutes_allotted']): ?><span class="tag"><?= icon('clock', 12) ?> <?= (int)$p['minutes_allotted'] ?> min</span><?php endif; ?>
        <?php if ($p['added_in_meeting']): ?><span class="pill s-neutral">Raised in meeting</span><?php endif; ?></div>
      <div class="point-b">
        <?php if ($p['details'] || $p['subpoints']): ?><div class="point-ctx"><?php if ($p['details']): ?><p><?= e($p['details']) ?></p><?php endif; ?><?php if ($p['subpoints']): ?><ul><?php foreach ($p['subpoints'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
        <label class="f">Minutes discussed<textarea name="discussion[<?= (int)$p['id'] ?>]" placeholder="What was discussed and decided on this point" <?= $isInvitee || $canManage ? '' : 'readonly' ?>><?= e($p['discussion']) ?></textarea></label>
        <div class="stack" style="gap:6px"><div class="eyebrow">Action items (<?= count($pa) ?>)</div>
          <div class="actions-mini">
            <?php if (!$pa && !$canManage): ?><div class="act faint">No action items yet.</div><?php endif; ?>
            <?php foreach ($pa as $x): ?>
            <div class="act"><a class="linkish <?= $x['status'] === 'done' ? 'strike' : '' ?>" href="<?= e(path('actions/' . $x['id'], ['from' => 'meetings'])) ?>"><?= e($x['title']) ?></a><?= pri_pill($x['priority']) ?><?= person_chip(['id' => $x['owner_id'], 'name' => $x['owner_name']]) ?><span class="mono" style="font-size:.82rem;white-space:nowrap"><?= e(fmt_date(Actions::effDeadline($x), false)) ?></span><span></span></div>
            <?php endforeach; ?>
            <?php if ($canManage): ?>
            <div class="add-act" data-action-form="<?= (int)$p['id'] ?>">
              <label class="f full">Action item<input type="text" data-f="title" placeholder="What needs to be done" maxlength="250"></label>
              <label class="f">Priority<select data-f="priority"><option value="high">High</option><option value="medium" selected>Medium</option><option value="low">Low</option></select></label>
              <label class="f">Responsible<select data-f="owner_id"><?php foreach ($invitees as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (int)($p['presenter_id'] ?? 0) === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select></label>
              <label class="f">Deadline<input type="date" data-f="deadline" value="<?= e(date_add_days(today(), 7)) ?>"></label>
              <button type="button" class="btn sm primary" data-add-action="<?= (int)$p['id'] ?>">Add</button>
            </div>
            <?php endif; ?>
          </div></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="row" style="justify-content:space-between;position:sticky;bottom:0;background:var(--bg);padding:10px 0;border-top:1px solid var(--line);z-index:2">
    <span class="faint small">Save before leaving. Action items save right away.</span>
    <button class="btn primary" type="submit"><?= icon('check', 15) ?>Save minutes</button>
  </div>
</form>

<form method="post" action="<?= e(path('meetings/' . $m['id'] . '/points')) ?>" class="panel"><div class="panel-b row" style="flex-wrap:nowrap"><?= csrf_field() ?>
  <input type="text" name="title" required maxlength="250" placeholder="Another point came up? Add it to the minutes" aria-label="New point"><button class="btn" type="submit"><?= icon('plus', 14) ?>Add point</button></div></form>

<form method="post" action="<?= e(path('meetings/' . $m['id'] . '/actions')) ?>" id="add-action-form" hidden><?= csrf_field() ?>
  <input type="hidden" name="agenda_item_id"><input type="hidden" name="title"><input type="hidden" name="priority"><input type="hidden" name="owner_id"><input type="hidden" name="deadline"></form>
<?php endif; ?>
