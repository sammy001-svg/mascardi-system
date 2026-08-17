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

if (!defined('VISITORS_SCHEMA_VERSION')) define('VISITORS_SCHEMA_VERSION', '1');

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

/**
 * Which customer relations officer gets the next walk-in lead.
 *
 * Not a rotating pointer: whoever currently carries the fewest open leads takes
 * the next one, and ties go to whoever was given a lead least recently. A plain
 * round robin keeps handing work to someone on leave, and drifts permanently out
 * of balance the moment one officer's leads are reassigned. Counting live load
 * every time is self-correcting.
 *
 * Falls back through the sales roles so a walk-in is never left unassigned just
 * because no one holds the customer_relations role.
 */
function visitorNextCrmOfficer(PDO $db): ?int
{
    $tiers = [
        ['customer_relations'],
        ['sales_person', 'sales_officer'],
        ['sales_manager'],
    ];
    foreach ($tiers as $roles) {
        $in = implode(',', array_fill(0, count($roles), '?'));
        try {
            $st = $db->prepare("
                SELECT u.id
                FROM users u
                LEFT JOIN crm_leads l
                       ON l.assigned_to = u.id
                      AND (l.stage IS NULL OR l.stage NOT IN ('won','lost','delivered'))
                WHERE u.role IN ({$in}) AND u.status = 'active'
                GROUP BY u.id
                ORDER BY COUNT(l.id) ASC,
                         COALESCE(MAX(l.created_at), '1970-01-01') ASC,
                         u.id ASC
                LIMIT 1");
            $st->execute($roles);
            $id = $st->fetchColumn();
            if ($id) return (int)$id;
        } catch (\Throwable $_) {}
    }
    return null;
}

/** Staff a visitor can ask for by name at reception. */
function visitorStaffList(PDO $db): array
{
    try {
        return $db->query("SELECT id, name, role FROM users
                           WHERE status = 'active' AND role <> 'visitor_book'
                           ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
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
    } catch (\Throwable $_) {}
    return $out;
}

} // function_exists('visitorsMigrate')
