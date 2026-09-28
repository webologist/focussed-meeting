<div class="land">
  <nav class="land-nav">
    <a class="brand-inline" href="<?= e(path('')) ?>" style="color:inherit;text-decoration:none"><span class="brand-mark" style="color:var(--accent-ink)"><?= icon('logo', 18) ?></span><?= e(config('app.name')) ?></a>
    <div class="row"><a class="btn ghost" href="<?= e(path('login')) ?>">Sign in</a><?php if (config('app.allow_signups')): ?><a class="btn primary" href="<?= e(path('register')) ?>">Create free workspace</a><?php endif; ?></div>
  </nav>
  <section class="land-hero">
    <div class="stack" style="gap:18px">
      <div class="eyebrow">For teams that meet with purpose</div>
      <h1>Every meeting ends with owners, deadlines and a record.</h1>
      <p>Send a point-by-point agenda through your own email, Google Meet or Microsoft Teams. Capture minutes, assign action items and follow them through to done.</p>
      <div class="row"><?php if (config('app.allow_signups')): ?><a class="btn primary" href="<?= e(path('register')) ?>">Create your company workspace</a><?php endif; ?><a class="btn" href="<?= e(path('login')) ?>">Sign in</a></div>
    </div>
    <div class="land-mock" aria-hidden="true">
      <div class="panel-h"><h2>Q4 roadmap review</h2><span class="pill s-done">Minutes recorded</span></div>
      <div class="list">
        <div class="li"><span class="pill p-high"><span class="dot"></span>High</span><div><div class="title">Write one-page brief for each Q4 theme</div><div class="meta">Rahul Mehta · due 28 Sep</div></div><span class="pill s-pending">Waiting on 1</span></div>
        <div class="li"><span class="pill p-medium"><span class="dot"></span>Medium</span><div><div class="title">Scope read-only partner API</div><div class="meta">Anita Desai · due 24 Sep</div></div><span class="late">Overdue</span></div>
        <div class="li"><span class="pill p-low"><span class="dot"></span>Low</span><div><div class="title">Publish Senior PM job description</div><div class="meta">Sunil · due 30 Sep</div></div><span class="pill s-done">Done</span></div>
      </div>
    </div>
  </section>
  <section class="land-steps">
    <div class="land-step"><span class="n">01</span><h3>Plan the agenda</h3><p>Points with details, presenter, time and sub-points. Invite your team.</p></div>
    <div class="land-step"><span class="n">02</span><h3>Send the invite</h3><p>From your own email, with a Google Meet or Teams link created for you.</p></div>
    <div class="land-step"><span class="n">03</span><h3>Record the minutes</h3><p>After the meeting, note what was discussed and assign action items.</p></div>
    <div class="land-step"><span class="n">04</span><h3>Follow through</h3><p>Deadlines, dependencies, reminders, notes and photos until it’s done.</p></div>
  </section>
  <footer class="land-foot">&copy; <?= date('Y') ?> <?= e(config('app.name')) ?></footer>
</div>
