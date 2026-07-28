<?php
/**
 * Showroom lead-capture bootstrap.
 *
 * The public forms (inquiry.php, contact.php) previously wrote into tables that
 * were never created by any migration, with the INSERT wrapped in a silent
 * catch — so submissions were discarded while the visitor was shown a success
 * message. This guarantees the tables exist and gives both endpoints one shared
 * path into the CRM, so a web enquiry becomes a real lead the team can work.
 *
 * Column names deliberately match what modules/showroom/index.php already reads.
 */

/** Inline migrations. Runs once per request; each statement no-ops if applied. */
function showroomLeadsMigrate(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS showroom_inquiries (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            car_id         INT NOT NULL,
            inquiry_name   VARCHAR(150) NOT NULL,
            inquiry_phone  VARCHAR(30)  NULL,
            inquiry_email  VARCHAR(150) NULL,
            message        TEXT NULL,
            status         VARCHAR(20) NOT NULL DEFAULT 'new',
            notes          TEXT NULL,
            responded_by   INT NULL,
            responded_at   DATETIME NULL,
            lead_id        INT NULL,
            created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_car    (car_id),
            INDEX idx_status (status),
            INDEX idx_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $_) {}

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            name         VARCHAR(150) NOT NULL,
            phone        VARCHAR(30)  NULL,
            email        VARCHAR(150) NULL,
            subject      VARCHAR(200) NULL,
            message      TEXT NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'new',
            notes        TEXT NULL,
            responded_by INT NULL,
            responded_at DATETIME NULL,
            lead_id      INT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $_) {}

    // Added after the tables shipped — no-op on fresh installs.
    try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN lead_id INT NULL"); } catch (\Throwable $_) {}
    try { $db->exec("ALTER TABLE contact_messages   ADD COLUMN lead_id INT NULL"); } catch (\Throwable $_) {}
}

/**
 * Create a CRM lead from a public web submission and return its id (0 on failure).
 *
 * Reuses an existing open lead for the same phone/email instead of creating a
 * duplicate, so a customer who enquires about three cars stays one lead with
 * three logged activities rather than cluttering the pipeline.
 */
function showroomCreateLead(
    PDO $db,
    string $name,
    ?string $phone,
    ?string $email,
    string $interestedIn,
    string $summary
): int {
    if ($name === '' || ($phone === null && $email === null)) return 0;

    try {
        // Match an existing lead still in play, by phone or email.
        $lead = null;
        if ($phone || $email) {
            $s = $db->prepare("SELECT id FROM crm_leads
                               WHERE stage NOT IN ('lost','delivered')
                                 AND ((phone IS NOT NULL AND phone <> '' AND phone = ?)
                                   OR (email IS NOT NULL AND email <> '' AND email = ?))
                               ORDER BY id DESC LIMIT 1");
            $s->execute([$phone ?? '', $email ?? '']);
            $lead = $s->fetchColumn();
        }

        if ($lead) {
            $leadId = (int)$lead;
            // Keep the lead surfacing in the pipeline as newly active.
            $db->prepare("UPDATE crm_leads SET updated_at = NOW() WHERE id = ?")->execute([$leadId]);
        } else {
            $db->prepare("INSERT INTO crm_leads (name, phone, email, source, interested_in, stage, notes)
                          VALUES (?,?,?,'website',?, 'new', ?)")
               ->execute([$name, $phone, $email, $interestedIn, $summary]);
            $leadId = (int)$db->lastInsertId();
        }

        // Log the submission on the lead's timeline.
        // crm_activities.summary is VARCHAR(300) — truncate so a long customer
        // message can't fail the insert and lose the timeline entry.
        try {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_at)
                          VALUES (?, 'note', ?, NOW())")
               ->execute([$leadId, mb_substr($summary, 0, 300)]);
        } catch (\Throwable $_) {}

        return $leadId;
    } catch (\Throwable $e) {
        error_log('showroomCreateLead: ' . $e->getMessage());
        return 0;
    }
}

/** Notify the sales/CRM roles that handle inbound web leads. */
function showroomNotifyNewLead(string $title, string $message, string $link): void {
    try {
        require_once __DIR__ . '/../includes/notifications.php';
        notifyRoles(
            ['super_admin', 'admin', 'customer_relations', 'sales_manager', 'sales_officer'],
            'info', $title, $message, $link
        );
    } catch (\Throwable $e) { error_log('showroomNotifyNewLead: ' . $e->getMessage()); }
}
