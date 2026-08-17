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
if (!defined('VISITORS_SCHEMA_VERSION')) define('VISITORS_SCHEMA_VERSION', '3');

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
