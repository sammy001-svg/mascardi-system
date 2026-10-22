<?php
/**
 * What a buyer has actually paid on a lead.
 *
 * A deposit is not one number. The first one is written onto the lead itself
 * (crm_leads.deposit_amount) when the car is reserved; every later one is a row
 * in crm_lead_deposits. Somebody who tops up twice has paid three times and the
 * lead column still says the first figure.
 *
 * Until now only view_lead.php knew that. The deposit receipt, the sales
 * receipt, the sales agreement, the delivery note and the reservations list all
 * read the column on its own, so a customer who topped up got a receipt for less
 * than they had paid and a balance that was too high — on a signed agreement, in
 * one case. This file exists so that the answer is worked out in one place and
 * every document tells the customer the same story.
 */

if (!function_exists('leadDepositsEnsure')) {

/** The additional-deposit table, created on first use like the rest of the schema. */
function leadDepositsEnsure(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS crm_lead_deposits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lead_id INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL,
                deposit_date DATE NOT NULL,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                voided_at DATETIME NULL,
                voided_reason VARCHAR(200) NULL,
                INDEX idx_cld_lead (lead_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // Added after the table shipped, so installs already carrying it come
        // forward. voided_by came last: the rows could say a deposit had been
        // set aside and why, but not by whom, which is the one thing an audit of
        // a reversed payment actually needs.
        foreach ([
            "ALTER TABLE crm_lead_deposits ADD COLUMN voided_at DATETIME NULL",
            "ALTER TABLE crm_lead_deposits ADD COLUMN voided_reason VARCHAR(200) NULL",
            "ALTER TABLE crm_lead_deposits ADD COLUMN voided_by INT NULL",
        ] as $alter) { try { $db->exec($alter); } catch (\Throwable $_) {} }
        return $done = true;
    } catch (\Throwable $e) {
        error_log('leadDepositsEnsure: ' . $e->getMessage());
        return $done = false;
    }
}

/** The top-ups, oldest first — the order they were paid in. */
function leadExtraDeposits(PDO $db, int $leadId): array
{
    if ($leadId <= 0) return [];
    leadDepositsEnsure($db);
    try {
        $st = $db->prepare(
            "SELECT d.id, d.amount, d.deposit_date, d.notes, d.created_by,
                    u.name AS user_name
               FROM crm_lead_deposits d
          LEFT JOIN users u ON u.id = d.created_by
              WHERE d.lead_id = ? AND d.voided_at IS NULL
           ORDER BY d.deposit_date ASC, d.id ASC"
        );
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('leadExtraDeposits: ' . $e->getMessage());
        return [];
    }
}

/**
 * Everything a document needs to state the deposit honestly.
 *
 * Pass the lead row that has already been fetched — every caller has one, and
 * re-reading it here would be a second query for a figure already in hand.
 *
 * @return array{initial:float, extra:float, total:float, rows:array, date:string, count:int}
 */
function leadDepositSummary(PDO $db, array $lead): array
{
    $initial = (float)($lead['deposit_amount'] ?? 0);
    $rows    = leadExtraDeposits($db, (int)($lead['id'] ?? 0));

    $extra = 0.0;
    foreach ($rows as $r) $extra += (float)$r['amount'];

    return [
        'initial' => $initial,
        'extra'   => $extra,
        'total'   => $initial + $extra,
        'rows'    => $rows,
        // The date the money was last topped up, which is what a receipt printed
        // today is actually acknowledging. Falls back to the original.
        'date'    => $rows ? (string)end($rows)['deposit_date']
                           : (string)($lead['deposit_date'] ?? date('Y-m-d')),
        'count'   => 1 + count($rows),
    ];
}

/** Just the number, for callers that want nothing else. */
function leadDepositTotal(PDO $db, array $lead): float
{
    return leadDepositSummary($db, $lead)['total'];
}

/**
 * The same total for many leads at once, keyed by lead id.
 *
 * The reservations list would otherwise run one query per row, which is the
 * usual reason a list page that was fine with twelve records crawls at two
 * hundred.
 */
function leadDepositTotals(PDO $db, array $leadIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $leadIds))));
    if (!$ids) return [];
    leadDepositsEnsure($db);
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT lead_id, COALESCE(SUM(amount),0) AS extra
                              FROM crm_lead_deposits
                             WHERE lead_id IN ($in) AND voided_at IS NULL
                          GROUP BY lead_id");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['lead_id']] = (float)$r['extra'];
        }
        return $out;
    } catch (\Throwable $e) {
        error_log('leadDepositTotals: ' . $e->getMessage());
        return [];
    }
}

/**
 * Set aside the top-ups on a lead without destroying them.
 *
 * Cancelling a reservation clears the deposit off the lead, but the top-up rows
 * used to survive it. Reserve the same lead again and last month's payments
 * quietly counted towards the new deposit. Deleting them would fix that and
 * lose the record of money that really was taken, so they are marked instead:
 * the row stays, and every total steps over it.
 */
function leadVoidExtraDeposits(PDO $db, int $leadId, string $why): int
{
    if ($leadId <= 0) return 0;
    leadDepositsEnsure($db);
    try {
        $st = $db->prepare("UPDATE crm_lead_deposits
                                SET voided_at = NOW(), voided_reason = ?
                              WHERE lead_id = ? AND voided_at IS NULL");
        $st->execute([mb_substr($why, 0, 200), $leadId]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('leadVoidExtraDeposits: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Correcting a deposit is a super-admin job.
 *
 * A top-up is not a note on a page: it has been quoted on a proforma, a
 * receipt and possibly a signed sales agreement, it has moved the balance, it
 * has been posted to a company account, and it may be what let the lead
 * through the 20% gate into reservation. Changing one rewrites all of that, so
 * it sits with the one role that is already trusted to update a reservation.
 */
function leadDepositCanManage(): bool
{
    return function_exists('isSuperAdmin') ? isSuperAdmin() : false;
}

/**
 * One top-up, found by its id but scoped to the lead it belongs to.
 *
 * The lead is part of the lookup rather than checked afterwards, so a forged id
 * in a posted form cannot reach another lead's money at all.
 */
function leadDepositRow(PDO $db, int $depositId, int $leadId): ?array
{
    if ($depositId <= 0 || $leadId <= 0) return null;
    leadDepositsEnsure($db);
    try {
        $st = $db->prepare(
            "SELECT d.*, u.name AS user_name, v.name AS voided_by_name
               FROM crm_lead_deposits d
          LEFT JOIN users u ON u.id = d.created_by
          LEFT JOIN users v ON v.id = d.voided_by
              WHERE d.id = ? AND d.lead_id = ?"
        );
        $st->execute([$depositId, $leadId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('leadDepositRow: ' . $e->getMessage());
        return null;
    }
}

/**
 * The top-ups that have been reversed, most recently reversed first.
 *
 * Shown on the lead rather than hidden. A deposit that silently disappears
 * looks like the system lost it; one struck through with a reason and a name
 * reads as what it is, and is the only way anybody can see that a correction
 * was made at all.
 */
function leadVoidedExtraDeposits(PDO $db, int $leadId): array
{
    if ($leadId <= 0) return [];
    leadDepositsEnsure($db);
    try {
        $st = $db->prepare(
            "SELECT d.id, d.amount, d.deposit_date, d.notes,
                    d.voided_at, d.voided_reason,
                    u.name AS user_name, v.name AS voided_by_name
               FROM crm_lead_deposits d
          LEFT JOIN users u ON u.id = d.created_by
          LEFT JOIN users v ON v.id = d.voided_by
              WHERE d.lead_id = ? AND d.voided_at IS NOT NULL
           ORDER BY d.voided_at DESC, d.id DESC"
        );
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('leadVoidedExtraDeposits: ' . $e->getMessage());
        return [];
    }
}

/**
 * Reverse one top-up, leaving the row where it is.
 *
 * Every total in the system already steps over a voided row -- the lead, the
 * reservations list, the delivered figures, the account statements and
 * balances -- so marking it is all that is needed to take the money back out.
 * A reason is required: the figure a customer was quoted has changed, and
 * "why" is the first thing anybody will ask.
 */
function leadDepositVoid(PDO $db, int $depositId, int $leadId, string $reason, int $uid): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Say why the deposit is being reversed.'];
    }
    $row = leadDepositRow($db, $depositId, $leadId);
    if (!$row) {
        return ['ok' => false, 'error' => 'That deposit is not on this lead.'];
    }
    if ($row['voided_at'] !== null) {
        return ['ok' => false, 'error' => 'That deposit has already been reversed.'];
    }
    try {
        $st = $db->prepare("UPDATE crm_lead_deposits
                               SET voided_at = NOW(), voided_reason = ?, voided_by = ?
                             WHERE id = ? AND lead_id = ? AND voided_at IS NULL");
        $st->execute([mb_substr($reason, 0, 200), $uid ?: null, $depositId, $leadId]);
        if ($st->rowCount() === 0) {
            // Somebody else reversed it between the read and the write.
            return ['ok' => false, 'error' => 'That deposit has already been reversed.'];
        }
    } catch (\Throwable $e) {
        error_log('leadDepositVoid: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The deposit could not be reversed.'];
    }

    if (function_exists('logActivity')) {
        logActivity('update', 'crm_leads', $leadId,
            'Additional deposit of ' . number_format((float)$row['amount'], 2)
            . ' dated ' . $row['deposit_date'] . ' reversed - ' . $reason);
    }
    return ['ok' => true, 'error' => '', 'amount' => (float)$row['amount'],
            'date' => (string)$row['deposit_date']];
}

/**
 * Correct one top-up's figures.
 *
 * Only the things that can be typed wrong: what was paid, when, the reference,
 * and which account it landed in. A reversed deposit is not editable -- it has
 * been taken out of every total already, and the way back is to record the
 * payment again rather than to quietly revive the old row.
 *
 * The future-date check runs in SQL on purpose. PHP here runs on UTC and MySQL
 * on EAT, so comparing against PHP's date() rejects a payment taken this
 * evening as being in the future, or lets tomorrow's through, depending on the
 * hour.
 */
function leadDepositEdit(PDO $db, int $depositId, int $leadId, float $amount,
                         string $date, ?string $notes, ?int $accountId, int $uid): array
{
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Enter an amount greater than zero.'];
    }
    $d = \DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return ['ok' => false, 'error' => 'That is not a valid date.'];
    }
    $row = leadDepositRow($db, $depositId, $leadId);
    if (!$row) {
        return ['ok' => false, 'error' => 'That deposit is not on this lead.'];
    }
    if ($row['voided_at'] !== null) {
        return ['ok' => false, 'error' => 'A reversed deposit cannot be edited. Record the payment again instead.'];
    }
    try {
        $st = $db->prepare('SELECT ? > CURDATE()');
        $st->execute([$date]);
        if ((int)$st->fetchColumn() === 1) {
            return ['ok' => false, 'error' => 'A deposit cannot be dated in the future.'];
        }
    } catch (\Throwable $_) { /* if the check cannot run, do not block the edit */ }

    $notes = ($notes !== null && trim($notes) !== '') ? mb_substr(trim($notes), 0, 255) : null;

    // What changed, in words, before the row stops being able to say.
    $changes = [];
    if (abs((float)$row['amount'] - $amount) > 0.009) {
        $changes[] = 'amount ' . number_format((float)$row['amount'], 2)
                   . ' to ' . number_format($amount, 2);
    }
    if ((string)$row['deposit_date'] !== $date) {
        $changes[] = 'date ' . $row['deposit_date'] . ' to ' . $date;
    }
    if ((string)($row['notes'] ?? '') !== (string)($notes ?? '')) {
        $changes[] = 'note "' . (string)($row['notes'] ?? '') . '" to "' . (string)($notes ?? '') . '"';
    }
    $oldAcct = $row['account_id'] === null ? null : (int)$row['account_id'];
    if ($oldAcct !== $accountId) {
        $changes[] = 'account ' . ($oldAcct ?: 'none') . ' to ' . ($accountId ?: 'none');
    }
    if (!$changes) {
        return ['ok' => true, 'error' => '', 'changed' => false,
                'amount' => $amount, 'changes' => []];
    }

    try {
        $db->prepare("UPDATE crm_lead_deposits
                         SET amount = ?, deposit_date = ?, notes = ?, account_id = ?
                       WHERE id = ? AND lead_id = ? AND voided_at IS NULL")
           ->execute([$amount, $date, $notes, $accountId, $depositId, $leadId]);
    } catch (\Throwable $e) {
        error_log('leadDepositEdit: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The deposit could not be updated.'];
    }

    if (function_exists('logActivity')) {
        logActivity('update', 'crm_leads', $leadId,
            'Additional deposit corrected: ' . implode('; ', $changes));
    }
    return ['ok' => true, 'error' => '', 'changed' => true,
            'amount' => $amount, 'changes' => $changes];
}

} // function_exists('leadDepositsEnsure')
