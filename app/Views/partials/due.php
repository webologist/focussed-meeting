<?php
// Expects $a (action row). Prints a short due-state label.
use App\Services\Actions;
if ($a['status'] === 'done') {
    if ($a['done_at']) echo '<span class="sub" style="display:block">Done ' . e(fmt_local($a['done_at'], 'j M')) . '</span>';
    return;
}
$d = days_between(Actions::effDeadline($a), today());
if ($d < 0) echo '<span class="late">Overdue by ' . e(plural(-$d, 'day')) . '</span>';
elseif ($d === 0) echo '<span class="sub" style="display:block">Due today</span>';
elseif ($d <= 7) echo '<span class="sub" style="display:block">In ' . e(plural($d, 'day')) . '</span>';
