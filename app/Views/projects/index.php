<?php
$active = array_filter($projects, fn($p) => $p['status'] === 'active');
$archived = array_filter($projects, fn($p) => $p['status'] === 'archived');
$row = function (array $p): string {
    $canEdit = is_admin() || (int)$p['created_by'] === uid();
    $archivedP = $p['status'] === 'archived';
    ob_start(); ?>
    <tr class="item" style="<?= $archivedP ? 'opacity:.6' : '' ?>">
      <td style="min-width:220px"><div class="title"><?= e($p['name']) ?></div>
        <?php if ($p['description']): ?><div class="sub"><?= e($p['description']) ?></div><?php endif; ?>
        <div class="sub">Started by <?= e($p['creator_name'] ?? '—') ?> · <?= e(fmt_local($p['created_at'], 'j M Y')) ?></div></td>
      <td class="num mono"><a class="linkish" href="<?= e(path('meetings', ['project' => $p['id'], 'tab' => (int)$p['upcoming'] ? null : 'past'])) ?>"><?= (int)$p['meetings'] ?></a><?php if ($p['next_meeting']): ?><div class="sub">next <?= e(fmt_date($p['next_meeting'], false)) ?></div><?php endif; ?></td>
      <td class="num mono"><a class="linkish" href="<?= e(path('tracker', ['project' => $p['id']])) ?>"><?= (int)$p['open_tasks'] ?></a></td>
      <td class="num mono"><a class="linkish" href="<?= e(path('tracker', ['project' => $p['id'], 'q' => 'done'])) ?>"><?= (int)$p['done_tasks'] ?></a></td>
      <td><div class="row" style="gap:6px;justify-content:flex-end;flex-wrap:nowrap">
        <?php if (!$archivedP): ?><a class="btn sm" href="<?= e(path('meetings', ['plan' => 1, 'project_id' => $p['id']])) ?>"><?= icon('cal', 14) ?>Plan meeting</a><?php endif; ?>
        <?php if ($canEdit): ?>
          <details class="menu"><summary class="btn sm ghost" aria-label="Edit <?= e($p['name']) ?>">Edit</summary>
            <form method="post" action="<?= e(path('projects/' . $p['id'])) ?>" class="menu-pop stack"><?= csrf_field() ?>
              <label class="f">Name<input type="text" name="name" value="<?= e($p['name']) ?>" required maxlength="120"></label>
              <label class="f">Description<input type="text" name="description" value="<?= e($p['description']) ?>" maxlength="500"></label>
              <div class="row"><button class="btn sm primary" type="submit">Save</button></div>
            </form></details>
          <form method="post" action="<?= e(path('projects/' . $p['id'])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $archivedP ? 'active' : 'archived' ?>">
            <button class="btn sm ghost" type="submit" <?= $archivedP ? '' : 'data-confirm="Archive this project? Its meetings and tasks stay as they are."' ?>><?= $archivedP ? 'Restore' : 'Archive' ?></button></form>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php return (string)ob_get_clean();
};
?>
<div class="head"><div><div class="eyebrow">Organise</div><h1>Projects</h1><p class="muted">Group meetings and tasks by project. Pick a project when you plan a meeting; its action items follow the project.</p></div></div>

<form class="panel" method="post" action="<?= e(path('projects')) ?>"><?= csrf_field() ?>
  <div class="qa-bar proj-bar" style="border-radius:12px;border-bottom:0">
    <input class="qa-text" type="text" name="name" required maxlength="120" placeholder="New project name, e.g. Mobile App v2" aria-label="Project name">
    <input type="text" name="description" maxlength="500" placeholder="Short description (optional)" aria-label="Description">
    <button class="btn primary" type="submit"><?= icon('plus', 14) ?>Create project</button>
  </div></form>

<div class="panel">
  <div class="panel-h"><h2>Active projects</h2><span class="faint small"><?= plural(count($active), 'project') ?></span></div>
  <?php if (!$active): ?><div class="empty">No projects yet. Create one above, or when you plan a meeting.</div><?php else: ?>
  <div class="tbl-wrap"><table class="compact" style="min-width:720px"><thead><tr><th>Project</th><th class="num">Meetings</th><th class="num">Open tasks</th><th class="num">Done</th><th></th></tr></thead>
    <tbody><?php foreach ($active as $p) echo $row($p); ?></tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($archived): ?>
<div class="panel">
  <div class="panel-h"><h2>Archived</h2><span class="faint small"><?= plural(count($archived), 'project') ?></span></div>
  <div class="tbl-wrap"><table class="compact" style="min-width:720px"><thead><tr><th>Project</th><th class="num">Meetings</th><th class="num">Open tasks</th><th class="num">Done</th><th></th></tr></thead>
    <tbody><?php foreach ($archived as $p) echo $row($p); ?></tbody></table></div>
</div>
<?php endif; ?>
