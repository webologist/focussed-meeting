<?php
// Minutes of meeting sheet. Used on screen and in the print view.
// $t translates text when a language is chosen (it returns the text unchanged otherwise). Names, emails and links are never translated.
use App\Services\Actions;
$t = $t ?? fn($s) => (string)$s;
$lang = $lang ?? null;
$present = array_filter($invitees, fn($i) => (int)$i['attended'] === 1);
$where = $m['platform'] === 'inperson' ? ($m['location'] ?: '—') : platform_label($m['platform']) . ($m['join_url'] ? ' · ' . $m['join_url'] : '');
$day = gmdate('l', strtotime($m['meeting_date'] . ' 00:00:00 UTC'));
// Host first, then everyone else by name
$roster = array_merge(array_filter($invitees, fn($i) => (int)$i['id'] === (int)$m['organizer_id']), array_filter($invitees, fn($i) => (int)$i['id'] !== (int)$m['organizer_id']));
$p = !empty($print);
$when = $day . ', ' . fmt_date($m['meeting_date']) . ', ' . fmt_time($m['start_time']);
$lead = 'The meeting ' . $m['title'] . ' was held on ' . $when . ($m['platform'] === 'inperson' ? ' at ' . ($m['location'] ?: 'the office') : ' over ' . platform_label($m['platform'])) . ', chaired by ' . $m['organizer_name'] . '. ' . count($present) . ' of ' . count($invitees) . ' invited members were present, as recorded below.';
?>
<article class="<?= $p ? 'paper' : 'mom' ?>" id="mom"<?= $lang ? ' lang="' . e($lang) . '"' : '' ?>>
  <div class="<?= $p ? 'rule' : 'mom-top' ?>">
    <div><div class="<?= $p ? 'pk' : 'eyebrow' ?>"><?= e($orgName ?? '') ?> · <?= e($t('Minutes of Meeting')) ?></div><h1 style="margin-top:4px"><?= e($t($m['title'])) ?></h1></div>
    <div class="mono faint" style="font-size:.8rem;text-align:right">Ref FM-<?= (int)$m['id'] ?>-<?= e(str_replace('-', '', $m['meeting_date'])) ?></div>
  </div>
  <dl>
    <dt><?= e($t('Date & time')) ?></dt><dd><?= e($t($when . ' (' . (int)$m['duration_min'] . ' min)')) ?></dd>
    <dt><?= e($t('Venue')) ?></dt><dd style="overflow-wrap:anywhere"><?= e($where) ?></dd>
    <?php if (!empty($project)): ?><dt><?= e($t('Project')) ?></dt><dd><?= e($t($project['name'])) ?></dd><?php endif; ?>
    <dt><?= e($lang ? $t('Chairperson') : 'Chaired by') ?></dt><dd><?= e($m['organizer_name']) ?><?= !empty($organizerTitle) ? ', ' . e($t($organizerTitle)) : '' ?></dd>
  </dl>

  <section class="formal"><h3><?= e($t('Attendance')) ?></h3>
    <?php if ($lang): ?><p class="formal-lead"><?= e($t($lead)) ?></p>
    <?php else: ?><p class="formal-lead">The meeting <b><?= e($m['title']) ?></b> was held on <?= e($when) ?><?= $m['platform'] === 'inperson' ? ' at ' . e($m['location'] ?: 'the office') : ' over ' . e(platform_label($m['platform'])) ?>, chaired by <?= e($m['organizer_name']) ?>. <?= count($present) ?> of <?= count($invitees) ?> invited members were present, as recorded below.</p><?php endif; ?>
    <div class="tbl-wrap"><table class="compact att"><thead><tr><th>#</th><th><?= e($t('Name')) ?></th><th><?= e($t('Designation')) ?></th><th><?= e($t('Role')) ?></th><th><?= e($t('Attendance')) ?></th></tr></thead><tbody>
      <?php foreach (array_values($roster) as $n => $i): $isChair = (int)$i['id'] === (int)$m['organizer_id']; ?>
      <tr><td class="mono"><?= $n + 1 ?></td><td><?= e($i['name']) ?></td><td><?= e($t($i['title'] ?: '—')) ?></td><td><?= e($isChair ? ($lang ? $t('Chairperson') : 'Chair') : $t('Member')) ?></td>
        <td><?= (int)$i['attended'] === 1 ? '<span class="pill s-done">' . e($t('Present')) . '</span>' : '<span class="pill s-neutral">' . e($t('Absent')) . '</span>' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section><h3><?= e($t('Agenda and discussion')) ?></h3>
    <ol class="disc">
      <?php foreach ($agenda as $pt): ?><li><b><?= e($t($pt['title'])) ?></b><?= $pt['presenter_name'] ? ' <span class="tag">· ' . e($pt['presenter_name']) . '</span>' : '' ?><?= $pt['request_status'] === 'approved' ? ' <span class="tag">· ' . e($t('added with the chair’s permission')) . '</span>' : '' ?><p><?= $pt['discussion'] ? e($t($pt['discussion'])) : '<span class="faint">' . e($t('No minutes recorded.')) . '</span>' ?></p></li><?php endforeach; ?>
    </ol></section>
  <?php if (!empty($declined)): ?>
  <section><h3><?= e($t('Points requested but not added')) ?></h3>
    <ol class="disc">
      <?php foreach ($declined as $d): ?><li><b><?= e($t($d['title'])) ?></b> <span class="tag">· <?= e($t('requested by')) ?> <?= e($d['requester_name'] ?? '') ?></span><p><?= e($t('Declined by')) ?> <?= e($d['reviewer_name'] ?? $t('the chair')) ?><?= $d['review_note'] ? ': ' . e($t($d['review_note'])) : '' ?></p></li><?php endforeach; ?>
    </ol></section>
  <?php endif; ?>
  <section><h3><?= e($t('Action items')) ?></h3>
    <?php if (!$actions): ?><div class="faint"><?= e($t('No action items recorded.')) ?></div><?php else: ?>
    <div class="tbl-wrap"><table class="compact"><thead><tr><th>#</th><th><?= e($t('Action')) ?></th><th><?= e($t('Responsible')) ?></th><th><?= e($t('Priority')) ?></th><th><?= e($t('Deadline')) ?></th><th><?= e($t('Status')) ?></th></tr></thead><tbody>
      <?php foreach ($actions as $n => $x): ?><tr><td class="mono"><?= $n + 1 ?></td><td><?= e($t($x['title'])) ?></td><td><?= e($x['owner_name']) ?></td><td><?= pri_pill($x['priority'], $t) ?></td>
        <td class="mono" style="white-space:nowrap"><?= e($t(fmt_date(Actions::effDeadline($x)))) ?><?= $x['new_deadline'] ? '<div class="sub">' . e($t('revised')) . '</div>' : '' ?></td>
        <td><?= Actions::statusPill($x, $t) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <?php if ($p): ?>
  <div class="signs"><div><span></span><?= e($t('Prepared by')) ?>: <?= e($m['organizer_name']) ?></div><div><span></span><?= e($t('Approved by')) ?></div></div>
  <?php endif; ?>
  <div class="<?= $p ? 'foot' : 'mom-foot' ?>"><?= e($t('Prepared by')) ?> <?= e($m['organizer_name']) ?> · <?= e($t('generated')) ?> <?= e($t(fmt_date(today()))) ?><?= $m['mom_sent_at'] ? ' · ' . e($t('shared with attendees on')) . ' ' . e($t(fmt_local($m['mom_sent_at'], 'j M Y'))) : '' ?>.<?php if (!empty($signature)): ?><br><br><?= nl2br(e($signature)) ?><?php endif; ?></div>
</article>
