<?php
/**
 * Mascardi Car Yard — Meeting Reminders Cron Job
 *
 * Two jobs in one pass:
 *
 *   1. Tops up recurring meeting series. A series with no end date cannot have
 *      all its occurrences written in advance, so they are created a rolling
 *      window ahead (see MEETING_SERIES_HORIZON_DAYS) and refilled here.
 *
 *   2. Sends the reminders due right now — a day before each meeting, and again
 *      30 minutes before — to every participant, by in-system notification and
 *      by email.
 *
 * Run it every 5 minutes. The 30-minute reminder can only be as punctual as the
 * schedule allows, so an hourly job would deliver it up to an hour late.
 * Running it more often is harmless: reminders are claimed in the database
 * before they are sent, so no one is ever reminded twice, whether the job runs
 * every minute, overlaps itself, or is triggered by hand.
 *
 * cPanel / Linux:
 *   *\/5 * * * * /usr/local/bin/php /home/USER/public_html/scripts/cron/meeting_reminders.php
 *
 * Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: "C:\Mascardi Systems\mascardi-system\scripts\cron\meeting_reminders.php"
 *   Trigger:   Daily, repeat every 5 minutes for 1 day
 *
 * See README.md in this folder.
 */

define('CRON_RUN', true);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../modules/meetings/_bootstrap.php';
require_once __DIR__ . '/../../modules/meetings/reminders.php';

$startTime = microtime(true);
$jobName   = 'meeting_reminders';
$db        = getDB();
$errors    = [];
$cli       = (PHP_SAPI === 'cli');

// Allow a browser to trigger it too, but only for a signed-in super admin —
// otherwise the URL would be an open invitation to spam every participant.
if (!$cli) {
    requireLogin();
    if (!isSuperAdmin()) { http_response_code(403); exit('Forbidden'); }
    header('Content-Type: text/plain; charset=utf-8');
}

if (!function_exists('cronLog')) {
    function cronLog(PDO $db, string $job, string $status, int $records, string $message, int $ms): void {
        try {
            $db->prepare("INSERT INTO cron_runs (job_name, status, duration_ms, records, message) VALUES (?,?,?,?,?)")
               ->execute([$job, $status, $ms, $records, $message]);
        } catch (Throwable $e) {
            error_log('[Cron] DB log failed: ' . $e->getMessage());
        }
    }
}

meetingsMigrate($db);

// ── 1. Keep recurring series stocked ─────────────────────────────────────────
$created = 0;
try {
    $created = meetingSeriesMaterialiseAll($db);
} catch (Throwable $e) {
    $errors[] = 'Series top-up: ' . $e->getMessage();
}

// ── 2. Send what is due ──────────────────────────────────────────────────────
$r = ['sent_email' => 0, 'sent_system' => 0, 'failed' => 0, 'meetings' => 0];
try {
    $r = meetingDispatchReminders($db, !$cli && !isset($_GET['verbose']));
} catch (Throwable $e) {
    $errors[] = 'Reminders: ' . $e->getMessage();
}

$totalSent = $r['sent_email'] + $r['sent_system'];
$ms        = (int)((microtime(true) - $startTime) * 1000);
$status    = $errors ? 'error' : 'success';
$message   = "Created {$created} occurrence(s); reminded {$r['meetings']} meeting(s): "
           . "{$r['sent_system']} in-system, {$r['sent_email']} email"
           . ($r['failed'] ? ", {$r['failed']} failed" : '')
           . ($errors ? ' — ' . implode('; ', $errors) : '.');

cronLog($db, $jobName, $status, $totalSent, $message, $ms);

if ($cli || !empty($_GET['verbose'])) {
    echo '[' . date('Y-m-d H:i:s') . "] {$jobName}: {$message} ({$ms}ms)\n";
    foreach ($errors as $e) echo "  ERROR: {$e}\n";
}
