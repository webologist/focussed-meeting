<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;

final class PublicController
{
    public function home(): void
    {
        if (Auth::check()) redirect('dashboard');
        echo view('public/home', ['title' => 'Agenda, minutes and follow-through'], 'layouts/bare');
    }
}
