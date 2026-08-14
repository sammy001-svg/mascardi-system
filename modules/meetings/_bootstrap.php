<?php
/**
 * Meetings — schema and shared helpers.
 *
 * Covers the whole life of a meeting: scheduling it, the agenda it will follow,
 * the minutes taken while it runs, and — the part that usually gets lost — the
 * deliverables agreed in it, tracked afterwards until they are actually done.
 *
 * Virtual meetings run peer-to-peer in the browser (WebRTC). There is no media
 * server; this application only carries the signalling, in `meeting_signals`,
 * using the same polled-database approach the 1:1 chat calls already use. That
 * works well for a handful of participants and is explained where the room is
 * built (modules/meetings/room.php).
 */

if (!function_exists('meetingsMigrate')) {

// 2 — recurring series (meeting_series, meetings.series_id/occurrence_date) and
//     reminder dispatch tracking (meeting_reminders).
if (!defined('MEETINGS_SCHEMA_VERSION')) define('MEETINGS_SCHEMA_VERSION', '2');

/** How far ahead occurrences of an open-ended series are created. */
if (!defined('MEETING_SERIES_HORIZON_DAYS')) define('MEETING_SERIES_HORIZON_DAYS', 120);

function meetingStatuses(): array {
    return [
        'scheduled'   => ['Scheduled',   '#2563eb'],
        'in_progress' => ['In Progress', '#16a34a'],
        'completed'   => ['Completed',   '#64748b'],
        'cancelled'   => ['Cancelled',   '#dc2626'],
    ];
}

function meetingActionStatuses(): array {
    return [
        'pending'     => ['Pending',     '#f59e0b'],
        'in_progress' => ['In Progress', '#2563eb'],
        'done'        => ['Done',        '#16a34a'],
        'blocked'     => ['Blocked',     '#dc2626'],
        'cancelled'   => ['Dropped',     '#94a3b8'],
    ];
}

function meetingTypes(): array {
    return [
        'physical' => ['In person', 'fa-building'],
        'virtual'  => ['Virtual',   'fa-video'],
        'hybrid'   => ['Hybrid',    'fa-tower-broadcast'],
    ];
}

function meetingParticipantRoles(): array {
    return ['chair' => 'Chair', 'secretary' => 'Secretary', 'attendee' => 'Attendee'];
}

/**
 * Idempotent, and cheap once applied — the version row means the steady state
 * is one settings lookup rather than a dozen DDL round-trips per request.
 */
function meetingsMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'meetings_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === MEETINGS_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS meetings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            purpose TEXT NULL,
            meeting_type ENUM('physical','virtual','hybrid') NOT NULL DEFAULT 'physical',
            venue VARCHAR(200) NULL,
            room_code VARCHAR(40) NULL,
            scheduled_start DATETIME NOT NULL,
            scheduled_end DATETIME NULL,
            actual_start DATETIME NULL,
            actual_end DATETIME NULL,
            status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            organiser_id INT NULL,
            minutes MEDIUMTEXT NULL,
            minutes_by INT NULL,
            minutes_at DATETIME NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mt_when (scheduled_start),
            KEY idx_mt_status (status),
            UNIQUE KEY uq_mt_room (room_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS meeting_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('chair','secretary','attendee') NOT NULL DEFAULT 'attendee',
            invite_status ENUM('invited','accepted','declined') NOT NULL DEFAULT 'invited',
            attended TINYINT(1) NOT NULL DEFAULT 0,
            joined_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mp (meeting_id, user_id),
            KEY idx_mp_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Agenda items double as the structure for the minutes: notes are kept
        // against the item they were discussed under, so the written record
        // follows the same order the meeting did.
        "CREATE TABLE IF NOT EXISTS meeting_agenda_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            position INT NOT NULL DEFAULT 0,
            title VARCHAR(200) NOT NULL,
            detail TEXT NULL,
            presenter_id INT NULL,
            duration_min INT NULL,
            discussion MEDIUMTEXT NULL,
            status ENUM('pending','discussed','deferred') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ai_meeting (meeting_id, position)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // The deliverables. Deliberately their own table rather than free text
        // inside the minutes — an action nobody can filter, sort or chase is an
        // action that quietly never happens.
        "CREATE TABLE IF NOT EXISTS meeting_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            agenda_item_id INT NULL,
            title VARCHAR(255) NOT NULL,
            detail TEXT NULL,
            assigned_to INT NULL,
            due_date DATE NULL,
            priority ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
            status ENUM('pending','in_progress','done','blocked','cancelled') NOT NULL DEFAULT 'pending',
            progress_note TEXT NULL,
            completed_at DATETIME NULL,
            completed_by INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ma_meeting (meeting_id),
            KEY idx_ma_owner (assigned_to, status),
            KEY idx_ma_due (due_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Who is currently in the virtual room. Rows go stale rather than being
        // deleted on leave — a browser that crashes never sends a goodbye.
        "CREATE TABLE IF NOT EXISTS meeting_peers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            peer_token VARCHAR(40) NOT NULL,
            mic_on TINYINT(1) NOT NULL DEFAULT 1,
            cam_on TINYINT(1) NOT NULL DEFAULT 1,
            sharing TINYINT(1) NOT NULL DEFAULT 0,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NULL,
            UNIQUE KEY uq_peer (meeting_id, user_id),
            KEY idx_peer_seen (meeting_id, last_seen)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Directed WebRTC signalling. Each row is consumed once by its
        // recipient and then deleted, so the table stays small.
        "CREATE TABLE IF NOT EXISTS meeting_signals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            from_user INT NOT NULL,
            to_user INT NOT NULL,
            kind ENUM('offer','answer','ice','bye') NOT NULL,
            payload MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sig_inbox (meeting_id, to_user, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ── Recurring series ────────────────────────────────────────────────
        // The rule only. The meeting itself — title, participants, agenda — is
        // held on the first occurrence, which every later one is cloned from,
        // so there is no second copy of a meeting's details to keep in step.
        //
        // ends_on NULL means the series runs indefinitely. Nothing infinite can
        // be written to a table, so occurrences are created a rolling window
        // ahead (MEETING_SERIES_HORIZON_DAYS) and topped up by the cron job.
        "CREATE TABLE IF NOT EXISTS meeting_series (
            id INT AUTO_INCREMENT PRIMARY KEY,
            frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
            weekdays VARCHAR(20) NULL,
            month_day TINYINT NULL,
            time_of_day TIME NOT NULL,
            duration_min INT NULL,
            starts_on DATE NOT NULL,
            ends_on DATE NULL,
            status ENUM('active','ended','cancelled') NOT NULL DEFAULT 'active',
            template_meeting_id INT NULL,
            materialised_to DATE NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ms_status (status, materialised_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // ── Reminder dispatch log ───────────────────────────────────────────
        // One row per reminder actually delivered. The unique key is what makes
        // the dispatcher safe to run as often as you like: a second attempt for
        // the same person, meeting, lead time and channel cannot insert, so no
        // one is ever reminded twice however the cron is scheduled.
        "CREATE TABLE IF NOT EXISTS meeting_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            meeting_id INT NOT NULL,
            user_id INT NOT NULL,
            lead_time ENUM('day','soon') NOT NULL,
            channel ENUM('email','system') NOT NULL,
            status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
            error VARCHAR(255) NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reminder (meeting_id, user_id, lead_time, channel),
            KEY idx_mr_meeting (meeting_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    // Added after the meetings table shipped, so CREATE TABLE IF NOT EXISTS
    // above will not apply them to an existing install.
    $columns = [
        "ALTER TABLE meetings ADD COLUMN series_id INT NULL AFTER organiser_id",
        "ALTER TABLE meetings ADD COLUMN occurrence_date DATE NULL AFTER series_id",
        // Materialising is idempotent: re-running can never duplicate a date.
        "ALTER TABLE meetings ADD UNIQUE KEY uq_series_date (series_id, occurrence_date)",
        "ALTER TABLE meetings ADD INDEX idx_upcoming (status, scheduled_start)",
    ];
    foreach ($columns as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('meetings_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([MEETINGS_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

// ── Recurring series ─────────────────────────────────────────────────────────

function meetingFrequencies(): array {
    return ['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];
}

/** ISO-8601 weekday numbers, which is what date('N') returns. */
function meetingWeekdayNames(): array {
    return [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
}

/** Parses the stored '1,3,5' into a clean, ordered list of ISO weekday numbers. */
function meetingParseWeekdays(?string $csv): array
{
    $out = [];
    foreach (explode(',', (string)$csv) as $d) {
        $d = (int)trim($d);
        if ($d >= 1 && $d <= 7 && !in_array($d, $out, true)) $out[] = $d;
    }
    sort($out);
    return $out;
}

/**
 * The dates a series falls on within a window, inclusive.
 *
 * Bounded by the series' own start and end. An open-ended series (ends_on NULL)
 * is bounded only by $to, which is what keeps "forever" finite at the point of
 * use — callers pass the horizon they are prepared to create rows for.
 *
 * @return string[] Y-m-d, ascending.
 */
function meetingSeriesDates(array $series, string $from, string $to): array
{
    $freq = $series['frequency'] ?? 'weekly';
    try {
        $cursor = new DateTimeImmutable(max($from, $series['starts_on']));
        $limit  = new DateTimeImmutable(
            !empty($series['ends_on']) ? min($to, $series['ends_on']) : $to
        );
    } catch (\Throwable $_) { return []; }
    if ($cursor > $limit) return [];

    $dates = [];
    // A guard, not a rule: no legitimate window produces this many dates, and it
    // stops a corrupt row from spinning forever.
    $guard = 4000;

    if ($freq === 'daily') {
        while ($cursor <= $limit && $guard-- > 0) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    } elseif ($freq === 'weekly') {
        $days = meetingParseWeekdays($series['weekdays'] ?? '');
        if (!$days) return [];
        while ($cursor <= $limit && $guard-- > 0) {
            if (in_array((int)$cursor->format('N'), $days, true)) $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }
    } else { // monthly
        $wanted = (int)($series['month_day'] ?? 0);
        if ($wanted < 1 || $wanted > 31) $wanted = (int)(new DateTimeImmutable($series['starts_on']))->format('j');
        $month = $cursor->modify('first day of this month');
        while ($month <= $limit && $guard-- > 0) {
            // "The 31st" in a 30-day month lands on the last day of it rather
            // than rolling into the next one.
            $dim  = (int)$month->format('t');
            $day  = min($wanted, $dim);
            $date = $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $day);
            if ($date >= $cursor && $date <= $limit) $dates[] = $date->format('Y-m-d');
            $month = $month->modify('+1 month');
        }
    }
    return $dates;
}

/** A one-line, human description of the rule — "Every Monday & Thursday at 10:00". */
function meetingSeriesDescribe(array $series): string
{
    $time = date('H:i', strtotime('2000-01-01 ' . ($series['time_of_day'] ?? '00:00')));
    $freq = $series['frequency'] ?? 'weekly';

    if ($freq === 'daily') {
        $what = 'Every day';
    } elseif ($freq === 'weekly') {
        $names = meetingWeekdayNames();
        $days  = array_map(fn($d) => $names[$d], meetingParseWeekdays($series['weekdays'] ?? ''));
        if (!$days)             $what = 'Every week';
        elseif (count($days) === 1) $what = 'Every ' . $days[0];
        else {
            $last = array_pop($days);
            $what = 'Every ' . implode(', ', $days) . ' & ' . $last;
        }
    } else {
        $d = (int)($series['month_day'] ?? 1);
        $suffix = (in_array($d % 100, [11,12,13], true)) ? 'th'
                : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$d % 10] ?? 'th');
        $what = "Monthly on the {$d}{$suffix}";
    }

    $out = $what . ' at ' . $time;
    if (!empty($series['ends_on'])) $out .= ', until ' . date('j M Y', strtotime($series['ends_on']));
    else                            $out .= ', with no end date';
    return $out;
}

/**
 * Creates any occurrences of a series that are missing between now and the
 * horizon, cloning the template meeting's participants and agenda into each.
 *
 * Safe to call repeatedly — the unique key on (series_id, occurrence_date) makes
 * a duplicate insert a no-op rather than a second meeting on the same day.
 *
 * @return int How many occurrences were created.
 */
function meetingSeriesMaterialise(PDO $db, int $seriesId, ?string $horizon = null): int
{
    $st = $db->prepare("SELECT * FROM meeting_series WHERE id = ?");
    $st->execute([$seriesId]);
    $series = $st->fetch(PDO::FETCH_ASSOC);
    if (!$series || $series['status'] !== 'active') return 0;

    $tpl = null;
    if (!empty($series['template_meeting_id'])) {
        $t = $db->prepare("SELECT * FROM meetings WHERE id = ?");
        $t->execute([(int)$series['template_meeting_id']]);
        $tpl = $t->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$tpl) return 0;

    $today   = meetingToday($db);
    $horizon = $horizon ?: date('Y-m-d', strtotime($today . ' +' . MEETING_SERIES_HORIZON_DAYS . ' days'));
    // Never backfill: a meeting that did not happen should not appear later as
    // though it had been scheduled all along.
    $from  = max($today, $series['starts_on']);
    $dates = meetingSeriesDates($series, $from, $horizon);
    if (!$dates) { meetingSeriesTouch($db, $seriesId, $horizon); return 0; }

    $have = [];
    $ex = $db->prepare("SELECT occurrence_date FROM meetings WHERE series_id = ?");
    $ex->execute([$seriesId]);
    foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $d) $have[$d] = true;

    $durMin = (int)($series['duration_min'] ?? 0);
    if ($durMin < 1 && !empty($tpl['scheduled_end'])) {
        $durMin = max(0, (int)round((strtotime($tpl['scheduled_end']) - strtotime($tpl['scheduled_start'])) / 60));
    }

    $insM = $db->prepare("INSERT IGNORE INTO meetings
            (title, purpose, meeting_type, venue, room_code, scheduled_start, scheduled_end,
             status, organiser_id, series_id, occurrence_date, created_by)
            VALUES (?,?,?,?,?,?,?, 'scheduled', ?,?,?,?)");
    $insP = $db->prepare("INSERT IGNORE INTO meeting_participants (meeting_id, user_id, role)
                          SELECT ?, user_id, role FROM meeting_participants WHERE meeting_id = ?");
    $insA = $db->prepare("INSERT INTO meeting_agenda_items
                            (meeting_id, position, title, detail, presenter_id, duration_min)
                          SELECT ?, position, title, detail, presenter_id, duration_min
                          FROM meeting_agenda_items WHERE meeting_id = ?");

    $made = 0;
    foreach ($dates as $d) {
        if (isset($have[$d])) continue;
        $start = $d . ' ' . date('H:i:s', strtotime('2000-01-01 ' . $series['time_of_day']));
        $end   = $durMin > 0 ? date('Y-m-d H:i:s', strtotime($start) + $durMin * 60) : null;
        try {
            $insM->execute([
                $tpl['title'], $tpl['purpose'], $tpl['meeting_type'], $tpl['venue'],
                // Each occurrence gets its own room: a stale link should not drop
                // someone into a different week's meeting.
                $tpl['meeting_type'] === 'physical' ? null : meetingRoomCode(),
                $start, $end, $tpl['organiser_id'], $seriesId, $d, $tpl['created_by'],
            ]);
            $newId = (int)$db->lastInsertId();
            if (!$newId) continue;          // lost the race to a concurrent run
            $insP->execute([$newId, (int)$tpl['id']]);
            $insA->execute([$newId, (int)$tpl['id']]);
            $made++;
        } catch (\Throwable $_) { /* a concurrent run got there first */ }
    }

    meetingSeriesTouch($db, $seriesId, $horizon);

    // A series with a closing date is finished once its last date is in the past.
    if (!empty($series['ends_on']) && strtotime($series['ends_on']) < strtotime($today)) {
        try { $db->prepare("UPDATE meeting_series SET status='ended' WHERE id=?")->execute([$seriesId]); }
        catch (\Throwable $_) {}
    }
    return $made;
}

/**
 * Today's date according to the DATABASE, not PHP.
 *
 * These two clocks do not agree here: PHP has no timezone configured and so runs
 * in UTC, while MySQL runs in local time (UTC+3). Scheduled times are stored as
 * the local wall-clock times the operator typed, and every other date comparison
 * in the system is made in SQL against NOW()/CURDATE() — so the database is the
 * clock this feature has to share. Using PHP's date() here instead would put the
 * "do not backfill" floor three hours out and, late in the evening, drop a day.
 */
function meetingToday(PDO $db): string
{
    try { return (string)$db->query("SELECT CURDATE()")->fetchColumn(); }
    catch (\Throwable $_) { return date('Y-m-d'); }
}

function meetingSeriesTouch(PDO $db, int $seriesId, string $horizon): void
{
    try {
        $db->prepare("UPDATE meeting_series SET materialised_to = ? WHERE id = ?")
           ->execute([$horizon, $seriesId]);
    } catch (\Throwable $_) {}
}

/** Tops up every active series. Called by the reminder cron. */
function meetingSeriesMaterialiseAll(PDO $db): int
{
    $made = 0;
    try {
        $ids = $db->query("SELECT id FROM meeting_series WHERE status='active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $sid) $made += meetingSeriesMaterialise($db, (int)$sid);
    } catch (\Throwable $_) {}
    return $made;
}

// ── Access ───────────────────────────────────────────────────────────────────

/**
 * A meeting is visible to its organiser, anyone invited, and management.
 * Everything else in this module gates on this, so a private discussion does
 * not leak through a guessed id.
 */
function meetingCanView(PDO $db, array $meeting, int $userId): bool
{
    if (isSuperAdmin()) return true;
    if ((int)($meeting['organiser_id'] ?? 0) === $userId) return true;
    if ((int)($meeting['created_by'] ?? 0) === $userId) return true;
    if (hasRole(['general_manager', 'manager'])) return true;
    try {
        $st = $db->prepare("SELECT 1 FROM meeting_participants WHERE meeting_id = ? AND user_id = ? LIMIT 1");
        $st->execute([(int)$meeting['id'], $userId]);
        return (bool)$st->fetchColumn();
    } catch (\Throwable $_) { return false; }
}

/** Editing the meeting itself — organiser, chair/secretary, or management. */
function meetingCanEdit(PDO $db, array $meeting, int $userId): bool
{
    if (isSuperAdmin()) return true;
    if ((int)($meeting['organiser_id'] ?? 0) === $userId) return true;
    if ((int)($meeting['created_by'] ?? 0) === $userId) return true;
    if (hasRole(['general_manager'])) return true;
    try {
        $st = $db->prepare("SELECT role FROM meeting_participants WHERE meeting_id = ? AND user_id = ?");
        $st->execute([(int)$meeting['id'], $userId]);
        return in_array((string)$st->fetchColumn(), ['chair', 'secretary'], true);
    } catch (\Throwable $_) { return false; }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Short, unguessable room code — also the join link for virtual meetings. */
function meetingRoomCode(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

/** Staff who can be invited. */
function meetingInvitableUsers(PDO $db): array
{
    try {
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch();
        $where = $col ? "WHERE COALESCE(status,'active') = 'active'" : '';
        return $db->query("SELECT id, name, role FROM users {$where} ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** Deterministic avatar colour, matching the convention used elsewhere. */
function meetingAvatarColor(int $id): string
{
    $palette = ['#2563eb','#7c3aed','#db2777','#dc2626','#ea580c',
                '#ca8a04','#16a34a','#0891b2','#4f46e5','#be123c'];
    return $palette[abs($id) % count($palette)];
}

function meetingInitials(string $name): string
{
    $p = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if (!$p) return '?';
    return mb_strtoupper(mb_substr($p[0], 0, 1) . (count($p) > 1 ? mb_substr(end($p), 0, 1) : ''));
}

/** Counts for a meeting's deliverables: done / pending / overdue. */
function meetingActionSummary(PDO $db, int $meetingId): array
{
    $out = ['total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0];
    try {
        $st = $db->prepare("
            SELECT COUNT(*) total,
                   SUM(status = 'done') done,
                   SUM(status IN ('pending','in_progress','blocked')) open,
                   SUM(status IN ('pending','in_progress','blocked')
                       AND due_date IS NOT NULL AND due_date < CURDATE()) overdue
            FROM meeting_actions WHERE meeting_id = ?");
        $st->execute([$meetingId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($out as $k => $_) $out[$k] = (int)($r[$k] ?? 0);
    } catch (\Throwable $_) {}
    return $out;
}

/**
 * Moves a meeting's status along with the clock, without ever overriding a
 * decision someone made by hand (a cancelled or completed meeting stays put).
 */
function meetingAutoStatus(PDO $db): void
{
    try {
        $db->exec("UPDATE meetings
                   SET status = 'in_progress'
                   WHERE status = 'scheduled'
                     AND scheduled_start <= NOW()
                     AND (scheduled_end IS NULL OR scheduled_end > NOW())");
        // Only close out meetings that actually started; one nobody opened is
        // left alone so it is visible as having been missed.
        $db->exec("UPDATE meetings
                   SET status = 'completed', actual_end = COALESCE(actual_end, scheduled_end)
                   WHERE status = 'in_progress'
                     AND scheduled_end IS NOT NULL
                     AND scheduled_end < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    } catch (\Throwable $_) {}
}

} // function_exists guard
