<?php
use App\Services\Meetings;
$list = $tab === 'past' ? $past : $up;
$pl = $_SESSION['_planner'] ?? [];
$pf = fn(string $k, string $d = '') => (string)($pl[$k] ?? $_GET[$k] ?? $d);
$defPlatform = $pf('platform', $settings['default_platform'] ?? 'meet');
$defDuration = (int)$pf('duration', (string)($settings['default_duration'] ?? 30));
$googleOk = isset($ints['google']); $msOk = isset($ints['microsoft']); $waOk = isset($ints['whatsapp']);
$emailOk = isset($ints['smtp']) || config('mail.fallback_for_companies');
?>
<div class="head"><div><div class="eyebrow">Plan · Invite · Minutes</div><h1>Meetings</h1></div></div>

<?php if (is_admin()): ?>
  <?php if (!$plan): ?>
  <form class="panel" method="get" action="<?= e(path('meetings')) ?>">
    <input type="hidden" name="plan" value="1">
    <div class="qa-bar mt-bar" style="border-radius:12px;border-bottom:0">
      <input class="qa-text" type="text" name="title" placeholder="Plan a meeting: type a title" aria-label="Meeting title">
      <input type="date" name="date" value="<?= e(date_add_days(today(), 1)) ?>" aria-label="Date">
      <input type="time" name="time" value="11:00" aria-label="Start time">
      <select name="platform" aria-label="Where"><?php foreach (['meet', 'teams', 'inperson'] as $p): ?><option value="<?= $p ?>" <?= $defPlatform === $p ? 'selected' : '' ?>><?= e(platform_label($p)) ?></option><?php endforeach; ?></select>
      <select name="duration" aria-label="Duration"><?php foreach ([15, 30, 45, 60, 90, 120] as $n): ?><option value="<?= $n ?>" <?= $defDuration === $n ? 'selected' : '' ?>><?= $n ?> min</option><?php endforeach; ?></select>
      <button class="btn primary icon-only" type="submit" aria-label="Add agenda and invitees" title="Add agenda and invitees"><?= icon('plus', 14) ?></button>
    </div>
  </form>
  <?php else: ?>
  <div class="panel" id="planner-panel">
    <div class="panel-h"><h2><?= icon('plus', 14) ?> Plan a meeting</h2><div class="row" style="gap:6px"><span class="faint small">Invitees get the agenda by email<?= $waOk ? ' and WhatsApp' : '' ?></span><a class="btn sm ghost" href="<?= e(path('meetings')) ?>"><?= icon('up', 14) ?> Collapse</a></div></div>
    <div class="panel-b">
    <form method="post" action="<?= e(path('meetings')) ?>" id="planner" class="grid2">
      <?= csrf_field() ?>
      <input type="hidden" name="agenda_json" id="agenda-json" value="<?= e($pl['agenda_json'] ?? '') ?>">
      <input type="hidden" name="new_people_json" id="new-people-json" value="<?= e($pl['new_people_json'] ?? '[]') ?>">
      <div class="stack">
        <div class="panel"><div class="panel-h"><h2>Details</h2></div><div class="panel-b stack">
          <label class="f">Meeting title<input type="text" name="title" id="d-title" value="<?= e($pf('title')) ?>" required maxlength="200" placeholder="e.g. Vendor contract review" data-preview></label>
          <div class="fields">
            <label class="f">Date<input type="date" name="date" id="d-date" value="<?= e($pf('date', date_add_days(today(), 1))) ?>" required data-preview></label>
            <label class="f">Start time<input type="time" name="time" id="d-time" value="<?= e($pf('time', '11:00')) ?>" required data-preview></label>
            <label class="f">Duration<select name="duration" id="d-dur" data-preview><?php foreach ([15, 30, 45, 60, 90, 120] as $n): ?><option value="<?= $n ?>" <?= $defDuration === $n ? 'selected' : '' ?>><?= $n ?> min</option><?php endforeach; ?></select></label>
          </div>
          <div class="fields">
            <label class="f">Where<select name="platform" id="d-plat" data-preview>
              <option value="meet" <?= $defPlatform === 'meet' ? 'selected' : '' ?>>Google Meet<?= $googleOk ? '' : ' (not connected)' ?></option>
              <option value="teams" <?= $defPlatform === 'teams' ? 'selected' : '' ?>>Microsoft Teams<?= $msOk ? '' : ' (not connected)' ?></option>
              <option value="inperson" <?= $defPlatform === 'inperson' ? 'selected' : '' ?>>In person</option></select></label>
            <label class="f" id="loc-wrap">Location<input type="text" name="location" id="d-loc" value="<?= e($pf('location')) ?>" maxlength="250" placeholder="Conference room, address" data-preview></label>
          </div>
          <div class="banner warn" id="plat-warn" hidden data-google="<?= $googleOk ? 1 : 0 ?>" data-ms="<?= $msOk ? 1 : 0 ?>"><div><span id="plat-warn-text"></span> <a class="btn sm" href="<?= e(path('integrations')) ?>">Open Integrations</a></div></div>
        </div></div>

        <div class="panel"><div class="panel-h"><h2>Agenda</h2><div class="alloc" id="alloc"><span class="mono" id="alloc-text">0 / 30 min</span><span class="alloc-bar"><i id="alloc-bar" style="width:0"></i></span><span id="alloc-over" hidden>Over time</span></div></div>
          <div class="panel-b stack"><div class="agenda-edit" id="agenda-builder"></div><div><button type="button" class="btn sm" id="add-point"><?= icon('plus', 14) ?>Add agenda point</button></div></div></div>

        <div class="panel"><div class="panel-h"><h2>Invitees</h2><span class="faint small" id="inv-count"></span></div><div class="panel-b stack">
          <div class="stack" style="gap:6px"><div class="eyebrow">Your team</div>
            <div class="attend" id="member-list">
              <?php $sel = array_map('intval', (array)($pl['invitees'] ?? [])); foreach ($members as $mem): if ((int)$mem['id'] === uid()) continue; ?>
              <label class="<?= in_array((int)$mem['id'], $sel, true) ? '' : 'off' ?>"><input type="checkbox" name="invitees[]" value="<?= (int)$mem['id'] ?>" data-name="<?= e($mem['name']) ?>" data-email="<?= e($mem['email']) ?>" <?= in_array((int)$mem['id'], $sel, true) ? 'checked' : '' ?>><?= avatar($mem, 22) ?><?= e($mem['name']) ?><?= $mem['status'] === 'invited' ? ' <span class="faint">(invited)</span>' : '' ?></label>
              <?php endforeach; ?>
              <?php if (count($members) <= 1): ?><span class="faint small">No teammates yet. Add people below; they’ll get an invitation to register.</span><?php endif; ?>
            </div></div>
          <div class="stack" style="gap:6px"><div class="eyebrow">Invite someone new</div>
            <div class="fields">
              <input type="text" id="np-name" placeholder="Name" aria-label="Name">
              <input type="email" id="np-email" placeholder="Email" aria-label="Email">
              <input type="tel" id="np-phone" placeholder="WhatsApp number (optional)" aria-label="WhatsApp number">
            </div>
            <div class="row"><button type="button" class="btn sm" id="np-add"><?= icon('plus', 14) ?>Add invitee</button><span class="faint small">New people join as participants and must register to add minutes.</span></div>
            <div class="row" id="np-list"></div>
          </div>
        </div></div>

        <div class="panel"><div class="panel-h"><h2>Send invite via</h2></div><div class="panel-b stack">
          <label class="check"><input type="checkbox" name="via_email" value="1" <?= ($pl ? !empty($pl['via_email']) : (int)($settings['invite_email'] ?? 1)) ? 'checked' : '' ?>>Email<?= isset($ints['smtp']) ? ' from ' . e($ints['smtp']['account_label']) : ($emailOk ? ' (platform mailer)' : ' (connect email in Integrations)') ?><?= $defPlatform !== 'inperson' ? '' : '' ?></label>
          <label class="check"><input type="checkbox" name="via_whatsapp" value="1" <?= $waOk ? (($pl ? !empty($pl['via_whatsapp']) : (int)($settings['invite_whatsapp'] ?? 0)) ? 'checked' : '') : 'disabled' ?>>WhatsApp<?= $waOk ? '' : ' (not connected)' ?></label>
          <p class="faint small" style="margin:0">With Google Meet or Teams, invitees also get a calendar invite from your connected account. Otherwise the email includes a calendar file.</p>
          <div class="row"><button class="btn primary" type="submit"><?= icon('send', 15) ?>Send invite</button></div>
        </div></div>
      </div>

      <div class="preview"><div class="eyebrow">What invitees receive</div>
        <div class="mail"><dl class="mail-h"><dt>From</dt><dd><?= e(user()['name']) ?> &lt;<?= e(isset($ints['smtp']) ? $ints['smtp']['account_label'] : user()['email']) ?>&gt;</dd><dt>Subject</dt><dd><b>Invitation: <span id="pv-title">Untitled meeting</span></b></dd></dl>
          <div class="mail-b"><div>Hi, you’re invited to <b id="pv-title2">Untitled meeting</b>.</div>
            <div><span class="faint">When</span> <span id="pv-when"></span><br><span class="faint">Where</span> <span id="pv-where"></span></div>
            <div><b>Agenda</b><div id="pv-agenda"></div></div>
            <span class="mail-cta locked">Add minutes (opens after the meeting)</span></div></div>
      </div>
    </form>
    </div>
  </div>
  <script>window.FM_MEMBERS = <?= json_encode(array_values(array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $members))) ?>; window.FM_ME = <?= uid() ?>;</script>
  <?php unset($_SESSION['_planner']); endif; ?>
<?php endif; ?>

<div class="row" style="justify-content:space-between">
  <div class="tabs" role="tablist">
    <a class="tabbtn <?= $tab === 'upcoming' ? 'on' : '' ?>" href="<?= e(path('meetings')) ?>">Upcoming (<?= count($up) ?>)</a>
    <a class="tabbtn <?= $tab === 'past' ? 'on' : '' ?>" href="<?= e(path('meetings', ['tab' => 'past'])) ?>">Past (<?= count($past) ?>)</a>
  </div>
</div>
<div class="panel">
  <?php if (!$list): ?><div class="empty">No <?= $tab ?> meetings.<?= is_admin() && $tab === 'upcoming' ? ' Plan one above.' : '' ?></div><?php endif; ?>
  <?php foreach ($list as $m): [$label, $cls] = Meetings::status($m, (int)$m['blank_points']); $t = strtotime($m['meeting_date'] . ' 00:00:00 UTC'); ?>
  <div class="mrow">
    <div class="datebox"><span><?= gmdate('D', $t) ?></span><b><?= gmdate('j', $t) ?></b><span><?= gmdate('M', $t) ?></span></div>
    <div style="min-width:0">
      <div class="row" style="gap:8px"><h3><a class="linkish" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= e($m['title']) ?></a></h3><span class="pill <?= $cls ?>"><?= e($label) ?></span></div>
      <div class="mmeta"><span class="mono"><?= e(fmt_time($m['start_time'])) ?> · <?= (int)$m['duration_min'] ?> min</span><span><?= e(platform_label($m['platform'])) ?></span><span><?= plural((int)$m['invitee_count'], 'invitee') ?></span><span><?= plural((int)$m['point_count'], 'agenda point') ?></span>
        <?php if ((int)$m['action_count']): ?><span><?= (int)$m['open_count'] ?> of <?= (int)$m['action_count'] ?> actions open</span><?php endif; ?>
        <?php if ((int)$m['organizer_id'] !== uid()): ?><span>Organised by <?= e($m['organizer_name']) ?></span><?php endif; ?></div>
    </div>
    <div class="row mbtns">
      <a class="btn sm" href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener"><?= icon('print', 14) ?>Invite</a>
      <?php if ($m['status'] === 'completed'): ?><a class="btn sm" href="<?= e(path('meetings/' . $m['id'] . '/mom')) ?>"><?= icon('doc', 14) ?>MoM</a><?php endif; ?>
      <a class="btn sm <?= $m['status'] === 'scheduled' && $m['meeting_date'] < today() ? 'primary' : '' ?>" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= $m['status'] === 'scheduled' && $m['meeting_date'] < today() ? 'Add minutes' : 'Open' ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
