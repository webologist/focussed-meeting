<?php
// Reminder ("remind me") and nudge ("send reminder") dialogs, shared by several pages.
$wa = App\Services\Integrations::connected(org_id(), 'whatsapp');
?>
<dialog class="dlg" id="reminder-dialog" aria-labelledby="rem-title">
  <form method="post" action="<?= e(path('reminders')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action_id" id="rem-action" value="">
    <h2 id="rem-title">Remind me</h2>
    <label class="f">Note<input type="text" name="body" id="rem-body" required maxlength="250" placeholder="What should we remind you about?"></label>
    <div class="stack" style="gap:6px"><span class="eyebrow">Quick picks</span>
      <div class="presets">
        <button type="button" class="btn sm" data-preset="1h">In 1 hour</button>
        <button type="button" class="btn sm" data-preset="eve">This evening</button>
        <button type="button" class="btn sm" data-preset="tom">Tomorrow 9 AM</button>
        <button type="button" class="btn sm" data-preset="before" id="preset-before">Day before due</button>
        <button type="button" class="btn sm" data-preset="mon">Next Monday</button>
      </div></div>
    <div class="fields">
      <label class="f">Date<input type="date" name="date" id="rem-date" required></label>
      <label class="f">Time<input type="time" name="time" id="rem-time" required value="09:00"></label>
    </div>
    <div class="stack" style="gap:8px"><span class="eyebrow">Remind me by</span>
      <div class="row">
        <label class="check"><input type="checkbox" checked disabled>In the app</label>
        <label class="check"><input type="checkbox" name="via_email" value="1" checked>Email</label>
        <label class="check"><input type="checkbox" name="via_whatsapp" value="1" <?= $wa ? '' : 'disabled' ?>>WhatsApp<?= $wa ? '' : ' (not connected)' ?></label>
      </div></div>
    <div class="mfoot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit">Set reminder</button></div>
  </form>
</dialog>

<dialog class="dlg" id="nudge-dialog" aria-labelledby="nudge-title">
  <form method="post" action="" id="nudge-form">
    <?= csrf_field() ?>
    <h2 id="nudge-title">Send reminder</h2>
    <p class="muted" style="margin:0" id="nudge-to"></p>
    <div class="stack" style="gap:8px"><span class="eyebrow">Send by</span>
      <div class="row">
        <label class="check"><input type="checkbox" name="via_email" value="1" checked>Email</label>
        <label class="check"><input type="checkbox" name="via_whatsapp" value="1" <?= $wa ? 'checked' : 'disabled' ?>>WhatsApp<?= $wa ? '' : ' (not connected)' ?></label>
      </div></div>
    <label class="f">Message<textarea name="message" id="nudge-msg" rows="6" required maxlength="2000"></textarea></label>
    <div class="mfoot"><button type="button" class="btn" data-close>Cancel</button><button class="btn primary" type="submit"><?= icon('send', 15) ?>Send reminder</button></div>
  </form>
</dialog>
<dialog class="dlg" id="complete-dialog" aria-labelledby="complete-title">
  <form method="post" action="" id="complete-form">
    <?= csrf_field() ?>
    <input type="hidden" name="status" value="review">
    <h2 id="complete-title">Mark as complete?</h2>
    <p class="muted" style="margin:0"><b id="complete-item"></b></p>
    <p class="banner" style="margin:0" id="complete-help"></p>
    <label class="f">Note for the host <span class="hint">optional</span><textarea name="note" rows="3" maxlength="4000" placeholder="What you did, where to find it, anything they should check"></textarea></label>
    <div class="mfoot"><button type="button" class="btn" data-close>Not yet</button><button class="btn primary" type="submit"><?= icon('check', 15) ?>Yes, it’s complete</button></div>
  </form>
</dialog>

<dialog class="dlg" id="review-dialog" aria-labelledby="review-title">
  <form method="post" action="" id="review-form">
    <?= csrf_field() ?>
    <h2 id="review-title">Approve completed item?</h2>
    <p class="muted" style="margin:0"><b id="review-item"></b></p>
    <p class="muted small" style="margin:0" id="review-help"></p>
    <label class="f">Message <span class="hint">needed to send it back</span><textarea name="note" rows="3" maxlength="4000" placeholder="Thanks, or what still needs doing"></textarea></label>
    <div class="mfoot"><button type="button" class="btn" data-close>Cancel</button><button class="btn" type="submit" id="review-back" formaction="">Send back</button><button class="btn primary" type="submit"><?= icon('check', 15) ?>Approve</button></div>
  </form>
</dialog>
<script>window.FM = Object.assign(window.FM || {}, {me: <?= json_encode(explode(' ', user()['name'])[0]) ?>, base: <?= json_encode(path('')) ?>});</script>
