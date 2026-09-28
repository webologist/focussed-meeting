<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;

final class NotificationController
{
    public function index(): void
    {
        $items = DB::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100', [uid()]);
        echo view('dashboard/notifications', ['items' => $items, 'title' => 'Alerts']);
        DB::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [uid()]);
    }

    public function markRead(): void
    {
        DB::run('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL', [uid()]);
        back('notifications');
    }
}
