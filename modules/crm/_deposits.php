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
        // Added after the table shipped, so installs already carrying it come forward.
        foreach ([
            "ALTER TABLE crm_lead_deposits ADD COLUMN voided_at DATETIME NULL",
            "ALTER TABLE crm_lead_deposits ADD COLUMN voided_reason VARCHAR(200) NULL",
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

} // function_exists('leadDepositsEnsure')
