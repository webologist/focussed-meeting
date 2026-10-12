<?php
use App\Services\Meetings;
$list = $tab === 'past' ? $past : $up;
$pl = $_SESSION['_planner'] ?? [];
$pf = fn(string $k, string $d = '') => (string)($pl[$k] ?? $_GET[$k] ?? $d);
$defPlatform = $pf('platform', $settings['default_platform'] ?? 'meet');
$defDuration = (int)$pf('duration', (string)($settings['default_duration'] ?? 30));
$googleOk = isset($ints['google']); $msOk = isset($ints['microsoft']); $waOk = isset($ints['whatsapp']);
$emailOk = isset($ints['smtp']) || config('mail.fallback_for_companies');
$selProject = (int)$pf('project_id', (string)($projectId ?: ''));
$curProject = $projectId ? ($projects[$projectId] ?? null) : null;
$tabUrl = fn(string $t) => path('meetings', array_filter(['tab' => $t === 'past' ? 'past' : null, 'project' => $projectId ?: null]));
?>
<div class="head"><div><div class="eyebrow">Plan · Invite · Minutes</div><h1>Meetings</h1>
  <?php if ($curProject): ?><p class="muted">Project: <b><?= e($curProject['name']) ?></b> · <a href="<?= e(path('meetings', array_filter(['tab' => $tab === 'past' ? 'past' : null]))) ?>">Show all projects</a></p><?php endif; ?></div></div>

<?php if (!$plan): ?>
<form class="panel" method="get" action="<?= e(path('meetings')) ?>">
  <input type="hidden" name="plan" value="1">
  <?php if ($curProject && $curProject['status'] === 'active'): ?><input type="hidden" name="project_id" value="<?= (int)$curProject['id'] ?>"><?php endif; ?>
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
  <div class="panel-h"><h2><?= icon('plus', 14) ?> Plan a meeting</h2><div class="row" style="gap:6px"><span class="faint small">You’ll be the host</span><a class="btn sm ghost" href="<?= e(path('meetings')) ?>"><?= icon('up', 14) ?> Collapse</a></div></div>
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
        <div class="banner warn" id="plat-warn" hidden data-google="<?= $googleOk ? 1 : 0 ?>" data-ms="<?= $msOk ? 1 : 0 ?>"><div><span id="plat-warn-text"></span> <?php if (is_admin()): ?><a class="btn sm" href="<?= e(path('integrations')) ?>">Open Integrations</a><?php else: ?>Ask an admin to connect it, or choose In person.<?php endif; ?></div></div>
        <div class="fields">
          <label class="f">Project <span class="hint">optional</span><select name="project_id" id="d-project" data-preview>
            <option value="">No project</option>
            <?php foreach ($activeProjects as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $selProject === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
            <option value="-1" <?= $selProject === -1 ? 'selected' : '' ?>>+ New project…</option></select></label>
          <label class="f" id="new-project-wrap" <?= $selProject === -1 ? '' : 'hidden' ?>>New project name<input type="text" name="new_project" id="d-new-project" value="<?= e($pf('new_project')) ?>" maxlength="120" placeholder="e.g. Mobile App v2" data-preview></label>
        </div>
      </div></div>

      <div class="panel"><div class="panel-h"><h2>Agenda</h2><div class="alloc" id="alloc"><span class="mono" id="alloc-text">0 / 30 min</span><span class="alloc-bar"><i id="alloc-bar" style="width:0"></i></span><span id="alloc-over" hidden>Over time</span></div></div>
        <div class="panel-b stack"><div class="agenda-edit" id="agenda-builder"></div><div><button type="button" class="btn sm" id="add-point"><?= icon('plus', 14) ?>Add agenda point</button></div>
          <p class="faint small" style="margin:0">Invitees can ask you to add more points. Their requests wait on the meeting page for your approval.</p></div></div>

      <div class="panel"><div class="panel-h"><h2>Invitees</h2><span class="faint small" id="inv-count"></span></div><div class="panel-b stack">
        <div class="stack" style="gap:6px"><div class="eyebrow">Your team</div>
          <div class="attend" id="member-list">
            <?php $sel = array_map('intval', (array)($pl['invitees'] ?? [])); $others = array_filter($members, fn($mem) => (int)$mem['id'] !== uid()); foreach ($others as $mem): ?>
            <label class="<?= in_array((int)$mem['id'], $sel, true) ? '' : 'off' ?>"><input type="checkbox" name="invitees[]" value="<?= (int)$mem['id'] ?>" data-name="<?= e($mem['name']) ?>" data-email="<?= e($mem['email']) ?>" <?= in_array((int)$mem['id'], $sel, true) ? 'checked' : '' ?>><?= avatar($mem, 22) ?><?= e($mem['name']) ?><?= $mem['status'] === 'invited' ? ' <span class="faint">(invited)</span>' : '' ?></label>
            <?php endforeach; ?>
            <?php if (!$others): ?><span class="faint small"><?= is_admin() ? 'No teammates yet. Add people below; they’ll get an invitation to register.' : 'No teammates yet. Ask an admin to invite your team.' ?></span><?php endif; ?>
          </div></div>
        <?php if (is_admin()): ?>
        <div class="stack" style="gap:6px"><div class="eyebrow">Invite someone new</div>
          <div class="fields">
            <input type="text" id="np-name" placeholder="Name" aria-label="Name">
            <input type="email" id="np-email" placeholder="Email" aria-label="Email">
            <input type="tel" id="np-phone" placeholder="WhatsApp number (optional)" aria-label="WhatsApp number">
          </div>
          <div class="row"><button type="button" class="btn sm" id="np-add"><?= icon('plus', 14) ?>Add invitee</button><span class="faint small">New people join as participants and must register to add minutes.</span></div>
        </div>
        <?php else: ?>
        <p class="faint small" style="margin:0">Only admins can invite people who aren’t on the team yet.</p>
        <?php endif; ?>
        <div class="row" id="np-list"></div>
      </div></div>

      <div class="panel"><div class="panel-h"><h2>Send invite via</h2></div><div class="panel-b stack">
        <label class="check"><input type="checkbox" name="via_email" value="1" <?= ($pl ? !empty($pl['via_email']) : (int)($settings['invite_email'] ?? 1)) ? 'checked' : '' ?>><span>Email<span class="faint"><?= isset($ints['smtp']) ? ' from ' . e($ints['smtp']['account_label']) : ($emailOk ? ' (platform mailer)' : ' (connect email in Integrations)') ?></span></span></label>
        <label class="check"><input type="checkbox" name="via_whatsapp" value="1" <?= $waOk ? (($pl ? !empty($pl['via_whatsapp']) : (int)($settings['invite_whatsapp'] ?? 0)) ? 'checked' : '') : 'disabled' ?>><span>WhatsApp<span class="faint"><?= $waOk ? '' : ' (not connected)' ?></span></span></label>
        <label class="check"><input type="checkbox" name="via_personal" value="1" <?= $pl && !empty($pl['via_personal']) ? 'checked' : '' ?>><span>Personal invite <span class="faint">· a signed invitation letter per invitee to print and hand out, plus the invite text to share from your own phone or mail app</span></span></label>
        <label class="f">Personal message <span class="hint">optional, goes with every invite</span><textarea name="personal_note" id="d-note" rows="3" maxlength="2000" placeholder="e.g. Please bring last quarter’s numbers. Lunch will be served." data-preview><?= e($pf('personal_note')) ?></textarea></label>
        <p class="faint small" style="margin:0">With Google Meet or Teams, invitees also get a calendar invite from your connected account. Otherwise the email includes a calendar file.</p>
        <div class="row"><button class="btn primary" type="submit"><?= icon('send', 15) ?>Send invite</button></div>
      </div></div>
    </div>

    <div class="preview"><div class="eyebrow">What invitees receive</div>
      <div class="mail"><dl class="mail-h"><dt>From</dt><dd><?= e(user()['name']) ?> &lt;<?= e(isset($ints['smtp']) ? $ints['smtp']['account_label'] : user()['email']) ?>&gt;</dd><dt>Subject</dt><dd><b>Invitation: <span id="pv-title">Untitled meeting</span></b></dd></dl>
        <div class="mail-b"><div>Hi, you’re invited to <b id="pv-title2">Untitled meeting</b>.</div>
          <div class="pv-note" id="pv-note" hidden></div>
          <div><span class="faint">When</span> <span id="pv-when"></span><br><span class="faint">Where</span> <span id="pv-where"></span><span id="pv-project-row" hidden><br><span class="faint">Project</span> <span id="pv-project"></span></span></div>
          <div><b>Agenda</b><div id="pv-agenda"></div></div>
          <span class="mail-cta locked">Add minutes (opens after the meeting)</span></div></div>
    </div>
  </form>
  </div>
</div>
<script>window.FM_MEMBERS = <?= json_encode(array_values(array_map(fn($m) => ['id' => (int)$m['id'], 'name' => $m['name']], $members))) ?>; window.FM_ME = <?= uid() ?>;</script>
<?php unset($_SESSION['_planner']); endif; ?>

<div class="row" style="justify-content:space-between">
  <div class="tabs" role="tablist">
    <a class="tabbtn <?= $tab === 'upcoming' ? 'on' : '' ?>" href="<?= e($tabUrl('upcoming')) ?>">Upcoming (<?= count($up) ?>)</a>
    <a class="tabbtn <?= $tab === 'past' ? 'on' : '' ?>" href="<?= e($tabUrl('past')) ?>">Past (<?= count($past) ?>)</a>
  </div>
  <?php if ($projects): ?>
  <form method="get" action="<?= e(path('meetings')) ?>" class="filters">
    <?php if ($tab === 'past'): ?><input type="hidden" name="tab" value="past"><?php endif; ?>
    <select name="project" data-autosubmit aria-label="Filter by project"><option value="">All projects</option>
      <?php foreach ($projects as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $projectId === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?><?= $pr['status'] === 'archived' ? ' (archived)' : '' ?></option><?php endforeach; ?></select>
  </form>
  <?php endif; ?>
</div>
<div class="panel">
  <?php if (!$list): ?><div class="empty">No <?= $tab ?> meetings<?= $curProject ? ' in this project' : '' ?>.<?= $tab === 'upcoming' ? ' Plan one above.' : '' ?></div><?php endif; ?>
  <?php foreach ($list as $m): [$label, $cls] = Meetings::status($m, (int)$m['blank_points']); $t = strtotime($m['meeting_date'] . ' 00:00:00 UTC'); $host = (int)$m['organizer_id'] === uid(); $req = $host ? (int)$m['pending_requests'] : 0; $due = $m['status'] === 'scheduled' && $m['meeting_date'] < today(); ?>
  <div class="mrow">
    <div class="datebox"><span><?= gmdate('D', $t) ?></span><b><?= gmdate('j', $t) ?></b><span><?= gmdate('M', $t) ?></span></div>
    <div style="min-width:0">
      <div class="row" style="gap:8px"><h3><a class="linkish" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= e($m['title']) ?></a></h3><span class="pill <?= $cls ?>"><?= e($label) ?></span>
        <?php if ($req): ?><a class="pill s-review" href="<?= e(path('meetings/' . $m['id'])) ?>#permission"><?= plural($req, 'point request') ?> to review</a><?php endif; ?>
        <?php if ($m['project_name']): ?><a class="pill s-project" href="<?= e(path('meetings', array_filter(['project' => $m['project_id'], 'tab' => $tab === 'past' ? 'past' : null]))) ?>"><?= e($m['project_name']) ?></a><?php endif; ?></div>
      <div class="mmeta"><span class="mono"><?= e(fmt_time($m['start_time'])) ?> · <?= (int)$m['duration_min'] ?> min</span><span><?= e(platform_label($m['platform'])) ?></span><span><?= plural((int)$m['invitee_count'], 'invitee') ?></span><span><?= plural((int)$m['point_count'], 'agenda point') ?></span>
        <?php if ((int)$m['action_count']): ?><span><?= (int)$m['open_count'] ?> of <?= (int)$m['action_count'] ?> actions open</span><?php endif; ?>
        <span><?= $host ? 'You’re the host' : 'Host: ' . e($m['organizer_name']) ?></span></div>
    </div>
    <div class="row mbtns">
      <?php if ($host): ?><a class="btn sm" href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener" title="Print the invitation"><?= icon('print', 14) ?>Invite</a><?php endif; ?>
      <?php if ($m['status'] === 'completed'): ?><a class="btn sm" href="<?= e(path('meetings/' . $m['id'] . '/mom')) ?>"><?= icon('doc', 14) ?>MoM</a><?php endif; ?>
      <a class="btn sm <?= $due && ($host || is_admin()) ? 'primary' : '' ?>" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= $due && ($host || is_admin()) ? 'Add minutes' : 'Open' ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
