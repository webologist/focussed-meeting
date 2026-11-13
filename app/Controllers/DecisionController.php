<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Access;
use App\Services\Meetings;

/** Decision log: every decision recorded in the minutes, across meetings the user can see. */
final class DecisionController
{
    public function index(): void
    {
        $q = (string)input('q', '');
        $project = (int)input('project', 0);
        $from = (string)input('from', '');
        $to = (string)input('to', '');
        $rows = Meetings::decisions(['q' => $q, 'project' => $project, 'from' => $from, 'to' => $to]);
        $projects = Access::projects(true);
        echo view('decisions/index', compact('rows', 'q', 'project', 'from', 'to', 'projects') + ['title' => 'Decision log']);
    }
}
