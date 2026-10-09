<?php
/**
 * Removing a lead.
 *
 * Deleting is for duplicates — the same walk-in entered twice, a test row, a
 * phone number typed into the wrong field. It is not a way to make a real deal
 * disappear, so a lead carrying money or a history refuses to be deleted and
 * says which of the two it is. The safe move for a deal that fell through is
 * Lost, which keeps the record.
 *
 * Fifteen tables carry a lead_id and only crm_activities has a foreign key, so
 * the database cleans up nothing for us. The two lists below split those
 * tables by who owns the row, and the split follows nullability exactly: a
 * deposit is NOT NULL because it cannot exist without its lead, while a
 * showroom visit is NULLable because the showroom owns it and the lead is only
 * a cross-reference.
 */

/**
 * Who may delete: the General Manager and the Super Admin.
 *
 * 'admin' is in the list because this system treats admin and super_admin as
 * one authority — isSuperAdmin() in auth.php returns true for both, and
 * canAccess() lets both into every module. More to the point, installs exist
 * whose users.role holds 'admin' and no 'super_admin' at all, so a gate
 * naming only 'super_admin' would show the button to nobody. That is the same
 * trap the note above $isSuperAdmin in view_lead.php was written about, where
 * it stalled the delivery protocol with no way forward.
 *
 * Tested by plain role comparison rather than hasRole(), which answers true
 * for admin whatever role it is asked about and so cannot exclude anyone.
 */
const LEAD_DELETE_ROLES = ['super_admin', 'admin', 'general_manager'];

/**
 * Rows the lead owns outright — they exist only because the lead does, and go
 * with it. crm_activities is here rather than in the detach list because a
 * timeline detached from its lead is unreadable noise (its foreign key would
 * cascade anyway; it is listed so the set is explicit rather than implied).
 */
const LEAD_OWNED_TABLES = [
    'crm_activities',
    // Created on demand by test_drives.php, so it is absent until someone
    // opens that page. leadTableExists() covers both cases.
    'crm_test_drives',
    'crm_lead_nudges',
    'crm_lead_documents',
    'crm_kyc_requests',
    'crm_lead_deposits',
    'crm_delivery_protocol',
    'reservation_cancellations',
    'import_placements',
];

/**
 * Rows another module owns that merely point here. A call recording, a
 * showroom visit, a website enquiry and a WhatsApp thread are records in their
 * own right; they lose the link, not their contents. Deleting these would
 * destroy another department's log to tidy a duplicate.
 */
const LEAD_LINKED_TABLES = [
    'call_logs',
    'visitors',
    'showroom_inquiries',
    'contact_messages',
    'wa_conversations',
    'car_sales',
];

/** May this user delete leads? */
function leadDeleteAllowed(?array $user = null): bool
{
    $user = $user ?: authUser();
    return $user && in_array($user['role'] ?? '', LEAD_DELETE_ROLES, true);
}

/**
 * Does this table exist? Cached, because the blocker check asks about a dozen
 * of them per page load.
 *
 * Asked at all because the delete this replaces swept crm_test_drives, which
 * test_drives.php creates on first visit. On an install where nobody had
 * opened that page the statement threw, the catch turned it into "Delete
 * failed", and no lead could be deleted at all; where the table did exist the
 * delete ran and orphaned the other thirteen tables instead. Both faults came
 * from assuming a table was there.
 */
function leadTableExists(PDO $db, string $table): bool
{
    static $seen = [];
    if (array_key_exists($table, $seen)) return $seen[$table];
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return $seen[$table] = (bool)$st->fetchColumn();
}

/**
 * Why this lead may not be deleted, as sentences for the person clicking.
 * An empty array means it is safe to remove.
 *
 * A missing table is not a blocker: if it is not there it holds no rows, so
 * there is nothing for the delete to orphan. Existence is checked up front
 * rather than by catching query errors, so that a genuinely broken check
 * cannot be mistaken for an all-clear.
 */
function leadDeleteBlockers(PDO $db, int $leadId): array
{
    $stop = [];

    $lead = null;
    $st = $db->prepare("SELECT stage, client_id FROM crm_leads WHERE id = ?");
    $st->execute([$leadId]);
    $lead = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lead) return ['That lead no longer exists.'];

    // ── Money received ──────────────────────────────────────────────────────
    // Voided deposits do not count: a voided receipt is a correction, and a
    // lead whose only deposit was reversed can still be a duplicate.
    if (leadTableExists($db, 'crm_lead_deposits')) {
        $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total
                            FROM crm_lead_deposits WHERE lead_id = ? AND voided_at IS NULL");
        $st->execute([$leadId]);
        $d = $st->fetch(PDO::FETCH_ASSOC);
        if ((int)$d['n'] > 0) {
            $stop[] = 'KES ' . number_format((float)$d['total']) . ' has been received against it ('
                    . (int)$d['n'] . ' deposit' . ((int)$d['n'] === 1 ? '' : 's') . ').';
        }
    }

    // ── Money owed ──────────────────────────────────────────────────────────
    // Any agreement at all, including a cancelled one: an agreement having
    // existed means this was a real buyer, and finance reports read these rows.
    if (leadTableExists($db, 'credit_agreements')) {
        $st = $db->prepare("SELECT reference, status FROM credit_agreements
                            WHERE lead_id = ? ORDER BY id LIMIT 1");
        $st->execute([$leadId]);
        if ($a = $st->fetch(PDO::FETCH_ASSOC)) {
            $stop[] = 'It has a credit agreement (' . ($a['reference'] ?: 'no reference')
                    . ', ' . str_replace('_', ' ', (string)$a['status']) . ').';
        }
    }

    // ── A sale ──────────────────────────────────────────────────────────────
    if (leadTableExists($db, 'car_sales')) {
        $st = $db->prepare("SELECT sale_number FROM car_sales WHERE lead_id = ? ORDER BY id LIMIT 1");
        $st->execute([$leadId]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
            $stop[] = 'A sale is recorded against it (' . ($s['sale_number'] ?: 'no number') . ').';
        }
    }

    // ── A delivery under way ────────────────────────────────────────────────
    // Only the numbered steps count. updated_at is set whenever the row is
    // touched, so it would report every empty shell as a delivery in progress.
    if (leadTableExists($db, 'crm_delivery_protocol')) {
        // Static: this is asked once per lead page view, and the columns do not
        // change between two calls in the same request.
        static $steps = null;
        if ($steps === null) {
            $cols  = $db->query("SHOW COLUMNS FROM crm_delivery_protocol")->fetchAll(PDO::FETCH_COLUMN);
            $steps = array_values(array_filter($cols, fn ($c) => (bool)preg_match('/^s\d+_.*_at$/', $c)));
        }
        if ($steps) {
            $any = implode(' IS NOT NULL OR ', array_map(fn ($c) => "`$c`", $steps)) . ' IS NOT NULL';
            $st  = $db->prepare("SELECT COUNT(*) FROM crm_delivery_protocol
                                 WHERE lead_id = ? AND ($any)");
            $st->execute([$leadId]);
            if ((int)$st->fetchColumn() > 0) $stop[] = 'Its delivery has already been started.';
        }
    }

    // ── Signed paperwork ────────────────────────────────────────────────────
    if (leadTableExists($db, 'crm_lead_documents')) {
        $st = $db->prepare("SELECT COUNT(*) FROM crm_lead_documents WHERE lead_id = ?");
        $st->execute([$leadId]);
        if (($n = (int)$st->fetchColumn()) > 0) {
            $stop[] = $n . ' signed document' . ($n === 1 ? ' is' : 's are') . ' filed against it.';
        }
    }

    // ── KYC the client actually sent in ─────────────────────────────────────
    // An unanswered request is just an email and does not block.
    if (leadTableExists($db, 'crm_kyc_requests')) {
        $st = $db->prepare("SELECT COUNT(*) FROM crm_kyc_requests
                            WHERE lead_id = ? AND submitted_at IS NOT NULL");
        $st->execute([$leadId]);
        if ((int)$st->fetchColumn() > 0) $stop[] = 'The client has submitted KYC documents through it.';
    }

    // ── Already a customer ──────────────────────────────────────────────────
    if (!empty($lead['client_id'])) {
        $stop[] = 'It has already been converted to a client record.';
    }
    if (($lead['stage'] ?? '') === 'delivered') {
        $stop[] = 'Its car has been delivered.';
    }

    return $stop;
}

/**
 * Delete a lead and everything that pointed at it.
 *
 * Returns ['ok' => bool, 'error' => string, 'name' => string, 'freed' => ?string].
 * Refuses outright if leadDeleteBlockers() finds anything, so a caller cannot
 * skip the check by accident.
 */
function leadDelete(PDO $db, int $leadId): array
{
    $out = ['ok' => false, 'error' => '', 'name' => '', 'freed' => null];

    $st = $db->prepare("SELECT id, name, phone, email, stage, pinned_car_id
                        FROM crm_leads WHERE id = ?");
    $st->execute([$leadId]);
    $lead = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lead) { $out['error'] = 'That lead no longer exists.'; return $out; }
    $out['name'] = (string)$lead['name'];

    if ($stop = leadDeleteBlockers($db, $leadId)) {
        $out['error'] = implode(' ', $stop);
        return $out;
    }

    // DDL implicitly commits in MySQL, so the SHOW COLUMNS inside the blocker
    // check above must happen before the transaction opens, not inside it.
    $db->beginTransaction();
    try {
        foreach (LEAD_OWNED_TABLES as $t) {
            if (leadTableExists($db, $t)) {
                $db->prepare("DELETE FROM `$t` WHERE lead_id = ?")->execute([$leadId]);
            }
        }
        foreach (LEAD_LINKED_TABLES as $t) {
            if (leadTableExists($db, $t)) {
                $db->prepare("UPDATE `$t` SET lead_id = NULL WHERE lead_id = ?")->execute([$leadId]);
            }
        }

        // Let the car go. A duplicate lead holding a reservation keeps a car
        // off the floor, which is the most expensive part of the duplicate.
        $carId = (int)($lead['pinned_car_id'] ?? 0);
        if ($carId) {
            $cs = $db->prepare("SELECT registration_number, chassis_number, status
                                FROM cars WHERE id = ?");
            $cs->execute([$carId]);
            if ($car = $cs->fetch(PDO::FETCH_ASSOC)) {
                if (($car['status'] ?? '') === 'reserved') {
                    $db->prepare("UPDATE cars SET status = 'arrived', updated_at = NOW()
                                  WHERE id = ? AND status = 'reserved'")->execute([$carId]);
                    $out['freed'] = $car['registration_number'] ?: $car['chassis_number'];
                }
            }
        }

        $db->prepare("DELETE FROM crm_leads WHERE id = ?")->execute([$leadId]);
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $out['error'] = $e->getMessage();
        return $out;
    }

    // Logged after the commit, and outside the transaction, so a failed audit
    // write can never roll back a delete that already happened.
    logActivity('delete', 'crm_leads', $leadId,
        'Deleted lead "' . $lead['name'] . '" (' . ($lead['phone'] ?: 'no phone') . ')'
        . ($out['freed'] ? ' — released ' . $out['freed'] : ''),
        $lead, null);

    $out['ok'] = true;
    return $out;
}
