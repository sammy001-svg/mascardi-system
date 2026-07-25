<?php
/**
 * Trade-In & Sale on Behalf — shared bootstrap.
 *
 * Consignment vehicles live in the normal `cars` table (so they inherit photos,
 * documents, workshop jobs and the showroom) and are distinguished by
 * car_type = 'sale_on_behalf' | 'trade_in'. The commercial side of the deal —
 * owner, commission, agreement dates, settlement — lives in `consignments`.
 */

/** Inline migrations. Runs once per request; every statement is a silent no-op if already applied. */
function tradeInMigrate(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Extend car_type so consignment vehicles flow through existing features.
    try {
        $db->exec("ALTER TABLE cars MODIFY COLUMN car_type
                   ENUM('inventory','client','trade_in','sale_on_behalf')
                   NOT NULL DEFAULT 'inventory'");
    } catch (\Throwable $_) {}

    // Columns the showroom relies on (already added by cars module, repeated for safety).
    try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
    try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS consignments (
            id                   INT AUTO_INCREMENT PRIMARY KEY,
            car_id               INT NOT NULL,
            deal_type            ENUM('sale_on_behalf','trade_in') NOT NULL DEFAULT 'sale_on_behalf',
            reference            VARCHAR(50) NULL,

            -- Owner (the person who brought the vehicle)
            owner_name           VARCHAR(150) NOT NULL,
            owner_phone          VARCHAR(30)  NULL,
            owner_email          VARCHAR(150) NULL,
            owner_id_number      VARCHAR(50)  NULL,
            owner_address        VARCHAR(255) NULL,
            client_id            INT NULL,

            -- Commercials
            owner_expected_price DECIMAL(15,2) NULL,   -- net amount the owner wants
            listing_price        DECIMAL(15,2) NULL,   -- what we advertise it at
            commission_type      ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
            commission_value     DECIMAL(15,2) NOT NULL DEFAULT 0,

            -- Trade-in specifics
            valuation_amount     DECIMAL(15,2) NULL,   -- our appraisal
            trade_in_value       DECIMAL(15,2) NULL,   -- allowance credited to the customer
            against_car_id       INT NULL,             -- vehicle they bought from us

            -- Agreement
            agreement_date       DATE NULL,
            expiry_date          DATE NULL,
            status               ENUM('active','sold','withdrawn','expired') NOT NULL DEFAULT 'active',

            -- Settlement
            sold_price           DECIMAL(15,2) NULL,
            sold_date            DATE NULL,
            commission_amount    DECIMAL(15,2) NULL,
            payout_amount        DECIMAL(15,2) NULL,
            payout_paid          DECIMAL(15,2) NOT NULL DEFAULT 0,
            payout_status        ENUM('pending','partial','paid') NOT NULL DEFAULT 'pending',
            payout_date          DATE NULL,
            payout_reference     VARCHAR(100) NULL,

            notes                TEXT NULL,
            created_by           INT NULL,
            created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_car        (car_id),
            INDEX idx_type_status(deal_type, status),
            INDEX idx_owner      (owner_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $_) {}

    // Added after the table shipped — no-op on fresh installs.
    try { $db->exec("ALTER TABLE consignments ADD COLUMN payout_paid DECIMAL(15,2) NOT NULL DEFAULT 0"); } catch (\Throwable $_) {}
}

/** Deal-type labels/colours, used for badges across the module. */
function consignmentTypes(): array {
    return [
        'sale_on_behalf' => ['label' => 'Sale on Behalf', 'icon' => 'fa-handshake',      'color' => '#0ea5e9'],
        'trade_in'       => ['label' => 'Trade-In',       'icon' => 'fa-right-left',     'color' => '#f59e0b'],
    ];
}

/** Status labels/colours. */
function consignmentStatuses(): array {
    return [
        'active'    => ['label' => 'Active',    'color' => '#16a34a'],
        'sold'      => ['label' => 'Sold',      'color' => '#2563eb'],
        'withdrawn' => ['label' => 'Withdrawn', 'color' => '#64748b'],
        'expired'   => ['label' => 'Expired',   'color' => '#dc2626'],
    ];
}

/**
 * Commission earned on a deal. Percent commissions are calculated against the
 * actual sale price when known, otherwise against the listing price.
 */
function consignmentCommission(array $c, ?float $salePrice = null): float {
    $price = $salePrice ?? (float)($c['sold_price'] ?: $c['listing_price'] ?: 0);
    if ($price <= 0) return 0.0;
    if (($c['commission_type'] ?? 'percent') === 'fixed') {
        return round(min((float)$c['commission_value'], $price), 2);
    }
    return round($price * (float)$c['commission_value'] / 100, 2);
}

/** What the owner receives after commission. Never negative. */
function consignmentPayout(array $c, ?float $salePrice = null): float {
    $price = $salePrice ?? (float)($c['sold_price'] ?: $c['listing_price'] ?: 0);
    if ($price <= 0) return 0.0;
    return round(max(0, $price - consignmentCommission($c, $price)), 2);
}

/** Next reference number, e.g. SOB-0007 / TRD-0007. */
function consignmentNextRef(PDO $db, string $dealType): string {
    $prefix = $dealType === 'trade_in' ? 'TRD' : 'SOB';
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM consignments WHERE deal_type = " . $db->quote($dealType))->fetchColumn();
    } catch (\Throwable $_) { $n = 0; }
    return $prefix . '-' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
}

/** Load a consignment joined with its vehicle. Returns null when not found. */
function consignmentFind(PDO $db, int $id): ?array {
    try {
        $s = $db->prepare("
            SELECT cs.*,
                   c.make, c.model, c.year, c.color, c.chassis_number, c.registration_number,
                   c.body_type, c.transmission, c.fuel_type, c.mileage, c.engine_cc,
                   c.asking_price, c.offer_price, c.show_on_website, c.status AS car_status,
                   c.car_type, c.notes AS car_notes, c.location_id,
                   u.name AS created_by_name,
                   (SELECT file_path FROM car_images WHERE car_id = cs.car_id AND is_primary = 1 LIMIT 1) AS primary_image
            FROM consignments cs
            JOIN cars c        ON c.id = cs.car_id
            LEFT JOIN users u  ON u.id = cs.created_by
            WHERE cs.id = ?
        ");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}
