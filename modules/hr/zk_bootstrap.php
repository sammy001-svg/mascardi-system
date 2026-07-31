<?php
/**
 * ZKTeco biometric integration — schema, protocol parsing and roll-up engine.
 *
 * Three ways punches reach us, because no single one works everywhere:
 *
 *   push   — the device POSTs to /iclock/cdata (ZKTeco "ADMS" / push SDK).
 *            The device opens the connection, so this is the only mode that
 *            works when the server is hosted and the device sits behind NAT
 *            on the yard LAN. This is the primary mode.
 *   pull   — we open a TCP socket to the device on port 4370 and ask for the
 *            log. Only possible when server and device share a network.
 *   import — a file exported from ZKTime/ZKAccess. Always available, needs no
 *            network path at all, and is the fallback when the others cannot
 *            be configured.
 *
 * Whichever way they arrive, punches land in `zk_punches` raw and un-judged.
 * Turning them into attendance is a separate, re-runnable step (zkRollupRange)
 * so a mapping fixed today can be applied to punches captured last week.
 */

if (!function_exists('zkMigrate')) {

/** Punch state as reported by the device's F1–F6 keys. */
function zkPunchStates(): array {
    return [
        0 => 'Check In', 1 => 'Check Out',
        2 => 'Break Out', 3 => 'Break In',
        4 => 'Overtime In', 5 => 'Overtime Out',
    ];
}

/** How the person identified themselves. */
function zkVerifyModes(): array {
    return [
        0 => 'Password', 1 => 'Fingerprint', 2 => 'Card', 3 => 'Card + Password',
        4 => 'Face', 9 => 'Fingerprint + Password', 15 => 'Face', 16 => 'Palm',
    ];
}

function zkMigrate(PDO $db): void
{
    $tables = [
        // A device is registered by its serial number. Unknown serials are
        // recorded as 'pending' rather than trusted — see zkDeviceFor().
        "CREATE TABLE IF NOT EXISTS zk_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            serial_number VARCHAR(64) NOT NULL,
            name VARCHAR(120) NULL,
            location_id INT NULL,
            mode ENUM('push','pull','import') NOT NULL DEFAULT 'push',
            ip_address VARCHAR(64) NULL,
            port INT NOT NULL DEFAULT 4370,
            comm_key INT NOT NULL DEFAULT 0,
            status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
            firmware VARCHAR(80) NULL,
            device_time VARCHAR(40) NULL,
            last_seen_at DATETIME NULL,
            last_punch_at DATETIME NULL,
            punch_count INT NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_zk_sn (serial_number),
            KEY idx_zk_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Raw punches, exactly as the device reported them. Never edited — the
        // roll-up reads these and writes attendance_records, so a mistake in
        // the roll-up can always be corrected and re-run against the truth.
        "CREATE TABLE IF NOT EXISTS zk_punches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_sn VARCHAR(64) NOT NULL DEFAULT '',
            device_pin VARCHAR(32) NOT NULL,
            punch_time DATETIME NOT NULL,
            punch_state TINYINT NOT NULL DEFAULT 0,
            verify_mode TINYINT NOT NULL DEFAULT 0,
            work_code VARCHAR(32) NULL,
            source ENUM('push','pull','import') NOT NULL DEFAULT 'push',
            staff_type ENUM('user','mechanic','driver') NULL,
            staff_id INT NULL,
            processed TINYINT(1) NOT NULL DEFAULT 0,
            raw VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_punch (device_sn, device_pin, punch_time),
            KEY idx_punch_day (punch_time),
            KEY idx_punch_staff (staff_type, staff_id, punch_time),
            KEY idx_punch_unproc (processed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Which device enrolment number is which employee. device_sn = '' means
        // the mapping applies to every device, which is the common case; a
        // device-specific row wins over it (see zkResolvePin).
        "CREATE TABLE IF NOT EXISTS zk_enrollments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_sn VARCHAR(64) NOT NULL DEFAULT '',
            device_pin VARCHAR(32) NOT NULL,
            staff_type ENUM('user','mechanic','driver') NOT NULL,
            staff_id INT NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_enrol (device_sn, device_pin),
            KEY idx_enrol_staff (staff_type, staff_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Every request the push endpoint receives, kept briefly. Device-side
        // problems are otherwise invisible — the device reports nothing back.
        "CREATE TABLE IF NOT EXISTS zk_push_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            device_sn VARCHAR(64) NULL,
            endpoint VARCHAR(40) NULL,
            method VARCHAR(10) NULL,
            table_name VARCHAR(40) NULL,
            rows_received INT NOT NULL DEFAULT 0,
            rows_stored INT NOT NULL DEFAULT 0,
            remote_ip VARCHAR(64) NULL,
            note VARCHAR(255) NULL,
            body MEDIUMTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pushlog_time (created_at),
            KEY idx_pushlog_sn (device_sn)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    // Attendance rows need to say where they came from, so the roll-up can
    // refresh what the device produced without ever overwriting a correction
    // an HR user typed by hand.
    try {
        $col = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'source'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $db->exec("ALTER TABLE attendance_records
                       ADD COLUMN source ENUM('manual','biometric') NOT NULL DEFAULT 'manual'");
        }
    } catch (\Throwable $_) {}
}

// ── Configuration ────────────────────────────────────────────────────────────

/** Working-day rules. Stored in `settings` so HR can change them. */
function zkConfig(): array
{
    return [
        'work_start'     => getSetting('zk_work_start', '08:00'),
        'work_end'       => getSetting('zk_work_end', '17:00'),
        'late_grace_min' => (int)getSetting('zk_late_grace_min', '10'),
        'min_hours_full' => (float)getSetting('zk_min_hours_full', '6'),
        'dedupe_seconds' => (int)getSetting('zk_dedupe_seconds', '60'),
        'auto_rollup'    => getSetting('zk_auto_rollup', '1') === '1',
        'push_key'       => getSetting('zk_push_key', ''),
    ];
}

function zkSetSetting(PDO $db, string $key, string $value): void
{
    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([$key, $value]);
    } catch (\Throwable $e) {
        error_log('zkSetSetting: ' . $e->getMessage());
    }
}

// ── Protocol parsing (pure — no database, no globals) ────────────────────────

/**
 * Parses an ATTLOG payload from a push, a pull, or an exported file.
 *
 * ZKTeco writes one record per line, fields separated by tabs:
 *   PIN <tab> YYYY-MM-DD HH:MM:SS <tab> state <tab> verify <tab> workcode ...
 *
 * Exported files are looser — comma or semicolon separated, sometimes with a
 * header row, sometimes with the date and time in separate columns. Rather
 * than assume a layout, each line is split on whatever separator it uses and
 * the fields are identified by shape: the first timestamp-looking field is the
 * punch time, the first field before it that is not a timestamp is the PIN.
 *
 * Returns ['rows' => [...], 'skipped' => int].
 */
function zkParseAttlog(string $payload): array
{
    $rows = [];
    $skipped = 0;

    $payload = str_replace(["\r\n", "\r"], "\n", $payload);
    foreach (explode("\n", $payload) as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Pick the separator this line actually uses.
        $sep = "\t";
        if (!str_contains($line, "\t")) {
            if (substr_count($line, ';') >= 2)      $sep = ';';
            elseif (substr_count($line, ',') >= 2)  $sep = ',';
            else                                    $sep = ' ';
        }
        $parts = $sep === ' '
            ? preg_split('/\s+/', $line)
            : array_map('trim', explode($sep, $line));
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
        if (count($parts) < 2) { $skipped++; continue; }

        // Locate the timestamp. Splitting on whitespace tears "2026-07-31
        // 08:05:00" in half, so a bare date is re-joined with the next field.
        $ts = null; $tsIndex = -1;
        for ($i = 0; $i < count($parts); $i++) {
            $cand = $parts[$i];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cand) && isset($parts[$i + 1])
                && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $parts[$i + 1])) {
                $cand = $cand . ' ' . $parts[$i + 1];
            }
            $norm = zkNormalizeTimestamp($cand);
            if ($norm !== null) { $ts = $norm; $tsIndex = $i; break; }
        }
        if ($ts === null) { $skipped++; continue; }

        // The PIN is the last non-timestamp field before the timestamp. A
        // header row has no numeric PIN and is skipped by that same test.
        $pin = null;
        for ($i = $tsIndex - 1; $i >= 0; $i--) {
            if (preg_match('/^[0-9]{1,20}$/', $parts[$i])) { $pin = ltrim($parts[$i], '0') ?: '0'; break; }
        }
        if ($pin === null) { $skipped++; continue; }

        // Trailing numeric fields, when present, are state and verify mode.
        $after  = array_slice($parts, $tsIndex + 1);
        $after  = array_values(array_filter($after, fn($x) => preg_match('/^-?\d+$/', $x)));
        $state  = isset($after[0]) ? (int)$after[0] : 0;
        $verify = isset($after[1]) ? (int)$after[1] : 0;
        $work   = isset($after[2]) ? (string)$after[2] : null;

        $rows[] = [
            'pin'         => $pin,
            'time'        => $ts,
            'punch_state' => ($state >= 0 && $state <= 5) ? $state : 0,
            'verify_mode' => ($verify >= 0 && $verify <= 255) ? $verify : 0,
            'work_code'   => $work,
            'raw'         => mb_substr($line, 0, 255),
        ];
    }

    return ['rows' => $rows, 'skipped' => $skipped];
}

/**
 * Accepts the timestamp spellings ZKTeco firmware and its exports produce,
 * returning 'Y-m-d H:i:s' or null. Deliberately strict about what it accepts:
 * a mis-read date silently files someone's attendance on the wrong day.
 */
function zkNormalizeTimestamp(string $v): ?string
{
    $v = trim($v);
    if ($v === '') return null;

    $formats = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i',
        'Y-m-d\TH:i:s', 'YmdHis',
    ];
    foreach ($formats as $f) {
        $d = DateTime::createFromFormat($f, $v);
        // createFromFormat is forgiving — verify it round-trips, otherwise
        // '2026-13-45' would be accepted and rolled forward into next year.
        if ($d && $d->format($f) === $v) {
            $y = (int)$d->format('Y');
            if ($y < 2000 || $y > 2100) return null;
            return $d->format('Y-m-d H:i:s');
        }
    }
    return null;
}

/**
 * Collapses one employee's punches for one day into in/out and a status.
 *
 * Punch-state keys (F1–F6) are ignored on purpose. In practice almost nobody
 * presses them, so most devices report state 0 for every scan; trusting the
 * state would file every punch as a check-in and leave clock_out permanently
 * empty. First punch in, last punch out is what the times actually mean.
 *
 * $punches: list of 'Y-m-d H:i:s' strings (any order).
 * Returns ['clock_in','clock_out','hours','status','punches'] or null.
 */
function zkBuildDay(array $punches, array $cfg): ?array
{
    $times = [];
    foreach ($punches as $p) {
        $t = is_array($p) ? ($p['punch_time'] ?? '') : (string)$p;
        $ts = strtotime($t);
        if ($ts) $times[] = $ts;
    }
    if (!$times) return null;
    sort($times);

    // Two taps a few seconds apart are one arrival, not an arrival and a
    // departure. Without this a double-tap sets clock_out to the same minute
    // as clock_in and the day reads as zero hours worked.
    $gap  = max(0, (int)($cfg['dedupe_seconds'] ?? 60));
    $kept = [$times[0]];
    foreach (array_slice($times, 1) as $t) {
        if ($t - end($kept) > $gap) $kept[] = $t;
    }

    $in  = $kept[0];
    $out = count($kept) > 1 ? end($kept) : null;

    $hours = $out ? round(($out - $in) / 3600, 2) : null;

    // Late is measured against the shift start plus the grace period.
    $startTs = strtotime(date('Y-m-d', $in) . ' ' . ($cfg['work_start'] ?? '08:00'));
    $grace   = max(0, (int)($cfg['late_grace_min'] ?? 0)) * 60;
    $status  = ($startTs && $in > $startTs + $grace) ? 'late' : 'present';

    // A short day is a half day — but only once they have clocked out. A
    // missing clock_out means the tap was forgotten, not that they left early,
    // so it must not silently halve someone's pay.
    $minHours = (float)($cfg['min_hours_full'] ?? 0);
    if ($out !== null && $minHours > 0 && $hours !== null && $hours < $minHours) {
        $status = 'half_day';
    }

    return [
        'clock_in'  => date('H:i:s', $in),
        'clock_out' => $out ? date('H:i:s', $out) : null,
        'hours'     => $hours,
        'status'    => $status,
        'punches'   => count($kept),
    ];
}

// ── Database-facing helpers ──────────────────────────────────────────────────

/**
 * The registered device for a serial number.
 *
 * An unrecognised serial is recorded as 'pending' and its punches are stored
 * but left unmapped. The endpoint is public, so treating any serial that turns
 * up as trusted would let anyone POST attendance for anyone. Pending devices
 * are listed in the UI for an HR user to approve.
 */
function zkDeviceFor(PDO $db, string $sn, string $remoteIp = ''): ?array
{
    $sn = trim($sn);
    if ($sn === '') return null;
    try {
        $st = $db->prepare("SELECT * FROM zk_devices WHERE serial_number = ?");
        $st->execute([$sn]);
        $dev = $st->fetch(PDO::FETCH_ASSOC);
        if ($dev) return $dev;

        $db->prepare("INSERT INTO zk_devices (serial_number, name, mode, status, ip_address, notes)
                      VALUES (?,?,'push','pending',?,?)")
           ->execute([$sn, 'Unregistered device ' . $sn, $remoteIp ?: null,
                      'Appeared automatically when the device first connected. Approve it to start recording attendance.']);
        $st->execute([$sn]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('zkDeviceFor: ' . $e->getMessage());
        return null;
    }
}

/** Employee behind a device PIN — device-specific mapping wins over a global one. */
function zkResolvePin(PDO $db, string $sn, string $pin): ?array
{
    static $cache = [];
    $k = $sn . '|' . $pin;
    if (array_key_exists($k, $cache)) return $cache[$k];

    try {
        $st = $db->prepare("SELECT staff_type, staff_id FROM zk_enrollments
                            WHERE device_pin = ? AND device_sn IN (?, '')
                            ORDER BY device_sn = ? DESC LIMIT 1");
        $st->execute([$pin, $sn, $sn]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('zkResolvePin: ' . $e->getMessage());
        $row = null;
    }
    return $cache[$k] = $row;
}

/**
 * Stores parsed punches. Idempotent — the unique key on
 * (device_sn, device_pin, punch_time) means a device that re-sends its log,
 * which they routinely do after a network drop, cannot double-count anyone.
 *
 * Returns ['stored'=>int,'duplicates'=>int,'unmapped'=>int,'days'=>[...]].
 */
function zkStorePunches(PDO $db, string $sn, array $rows, string $source = 'push'): array
{
    $stored = $dupes = $unmapped = 0;
    $days   = [];

    if (!$rows) return ['stored' => 0, 'duplicates' => 0, 'unmapped' => 0, 'days' => []];

    $ins = $db->prepare("INSERT INTO zk_punches
            (device_sn, device_pin, punch_time, punch_state, verify_mode, work_code,
             source, staff_type, staff_id, raw)
         VALUES (?,?,?,?,?,?,?,?,?,?)");

    foreach ($rows as $r) {
        $map = zkResolvePin($db, $sn, (string)$r['pin']);
        if (!$map) $unmapped++;
        try {
            $ins->execute([
                $sn, (string)$r['pin'], $r['time'],
                (int)$r['punch_state'], (int)$r['verify_mode'], $r['work_code'] ?? null,
                $source, $map['staff_type'] ?? null, $map['staff_id'] ?? null,
                $r['raw'] ?? null,
            ]);
            $stored++;
            if ($map) $days[substr($r['time'], 0, 10)] = true;
        } catch (\PDOException $e) {
            // 23000 here is the unique key doing its job on a re-send.
            if ($e->getCode() === '23000') { $dupes++; continue; }
            error_log('zkStorePunches: ' . $e->getMessage());
        }
    }

    if ($stored > 0) {
        try {
            $db->prepare("UPDATE zk_devices
                          SET last_seen_at = NOW(), last_punch_at = NOW(),
                              punch_count = punch_count + ?
                          WHERE serial_number = ?")->execute([$stored, $sn]);
        } catch (\Throwable $_) {}
    }

    return ['stored' => $stored, 'duplicates' => $dupes,
            'unmapped' => $unmapped, 'days' => array_keys($days)];
}

/**
 * Applies a mapping retrospectively. Punches captured before an employee was
 * linked to their PIN sit in the log unattached; without this they would have
 * to be re-imported from the device to count.
 */
function zkBackfillPin(PDO $db, string $sn, string $pin, string $type, int $id): int
{
    try {
        $sql = "UPDATE zk_punches SET staff_type = ?, staff_id = ?, processed = 0
                WHERE device_pin = ? AND staff_id IS NULL";
        $args = [$type, $id, $pin];
        if ($sn !== '') { $sql .= " AND device_sn = ?"; $args[] = $sn; }
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('zkBackfillPin: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Turns punches into attendance_records for a date range.
 *
 * Only ever writes rows that are absent or already marked 'biometric'. A row
 * an HR user set by hand is left exactly as it is — the device is a data
 * source, not an authority that overrules a human correction.
 *
 * Returns ['days'=>int,'written'=>int,'skipped_manual'=>int].
 */
function zkRollupRange(PDO $db, string $from, string $to, ?array $cfg = null): array
{
    $cfg = $cfg ?? zkConfig();
    $written = $skippedManual = 0;

    try {
        $st = $db->prepare("SELECT staff_type, staff_id, DATE(punch_time) AS d, punch_time
                            FROM zk_punches
                            WHERE staff_id IS NOT NULL
                              AND DATE(punch_time) BETWEEN ? AND ?
                            ORDER BY staff_type, staff_id, punch_time");
        $st->execute([$from, $to]);

        $grouped = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $grouped[$r['staff_type'] . '|' . $r['staff_id'] . '|' . $r['d']][] = $r['punch_time'];
        }
        if (!$grouped) return ['days' => 0, 'written' => 0, 'skipped_manual' => 0];

        zkEnsureAttendanceSource($db);

        $lookup = $db->prepare("SELECT id, source FROM attendance_records
                                WHERE staff_type = ? AND staff_id = ? AND attendance_date = ?");
        $upd = $db->prepare("UPDATE attendance_records
                             SET status = ?, clock_in = ?, clock_out = ?, source = 'biometric'
                             WHERE id = ?");
        $ins = $db->prepare("INSERT INTO attendance_records
                (staff_type, staff_id, attendance_date, status, clock_in, clock_out, notes, source)
             VALUES (?,?,?,?,?,?,?, 'biometric')");

        foreach ($grouped as $key => $times) {
            [$type, $id, $date] = explode('|', $key);
            $day = zkBuildDay($times, $cfg);
            if (!$day) continue;

            $lookup->execute([$type, (int)$id, $date]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if (($existing['source'] ?? 'manual') !== 'biometric') { $skippedManual++; continue; }
                $upd->execute([$day['status'], $day['clock_in'], $day['clock_out'], (int)$existing['id']]);
            } else {
                $note = $day['clock_out'] === null ? 'No clock-out recorded on the device' : null;
                $ins->execute([$type, (int)$id, $date, $day['status'],
                               $day['clock_in'], $day['clock_out'], $note]);
            }
            $written++;
        }

        $db->prepare("UPDATE zk_punches SET processed = 1
                      WHERE staff_id IS NOT NULL AND DATE(punch_time) BETWEEN ? AND ?")
           ->execute([$from, $to]);

        return ['days' => count($grouped), 'written' => $written, 'skipped_manual' => $skippedManual];
    } catch (\Throwable $e) {
        error_log('zkRollupRange: ' . $e->getMessage());
        return ['days' => 0, 'written' => 0, 'skipped_manual' => 0, 'error' => $e->getMessage()];
    }
}

/** The roll-up depends on the `source` column; make sure it is there. */
function zkEnsureAttendanceSource(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $col = $db->query("SHOW COLUMNS FROM attendance_records LIKE 'source'")->fetch();
        if (!$col) {
            $db->exec("ALTER TABLE attendance_records
                       ADD COLUMN source ENUM('manual','biometric') NOT NULL DEFAULT 'manual'");
        }
    } catch (\Throwable $_) {}
}

/** Device PINs seen in the log that no employee is linked to yet. */
function zkUnmappedPins(PDO $db, int $limit = 100): array
{
    try {
        $st = $db->prepare("SELECT device_sn, device_pin, COUNT(*) AS punches,
                                   MIN(punch_time) AS first_seen, MAX(punch_time) AS last_seen
                            FROM zk_punches
                            WHERE staff_id IS NULL
                            GROUP BY device_sn, device_pin
                            ORDER BY last_seen DESC
                            LIMIT {$limit}");
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** The URL the device's "server address" must point at. */
function zkPushUrl(): string
{
    return rtrim(BASE_URL, '/') . '/iclock/cdata';
}

} // function_exists guard
