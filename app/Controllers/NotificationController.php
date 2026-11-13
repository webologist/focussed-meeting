<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\MeetingAlerts;

final class NotificationController
{
    public function index(): void
    {
        $items = DB::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100', [uid()]);
        echo view('dashboard/notifications', ['items' => $items, 'title' => 'Alerts']);
        DB::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [uid()]);
    }

    /** Asked by the browser every ~30 seconds: meeting alerts that just became due, plus the unread count for the bell. */
    public function poll(): void
    {
        header('Cache-Control: no-store');
        $alerts = MeetingAlerts::poll(uid(), org_id());
        json_response(['alerts' => $alerts, 'unread' => (int)DB::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [uid()])]);
    }

    public function markRead(): void
    {
        DB::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [uid()]);
        back('notifications');
    }
}
