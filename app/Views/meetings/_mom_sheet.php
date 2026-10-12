<?php
// Minutes of meeting sheet. Used on screen and in the print view.
use App\Services\Actions;
$present = array_filter($invitees, fn($i) => (int)$i['attended'] === 1);
$where = $m['platform'] === 'inperson' ? ($m['location'] ?: '—') : platform_label($m['platform']) . ($m['join_url'] ? ' · ' . $m['join_url'] : '');
$day = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'));
// Host first, then everyone else by name
$roster = array_merge(array_filter($invitees, fn($i) => (int)$i['id'] === (int)$m['organizer_id']), array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$p = !empty($print);
?>
<article class="<?= $p ? 'paper' : 'mom' ?>" id="mom">
  <div class="<?= $p ? 'rule' : 'mom-top' ?>">
    <div><div class="<?= $p ? 'pk' : 'eyebrow' ?>"><?= e($orgName ?? '') ?> · Minutes of Meeting</div><h1 style="margin-top:4px"><?= e($m['title']) ?></h1></div>
    <div class="mono faint" style="font-size:.8rem;text-align:right">Ref FM-<?= (int)$m['id'] ?>-<?= e(str_replace('-', '', $m['meeting_date'])) ?></div>
  </div>
  <dl>
    <dt>Date &amp; time</dt><dd><?= e($day) ?>, <?= e(fmt_date($m['meeting_date'])) ?>, <?= e(fmt_time($m['start_time'])) ?> (<?= (int)$m['duration_min'] ?> min)</dd>
    <dt>Venue</dt><dd style="overflow-wrap:anywhere"><?= e($where) ?></dd>
    <?php if (!empty($project)): ?><dt>Project</dt><dd><?= e($project['name']) ?></dd><?php endif; ?>
    <dt>Chaired by</dt><dd><?= e($m['organizer_name']) ?><?= !empty($organizerTitle) ? ', ' . e($organizerTitle) : '' ?></dd>
  </dl>

  <section class="formal"><h3>Attendance</h3>
    <p class="formal-lead">The meeting <b><?= e($m['title']) ?></b> was held on <?= e($day) ?>, <?= e(fmt_date($m['meeting_date'])) ?> at <?= e(fmt_time($m['start_time'])) ?><?= $m['platform'] === 'inperson' ? ' at ' . e($m['location'] ?: 'the office') : ' over ' . e(platform_label($m['platform'])) ?>, chaired by <?= e($m['organizer_name']) ?>. <?= count($present) ?> of <?= count($invitees) ?> invited members were present, as recorded below.</p>
    <div class="tbl-wrap"><table class="compact att"><thead><tr><th>#</th><th>Name</th><th>Designation</th><th>Role</th><th>Attendance</th></tr></thead><tbody>
      <?php foreach (array_values($roster) as $n => $i): $isChair = (int)$i['id'] === (int)$m['organizer_id']; ?>
      <tr><td class="mono"><?= $n + 1 ?></td><td><?= e($i['name']) ?></td><td><?= e($i['title'] ?: '—') ?></td><td><?= $isChair ? 'Chair' : 'Member' ?></td>
        <td><?= (int)$i['attended'] === 1 ? '<span class="pill s-done">Present</span>' : '<span class="pill s-neutral">Absent</span>' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section><h3>Agenda and discussion</h3>
    <ol class="disc">
      <?php foreach ($agenda as $pt): ?><li><b><?= e($pt['title']) ?></b><?= $pt['presenter_name'] ? ' <span class="tag">· ' . e($pt['presenter_name']) . '</span>' : '' ?><?= $pt['request_status'] === 'approved' ? ' <span class="tag">· added with the chair’s permission</span>' : '' ?><p><?= $pt['discussion'] ? e($pt['discussion']) : '<span class="faint">No minutes recorded.</span>' ?></p></li><?php endforeach; ?>
    </ol></section>
  <section><h3>Action items</h3>
    <?php if (!$actions): ?><div class="faint">No action items recorded.</div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>#</th><th>Action</th><th>Responsible</th><th>Priority</th><th>Deadline</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($actions as $n => $x): ?><tr><td class="mono"><?= $n + 1 ?></td><td><?= e($x['title']) ?></td><td><?= e($x['owner_name']) ?></td><td><?= pri_pill($x['priority']) ?></td>
        <td class="mono" style="white-space:nowrap"><?= e(fmt_date(Actions::effDeadline($x))) ?><?= $x['new_deadline'] ? '<div class="sub">revised</div>' : '' ?></td>
        <td><?= Actions::statusPill($x) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <?php if ($p): ?>
  <div class="signs"><div><span></span>Prepared by: <?= e($m['organizer_name']) ?></div><div><span></span>Approved by</div></div>
  <?php endif; ?>
  <div class="<?= $p ? 'foot' : 'mom-foot' ?>">Prepared by <?= e($m['organizer_name']) ?> · generated <?= e(fmt_date(today())) ?><?= $m['mom_sent_at'] ? ' · shared with attendees on ' . e(fmt_local($m['mom_sent_at'], 'j M Y')) : '' ?>.<?php if (!empty($signature)): ?><br><br><?= nl2br(e($signature)) ?><?php endif; ?></div>
</article>
