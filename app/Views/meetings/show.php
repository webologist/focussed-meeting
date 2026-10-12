<?php
use App\Services\Meetings;
use App\Services\Actions;
[$label, $cls] = Meetings::status($m);
$isOver = $m['status'] === 'completed';
$where = Meetings::where($m);
$total = array_sum(array_map(fn($p) => (int)$p['minutes_allotted'], $agenda));
$rsvpLabels = ['pending' => 'No reply', 'yes' => 'Going', 'no' => 'Not going', 'maybe' => 'Maybe'];
$others = array_values(array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$pending = array_values(array_filter($requests, fn($r) => $r['request_status'] === 'pending'));
$reviewed = array_values(array_filter($requests, fn($r) => $r['request_status'] !== 'pending'));
$mine = array_values(array_filter($requests, fn($r) => (int)$r['requested_by'] === uid()));
$canAsk = $isInvitee && !$isHost && $m['status'] === 'scheduled';
$automatic = $m['sent_email'] || $m['sent_whatsapp'];
$channels = array_filter([$m['sent_email'] ? 'Email' : null, $m['sent_whatsapp'] ? 'WhatsApp' : null, $m['sent_personal'] ? 'Personal invite' : null]);
$reqPill = ['pending' => '<span class="pill s-pending">Waiting for the host</span>', 'approved' => '<span class="pill s-done">Added to the agenda</span>', 'declined' => '<span class="pill s-neutral">Declined</span>'];
/** "By Admin’s permission": the host reviews points invitees asked to add. */
$requestsPanel = function () use ($m, $pending, $reviewed, $reqPill): string {
    ob_start(); ?>
    <div class="panel permission" id="permission"><div class="panel-h"><h2>By Admin’s permission</h2><span class="faint small"><?= $pending ? plural(count($pending), 'request') . ' to review' : 'Points invitees ask to add' ?></span></div>
      <div class="panel-b stack">
        <?php if (!$pending && !$reviewed): ?><div class="empty" style="padding:8px 0">No requests yet. Invitees can ask you to add a discussion point from this page.</div><?php endif; ?>
        <?php foreach ($pending as $r): ?>
        <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/requests/' . $r['id'] . '/review')) ?>" class="req pending" id="request-<?= (int)$r['id'] ?>"><?= csrf_field() ?>
          <div class="row" style="justify-content:space-between;gap:8px"><b><?= e($r['title']) ?></b><span class="faint small"><?= e($r['requester_name'] ?? '') ?><?= $r['minutes_allotted'] ? ' · ' . (int)$r['minutes_allotted'] . ' min' : '' ?></span></div>
          <?php if ($r['details']): ?><div class="req-note"><?= e($r['details']) ?></div><?php endif; ?>
          <input type="text" name="note" maxlength="500" placeholder="Message to <?= e(explode(' ', (string)$r['requester_name'])[0]) ?> (needed to decline)" aria-label="Message">
          <div class="row" style="justify-content:flex-end;gap:6px">
            <button class="btn sm" type="submit" name="decision" value="decline">Decline</button>
            <button class="btn sm primary" type="submit" name="decision" value="approve"><?= icon('check', 14) ?>Add to agenda</button></div>
        </form>
        <?php endforeach; ?>
        <?php if ($reviewed): ?>
        <details <?= $pending ? '' : 'open' ?>><summary class="linkish small">Reviewed (<?= count($reviewed) ?>)</summary>
          <div class="stack" style="gap:6px;margin-top:8px">
          <?php foreach ($reviewed as $r): ?>
            <div class="req"><div class="row" style="justify-content:space-between;gap:8px"><span><b><?= e($r['title']) ?></b> <span class="faint small">· <?= e($r['requester_name'] ?? '') ?></span></span><?= $reqPill[$r['request_status']] ?></div>
              <?php if ($r['review_note']): ?><div class="req-note"><?= e($r['reviewer_name'] ?? '') ?>: <?= e($r['review_note']) ?></div><?php endif; ?></div>
          <?php endforeach; ?>
          </div></details>
        <?php endif; ?>
      </div></div>
    <?php return (string)ob_get_clean();
};
?>
<div><a class="btn ghost sm" href="<?= e(path('meetings')) ?>"><?= icon('back', 14) ?>All meetings</a></div>
<div class="head">
  <div style="min-width:0">
    <div class="row" style="gap:8px"><div class="eyebrow"><?= e(fmt_date($m['meeting_date'])) ?> · <?= e(fmt_time($m['start_time'])) ?> · <?= e(platform_label($m['platform'])) ?></div><span class="pill <?= $cls ?>"><?= e($label) ?></span>
      <?php if ($project): ?><a class="pill s-project" href="<?= e(path('meetings', ['project' => $project['id']])) ?>"><?= e($project['name']) ?></a><?php endif; ?></div>
    <h1 style="margin-top:4px"><?= e($m['title']) ?></h1>
    <p class="muted" style="overflow-wrap:anywhere"><?= preg_match('~^https?://~', $where) ? '<a href="' . e($where) . '" target="_blank" rel="noopener">' . e($where) . '</a>' : e($where) ?> · Host: <?= $isHost ? 'you' : e($m['organizer_name']) ?> · <?= (int)$m['duration_min'] ?> min · <?= e($m['timezone']) ?></p>
  </div>
  <div class="row">
    <a class="btn" href="<?= e(path('meetings/' . $m['id'] . '/ics')) ?>"><?= icon('cal', 15) ?>Add to calendar</a>
    <?php if ($isHost): ?>
    <details class="menu"><summary class="btn"><?= icon('print', 15) ?>Print</summary>
      <div class="menu-pop menu-list">
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener">Meeting invitation</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/personal')) ?>" target="_blank" rel="noopener">Personal invitation letters</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/attendance')) ?>" target="_blank" rel="noopener">Attendance register</a>
        <?php if ($isOver): ?>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/mom')) ?>" target="_blank" rel="noopener">Minutes of meeting</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/actions')) ?>" target="_blank" rel="noopener">Action items</a>
        <?php endif; ?>
      </div></details>
    <?php endif; ?>
    <?php if ($isOver): ?>
      <a class="btn primary" href="<?= e(path('meetings/' . $m['id'] . '/mom')) ?>"><?= icon('doc', 15) ?>Minutes of meeting</a>
    <?php elseif ($m['status'] === 'scheduled' && $canManage): ?>
      <?php if ($automatic): ?><form method="post" action="<?= e(path('meetings/' . $m['id'] . '/resend')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Send the invite again to everyone?">Resend</button></form><?php endif; ?>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/cancel')) ?>" class="inline"><?= csrf_field() ?><button class="btn" type="submit" data-confirm="Cancel this meeting and notify all invitees?">Cancel meeting</button></form>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/complete')) ?>" class="inline"><?= csrf_field() ?><button class="btn primary" type="submit">Meeting is over, add minutes</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canManage && $pending): ?>
<div class="banner warn"><div><b><?= plural(count($pending), 'point request') ?></b> <?= count($pending) === 1 ? 'waits' : 'wait' ?> for your decision. <a href="#permission">Review now</a></div></div>
<?php endif; ?>

<?php if ($m['status'] === 'scheduled'): ?>
<div class="grid2">
  <div class="stack">
    <div class="banner"><div>Minutes and action items open once the meeting is over<?= $canManage ? '' : '. The host marks it as over' ?>. Invitees get an <b>Add minutes</b> button in their invite.</div></div>

    <?php if ($isInvitee && !$isHost): ?>
    <div class="panel"><div class="panel-b row" style="justify-content:space-between">
      <span>Are you going? <span class="faint">Your reply: <?= e($rsvpLabels[$myRsvp] ?? 'No reply') ?></span></span>
      <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/rsvp')) ?>" class="row" style="gap:6px"><?= csrf_field() ?>
        <?php foreach (['yes' => 'Yes', 'maybe' => 'Maybe', 'no' => 'No'] as $k => $l): ?><button class="btn sm <?= $myRsvp === $k ? 'primary' : '' ?>" name="rsvp" value="<?= $k ?>" type="submit"><?= $l ?></button><?php endforeach; ?></form>
    </div></div>
    <?php endif; ?>

    <?php if ($m['personal_note']): ?>
    <div class="panel"><div class="panel-b"><div class="eyebrow">Message from <?= $isHost ? 'you' : e($m['organizer_name']) ?></div><p class="note-text"><?= e($m['personal_note']) ?></p></div></div>
    <?php endif; ?>

    <?php if ($m['sent_personal'] && $isHost):
      $text = Meetings::inviteText($m, $agenda, $m['organizer_name']);
      $subject = 'Invitation: ' . $m['title'] . ' · ' . fmt_date($m['meeting_date'], false) . ', ' . fmt_time($m['start_time']);
      $clip = fn(string $t) => mb_strlen($t) > 1800 ? mb_substr($t, 0, 1800) . '…' : $t;
      $mailto = 'mailto:' . implode(',', array_map('rawurlencode', array_column($others, 'email'))) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($clip($text));
    ?>
    <div class="panel personal-invite"><div class="panel-h"><h2>Personal invite</h2><span class="faint small">Hand out a letter, or share from your own phone or mail app</span></div>
      <div class="panel-b stack">
        <div class="row" style="flex-wrap:wrap">
          <a class="btn primary" href="<?= e(path('meetings/' . $m['id'] . '/print/personal')) ?>" target="_blank" rel="noopener"><?= icon('print', 15) ?>Print invitation letters (<?= count($others) ?>)</a>
          <button class="btn" type="button" data-copy="#invite-text"><?= icon('doc', 15) ?>Copy invite text</button>
          <a class="btn" href="<?= e($mailto) ?>"><?= icon('mail', 15) ?>Email from my mail app</a>
          <a class="btn" href="https://wa.me/?text=<?= e(rawurlencode($text)) ?>" target="_blank" rel="noopener"><?= icon('send', 15) ?>Share on WhatsApp</a>
        </div>
        <details class="invite-preview"><summary class="linkish small">Show the invite text</summary><pre class="invite-text" id="invite-text"><?= e($text) ?></pre></details>
        <?php if ($others): ?>
        <div class="list">
          <?php foreach ($others as $i): $d = preg_replace('/\D/', '', (string)($i['phone'] ?? '')); $one = Meetings::inviteText($m, $agenda, $m['organizer_name'], $i['name']); ?>
          <div class="li"><?= avatar($i) ?><div style="min-width:0"><div class="title"><?= e($i['name']) ?></div><div class="meta"><span class="mono"><?= e($i['email']) ?></span><?= $i['phone'] ? ' · <span class="mono">' . e($i['phone']) . '</span>' : '' ?></div></div>
            <div class="row" style="gap:6px;margin-left:auto">
              <a class="btn sm ghost" href="<?= e('mailto:' . rawurlencode($i['email']) . '?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($clip($one))) ?>" title="Email <?= e($i['name']) ?> from your mail app"><?= icon('mail', 14) ?>Email</a>
              <?php if (strlen($d) >= 10): ?><a class="btn sm ghost" href="https://wa.me/<?= e($d) ?>?text=<?= e(rawurlencode($one)) ?>" target="_blank" rel="noopener" title="WhatsApp <?= e($i['name']) ?> from your phone"><?= icon('send', 14) ?>WhatsApp</a><?php endif; ?>
            </div></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div></div>
    <?php endif; ?>

    <div class="panel"><div class="panel-h"><h2>Agenda</h2><span class="faint small mono"><?= $total ?> / <?= (int)$m['duration_min'] ?> min</span></div>
      <div class="panel-b"><ol class="ag-view">
        <?php foreach ($agenda as $p): ?>
        <li><b style="font-weight:600"><?= e($p['title']) ?></b>
          <?php if ($p['presenter_name'] || $p['minutes_allotted']): ?><span class="tag"><?= $p['presenter_name'] ? icon('mic', 12) . ' ' . e($p['presenter_name']) : '' ?><?= $p['presenter_name'] && $p['minutes_allotted'] ? ' · ' : '' ?><?= $p['minutes_allotted'] ? icon('clock', 12) . ' ' . (int)$p['minutes_allotted'] . ' min' : '' ?></span><?php endif; ?>
          <?php if ($p['request_status'] === 'approved'): ?><span class="pill s-neutral" title="Asked for by <?= e($p['requester_name'] ?? '') ?>">Added on request</span><?php endif; ?>
          <?php if ($p['details']): ?><div class="d"><?= e($p['details']) ?></div><?php endif; ?>
          <?php if ($p['subpoints']): ?><ul><?php foreach ($p['subpoints'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol></div></div>

    <?php if ($canAsk || $mine): ?>
    <div class="panel" id="ask"><div class="panel-h"><h2>Ask to add a discussion point</h2><span class="faint small"><?= e($m['organizer_name']) ?> decides</span></div>
      <div class="panel-b stack">
        <?php if ($canAsk): ?>
        <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/requests')) ?>" class="stack" style="gap:8px"><?= csrf_field() ?>
          <label class="f">Point<input type="text" name="title" required minlength="3" maxlength="250" placeholder="What would you like to discuss?"></label>
          <label class="f">Why it matters <span class="hint">optional</span><textarea name="details" rows="2" maxlength="4000" placeholder="Context, links or what you need from the group"></textarea></label>
          <div class="row" style="justify-content:space-between"><label class="f" style="max-width:150px">Time needed<select name="mins"><?php foreach ([0 => 'Not sure', 5 => '5 min', 10 => '10 min', 15 => '15 min', 20 => '20 min', 30 => '30 min'] as $v => $l): ?><option value="<?= $v ?>" <?= $v === 10 ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
            <button class="btn primary" type="submit"><?= icon('send', 15) ?>Send request</button></div>
        </form>
        <?php endif; ?>
        <?php if ($mine): ?>
        <div class="stack" style="gap:6px"><div class="eyebrow">Your requests</div>
          <?php foreach ($mine as $r): ?>
          <div class="req"><div class="row" style="justify-content:space-between;gap:8px"><b><?= e($r['title']) ?></b><?= $reqPill[$r['request_status']] ?></div>
            <?php if ($r['review_note']): ?><div class="req-note"><?= e($r['reviewer_name'] ?? 'Host') ?>: <?= e($r['review_note']) ?></div><?php endif; ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div></div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <?php if ($canManage): ?>
    <?= $requestsPanel() ?>
    <?php endif; ?>
    <div class="panel"><div class="panel-h"><h2>Invitees</h2><span class="faint small">Sent via <?= e(implode(' + ', $channels)) ?></span></div>
      <div class="list"><?php foreach ($invitees as $i): ?>
        <div class="li"><?= avatar($i) ?><div style="min-width:0"><div class="title"><?= e($i['name']) ?><?= (int)$i['id'] === uid() ? ' (you)' : '' ?></div><div class="meta"><?php if ($i['title']): ?><span><?= e($i['title']) ?></span><?php endif; ?><span class="mono"><?= e($i['email']) ?></span><?= $i['status'] === 'invited' ? '<span class="pill s-pending">Not registered yet</span>' : '' ?></div></div>
          <span class="pill <?= (int)$i['id'] === (int)$m['organizer_id'] ? 'p-admin' : ($i['rsvp'] === 'yes' ? 's-done' : ($i['rsvp'] === 'no' ? 'p-high' : 's-neutral')) ?>"><?= e((int)$i['id'] === (int)$m['organizer_id'] ? 'Host' : $rsvpLabels[$i['rsvp']]) ?></span></div>
      <?php endforeach; ?></div></div>
  </div>
</div>

<?php elseif ($m['status'] === 'cancelled'): ?>
<div class="banner warn"><div>This meeting was cancelled. Invitees were notified.</div></div>

<?php else: ?>
<?php if ($canManage && ($pending || $reviewed)): ?><?= $requestsPanel() ?><?php endif; ?>
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
        <?php if ($p['added_in_meeting']): ?><span class="pill s-neutral">Raised in meeting</span><?php endif; ?>
        <?php if ($p['request_status'] === 'approved'): ?><span class="pill s-neutral">Added with the host’s permission</span><?php endif; ?></div>
      <div class="point-b">
        <?php if ($p['details'] || $p['subpoints']): ?><div class="point-ctx"><?php if ($p['details']): ?><p><?= e($p['details']) ?></p><?php endif; ?><?php if ($p['subpoints']): ?><ul><?php foreach ($p['subpoints'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
        <label class="f">Minutes discussed<textarea name="discussion[<?= (int)$p['id'] ?>]" data-point="<?= e($p['title']) ?>" placeholder="What was discussed and decided on this point" <?= $isInvitee || $canManage ? '' : 'readonly' ?>><?= e($p['discussion']) ?></textarea></label>
        <div class="stack" style="gap:6px"><div class="eyebrow">Action items (<?= count($pa) ?>)</div>
          <div class="actions-mini">
            <?php if (!$pa && !$canManage): ?><div class="act faint">No action items yet.</div><?php endif; ?>
            <?php foreach ($pa as $x): ?>
            <div class="act"><a class="linkish <?= $x['status'] === 'done' ? 'strike' : '' ?>" href="<?= e(path('actions/' . $x['id'], ['from' => 'meetings'])) ?>"><?= e($x['title']) ?></a><?= pri_pill($x['priority']) ?><?= person_chip(['id' => $x['owner_id'], 'name' => $x['owner_name']]) ?><span class="mono" style="font-size:.82rem;white-space:nowrap"><?= e(fmt_date(Actions::effDeadline($x), false)) ?></span><?= Actions::statusPill($x) ?></div>
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
  <?php if ($isInvitee || $canManage): ?>
  <div class="row save-bar">
    <span class="faint small" id="minutes-state">Save before leaving. Action items save right away.</span>
    <button class="btn primary" type="submit"><?= icon('check', 15) ?>Save minutes</button>
  </div>
  <?php endif; ?>
</form>

<?php if ($isInvitee || $canManage): ?>
<form method="post" action="<?= e(path('meetings/' . $m['id'] . '/points')) ?>" class="panel" id="add-point-form"><div class="panel-b row" style="flex-wrap:nowrap"><?= csrf_field() ?>
  <input type="text" name="title" required maxlength="250" placeholder="Another point came up in the meeting? Add it to the minutes" aria-label="New point"><button class="btn" type="submit"><?= icon('plus', 14) ?>Add point</button></div></form>

<dialog class="dlg" id="minutes-confirm" aria-labelledby="mc-title">
  <form method="dialog" class="stack">
    <h2 id="mc-title">Save the minutes?</h2>
    <ul class="mc-summary" id="mc-summary"></ul>
    <p class="muted small" style="margin:0">Everyone invited can see the minutes. You can keep editing them after saving.</p>
    <div class="mfoot"><button class="btn" value="cancel">Keep editing</button><button class="btn primary" value="save"><?= icon('check', 15) ?>Yes, save minutes</button></div>
  </form>
</dialog>
<?php endif; ?>

<form method="post" action="<?= e(path('meetings/' . $m['id'] . '/actions')) ?>" id="add-action-form" hidden><?= csrf_field() ?>
  <input type="hidden" name="agenda_item_id"><input type="hidden" name="title"><input type="hidden" name="priority"><input type="hidden" name="owner_id"><input type="hidden" name="deadline"></form>
<?php endif; ?>
