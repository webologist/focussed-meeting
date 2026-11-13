<?php use App\Core\View; use App\Services\Translator; ?>
<div class="row" style="justify-content:space-between">
  <a class="btn ghost sm" href="<?= e(path('meetings/' . $m['id'])) ?>"><?= icon('back', 14) ?><?= $canManage ? 'Edit minutes' : 'Back to meeting' ?></a>
  <div class="row">
    <button type="button" class="btn" data-copy="#mom"><?= icon('doc', 15) ?>Copy as text</button>
    <a class="btn" href="https://wa.me/?text=<?= e(rawurlencode(mb_strlen($waText) > 1800 ? mb_substr($waText, 0, 1800) . '…' : $waText)) ?>" target="_blank" rel="noopener"><?= icon('send', 15) ?>Share on WhatsApp</a>
    <button type="button" class="btn" data-copy="#wa-text"><?= icon('doc', 15) ?>Copy WhatsApp summary</button>
    <?php if ($isHost): ?>
    <details class="menu"><summary class="btn"><?= icon('print', 15) ?>Print</summary>
      <div class="menu-pop menu-list">
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/mom', ['lang' => $lang])) ?>" target="_blank" rel="noopener">Minutes of meeting<?= $lang ? ' (' . e(Translator::LANGS[$lang]) . ')' : '' ?></a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/attendance')) ?>" target="_blank" rel="noopener">Attendance register</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/actions')) ?>" target="_blank" rel="noopener">Action items</a>
        <a href="<?= e(path('meetings/' . $m['id'] . '/print/invite')) ?>" target="_blank" rel="noopener">Meeting invitation</a>
      </div></details>
    <?php endif; ?>
    <?php if ($canManage): ?>
    <form method="post" action="<?= e(path('meetings/' . $m['id'] . '/mom/send')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="lang" value="<?= e($lang) ?>"><button class="btn primary" type="submit" data-confirm="Email the minutes to all <?= count($invitees) ?> invitees<?= $lang && $translateReady ? ' in ' . e(Translator::LANGS[$lang]) : '' ?>?"><?= icon('send', 15) ?><?= $m['mom_sent_at'] ? 'Resend' : 'Send' ?> MoM to attendees</button></form>
    <?php endif; ?>
  </div>
</div>
<div class="row" style="gap:6px;flex-wrap:wrap;margin:12px 0" role="group" aria-label="Language of the minutes">
  <span class="faint small">Language</span>
  <a class="btn sm <?= $lang ? '' : 'primary' ?>" href="<?= e(path('meetings/' . $m['id'] . '/mom')) ?>">English</a>
  <?php foreach (Translator::LANGS as $k => $label): ?><a class="btn sm <?= $lang === $k ? 'primary' : '' ?>" href="<?= e(path('meetings/' . $m['id'] . '/mom', ['lang' => $k])) ?>" lang="<?= e($k) ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<?php if ($lang && !$translateReady): ?><div class="banner warn" style="margin-bottom:12px"><div>Translation isn’t set up yet, so this is still in English. An admin needs to add a Google Cloud Translation API key as <code>translate.google_api_key</code> in <code>config/config.php</code> (see docs/INTEGRATIONS.md).</div></div>
<?php elseif ($translateError): ?><div class="banner warn" style="margin-bottom:12px"><div>Couldn’t translate right now, so this is in English. <?= e($translateError) ?></div></div>
<?php elseif ($lang): ?><div class="banner" style="margin-bottom:12px"><div>Translated automatically from English into <?= e(Translator::LANGS[$lang]) ?>. Please check names and important wording before sharing.</div></div><?php endif; ?>
<pre id="wa-text" hidden><?= e($waText) ?></pre>
<?php if (!empty($seen) && $seen['total']): ?>
<div class="panel" style="margin-bottom:12px"><div class="panel-b row" style="justify-content:space-between;gap:10px">
  <span><b>Seen by <?= (int)$seen['seen'] ?> of <?= (int)$seen['total'] ?></b> <span class="faint small">invitees have opened the saved minutes</span></span>
  <details class="invite-preview"><summary class="linkish small">Who</summary>
    <div class="stack" style="gap:4px;margin-top:6px">
      <?php foreach ($seen['rows'] as $r): ?><div class="small"><?= $r['first_seen_at'] ? '<span class="pill s-done">✓ Seen</span> ' . e($r['name']) . ' <span class="faint">' . e(fmt_local($r['first_seen_at'], 'j M, g:i A')) . '</span>' : '<span class="pill s-neutral">Not yet</span> ' . e($r['name']) ?></div><?php endforeach; ?>
    </div></details>
</div></div>
<?php endif; ?>
<?= View::partial('meetings/_mom_sheet', get_defined_vars()) ?>
