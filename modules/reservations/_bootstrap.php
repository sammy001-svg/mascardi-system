<?php
/**
 * Reservations — cancellation schema and helpers.
 *
 * Cancelling a reservation undoes real work: a deposit was taken, a vehicle was
 * held off the market, and a customer relations officer told a customer it was
 * theirs. So it is never a silent reset. Every cancellation records why, who did
 * it, and what the reservation was worth at the time, and the officer who took it
 * is told directly.
 *
 * Why a table rather than columns on crm_leads
 * -------------------------------------------
 * A lead can be reserved, cancelled, and reserved again. Columns would keep only
 * the most recent cancellation and quietly lose the rest, which is the opposite
 * of what an audit of revoked deposits is for. The figures are snapshotted here
 * because cancelling clears them from the lead — without the snapshot there would
 * be no record of what was actually given up.
 */

if (!function_exists('reservationsMigrate')) {

if (!defined('RESERVATIONS_SCHEMA_VERSION')) define('RESERVATIONS_SCHEMA_VERSION', '1');

/** Reasons offered in the dialog. Free text is still required alongside. */
function reservationCancelReasons(): array
{
    return [
        'customer_withdrew'  => 'Customer changed their mind / withdrew',
        'no_payment'         => 'Balance not paid within the agreed time',
        'deposit_refunded'   => 'Deposit refunded to the customer',
        'financing_declined' => 'Financing or loan declined',
        'vehicle_issue'      => 'Problem found with the vehicle',
        'better_offer'       => 'Vehicle released for another buyer',
        'duplicate'          => 'Duplicate or mistaken reservation',
        'other'              => 'Other',
    ];
}

function reservationsMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reservations_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === RESERVATIONS_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $sql = "CREATE TABLE IF NOT EXISTS reservation_cancellations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        car_id INT NULL,
        client_id INT NULL,
        assigned_to INT NULL,
        reason_code VARCHAR(40) NULL,
        reason TEXT NOT NULL,
        deposit_amount DECIMAL(15,2) NULL,
        agreed_sale_price DECIMAL(15,2) NULL,
        vehicle_label VARCHAR(180) NULL,
        cancelled_by INT NOT NULL,
        cancelled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        acknowledged_at DATETIME NULL,
        KEY idx_rc_lead (lead_id, cancelled_at),
        KEY idx_rc_agent (assigned_to, cancelled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $db->exec($sql); } catch (\Throwable $_) {}

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('reservations_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([RESERVATIONS_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

/**
 * Who may cancel. Deliberately narrow: this releases a held deposit and a
 * vehicle. isSuperAdmin() is used rather than a strict role comparison — the
 * previous revoke tested $me['role'] === 'admin', which excluded super_admin
 * from the one action it was most obviously meant to have.
 */
function reservationCanCancel(): bool
{
    return isSuperAdmin() || authRole() === 'admin';
}

/** The most recent cancellation on a lead, for showing the officer why. */
function reservationLastCancellation(PDO $db, int $leadId): ?array
{
    try {
        $st = $db->prepare("SELECT rc.*, u.name AS cancelled_by_name
                            FROM reservation_cancellations rc
                            LEFT JOIN users u ON u.id = rc.cancelled_by
                            WHERE rc.lead_id = ?
                            ORDER BY rc.cancelled_at DESC LIMIT 1");
        $st->execute([$leadId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

/**
 * Cancels a reservation: frees the vehicle, resets the lead, records why, and
 * returns what the caller needs to tell the officer who took it.
 *
 * The reason is required by this function, not merely by the form. A cancellation
 * with no explanation is the thing this feature exists to prevent, and a second
 * caller added later must not be able to skip it.
 *
 * @return array{ok:bool,error:string,cancellation_id:int,notify:?array}
 */
function reservationCancel(PDO $db, int $leadId, string $reason, string $reasonCode, int $byUserId): array
{
    $fail = fn(string $m) => ['ok' => false, 'error' => $m, 'cancellation_id' => 0, 'notify' => null];

    $reason = trim($reason);
    if ($reason === '')         return $fail('Give a reason for the cancellation.');
    if (mb_strlen($reason) < 5) return $fail('Give a fuller reason — the officer needs to understand why.');
    if (!isset(reservationCancelReasons()[$reasonCode])) $reasonCode = 'other';

    try {
        $st = $db->prepare("
            SELECT l.*, u.name AS agent_name, u.email AS agent_email,
                   TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS vehicle_label,
                   c.registration_number
            FROM crm_leads l
            LEFT JOIN users u ON u.id = l.assigned_to
            LEFT JOIN cars  c ON c.id = l.pinned_car_id
            WHERE l.id = ?");
        $st->execute([$leadId]);
        $lead = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { return $fail('Could not read the reservation: ' . $e->getMessage()); }

    if (!$lead) return $fail('That lead no longer exists.');

    $isReserved = ($lead['stage'] ?? '') === 'reserved'
               || ($lead['reservation_status'] ?? '') === 'pending_approval';
    if (!$isReserved) return $fail('That lead is not currently reserved.');

    $carId = (int)($lead['pinned_car_id'] ?? 0);
    $label = trim((string)($lead['vehicle_label'] ?? ''));
    if ($label !== '' && !empty($lead['registration_number'])) {
        $label .= ' (' . $lead['registration_number'] . ')';
    }

    try {
        $db->beginTransaction();

        // Free the vehicle, but only if it is still held by this reservation —
        // never stamp a free status over a car that has since been sold.
        //
        // The status comes from carAvailableStatus() rather than the literal
        // 'available': that word is not in the cars.status ENUM here, and with
        // strict mode off it is silently coerced to '', leaving the car with no
        // status and dropping it out of listings that filter by state.
        if ($carId) {
            $db->prepare("UPDATE cars SET status = ?, updated_at = NOW()
                          WHERE id = ? AND status = 'reserved'")
               ->execute([carAvailableStatus($db), $carId]);
        }

        // Snapshot before the reset below wipes the figures off the lead.
        $db->prepare("INSERT INTO reservation_cancellations
                (lead_id, car_id, client_id, assigned_to, reason_code, reason,
                 deposit_amount, agreed_sale_price, vehicle_label, cancelled_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $leadId, $carId ?: null, (int)($lead['client_id'] ?? 0) ?: null,
               (int)($lead['assigned_to'] ?? 0) ?: null,
               $reasonCode, $reason,
               $lead['deposit_amount'] !== null ? (float)$lead['deposit_amount'] : null,
               $lead['agreed_sale_price'] !== null ? (float)$lead['agreed_sale_price'] : null,
               $label ?: null, $byUserId,
           ]);
        $cid = (int)$db->lastInsertId();

        $sets = "stage = 'active', pinned_car_id = NULL, deposit_amount = NULL,
                 deposit_date = NULL, deposit_notes = NULL, agreed_sale_price = NULL,
                 due_date = NULL, updated_at = NOW()";
        // Only touch reservation_status where that column exists.
        try {
            if ($db->query("SHOW COLUMNS FROM crm_leads LIKE 'reservation_status'")->fetch()) {
                $sets .= ", reservation_status = NULL";
            }
        } catch (\Throwable $_) {}
        $db->prepare("UPDATE crm_leads SET {$sets} WHERE id = ?")->execute([$leadId]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('reservationCancel: ' . $e->getMessage());
        return $fail('Could not cancel the reservation: ' . $e->getMessage());
    }

    return [
        'ok' => true, 'error' => '', 'cancellation_id' => $cid,
        'notify' => [
            'lead_id'      => $leadId,
            'agent_id'     => (int)($lead['assigned_to'] ?? 0),
            'agent_name'   => (string)($lead['agent_name'] ?? ''),
            'agent_email'  => (string)($lead['agent_email'] ?? ''),
            'customer'     => (string)($lead['name'] ?? ''),
            'vehicle'      => $label,
            'deposit'      => $lead['deposit_amount'] !== null ? (float)$lead['deposit_amount'] : null,
            'reason'       => $reason,
            'reason_label' => reservationCancelReasons()[$reasonCode] ?? 'Other',
        ],
    ];
}

/**
 * Tells the officer who took the reservation that it has been cancelled, and why.
 *
 * The in-system notification goes first because it always lands. The email is
 * best effort and skipped when the officer has no address on file.
 */
function reservationNotifyCancelled(PDO $db, array $n, string $byName): void
{
    $agentId = (int)($n['agent_id'] ?? 0);
    if (!$agentId) return;   // nobody owned it, so nobody to tell

    $link  = rtrim(BASE_URL, '/') . '/modules/crm/view_lead.php?id=' . (int)$n['lead_id'];
    $money = fn($v) => $v === null ? '' : 'KES ' . number_format((float)$v);

    try {
        require_once __DIR__ . '/../../includes/notifications.php';
        createNotification(
            $agentId, 'alert',
            'Reservation cancelled: ' . ($n['customer'] ?: 'lead #' . $n['lead_id']),
            trim(($n['vehicle'] ? $n['vehicle'] . ' — ' : '') . $n['reason_label']
               . '. Cancelled by ' . $byName . '. Reason: ' . $n['reason']),
            $link
        );
    } catch (\Throwable $_) {}

    $email = trim((string)($n['agent_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $company = getSetting('company_name', 'Mascardi');
        $body = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto">'
              . '<div style="border-bottom:3px solid #dc2626;padding:0 0 12px;margin:0 0 22px">'
              . '<div style="font-size:12px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;'
              . 'color:#dc2626">' . htmlspecialchars($company) . '</div>'
              . '<h1 style="font-size:19px;font-weight:800;color:#111;margin:6px 0 0">Reservation cancelled</h1></div>'
              . '<p style="font-size:15px;color:#111;margin:0 0 14px">Hi '
              . htmlspecialchars($n['agent_name'] ?: 'there') . ',</p>'
              . '<p style="font-size:15px;color:#333;margin:0 0 16px">A reservation you were handling has been '
              . 'cancelled by <strong>' . htmlspecialchars($byName) . '</strong>. The vehicle has been returned '
              . 'to available stock and the lead is back to Active.</p>'
              . '<table style="width:100%;border-collapse:collapse;margin:0 0 18px;font-size:14px;color:#333">'
              . '<tr><td style="padding:7px 0;width:120px;color:#666">Customer</td><td style="padding:7px 0">'
              . '<strong>' . htmlspecialchars($n['customer']) . '</strong></td></tr>'
              . ($n['vehicle'] ? '<tr><td style="padding:7px 0;color:#666">Vehicle</td><td style="padding:7px 0">'
                  . htmlspecialchars($n['vehicle']) . '</td></tr>' : '')
              . ($n['deposit'] !== null ? '<tr><td style="padding:7px 0;color:#666">Deposit held</td>'
                  . '<td style="padding:7px 0">' . $money($n['deposit']) . '</td></tr>' : '')
              . '<tr><td style="padding:7px 0;color:#666">Reason</td><td style="padding:7px 0">'
              . htmlspecialchars($n['reason_label']) . '</td></tr>'
              . '</table>'
              . '<p style="font-size:14px;color:#333;margin:0 0 18px;padding:12px 14px;background:#fef2f2;'
              . 'border-left:3px solid #dc2626"><em>' . nl2br(htmlspecialchars($n['reason'])) . '</em></p>'
              . '<p style="margin:0 0 8px"><a href="' . htmlspecialchars($link) . '" '
              . 'style="display:inline-block;background:#111827;color:#fff;text-decoration:none;'
              . 'padding:11px 22px;border-radius:8px;font-weight:600;font-size:14px">Open the lead</a></p>'
              . '<p style="font-size:11.5px;color:#94a3b8;margin:26px 0 0;padding-top:14px;'
              . 'border-top:1px solid #e2e8f0">If a deposit was taken, check with accounts whether a refund '
              . 'is due.</p></div>';

        sendMail($email, (string)$n['agent_name'],
                 'Reservation cancelled: ' . ($n['customer'] ?: 'lead #' . $n['lead_id']),
                 $body, 'reservation', (int)$n['lead_id']);
    } catch (\Throwable $e) { error_log('reservationNotifyCancelled: ' . $e->getMessage()); }
}

} // function_exists('reservationsMigrate')
