<?php
// Minutes of meeting sheet. Used on screen and in the print view.
use App\Services\Actions;
$present = array_filter($invitees, fn($i) => (int)$i['attended'] === 1);
$absent = array_filter($invitees, fn($i) => (int)$i['attended'] !== 1);
$where = $m['platform'] === 'inperson' ? ($m['location'] ?: '—') : platform_label($m['platform']) . ($m['join_url'] ? ' · ' . $m['join_url'] : '');
?>
<article class="<?= !empty($print) ? 'paper' : 'mom' ?>" id="mom">
  <div class="<?= !empty($print) ? 'rule' : 'mom-top' ?>">
    <div><div class="<?= !empty($print) ? 'pk' : 'eyebrow' ?>">Minutes of Meeting</div><h1 style="margin-top:4px"><?= e($m['title']) ?></h1></div>
    <div class="mono faint" style="font-size:.8rem;text-align:right">Ref FM-<?= (int)$m['id'] ?>-<?= e(str_replace('-', '', $m['meeting_date'])) ?></div>
  </div>
  <dl>
    <dt>Date &amp; time</dt><dd><?= e(fmt_date($m['meeting_date'])) ?>, <?= e(fmt_time($m['start_time'])) ?> (<?= (int)$m['duration_min'] ?> min)</dd>
    <dt>Venue</dt><dd style="overflow-wrap:anywhere"><?= e($where) ?></dd>
    <dt>Chaired by</dt><dd><?= e($m['organizer_name']) ?></dd>
    <dt>Present</dt><dd><?= e(implode(', ', array_column($present, 'name')) ?: '—') ?></dd>
    <?php if ($absent): ?><dt>Absent</dt><dd><?= e(implode(', ', array_column($absent, 'name'))) ?></dd><?php endif; ?>
  </dl>
  <section><h3>Agenda and discussion</h3>
    <ol class="disc">
      <?php foreach ($agenda as $p): ?><li><b><?= e($p['title']) ?></b><?= $p['presenter_name'] ? ' <span class="tag">· ' . e($p['presenter_name']) . '</span>' : '' ?><p><?= $p['discussion'] ? e($p['discussion']) : '<span class="faint">No minutes recorded.</span>' ?></p></li><?php endforeach; ?>
    </ol></section>
  <section><h3>Action items</h3>
    <?php if (!$actions): ?><div class="faint">No action items recorded.</div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>#</th><th>Action</th><th>Responsible</th><th>Priority</th><th>Deadline</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($actions as $n => $x): ?><tr><td class="mono"><?= $n + 1 ?></td><td><?= e($x['title']) ?></td><td><?= e($x['owner_name']) ?></td><td><?= pri_pill($x['priority']) ?></td>
        <td class="mono" style="white-space:nowrap"><?= e(fmt_date(Actions::effDeadline($x))) ?><?= $x['new_deadline'] ? '<div class="sub">revised</div>' : '' ?></td>
        <td><?= $x['status'] === 'done' ? '<span class="pill s-done">Done</span>' : (Actions::isOverdue($x) ? '<span class="pill p-high">Overdue</span>' : '<span class="pill s-neutral">Open</span>') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <div class="<?= !empty($print) ? 'foot' : 'mom-foot' ?>">Prepared by <?= e($m['organizer_name']) ?> · generated <?= e(fmt_date(today())) ?><?= $m['mom_sent_at'] ? ' · shared with attendees on ' . e(fmt_local($m['mom_sent_at'], 'j M Y')) : '' ?>.<?php if (!empty($signature)): ?><br><br><?= nl2br(e($signature)) ?><?php endif; ?></div>
</article>
