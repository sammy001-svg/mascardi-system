<?php
/**
 * Visitors Book — schema and shared helpers.
 *
 * Two surfaces share this file:
 *
 *   visitorbook/      the sign-in kiosk at reception. Its own login, no sidebar,
 *                     no modules — a visitor-facing form and nothing else.
 *   modules/visitors/ the record for management: who came, why, and what
 *                     happened to them afterwards.
 *
 * The point of the book is not the log. It is that a visit turns into something:
 *
 *   buying a car   → a CRM lead, handed to a customer relations officer
 *   car service    → a client record, ready for a service booking
 *   seeing someone → a notification to the person they came to see
 *
 * Every visitor row keeps the id of whatever it produced (lead_id, client_id),
 * so a walk-in can always be traced to the deal or the job it became.
 */

if (!function_exists('visitorsMigrate')) {

// 2 — check-out tracking (checked_out_at / checked_out_by).
// 3 — visitors.location_id, so a walk-in is tied to the branch that received it.
// 4 — visitor_kiosk_sessions: one live desk per location, many desks per account.
if (!defined('VISITORS_SCHEMA_VERSION')) define('VISITORS_SCHEMA_VERSION', '4');

/**
 * How long a desk's claim on a location survives without a heartbeat.
 *
 * Comfortably longer than the ping interval below, so a slow network or a
 * momentarily sleeping tablet does not hand its location to someone else.
 */
if (!defined('VISITOR_DESK_TTL')) define('VISITOR_DESK_TTL', 240);   // seconds
if (!defined('VISITOR_DESK_PING')) define('VISITOR_DESK_PING', 60);  // seconds

/** How long the kiosk sits untouched before it clears itself, in seconds. */
if (!defined('VISITOR_KIOSK_IDLE')) define('VISITOR_KIOSK_IDLE', 120);

function visitorPurposes(): array
{
    return [
        'buy_car'      => ['Buy a car',            'fa-car',            '#7e22ce'],
        'car_service'  => ['Car service',          'fa-screwdriver-wrench', '#c2410c'],
        'see_someone'  => ['See someone in office', 'fa-user-tie',      '#2563eb'],
    ];
}

/** Where the visitor heard about the company — feeds marketing attribution. */
function visitorHeardFrom(): array
{
    return ['Walk-in / Passing by', 'Facebook', 'Instagram', 'TikTok', 'Google search',
            'Our website', 'Referred by a friend', 'Returning customer',
            'Radio', 'TV', 'Billboard', 'WhatsApp', 'Other'];
}

function visitorsMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'visitors_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === VISITORS_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    // One table with the purpose-specific columns left nullable, rather than a
    // table per purpose. A visit is a single event and the headline question —
    // how many people came and why — is then one query with no unions.
    $sql = "CREATE TABLE IF NOT EXISTS visitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        first_name VARCHAR(80) NOT NULL,
        middle_name VARCHAR(80) NULL,
        last_name VARCHAR(80) NULL,
        phone VARCHAR(30) NOT NULL,
        id_number VARCHAR(50) NULL,
        email VARCHAR(200) NULL,
        heard_from VARCHAR(60) NULL,
        purpose ENUM('buy_car','car_service','see_someone') NOT NULL,

        -- Buying a car
        car_id INT NULL,
        buy_comment TEXT NULL,

        -- Car service
        svc_make VARCHAR(60) NULL,
        svc_model VARCHAR(60) NULL,
        svc_year VARCHAR(10) NULL,
        svc_reg VARCHAR(40) NULL,
        svc_mileage INT NULL,
        svc_notes TEXT NULL,

        -- Seeing a member of staff
        staff_id INT NULL,
        visit_reason TEXT NULL,

        -- What the visit produced
        lead_id INT NULL,
        client_id INT NULL,
        assigned_to INT NULL,

        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_v_when (created_at),
        KEY idx_v_purpose (purpose, created_at),
        KEY idx_v_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $db->exec($sql); } catch (\Throwable $_) {}

    // ── One desk per location ────────────────────────────────────────────────
    // The visitors book is meant to run on several tablets at once under a single
    // shared login — one per branch. What must not happen is two tablets claiming
    // the SAME branch, because then two people are recording against one desk and
    // neither knows about the other.
    //
    // The rule lives in the UNIQUE key on location_id, not in PHP. Two devices
    // pressing Save in the same second is exactly the case application-level
    // checking gets wrong, and the database is the only thing here that can
    // actually serialise them.
    $desks = "CREATE TABLE IF NOT EXISTS visitor_kiosk_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        location_id INT NOT NULL,
        user_id INT NOT NULL,
        session_hash CHAR(64) NOT NULL,
        device_label VARCHAR(120) NULL,
        ip_address VARCHAR(45) NULL,
        last_seen DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desk_location (location_id),
        KEY idx_desk_session (session_hash),
        KEY idx_desk_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $db->exec($desks); } catch (\Throwable $_) {}

    // Added after the table shipped, so CREATE TABLE IF NOT EXISTS above is a
    // no-op for them on an existing install.
    $columns = [
        "ALTER TABLE visitors ADD COLUMN checked_out_at DATETIME NULL AFTER created_at",
        "ALTER TABLE visitors ADD COLUMN checked_out_by INT NULL AFTER checked_out_at",
        // Answers "who is in the building right now" off the index.
        "ALTER TABLE visitors ADD INDEX idx_v_onsite (checked_out_at, created_at)",
        // Which branch received the visitor. Set from the location the kiosk was
        // signed in to, and what lead allocation is scoped by.
        "ALTER TABLE visitors ADD COLUMN location_id INT NULL AFTER purpose",
        "ALTER TABLE visitors ADD INDEX idx_v_location (location_id, created_at)",
    ];
    foreach ($columns as $c) { try { $db->exec($c); } catch (\Throwable $_) {} }

    // The kiosk logs in as its own account, so the role has to exist before one
    // can be created. Additive — see ensureUserRole().
    if (function_exists('ensureUserRole')) ensureUserRole('visitor_book');

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('visitors_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([VISITORS_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

/** The visitor's name as one string, skipping the parts they left blank. */
function visitorFullName(array $v): string
{
    return trim(implode(' ', array_filter([
        trim((string)($v['first_name']  ?? '')),
        trim((string)($v['middle_name'] ?? '')),
        trim((string)($v['last_name']   ?? '')),
    ])));
}

// ── Locations ────────────────────────────────────────────────────────────────

/** Branches the kiosk can be signed in to. Empty is a valid state. */
function visitorLocations(PDO $db): array
{
    try {
        return $db->query("
            SELECT l.id, l.name, l.type, l.address, l.phone,
                   IFNULL(p.name, '') AS parent_name
            FROM locations l
            LEFT JOIN locations p ON p.id = l.parent_id
            WHERE l.status IS NULL OR l.status = 'active'
            ORDER BY COALESCE(p.name, l.name), l.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

function visitorLocationName(PDO $db, ?int $id): string
{
    if (!$id) return '';
    try {
        $st = $db->prepare("SELECT name FROM locations WHERE id = ?");
        $st->execute([$id]);
        return (string)($st->fetchColumn() ?: '');
    } catch (\Throwable $_) { return ''; }
}

/**
 * The location this kiosk is signed in to, or null.
 *
 * Held in the session rather than on the account, because one login can be moved
 * between desks and the answer belongs to the sitting, not to the user row. The
 * account keeps a copy separately, but only to pre-select the choice next time.
 */
function visitorSessionLocation(): ?int
{
    $id = (int)($_SESSION['vb_location_id'] ?? 0);
    return $id > 0 ? $id : null;
}

// ── Desk claims: one device per location ─────────────────────────────────────

/**
 * This browser's identity for desk purposes.
 *
 * The PHP session id, hashed. Hashed because a raw session id sitting in a table
 * is a stolen session waiting to happen, and only equality is ever needed here.
 *
 * Two tablets sharing one login get different session ids, which is precisely
 * what lets the same account hold several locations at once while still being
 * distinguishable from itself.
 */
function visitorDeskKey(): string
{
    $sid = session_id();
    if ($sid === '' || $sid === false) return '';
    return hash('sha256', 'desk|' . $sid);
}

/** A short, honest description of the device, for the conflict message. */
function visitorDeviceLabel(?string $ua = null): string
{
    $ua = $ua ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return 'an unknown device';

    $os = 'a device';
    if (preg_match('/iPad|Macintosh.*Mobile/i', $ua))      $os = 'an iPad';
    elseif (preg_match('/iPhone/i', $ua))                  $os = 'an iPhone';
    elseif (preg_match('/Android/i', $ua))                  $os = preg_match('/Mobile/i', $ua) ? 'an Android phone' : 'an Android tablet';
    elseif (preg_match('/Windows/i', $ua))                  $os = 'a Windows computer';
    elseif (preg_match('/Macintosh|Mac OS X/i', $ua))       $os = 'a Mac';
    elseif (preg_match('/Linux|CrOS/i', $ua))               $os = 'a computer';

    $br = '';
    if     (preg_match('/Edg\//i', $ua))                       $br = 'Edge';
    elseif (preg_match('/CriOS|Chrome\//i', $ua))              $br = 'Chrome';
    elseif (preg_match('/FxiOS|Firefox\//i', $ua))             $br = 'Firefox';
    elseif (preg_match('/Safari\//i', $ua))                    $br = 'Safari';

    return $br !== '' ? $os . ' (' . $br . ')' : $os;
}

/** Clears claims whose device has stopped checking in. */
function visitorDeskPrune(PDO $db): void
{
    try {
        $db->prepare("DELETE FROM visitor_kiosk_sessions
                      WHERE last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND)")
           ->execute([VISITOR_DESK_TTL]);
    } catch (\Throwable $_) {}
}

/** Whoever currently holds a location, or null. Stale claims are cleared first. */
function visitorDeskHolder(PDO $db, int $locationId): ?array
{
    visitorDeskPrune($db);
    try {
        $st = $db->prepare("SELECT k.*, u.name AS user_name,
                                   TIMESTAMPDIFF(MINUTE, k.created_at, NOW()) AS held_minutes
                            FROM visitor_kiosk_sessions k
                            LEFT JOIN users u ON u.id = k.user_id
                            WHERE k.location_id = ?");
        $st->execute([$locationId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/**
 * Takes this location for this device.
 *
 * Same device re-claiming the same location is a no-op refresh. A device moving
 * between locations releases the one it held first, so a tablet carried from one
 * branch to another does not strand its old claim for the whole TTL.
 *
 * @return array{ok:bool,holder:?array} holder is set only when refused.
 */
function visitorDeskClaim(PDO $db, int $locationId, int $userId): array
{
    $me = visitorDeskKey();
    if ($me === '' || $locationId < 1) return ['ok' => false, 'holder' => null];

    visitorDeskPrune($db);

    // Give up anything this device held elsewhere.
    try {
        $db->prepare("DELETE FROM visitor_kiosk_sessions
                      WHERE session_hash = ? AND location_id <> ?")
           ->execute([$me, $locationId]);
    } catch (\Throwable $_) {}

    $label = visitorDeviceLabel();
    $ip    = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

    // One statement decides it. The unique key means only one device can insert;
    // the ON DUPLICATE branch updates only when the row is already ours, so a
    // rival device's claim is never quietly overwritten.
    try {
        $st = $db->prepare("
            INSERT INTO visitor_kiosk_sessions
                   (location_id, user_id, session_hash, device_label, ip_address, last_seen)
            VALUES (?,?,?,?,?, NOW())
            ON DUPLICATE KEY UPDATE
                user_id      = IF(session_hash = VALUES(session_hash), VALUES(user_id),      user_id),
                device_label = IF(session_hash = VALUES(session_hash), VALUES(device_label),  device_label),
                ip_address   = IF(session_hash = VALUES(session_hash), VALUES(ip_address),    ip_address),
                last_seen    = IF(session_hash = VALUES(session_hash), NOW(),                 last_seen)");
        $st->execute([$locationId, $userId, $me, $label, $ip]);
    } catch (\Throwable $_) {
        return ['ok' => false, 'holder' => visitorDeskHolder($db, $locationId)];
    }

    // Read back who actually holds it — the only trustworthy answer.
    $holder = visitorDeskHolder($db, $locationId);
    if ($holder && hash_equals((string)$holder['session_hash'], $me)) {
        return ['ok' => true, 'holder' => $holder];
    }
    return ['ok' => false, 'holder' => $holder];
}

/**
 * Confirms this device still holds its location and refreshes the claim.
 *
 * Called on every kiosk page load and by the background ping. Returning false
 * means the claim is gone — the device slept past the TTL and another took the
 * location — and the caller sends the operator back to choose again rather than
 * letting two desks record against one branch.
 */
function visitorDeskTouch(PDO $db, int $locationId): bool
{
    $me = visitorDeskKey();
    if ($me === '' || $locationId < 1) return false;
    try {
        $st = $db->prepare("UPDATE visitor_kiosk_sessions SET last_seen = NOW()
                            WHERE location_id = ? AND session_hash = ?");
        $st->execute([$locationId, $me]);
        if ($st->rowCount() > 0) return true;

        // rowCount 0 is ambiguous: either not ours, or ours and already stamped
        // this second. Check before declaring the desk lost.
        $chk = $db->prepare("SELECT session_hash FROM visitor_kiosk_sessions
                             WHERE location_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $chk->execute([$locationId, VISITOR_DESK_TTL]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        return $row && hash_equals((string)$row['session_hash'], $me);
    } catch (\Throwable $_) {
        // A database hiccup must not throw reception off the desk.
        return true;
    }
}

/** Releases whatever this device holds. Used on sign-out. */
function visitorDeskRelease(PDO $db): void
{
    $me = visitorDeskKey();
    if ($me === '') return;
    try {
        $db->prepare("DELETE FROM visitor_kiosk_sessions WHERE session_hash = ?")->execute([$me]);
    } catch (\Throwable $_) {}
}

/** Every desk currently signed in, for the management module. */
function visitorActiveDesks(PDO $db): array
{
    visitorDeskPrune($db);
    try {
        return $db->query("
            SELECT k.*, l.name AS location_name, u.name AS user_name,
                   TIMESTAMPDIFF(MINUTE, k.created_at, NOW()) AS held_minutes
            FROM visitor_kiosk_sessions k
            LEFT JOIN locations l ON l.id = k.location_id
            LEFT JOIN users u ON u.id = k.user_id
            ORDER BY l.name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/**
 * Which customer relations officer gets the next walk-in lead.
 *
 * Not a rotating pointer: whoever currently carries the fewest open leads takes
 * the next one, and ties go to whoever was given a lead least recently. A plain
 * round robin keeps handing work to someone on leave, and drifts permanently out
 * of balance the moment one officer's leads are reassigned. Counting live load
 * every time is self-correcting.
 *
 * On location
 * -----------
 * A visitor who walks into a branch expects to be followed up by someone at that
 * branch, so officers there are tried first. The tiers then widen: same location
 * before role, because a sales person standing in front of the customer is more
 * use than a customer relations officer in another town.
 *
 * The last tiers drop the location entirely. That is deliberate — an unassigned
 * walk-in is a lead nobody follows up, which is worse than one followed up from
 * the wrong branch. It also keeps the book working before locations have been
 * set up or staff assigned to them, which is the state most installs start in.
 */
function visitorNextCrmOfficer(PDO $db, ?int $locationId = null): ?int
{
    $roleTiers = [
        ['customer_relations'],
        ['sales_person', 'sales_officer'],
        ['sales_manager'],
    ];

    // Each pass is (roles, restrict to this location?).
    $passes = [];
    if ($locationId) foreach ($roleTiers as $r) $passes[] = [$r, true];
    foreach ($roleTiers as $r)                  $passes[] = [$r, false];

    foreach ($passes as [$roles, $scoped]) {
        $in   = implode(',', array_fill(0, count($roles), '?'));
        $args = $roles;
        $where = "u.role IN ({$in}) AND u.status = 'active'";
        if ($scoped) { $where .= " AND u.location_id = ?"; $args[] = $locationId; }
        try {
            $st = $db->prepare("
                SELECT u.id
                FROM users u
                LEFT JOIN crm_leads l
                       ON l.assigned_to = u.id
                      AND (l.stage IS NULL OR l.stage NOT IN ('won','lost','delivered'))
                WHERE {$where}
                GROUP BY u.id
                ORDER BY COUNT(l.id) ASC,
                         COALESCE(MAX(l.created_at), '1970-01-01') ASC,
                         u.id ASC
                LIMIT 1");
            $st->execute($args);
            $id = $st->fetchColumn();
            if ($id) return (int)$id;
        } catch (\Throwable $_) {}
    }
    return null;
}

/**
 * Staff a visitor can ask for by name at reception.
 *
 * `in_today` says whether they have been seen in the system today, which is used
 * to warn reception that the person asked for may not be on site — better to
 * find out at the desk than after the visitor has waited twenty minutes.
 */
function visitorStaffList(PDO $db): array
{
    // last_seen is maintained by ordinary page activity; last_login only moves on
    // a fresh sign-in, so someone who stayed signed in from yesterday still shows
    // as present. Take the later of the two.
    try {
        return $db->query("
            SELECT id, name, role,
                   (DATE(GREATEST(COALESCE(last_seen,'1970-01-01'),
                                  COALESCE(last_login,'1970-01-01'))) = CURDATE()) AS in_today
            FROM users
            WHERE status = 'active' AND role <> 'visitor_book'
            ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {
        try {
            return $db->query("SELECT id, name, role, 1 AS in_today FROM users
                               WHERE status = 'active' AND role <> 'visitor_book'
                               ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $_) { return []; }
    }
}

// ── Check-out ────────────────────────────────────────────────────────────────

/**
 * Everyone signed in and not yet signed out.
 *
 * Scoped to today by default. A visit left open overnight is almost always a
 * forgotten check-out rather than someone still in the building, and carrying
 * those forward would make the on-site figure meaningless.
 */
function visitorsOnSite(PDO $db, bool $todayOnly = true): array
{
    $where = 'v.checked_out_at IS NULL' . ($todayOnly ? ' AND DATE(v.created_at) = CURDATE()' : '');
    try {
        return $db->query("
            SELECT v.*, u.name AS staff_name,
                   TIMESTAMPDIFF(MINUTE, v.created_at, NOW()) AS minutes_here
            FROM visitors v
            LEFT JOIN users u ON u.id = v.staff_id
            WHERE {$where}
            ORDER BY v.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** Visits left open from a previous day — a forgotten check-out, to be tidied. */
function visitorsStale(PDO $db): array
{
    try {
        return $db->query("
            SELECT v.*, TIMESTAMPDIFF(HOUR, v.created_at, NOW()) AS hours_open
            FROM visitors v
            WHERE v.checked_out_at IS NULL AND DATE(v.created_at) < CURDATE()
            ORDER BY v.created_at DESC
            LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/**
 * Signs a visitor out.
 *
 * Only ever stamps a visit that is still open, so a second attempt cannot
 * overwrite the original departure time with a later one.
 *
 * @return bool Whether this call is the one that checked them out.
 */
function visitorCheckOut(PDO $db, int $visitorId, ?int $byUserId = null): bool
{
    try {
        $st = $db->prepare("UPDATE visitors SET checked_out_at = NOW(), checked_out_by = ?
                            WHERE id = ? AND checked_out_at IS NULL");
        $st->execute([$byUserId ?: null, $visitorId]);
        return $st->rowCount() > 0;
    } catch (\Throwable $_) { return false; }
}

/**
 * The open visit for a phone number today, for self-service check-out at the
 * kiosk. Matching on phone means the visitor identifies themselves without the
 * screen having to list everybody who is on site to whoever is standing there.
 */
function visitorOpenVisitByPhone(PDO $db, string $phone): ?array
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 7) return null;
    try {
        // Compare on the last 9 digits so 0711…, +254711… and 254711… all match.
        $tail = substr($digits, -9);
        $st = $db->prepare("
            SELECT * FROM visitors
            WHERE checked_out_at IS NULL
              AND DATE(created_at) = CURDATE()
              AND RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ?
            ORDER BY created_at DESC LIMIT 1");
        $st->execute([$tail]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/**
 * A previous visitor's details, for prefilling the form when they come back.
 *
 * Deliberately narrow. It returns the fields the form needs and nothing else —
 * no visit dates, no purposes, no history. The kiosk stands in a public place, so
 * anyone can type a phone number into it; the most this can confirm is that a
 * number has been here before, and it will not say when or what for.
 */
function visitorLookupByPhone(PDO $db, string $phone): ?array
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 9) return null;   // full number only, never a prefix
    try {
        $tail = substr($digits, -9);
        $st = $db->prepare("
            SELECT first_name, middle_name, last_name, phone, id_number, email, heard_from
            FROM visitors
            WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ?
            ORDER BY created_at DESC LIMIT 1");
        $st->execute([$tail]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/**
 * Cars a visitor can point at. Only what is genuinely on the yard and public —
 * the same rule the website uses, so a visitor is never shown something that is
 * sold, reserved, or still on the water.
 */
function visitorSelectableCars(PDO $db, int $limit = 60): array
{
    try {
        $st = $db->prepare("
            SELECT c.id, c.make, c.model, c.year, c.color, c.body_type, c.transmission,
                   c.fuel_type, c.mileage, c.engine_cc, c.registration_number,
                   IFNULL(c.offer_price, 0) AS offer_price,
                   IFNULL(c.asking_price, 0) AS asking_price,
                   (SELECT file_path FROM car_images
                    WHERE car_id = c.id AND is_primary = 1 LIMIT 1) AS primary_image
            FROM cars c
            WHERE c.car_type IN ('inventory','sale_on_behalf')
              AND c.show_on_website = 1
              AND (c.status IS NULL OR c.status NOT IN ('delivered','sold','reserved','in_transit'))
            ORDER BY c.featured DESC, c.created_at DESC
            LIMIT " . (int)$limit);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/** The price to show on a car card — the offer if there is one, else the ask. */
function visitorCarPrice(array $car): ?float
{
    $offer = (float)($car['offer_price'] ?? 0);
    if ($offer > 0) return $offer;
    $ask = (float)($car['asking_price'] ?? 0);
    return $ask > 0 ? $ask : null;
}

// ── Allocation emails ────────────────────────────────────────────────────────

/**
 * Emails sent when a walk-in is handed to a customer relations officer: a welcome
 * to the visitor naming who will look after them, and a nudge to the officer that
 * somebody is waiting.
 *
 * On timing
 * ---------
 * These are sent AFTER the response has gone to the browser (see
 * visitorFlushThenSend). SMTP here is a blocking socket with a 15-second
 * connect timeout, and two messages in the request would leave reception staring
 * at a spinner with a customer in front of them. The officer gets an in-system
 * notification inside the request, which is the part that has to be instant.
 *
 * @return array{visitor:?bool,officer:?bool} null where there was no address.
 */
function visitorSendAllocationEmails(PDO $db, int $visitorId): array
{
    $out = ['visitor' => null, 'officer' => null];
    require_once __DIR__ . '/../../includes/mailer.php';

    try {
        $st = $db->prepare("
            SELECT v.*, u.name AS officer_name, u.email AS officer_email,
                   l.name AS location_name,
                   TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS car_label
            FROM visitors v
            LEFT JOIN users u ON u.id = v.assigned_to
            LEFT JOIN locations l ON l.id = v.location_id
            LEFT JOIN cars c ON c.id = v.car_id
            WHERE v.id = ?");
        $st->execute([$visitorId]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('visitorSendAllocationEmails: ' . $e->getMessage());
        return $out;
    }
    if (!$v || empty($v['assigned_to'])) return $out;

    $company  = getSetting('company_name',  'Mascardi');
    $coPhone  = getSetting('company_phone', '');
    $visitor  = visitorFullName($v);
    $officer  = trim((string)($v['officer_name'] ?? ''));
    $where    = trim((string)($v['location_name'] ?? ''));
    $car      = trim((string)($v['car_label'] ?? ''));

    // ── To the visitor ───────────────────────────────────────────────────────
    $email = trim((string)($v['email'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body = visitorEmailShell(
            'Welcome to ' . $company,
            '<p style="font-size:15px;color:#111;margin:0 0 14px">Dear '
                . htmlspecialchars($v['first_name'] ?: 'Customer') . ',</p>'
            . '<p style="font-size:15px;color:#333;margin:0 0 14px">Thank you for visiting '
                . htmlspecialchars($company) . ($where !== '' ? ' at ' . htmlspecialchars($where) : '')
                . ' today. It was a pleasure to have you with us.</p>'
            . ($officer !== ''
                ? '<p style="font-size:15px;color:#333;margin:0 0 14px">Your enquiry is being looked '
                  . 'after by <strong>' . htmlspecialchars($officer) . '</strong>, who will be in touch '
                  . 'shortly to help you with the next steps.</p>'
                : '')
            . ($car !== ''
                ? '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:14px;color:#333">'
                  . '<tr><td style="padding:7px 0;width:110px;color:#666">Vehicle</td>'
                  . '<td style="padding:7px 0"><strong>' . htmlspecialchars($car) . '</strong></td></tr>'
                  . ($officer !== '' ? '<tr><td style="padding:7px 0;color:#666">Looking after you</td>'
                      . '<td style="padding:7px 0">' . htmlspecialchars($officer) . '</td></tr>' : '')
                  . '</table>'
                : '')
            . '<p style="font-size:15px;color:#333;margin:0 0 14px">If you have any questions in the '
                . 'meantime, do reply to this email'
                . ($coPhone !== '' ? ' or call us on <strong>' . htmlspecialchars($coPhone) . '</strong>' : '')
                . '.</p>'
            . '<p style="font-size:15px;color:#333;margin:0">Warm regards,<br>'
                . htmlspecialchars($company) . '</p>',
            $company
        );
        $res = sendMail($email, $visitor, 'Welcome to ' . $company, $body, 'visitor', $visitorId);
        $out['visitor'] = !empty($res['ok']);
    }

    // ── To the officer ───────────────────────────────────────────────────────
    $oEmail = trim((string)($v['officer_email'] ?? ''));
    if ($oEmail !== '' && filter_var($oEmail, FILTER_VALIDATE_EMAIL)) {
        $link = rtrim(BASE_URL, '/') . '/modules/crm/view_lead.php?id=' . (int)$v['lead_id'];
        $body = visitorEmailShell(
            'A visitor has been allocated to you',
            '<p style="font-size:15px;color:#111;margin:0 0 14px">Hi '
                . htmlspecialchars($officer ?: 'there') . ',</p>'
            . '<p style="font-size:15px;color:#333;margin:0 0 16px">'
                . '<strong>' . htmlspecialchars($visitor) . '</strong> has signed in at reception'
                . ($where !== '' ? ' at <strong>' . htmlspecialchars($where) . '</strong>' : '')
                . ' and has been allocated to you. <strong>Please attend to them.</strong></p>'
            . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px;font-size:14px;color:#333">'
            . '<tr><td style="padding:7px 0;width:110px;color:#666">Visitor</td>'
                . '<td style="padding:7px 0"><strong>' . htmlspecialchars($visitor) . '</strong></td></tr>'
            . '<tr><td style="padding:7px 0;color:#666">Phone</td>'
                . '<td style="padding:7px 0">' . htmlspecialchars((string)$v['phone']) . '</td></tr>'
            . ($car !== '' ? '<tr><td style="padding:7px 0;color:#666">Interested in</td>'
                . '<td style="padding:7px 0">' . htmlspecialchars($car) . '</td></tr>' : '')
            . ($where !== '' ? '<tr><td style="padding:7px 0;color:#666">Location</td>'
                . '<td style="padding:7px 0">' . htmlspecialchars($where) . '</td></tr>' : '')
            . '<tr><td style="padding:7px 0;color:#666">Signed in</td>'
                . '<td style="padding:7px 0">' . date('D j M Y, H:i', strtotime($v['created_at'])) . '</td></tr>'
            . '</table>'
            . (trim((string)($v['buy_comment'] ?? '')) !== ''
                ? '<p style="font-size:14px;color:#333;margin:0 0 16px;padding:12px 14px;'
                  . 'background:#f8fafc;border-left:3px solid #7e22ce">'
                  . '<em>' . nl2br(htmlspecialchars($v['buy_comment'])) . '</em></p>'
                : '')
            . ($v['lead_id']
                ? '<p style="margin:0 0 8px"><a href="' . htmlspecialchars($link) . '" '
                  . 'style="display:inline-block;background:#7e22ce;color:#fff;text-decoration:none;'
                  . 'padding:11px 22px;border-radius:8px;font-weight:600;font-size:14px">'
                  . 'Open the lead</a></p>'
                : ''),
            $company
        );
        $res = sendMail($oEmail, $officer, 'Visitor allocated to you: ' . $visitor, $body, 'visitor', $visitorId);
        $out['officer'] = !empty($res['ok']);
    }

    return $out;
}

/** Shared wrapper so both emails look like they came from the same company. */
function visitorEmailShell(string $heading, string $inner, string $company): string
{
    return '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;'
         . 'padding:0 4px">'
         . '<div style="border-bottom:3px solid #7e22ce;padding:0 0 12px;margin:0 0 22px">'
         . '<div style="font-size:12px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;'
         . 'color:#7e22ce">' . htmlspecialchars($company) . '</div>'
         . '<h1 style="font-size:19px;font-weight:800;color:#111;margin:6px 0 0">'
         . htmlspecialchars($heading) . '</h1></div>'
         . $inner
         . '<p style="font-size:11.5px;color:#94a3b8;margin:26px 0 0;padding-top:14px;'
         . 'border-top:1px solid #e2e8f0">Sent automatically from the ' . htmlspecialchars($company)
         . ' visitors book.</p></div>';
}

/**
 * Ends the request, then runs $work with the browser already gone.
 *
 * The kiosk must feel instant — a member of the public is standing at the desk —
 * but the emails go over a blocking SMTP socket. Under PHP-FPM the connection is
 * closed properly; elsewhere the response is flushed and the work simply runs on
 * with the abort handler disabled so a closing browser cannot kill it.
 */
function visitorFlushThenSend(callable $work): void
{
    @ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        if (!headers_sent()) header('Content-Length: 0');
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
    }
    try { $work(); } catch (\Throwable $e) { error_log('visitor emails: ' . $e->getMessage()); }
}

/** Headline counts for the management module. */
function visitorStats(PDO $db): array
{
    $out = ['today' => 0, 'week' => 0, 'month' => 0, 'total' => 0, 'by_purpose' => []];
    try {
        $r = $db->query("SELECT
                COUNT(*) AS total,
                SUM(DATE(created_at) = CURDATE()) AS today,
                SUM(YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)) AS week,
                SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month
              FROM visitors")->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['total','today','week','month'] as $k) $out[$k] = (int)($r[$k] ?? 0);

        foreach ($db->query("SELECT purpose, COUNT(*) c FROM visitors GROUP BY purpose") as $row) {
            $out['by_purpose'][$row['purpose']] = (int)$row['c'];
        }
        $out['on_site'] = (int)$db->query("SELECT COUNT(*) FROM visitors
            WHERE checked_out_at IS NULL AND DATE(created_at) = CURDATE()")->fetchColumn();
        $out['stale'] = (int)$db->query("SELECT COUNT(*) FROM visitors
            WHERE checked_out_at IS NULL AND DATE(created_at) < CURDATE()")->fetchColumn();
    } catch (\Throwable $_) {}
    return $out;
}

} // function_exists('visitorsMigrate')
