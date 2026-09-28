<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Access;

final class ReminderController
{
    public function store(): void
    {
        $body = (string)input('body');
        $date = (string)input('date');
        $time = (string)input('time');
        $actionId = (int)input('action_id', 0);
        if ($body === '') { flash('error', 'Add a note for the reminder.'); back(); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) { flash('error', 'Pick a date and time.'); back(); }
        $at = local_to_utc($date, $time);
        if ($at <= now_utc()) { flash('error', 'Pick a time in the future.'); back(); }
        if ($actionId) Access::action($actionId);
        DB::insert('reminders', [
            'org_id' => org_id(), 'user_id' => uid(), 'action_id' => $actionId ?: null, 'body' => mb_substr($body, 0, 250),
            'remind_at' => $at, 'via_email' => empty($_POST['via_email']) ? 0 : 1, 'via_whatsapp' => empty($_POST['via_whatsapp']) ? 0 : 1, 'created_at' => now_utc(),
        ]);
        flash('success', 'Reminder set for ' . fmt_date($date, false) . ' at ' . fmt_time($time) . '.');
        back();
    }

    public function destroy(int $id): void
    {
        DB::run('DELETE FROM reminders WHERE id = ? AND user_id = ?', [$id, uid()]);
        flash('success', 'Reminder deleted.');
        back();
    }
}
