<?php
// Loads demo data (two companies with people, projects, meetings, agendas, agenda requests, action items
// in every status, comments, reminders and notifications) so the app has something to show. For local development only.
//   php database/seed.php          adds the demo companies if they are not there yet
//   php database/seed.php --fresh  deletes the demo companies first, then adds them again
//   php database/seed.php --remove deletes the demo companies and everything in them
// Dates are relative to today, so re-running with --fresh keeps "overdue" and "upcoming" meaningful.
// Every demo user signs in with the password below. Emails use the reserved .test domain,
// and nothing is written to the outbox, so no real messages are ever sent.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

const DEMO_PASSWORD = 'Admin@123';
const DEMO_SLUGS = ['acme-demo', 'globex-demo'];

if (config('app.env') !== 'development' && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "app.env is not 'development'. Demo data is meant for local use; add --force to load it anyway.\n");
    exit(1);
}

// ---------------------------------------------------------------- helpers
mt_srand(42);
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata'));
$day = fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
/** UTC timestamp $offset days from today at local HH:MM (Asia/Kolkata). */
$at = fn(int $offset, string $hm = '10:00'): string => local_to_utc($day($offset), $hm, 'Asia/Kolkata');
$hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

function wipe_demo(): void
{
    [$in, $p] = DB::in(DEMO_SLUGS);
    $orgIds = array_column(DB::all("SELECT id FROM organizations WHERE slug IN $in", $p), 'id');
    if (!$orgIds) return;
    [$oin, $op] = DB::in($orgIds, 'o');
    DB::pdo()->exec('SET foreign_key_checks = 0');
    try {
        $userIds = array_column(DB::all("SELECT id FROM users WHERE org_id IN $oin", $op), 'id');
        $meetingIds = array_column(DB::all("SELECT id FROM meetings WHERE org_id IN $oin", $op), 'id');
        $actionIds = array_column(DB::all("SELECT id FROM actions WHERE org_id IN $oin", $op), 'id');
        [$uin, $up] = DB::in($userIds, 'u');
        [$min, $mp] = DB::in($meetingIds, 'm');
        [$ain, $ap] = DB::in($actionIds, 'a');
        DB::run("DELETE FROM note_images WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM action_notes WHERE action_id IN $ain", $ap);
        DB::run("DELETE FROM action_comments WHERE action_id IN $ain", $ap);
        DB::run("DELETE FROM action_dependencies WHERE action_id IN $ain", $ap);
        DB::run("DELETE FROM reminders WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM actions WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM agenda_items WHERE meeting_id IN $min", $mp);
        DB::run("DELETE FROM meeting_invitees WHERE meeting_id IN $min", $mp);
        DB::run("DELETE FROM meetings WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM notifications WHERE user_id IN $uin", $up);
        DB::run("DELETE FROM auth_tokens WHERE user_id IN $uin", $up);
        DB::run("DELETE FROM user_settings WHERE user_id IN $uin", $up);
        DB::run("DELETE FROM users WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM integrations WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM oauth_clients WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM projects WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM audit_log WHERE org_id IN $oin", $op);
        DB::run("DELETE FROM organizations WHERE id IN $oin", $op);
    } finally {
        DB::pdo()->exec('SET foreign_key_checks = 1');
    }
    echo "Removed the existing demo companies.\n";
}

if (in_array('--remove', $argv, true)) {
    wipe_demo();
    echo "Demo data removed. Your own companies were not touched.\n";
    exit(0);
}
if (in_array('--fresh', $argv, true)) wipe_demo();

[$in, $p] = DB::in(DEMO_SLUGS);
if (DB::value("SELECT COUNT(*) FROM organizations WHERE slug IN $in", $p)) {
    echo "Demo data is already loaded. Run with --fresh to reload it.\n";
    exit(0);
}
foreach (['demo.admin@acme.test', 'demo.admin@globex.test'] as $email) {
    if (DB::value('SELECT 1 FROM users WHERE email = ?', [$email])) { fwrite(STDERR, "$email is already taken by a non-demo company.\n"); exit(1); }
}

// ---------------------------------------------------------------- builders
function add_user(int $orgId, array $u, string $hash, string $createdAt): int
{
    $invited = ($u['status'] ?? 'active') === 'invited';
    $id = DB::insert('users', [
        'org_id' => $orgId, 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'] ?? null, 'title' => $u['title'] ?? null,
        'password_hash' => $invited ? null : $hash, 'role' => $u['role'] ?? 'participant', 'status' => $u['status'] ?? 'active',
        'email_verified_at' => $invited ? null : $createdAt, 'last_login_at' => $invited ? null : $createdAt,
        'invited_by' => $u['invited_by'] ?? null, 'created_at' => $createdAt,
    ]);
    DB::insert('user_settings', ['user_id' => $id] + ($u['settings'] ?? []));
    return $id;
}

/**
 * $m: title, offset (days from today), time, duration, platform, organizer, invitees (user ids), project, personal, note,
 *     status, agenda: [[title, presenter, minutes, subpoints[], discussion|null], ...],
 *     requests: [[title, requestedBy, status, reviewNote|null, discussion|null], ...]  (points attendees asked to add)
 * Returns [meetingId, agendaIds[]].
 */
function add_meeting(int $orgId, array $m, callable $day, callable $at): array
{
    $past = $m['offset'] < 0;
    $status = $m['status'] ?? ($past ? 'completed' : 'scheduled');
    $platform = $m['platform'] ?? 'meet';
    $startsUtc = local_to_utc($day($m['offset']), $m['time'], 'Asia/Kolkata');
    $endsUtc = gmdate('Y-m-d H:i:s', strtotime($startsUtc . ' UTC') + ($m['duration'] ?? 30) * 60);
    $id = DB::insert('meetings', [
        'org_id' => $orgId, 'project_id' => $m['project'] ?? null, 'organizer_id' => $m['organizer'], 'title' => $m['title'],
        'meeting_date' => $day($m['offset']), 'start_time' => $m['time'], 'duration_min' => $m['duration'] ?? 30,
        'timezone' => 'Asia/Kolkata', 'starts_at_utc' => $startsUtc, 'platform' => $platform,
        'location' => $platform === 'inperson' ? ($m['location'] ?? 'Conference room A') : null,
        'join_url' => match ($platform) {
            'meet' => 'https://meet.google.com/demo-' . substr(md5($m['title']), 0, 3) . '-' . substr(md5($m['title']), 3, 4),
            'teams' => 'https://teams.microsoft.com/l/meetup-join/demo-' . substr(md5($m['title']), 0, 12),
            default => null,
        },
        'status' => $status, 'sent_email' => (int)!($m['personal_only'] ?? false), 'sent_whatsapp' => (int)($m['whatsapp'] ?? 0),
        'sent_personal' => (int)(($m['personal'] ?? false) || ($m['personal_only'] ?? false)), 'personal_note' => $m['note'] ?? null,
        'invite_sent_at' => $at($m['offset'] - 3, '11:30'),
        'mom_sent_at' => $status === 'completed' ? $endsUtc : null,
        'completed_at' => $status === 'completed' ? $endsUtc : null,
        'created_at' => $at(min($m['offset'], 0) - 3, '11:25'),
        'updated_at' => $status === 'completed' ? $endsUtc : null,
    ]);
    foreach (array_unique(array_merge([$m['organizer']], $m['invitees'])) as $i => $uid) {
        $rsvp = $status === 'scheduled' ? ['yes', 'yes', 'pending', 'maybe', 'yes', 'no'][$i % 6] : 'yes';
        if ($uid === $m['organizer']) $rsvp = 'yes';
        DB::insert('meeting_invitees', ['meeting_id' => $id, 'user_id' => $uid, 'attended' => (int)($status !== 'completed' || $i % 7 !== 5), 'rsvp' => $rsvp]);
    }
    $agendaIds = [];
    foreach ($m['agenda'] as $pos => $a) {
        $agendaIds[] = DB::insert('agenda_items', [
            'meeting_id' => $id, 'position' => $pos, 'title' => $a[0], 'details' => null, 'presenter_id' => $a[1],
            'minutes_allotted' => $a[2], 'subpoints' => json_encode($a[3]),
            'discussion' => $status === 'completed' ? ($a[4] ?? null) : null,
            'added_in_meeting' => (int)($a[5] ?? 0),
            'updated_by' => $status === 'completed' ? $m['organizer'] : null,
            'updated_at' => $status === 'completed' ? $endsUtc : null,
        ]);
    }
    foreach ($m['requests'] ?? [] as $n => [$title, $by, $st, $reviewNote, $discussion]) {
        $reviewed = $st !== 'pending';
        DB::insert('agenda_items', [
            'meeting_id' => $id, 'position' => count($agendaIds) + $n, 'title' => $title, 'presenter_id' => $by, 'subpoints' => '[]',
            'discussion' => $status === 'completed' && $st === 'approved' ? $discussion : null,
            'added_in_meeting' => 0, 'request_status' => $st, 'requested_by' => $by, 'review_note' => $reviewNote,
            'reviewed_by' => $reviewed ? $m['organizer'] : null, 'reviewed_at' => $reviewed ? $at(min($m['offset'], 0) - 1, '16:00') : null,
            'updated_by' => $by, 'updated_at' => $at(min($m['offset'], 0) - 2, '12:30'),
        ]);
    }
    return [$id, $agendaIds];
}

/**
 * $a: title, details, priority, owner, by, deadline (offset), new_deadline (offset|null), done (offset|null),
 *     status ('progress' | 'review'), submitted (offset), meeting, agenda, project, created (offset)
 */
function add_action(int $orgId, array $a, callable $day, callable $at): int
{
    $done = $a['done'] ?? null;
    $status = $done !== null ? 'done' : ($a['status'] ?? 'open');
    $submitted = $status === 'review' ? $at($a['submitted'] ?? -1, '16:40') : ($done !== null ? $at($done, '17:10') : null);
    return DB::insert('actions', [
        'org_id' => $orgId, 'project_id' => $a['project'] ?? null, 'meeting_id' => $a['meeting'] ?? null, 'agenda_item_id' => $a['agenda'] ?? null,
        'title' => $a['title'], 'details' => $a['details'] ?? null, 'priority' => $a['priority'] ?? 'medium',
        'owner_id' => $a['owner'], 'created_by' => $a['by'], 'deadline' => $day($a['deadline']),
        'new_deadline' => isset($a['new_deadline']) ? $day($a['new_deadline']) : null,
        'status' => $status, 'submitted_at' => $submitted, 'approved_by' => $done !== null ? $a['by'] : null,
        'done_at' => $done === null ? null : $at($done, '17:10'),
        'created_at' => $at($a['created'], '12:00'), 'updated_at' => $done === null ? null : $at($done, '17:10'),
    ]);
}

function comment(int $actionId, int $userId, string $body, string $at, bool $system = false): void
{
    DB::insert('action_comments', ['action_id' => $actionId, 'user_id' => $userId, 'body' => $body, 'is_system' => (int)$system, 'created_at' => $at]);
}

// ================================================================ Acme (main demo company)
DB::transaction(function () use ($hash, $day, $at) {
    $orgId = DB::insert('organizations', [
        'name' => 'Acme Industries (Demo)', 'slug' => 'acme-demo', 'timezone' => 'Asia/Kolkata', 'plan' => 'pro',
        'seats_limit' => 25, 'status' => 'active', 'created_at' => $at(-60, '09:00'),
    ]);
    $joined = $at(-60, '09:05');
    $admin = add_user($orgId, ['name' => 'Priya Sharma', 'email' => 'demo.admin@acme.test', 'title' => 'Head of Operations', 'phone' => '+919800000001', 'role' => 'admin',
        'settings' => ['signature' => "Regards,\nPriya Sharma\nHead of Operations, Acme Industries"]], $hash, $joined);
    $people = [
        ['Rahul Verma', 'rahul@acme.test', 'Engineering Manager', 'admin'],
        ['Ananya Iyer', 'ananya@acme.test', 'Product Manager', 'participant'],
        ['Vikram Singh', 'vikram@acme.test', 'Sales Lead', 'participant'],
        ['Meera Nair', 'meera@acme.test', 'Finance Controller', 'participant'],
        ['Arjun Patel', 'arjun@acme.test', 'Senior Developer', 'participant'],
        ['Sneha Reddy', 'sneha@acme.test', 'Marketing Manager', 'participant'],
        ['Karan Mehta', 'karan@acme.test', 'Customer Success', 'participant'],
    ];
    $u = [];
    foreach ($people as $i => [$name, $email, $title, $role]) {
        $u[] = add_user($orgId, ['name' => $name, 'email' => $email, 'title' => $title, 'role' => $role, 'phone' => '+9198000000' . sprintf('%02d', $i + 2), 'invited_by' => $admin], $hash, $at(-58 + $i, '10:00'));
    }
    [$rahul, $ananya, $vikram, $meera, $arjun, $sneha, $karan] = $u;
    $divya = add_user($orgId, ['name' => 'Divya Kapoor', 'email' => 'divya@acme.test', 'title' => 'HR Business Partner', 'status' => 'invited', 'invited_by' => $admin], $hash, $at(-2, '15:00'));
    DB::insert('auth_tokens', ['user_id' => $divya, 'type' => 'invite', 'token_hash' => hash('sha256', random_bytes(32)), 'expires_at' => $at(5), 'created_at' => $at(-2, '15:00')]);
    $everyone = array_merge([$admin], $u);

    $proj = fn(string $name, string $desc, int $by, int $off) => DB::insert('projects', ['org_id' => $orgId, 'name' => $name, 'description' => $desc, 'created_by' => $by, 'created_at' => $at($off, '10:30')]);
    $pApp = $proj('Mobile App v2', 'Redesigned mobile app, launching this quarter', $ananya, -35);
    $pGlobex = $proj('Globex Account', 'Renewal and recovery plan for our largest customer', $vikram, -25);
    $pFin = $proj('Finance & Operations', 'Budgets, cost control and compliance', $meera, -50);
    $pOld = $proj('Office Move 2025', 'Completed', $admin, -58);
    DB::update('projects', ['status' => 'archived'], ['id' => $pOld]);

    // ---- past meetings (completed, with minutes)
    [$m1, $a1] = add_meeting($orgId, ['title' => 'Q3 Planning Kick-off', 'offset' => -42, 'time' => '10:00', 'duration' => 60, 'organizer' => $admin, 'invitees' => $everyone, 'agenda' => [
        ['Review Q2 results', $meera, 15, ['Revenue vs target', 'Cost overruns'], 'Revenue closed at 104% of target. Marketing spend ran 8% over budget; agreed to tighten approvals.'],
        ['Q3 goals and OKRs', $admin, 25, ['Company OKRs', 'Team-level goals'], 'Three company OKRs agreed: launch v2 mobile app, grow ARR 20%, cut support backlog by half.'],
        ['Hiring plan', $rahul, 15, ['Two backend engineers', 'One designer'], 'Approved two backend roles now; designer role deferred to Q4.'],
    ]], $day, $at);
    [$m2, $a2] = add_meeting($orgId, ['title' => 'Mobile App v2 – Design Review', 'project' => $pApp, 'offset' => -30, 'time' => '15:00', 'duration' => 45, 'platform' => 'teams', 'organizer' => $ananya, 'invitees' => [$rahul, $arjun, $sneha, $admin], 'agenda' => [
        ['Onboarding flow walkthrough', $ananya, 15, ['Sign-up in 3 steps', 'Social login'], 'Team liked the 3-step flow. Social login limited to Google for launch.'],
        ['Navigation redesign', $arjun, 15, ['Bottom tab bar', 'Offline states'], 'Bottom tabs approved. Need explicit offline and empty states before build.'],
        ['Brand refresh assets', $sneha, 10, [], 'New icon set ready; illustrations due next week.'],
    ]], $day, $at);
    [$m3, $a3] = add_meeting($orgId, ['title' => 'Weekly Sales Pipeline Review', 'project' => $pGlobex, 'offset' => -21, 'time' => '11:00', 'duration' => 30, 'organizer' => $vikram, 'invitees' => [$admin, $karan, $meera], 'agenda' => [
        ['Top 10 deals', $vikram, 15, ['Stage changes', 'Risks'], 'Two enterprise deals moved to negotiation. Globex renewal at risk over pricing.'],
        ['Pricing exceptions', $meera, 10, [], 'Max 15% discount without CFO sign-off.'],
    ]], $day, $at);
    [$m4, $a4] = add_meeting($orgId, ['title' => 'Customer Escalation – Globex Renewal', 'project' => $pGlobex, 'offset' => -16, 'time' => '16:30', 'duration' => 30, 'platform' => 'inperson', 'location' => 'Board room, 4th floor', 'organizer' => $admin, 'invitees' => [$vikram, $karan, $rahul], 'agenda' => [
        ['What went wrong', $karan, 10, ['Three P1 tickets in August', 'Slow response times'], 'Root cause: on-call gap during a release week. Customer wants an SLA credit.'],
        ['Recovery plan', $admin, 15, ['SLA credit', 'Dedicated CSM'], 'Offer one month credit and a named CSM. Rahul to share incident report.'],
    ]], $day, $at);
    [$m5, $a5] = add_meeting($orgId, ['title' => 'Monthly Finance Review', 'project' => $pFin, 'offset' => -9, 'time' => '14:00', 'duration' => 45, 'organizer' => $meera, 'invitees' => [$admin, $rahul, $vikram], 'agenda' => [
        ['Burn rate and runway', $meera, 15, [], 'Runway at 19 months. Cloud costs up 12% month-on-month.'],
        ['Cloud cost optimisation', $rahul, 15, ['Reserved instances', 'Unused environments'], 'Estimated 20% saving by moving to reserved instances and shutting idle staging stacks.'],
        ['Vendor contracts', $meera, 10, [], 'Two contracts up for renewal; renegotiate the analytics tool.', 1],
    ], 'requests' => [
        ['Q4 sales incentive budget', $vikram, 'approved', 'Good idea, let’s cover it after vendor contracts.', 'Agreed a Q4 incentive pool of ₹6 lakh, tied to net-new ARR. Vikram to share the plan.'],
        ['Team offsite budget', $rahul, 'declined', 'Let’s take this to the Q4 planning kick-off, where we set next quarter’s budgets.', null],
    ]], $day, $at);
    [$m6, $a6] = add_meeting($orgId, ['title' => 'Sprint 14 Retrospective', 'project' => $pApp, 'offset' => -3, 'time' => '17:00', 'duration' => 30, 'organizer' => $rahul, 'invitees' => [$arjun, $ananya], 'agenda' => [
        ['What went well', $rahul, 10, [], 'Release shipped on time; pairing on the API helped.'],
        ['What to improve', $arjun, 10, ['Flaky tests', 'Late design changes'], 'Freeze designs two days before sprint start. Quarantine flaky tests.'],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Marketing Campaign Brainstorm', 'offset' => -7, 'time' => '12:00', 'duration' => 30, 'status' => 'cancelled', 'organizer' => $sneha, 'invitees' => [$vikram, $ananya], 'agenda' => [
        ['Festive season campaign ideas', $sneha, 20, [], null],
    ]], $day, $at);

    // ---- upcoming meetings
    add_meeting($orgId, ['title' => 'Daily Stand-up', 'project' => $pApp, 'offset' => 0, 'time' => '09:30', 'duration' => 15, 'organizer' => $rahul, 'invitees' => [$arjun, $ananya], 'agenda' => [
        ['Yesterday / today / blockers', $rahul, 15, [], null],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Weekly Sales Pipeline Review', 'project' => $pGlobex, 'offset' => 1, 'time' => '11:00', 'duration' => 30, 'organizer' => $vikram, 'invitees' => [$admin, $karan, $meera], 'whatsapp' => 1, 'agenda' => [
        ['Top 10 deals', $vikram, 15, ['Stage changes', 'Risks'], null],
        ['Globex renewal status', $karan, 10, [], null],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Mobile App v2 – Launch Readiness', 'project' => $pApp, 'offset' => 4, 'time' => '15:00', 'duration' => 60, 'platform' => 'teams', 'organizer' => $ananya, 'invitees' => [$admin, $rahul, $arjun, $sneha, $karan],
        'personal' => true, 'note' => 'This is our go / no-go call for the launch. Please come with your area’s status and any blockers.', 'requests' => [
            ['Support staffing for launch week', $karan, 'pending', null, null],
            ['App store screenshots and listing copy', $sneha, 'approved', 'Yes, add it after launch communications. 5 minutes.', null],
        ], 'agenda' => [
        ['QA sign-off', $arjun, 15, ['Open bugs', 'Performance numbers'], null],
        ['Launch communications', $sneha, 15, ['Press note', 'In-app announcement', 'Email to customers'], null],
        ['Support readiness', $karan, 15, ['Help articles', 'Escalation path'], null],
        ['Go / no-go', $admin, 10, [], null],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Quarterly Board Prep', 'project' => $pFin, 'offset' => 9, 'time' => '10:30', 'duration' => 90, 'platform' => 'inperson', 'location' => 'Board room, 4th floor', 'organizer' => $admin, 'invitees' => [$meera, $rahul, $vikram, $ananya],
        'personal_only' => true, 'note' => 'Board materials are confidential, so I’m sharing this invite in person. Please keep the date to yourself for now.', 'agenda' => [
        ['Financial summary', $meera, 20, [], null],
        ['Product roadmap', $ananya, 20, [], null],
        ['Sales outlook', $vikram, 20, [], null],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Q4 Planning Kick-off', 'offset' => 14, 'time' => '10:00', 'duration' => 60, 'organizer' => $admin, 'invitees' => $everyone,
        'requests' => [['Q4 hiring plan and budget', $rahul, 'pending', null, null], ['Team offsite budget', $arjun, 'pending', null, null]], 'agenda' => [
        ['Q3 retrospective', $admin, 20, [], null],
        ['Q4 goals', $admin, 30, [], null],
    ]], $day, $at);

    // ---- action items from meetings, plus standalone tasks
    $acts = [];
    $acts['okr'] = add_action($orgId, ['title' => 'Publish Q3 OKRs to the whole company', 'priority' => 'high', 'owner' => $admin, 'by' => $admin, 'deadline' => -38, 'done' => -39, 'meeting' => $m1, 'agenda' => $a1[1], 'created' => -42], $day, $at);
    $acts['jd'] = add_action($orgId, ['title' => 'Post job descriptions for two backend engineers', 'details' => 'Use the updated JD template. Post on LinkedIn and Naukri.', 'owner' => $rahul, 'by' => $admin, 'deadline' => -35, 'done' => -33, 'meeting' => $m1, 'agenda' => $a1[2], 'created' => -42], $day, $at);
    $acts['budget'] = add_action($orgId, ['project' => $pFin, 'title' => 'Set up approval workflow for marketing spend above ₹50,000', 'owner' => $meera, 'by' => $admin, 'deadline' => -28, 'new_deadline' => -14, 'done' => -15, 'meeting' => $m1, 'agenda' => $a1[0], 'created' => -42], $day, $at);
    $acts['offline'] = add_action($orgId, ['project' => $pApp, 'title' => 'Design offline and empty states for the app', 'priority' => 'high', 'owner' => $ananya, 'by' => $ananya, 'deadline' => -20, 'done' => -22, 'meeting' => $m2, 'agenda' => $a2[1], 'created' => -30], $day, $at);
    $acts['tabs'] = add_action($orgId, ['status' => 'progress', 'project' => $pApp, 'title' => 'Build bottom tab navigation', 'details' => 'Follow the approved Figma file. Include badge counts on the Tasks tab.', 'priority' => 'high', 'owner' => $arjun, 'by' => $ananya, 'deadline' => -6, 'new_deadline' => 2, 'meeting' => $m2, 'agenda' => $a2[1], 'created' => -30], $day, $at);
    $acts['illus'] = add_action($orgId, ['project' => $pApp, 'title' => 'Deliver onboarding illustrations', 'priority' => 'medium', 'owner' => $sneha, 'by' => $ananya, 'deadline' => -23, 'done' => -24, 'meeting' => $m2, 'agenda' => $a2[2], 'created' => -30], $day, $at);
    $acts['google'] = add_action($orgId, ['status' => 'progress', 'project' => $pApp, 'title' => 'Integrate Google sign-in', 'owner' => $arjun, 'by' => $ananya, 'deadline' => -4, 'meeting' => $m2, 'agenda' => $a2[0], 'created' => -30], $day, $at);
    $acts['globexprop'] = add_action($orgId, ['project' => $pGlobex, 'title' => 'Send revised pricing proposal to Globex', 'priority' => 'high', 'owner' => $vikram, 'by' => $vikram, 'deadline' => -17, 'done' => -18, 'meeting' => $m3, 'agenda' => $a3[0], 'created' => -21], $day, $at);
    $acts['discount'] = add_action($orgId, ['status' => 'review', 'submitted' => -1, 'project' => $pGlobex, 'title' => 'Document the discount approval policy', 'priority' => 'low', 'owner' => $meera, 'by' => $vikram, 'deadline' => -2, 'meeting' => $m3, 'agenda' => $a3[1], 'created' => -21], $day, $at);
    $acts['incident'] = add_action($orgId, ['project' => $pGlobex, 'title' => 'Share August incident report with Globex', 'priority' => 'high', 'owner' => $rahul, 'by' => $admin, 'deadline' => -13, 'done' => -12, 'meeting' => $m4, 'agenda' => $a4[1], 'created' => -16], $day, $at);
    $acts['csm'] = add_action($orgId, ['project' => $pGlobex, 'title' => 'Assign a named CSM and schedule weekly check-ins with Globex', 'owner' => $karan, 'by' => $admin, 'deadline' => -10, 'done' => -11, 'meeting' => $m4, 'agenda' => $a4[1], 'created' => -16], $day, $at);
    $acts['credit'] = add_action($orgId, ['project' => $pGlobex, 'title' => 'Issue one-month SLA credit to Globex', 'owner' => $meera, 'by' => $admin, 'deadline' => -8, 'meeting' => $m4, 'agenda' => $a4[1], 'created' => -16], $day, $at);
    $acts['oncall'] = add_action($orgId, ['status' => 'progress', 'project' => $pGlobex, 'title' => 'Fix on-call rota gaps during release weeks', 'priority' => 'high', 'owner' => $rahul, 'by' => $admin, 'deadline' => 3, 'meeting' => $m4, 'agenda' => $a4[0], 'created' => -16], $day, $at);
    $acts['ri'] = add_action($orgId, ['status' => 'progress', 'project' => $pFin, 'title' => 'Move production servers to reserved instances', 'priority' => 'medium', 'owner' => $rahul, 'by' => $meera, 'deadline' => 12, 'meeting' => $m5, 'agenda' => $a5[1], 'created' => -9], $day, $at);
    $acts['staging'] = add_action($orgId, ['project' => $pFin, 'title' => 'Shut down idle staging environments', 'priority' => 'low', 'owner' => $arjun, 'by' => $meera, 'deadline' => -1, 'done' => -2, 'meeting' => $m5, 'agenda' => $a5[1], 'created' => -9], $day, $at);
    $acts['vendor'] = add_action($orgId, ['project' => $pFin, 'title' => 'Renegotiate the analytics tool contract', 'owner' => $meera, 'by' => $meera, 'deadline' => 6, 'meeting' => $m5, 'agenda' => $a5[2], 'created' => -9], $day, $at);
    $acts['flaky'] = add_action($orgId, ['status' => 'review', 'submitted' => 0, 'project' => $pApp, 'title' => 'Quarantine flaky tests in CI', 'owner' => $arjun, 'by' => $rahul, 'deadline' => 1, 'meeting' => $m6, 'agenda' => $a6[1], 'created' => -3], $day, $at);
    $acts['freeze'] = add_action($orgId, ['status' => 'review', 'submitted' => -1, 'project' => $pApp, 'title' => 'Add a design-freeze step to the sprint calendar', 'priority' => 'low', 'owner' => $ananya, 'by' => $rahul, 'deadline' => 5, 'meeting' => $m6, 'agenda' => $a6[1], 'created' => -3], $day, $at);
    // standalone tasks (no meeting)
    $acts['press'] = add_action($orgId, ['project' => $pApp, 'title' => 'Draft press note for the app launch', 'priority' => 'medium', 'owner' => $sneha, 'by' => $admin, 'deadline' => 3, 'created' => -5], $day, $at);
    $acts['help'] = add_action($orgId, ['status' => 'progress', 'project' => $pApp, 'title' => 'Write help-centre articles for v2', 'details' => 'At least: getting started, notifications, offline mode, account deletion.', 'owner' => $karan, 'by' => $ananya, 'deadline' => 4, 'created' => -6], $day, $at);
    $acts['gst'] = add_action($orgId, ['project' => $pFin, 'title' => 'File quarterly GST return', 'priority' => 'high', 'owner' => $meera, 'by' => $meera, 'deadline' => 0, 'created' => -10], $day, $at);
    $acts['crm'] = add_action($orgId, ['title' => 'Clean up duplicate leads in the CRM', 'priority' => 'low', 'owner' => $vikram, 'by' => $vikram, 'deadline' => -5, 'created' => -14], $day, $at);
    $acts['onboard'] = add_action($orgId, ['status' => 'review', 'submitted' => -1, 'title' => 'Prepare onboarding kit for new engineers', 'owner' => $rahul, 'by' => $admin, 'deadline' => 10, 'created' => -4], $day, $at);
    $acts['deck'] = add_action($orgId, ['project' => $pFin, 'title' => 'Prepare board deck', 'priority' => 'high', 'owner' => $admin, 'by' => $admin, 'deadline' => 8, 'created' => -2], $day, $at);

    // dependencies
    foreach ([['tabs', 'offline'], ['press', 'tabs'], ['help', 'tabs'], ['deck', 'vendor'], ['ri', 'staging']] as [$a, $b]) {
        DB::insert('action_dependencies', ['action_id' => $acts[$a], 'depends_on_id' => $acts[$b]]);
    }

    // comments and history
    comment($acts['budget'], $meera, 'Deadline moved from ' . $day(-28) . ' to ' . $day(-14) . '.', $at(-27, '10:00'), true);
    comment($acts['budget'], $meera, 'Waiting on the new finance tool licence before I can build the workflow.', $at(-27, '10:02'));
    comment($acts['budget'], $admin, 'Fine, but please keep it before the month-end close.', $at(-27, '12:40'));
    comment($acts['budget'], $meera, 'Marked as done.', $at(-15, '17:10'), true);
    comment($acts['tabs'], $arjun, 'Deadline moved from ' . $day(-6) . ' to ' . $day(2) . '.', $at(-7, '18:05'), true);
    comment($acts['tabs'], $arjun, 'Badge counts need a new API endpoint. Adding it this week.', $at(-7, '18:06'));
    comment($acts['tabs'], $ananya, 'Thanks. Can you share a TestFlight build once the tabs are in?', $at(-6, '10:15'));
    comment($acts['google'], $ananya, '@Arjun this is now overdue. Anything blocking?', $at(-2, '11:00'));
    comment($acts['google'], $arjun, 'Waiting on the OAuth consent screen verification from Google. Should clear in 2–3 days.', $at(-2, '14:30'));
    comment($acts['credit'], $admin, 'Globex is asking about this on every call. Please prioritise.', $at(-5, '16:00'));
    comment($acts['globexprop'], $vikram, 'Sent with a 12% discount, inside the policy limit.', $at(-18, '17:15'));
    comment($acts['globexprop'], $vikram, 'Marked as done.', $at(-18, '17:15'), true);
    comment($acts['oncall'], $rahul, 'Draft rota shared in the engineering channel. Collecting feedback.', $at(-4, '12:00'));
    comment($acts['gst'], $meera, 'All invoices reconciled. Filing this evening.', $at(0, '09:15'));
    comment($acts['flaky'], $arjun, 'Marked complete. Waiting for approval', $at(0, '16:40'), true);
    comment($acts['flaky'], $arjun, 'Moved 7 flaky tests to a quarantine suite. CI has been green for 20 runs.', $at(0, '16:41'));
    comment($acts['onboard'], $rahul, 'Marked complete. Waiting for approval', $at(-1, '16:40'), true);
    comment($acts['onboard'], $rahul, 'Kit is in the shared drive: laptop setup, repo access, first-week plan and buddy list.', $at(-1, '16:41'));
    comment($acts['discount'], $meera, 'Marked complete. Waiting for approval', $at(-1, '16:40'), true);
    comment($acts['freeze'], $ananya, 'Marked complete. Waiting for approval', $at(-1, '16:40'), true);
    comment($acts['tabs'], $arjun, 'Moved to in progress', $at(-5, '10:00'), true);

    // notes (only by the owner)
    foreach ([
        [$acts['incident'], $rahul, "Timeline:\n- 12 Aug: DB failover took 40 min\n- 19 Aug: queue backlog after release\n- 26 Aug: API rate limits misconfigured\nAll three now have regression alerts.", -12],
        [$acts['staging'], $arjun, 'Deleted 4 staging stacks and 2 unused databases. Saves roughly $1,100/month.', -2],
        [$acts['oncall'], $rahul, 'Proposal: two engineers on call during release weeks, with a 30-minute response target.', -4],
    ] as [$aid, $uid, $body, $off]) {
        DB::insert('action_notes', ['action_id' => $aid, 'user_id' => $uid, 'body' => $body, 'created_at' => $at($off, '16:00')]);
    }

    // personal reminders (some already fired)
    foreach ([
        [$admin, $acts['deck'], 'Start the board deck outline', 2, '10:00', null],
        [$admin, null, 'Call Globex CTO about renewal', 1, '15:30', null],
        [$meera, $acts['gst'], 'GST filing due today', 0, '09:00', $at(0, '09:00')],
        [$rahul, $acts['oncall'], 'Finalise on-call rota', 2, '11:00', null],
        [$vikram, null, 'Follow up with Initech on the pilot', -1, '12:00', $at(-1, '12:00')],
    ] as [$uid, $aid, $body, $off, $hm, $fired]) {
        DB::insert('reminders', ['org_id' => $orgId, 'user_id' => $uid, 'action_id' => $aid, 'body' => $body, 'remind_at' => $at($off, $hm), 'via_email' => 1, 'fired_at' => $fired, 'created_at' => $at(-3)]);
    }

    // notifications (bell)
    foreach ([
        [$admin, 'Meera Nair commented on "Set up approval workflow for marketing spend above ₹50,000"', 'actions/' . $acts['budget'], -27, true],
        [$admin, 'Rahul Verma completed "Share August incident report with Globex"', 'actions/' . $acts['incident'], -12, true],
        [$admin, '"Issue one-month SLA credit to Globex" is overdue', 'actions/' . $acts['credit'], -7, false],
        [$admin, 'Ananya Iyer invited you to "Mobile App v2 – Launch Readiness"', 'meetings', -1, false],
        [$admin, 'Reminder: Call Globex CTO about renewal', null, 0, false],
        [$admin, 'Rahul Verma completed "Prepare onboarding kit for new engineers". Please review and approve.', 'actions/' . $acts['onboard'], -1, false],
        [$admin, 'Rahul Verma asked to add "Q4 hiring plan and budget" to Q4 Planning Kick-off', 'meetings', -1, false],
        [$arjun, 'Ananya Iyer commented on "Build bottom tab navigation"', 'actions/' . $acts['tabs'], -6, true],
        [$arjun, '"Integrate Google sign-in" is overdue', 'actions/' . $acts['google'], -3, false],
        [$arjun, 'Rahul Verma assigned you "Quarantine flaky tests in CI"', 'actions/' . $acts['flaky'], -3, false],
        [$meera, '"Issue one-month SLA credit to Globex" is overdue', 'actions/' . $acts['credit'], -7, false],
        [$meera, 'Reminder: GST filing due today', 'actions/' . $acts['gst'], 0, false],
        [$vikram, '"Clean up duplicate leads in the CRM" is overdue', 'actions/' . $acts['crm'], -4, false],
    ] as [$uid, $body, $link, $off, $read]) {
        DB::insert('notifications', ['user_id' => $uid, 'body' => $body, 'link' => $link, 'read_at' => $read ? $at($off, '18:00') : null, 'created_at' => $at($off, '09:30')]);
    }

    // audit trail
    DB::insert('audit_log', ['org_id' => $orgId, 'user_id' => $admin, 'event' => 'company.registered', 'ip' => '127.0.0.1', 'created_at' => $at(-60, '09:00')]);
    foreach ($u as $i => $uid) DB::insert('audit_log', ['org_id' => $orgId, 'user_id' => $admin, 'event' => 'user.invited', 'entity' => 'user', 'entity_id' => $uid, 'ip' => '127.0.0.1', 'created_at' => $at(-58 + $i, '10:00')]);
    foreach ([$m1 => -45, $m2 => -33, $m3 => -24, $m4 => -19, $m5 => -12, $m6 => -6] as $mid => $off) {
        DB::insert('audit_log', ['org_id' => $orgId, 'user_id' => $admin, 'event' => 'meeting.created', 'entity' => 'meeting', 'entity_id' => $mid, 'ip' => '127.0.0.1', 'created_at' => $at($off, '11:25')]);
    }
});

// ================================================================ Globex (small second company, for the owner panel)
DB::transaction(function () use ($hash, $day, $at) {
    $orgId = DB::insert('organizations', [
        'name' => 'Globex Corporation (Demo)', 'slug' => 'globex-demo', 'timezone' => 'Asia/Kolkata', 'plan' => 'free',
        'seats_limit' => 5, 'status' => 'active', 'created_at' => $at(-20, '14:00'),
    ]);
    $admin = add_user($orgId, ['name' => 'Rohan Das', 'email' => 'demo.admin@globex.test', 'title' => 'Founder', 'role' => 'admin'], $hash, $at(-20, '14:00'));
    $tara = add_user($orgId, ['name' => 'Tara Joshi', 'email' => 'tara@globex.test', 'title' => 'Operations', 'invited_by' => $admin], $hash, $at(-19, '10:00'));
    $neil = add_user($orgId, ['name' => 'Neil Bose', 'email' => 'neil@globex.test', 'title' => 'Developer', 'invited_by' => $admin], $hash, $at(-19, '10:05'));

    $pWeb = DB::insert('projects', ['org_id' => $orgId, 'name' => 'Website Refresh', 'created_by' => $admin, 'created_at' => $at(-15, '11:00')]);
    [$m, $a] = add_meeting($orgId, ['title' => 'Weekly Team Sync', 'offset' => -6, 'time' => '10:00', 'duration' => 30, 'organizer' => $admin, 'invitees' => [$tara, $neil], 'agenda' => [
        ['Vendor evaluation', $tara, 15, ['Acme', 'Initech'], 'Shortlisted Acme; waiting on revised pricing and SLA terms.'],
        ['Website refresh', $neil, 10, [], 'New landing page ready for review.'],
    ]], $day, $at);
    add_meeting($orgId, ['title' => 'Weekly Team Sync', 'offset' => 1, 'time' => '10:00', 'duration' => 30, 'organizer' => $admin, 'invitees' => [$tara, $neil], 'agenda' => [
        ['Vendor decision', $tara, 15, [], null],
    ]], $day, $at);
    add_action($orgId, ['title' => 'Compare Acme and Initech SLA terms', 'owner' => $tara, 'by' => $admin, 'deadline' => 2, 'meeting' => $m, 'agenda' => $a[0], 'created' => -6], $day, $at);
    add_action($orgId, ['title' => 'Publish new landing page', 'project' => $pWeb, 'status' => 'review', 'priority' => 'high', 'owner' => $neil, 'by' => $admin, 'deadline' => -1, 'meeting' => $m, 'agenda' => $a[1], 'created' => -6], $day, $at);
    add_action($orgId, ['title' => 'Renew domain and SSL certificate', 'priority' => 'low', 'owner' => $neil, 'by' => $neil, 'deadline' => -4, 'done' => -5, 'created' => -10], $day, $at);
    DB::insert('audit_log', ['org_id' => $orgId, 'user_id' => $admin, 'event' => 'company.registered', 'ip' => '127.0.0.1', 'created_at' => $at(-20, '14:00')]);
});

echo "Demo data loaded.\n\n";
echo "  Acme Industries (Demo)     demo.admin@acme.test    (admin; also rahul@, ananya@, arjun@, meera@ ... @acme.test)\n";
echo "  Globex Corporation (Demo)  demo.admin@globex.test  (admin; also tara@, neil@globex.test)\n\n";
echo "All demo users share the password defined as DEMO_PASSWORD in database/seed.php.\n";
echo "Sign in at " . url('login') . "\n";
