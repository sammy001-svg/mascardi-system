<?php
/**
 * Delivered cars, into the sales book.
 *
 * A car is sold the moment its lead reaches Delivered. Nothing used to write
 * that down: marking a lead delivered set cars.status='delivered' and stopped
 * there, so the Sales module stayed empty however many cars went out the gate.
 *
 * The one thing that did fill it — syncDeliveredCarSales() — read the cars
 * table rather than the lead, and invented what it could not find there:
 *
 *   sale price  the asking price, or a flat 3,500,000 when there was none
 *   sale date   the day the car ARRIVED in stock, not the day it was sold
 *   buyer       the literal string "Client " followed by the number plate
 *   agent       nobody
 *   costs       purchase = half the sale price, duty = a fifth, freight 150,000
 *
 * The date is why a month filter came back empty: a car that arrived in July
 * and sold in September was filed under July. The costs are why margins could
 * not be trusted — they were arithmetic on a guess.
 *
 * All of it is already on the lead: the agreed price, the buyer, the agent who
 * sold it, the day it was handed over, the deposits taken and any credit
 * agreement. This builds the sale from that, and writes nothing it cannot
 * source. Where a figure genuinely is not known it stays empty and is counted,
 * because an empty field gets filled in and an invented one never gets
 * questioned.
 */

require_once __DIR__ . '/../crm/_deposits.php';

/** Columns this sync owns. Added once, in the codebase's usual inline style. */
function salesSyncSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $ddl = [
        "ALTER TABLE car_sales ADD COLUMN lead_id INT NULL DEFAULT NULL",
        // When this sync last wrote the row. If updated_at has moved on since,
        // somebody edited the sale by hand and it is left alone.
        "ALTER TABLE car_sales ADD COLUMN synced_at DATETIME NULL DEFAULT NULL",
        "ALTER TABLE car_sales ADD INDEX idx_sale_lead (lead_id)",
    ];
    foreach ($ddl as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { /* already there */ }
    }
}

/**
 * What the buyer has actually handed over on this lead: the deposit taken at
 * reservation, every top-up since, and every instalment paid against a credit
 * agreement. Voided deposits are stepped over by leadDepositTotals().
 */
function salesPaidOnLead(PDO $db, int $leadId, float $initialDeposit): float
{
    $paid = $initialDeposit + (leadDepositTotals($db, [$leadId])[$leadId] ?? 0.0);
    try {
        $st = $db->prepare("SELECT COALESCE(SUM(cp.amount),0)
                              FROM credit_payments cp
                              JOIN credit_agreements ca ON ca.id = cp.agreement_id
                             WHERE ca.lead_id = ?");
        $st->execute([$leadId]);
        $paid += (float)$st->fetchColumn();
    } catch (\Throwable $e) { /* no credit tables on this install */ }
    return round($paid, 2);
}

/** Does this lead have money still owing under a credit agreement? */
function salesLeadOnCredit(PDO $db, int $leadId): bool
{
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM credit_agreements
                             WHERE lead_id = ? AND status <> 'cancelled'");
        $st->execute([$leadId]);
        return (int)$st->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Bring the sales book in line with what has been delivered.
 *
 * Returns a tally rather than nothing, so a caller can say what happened and a
 * test can assert on it. Pass a lead id to sync just that one, which is what
 * the delivery itself does.
 */
function salesSyncFromLeads(PDO $db, ?int $onlyLead = null): array
{
    $out = ['created' => 0, 'updated' => 0, 'adopted' => 0,
            'manual_kept' => 0, 'no_vehicle' => 0, 'no_price' => 0];

    try {
        salesSyncSchema($db);

        $sql = "SELECT l.id, l.name, l.phone, l.email, l.id_number, l.pinned_car_id,
                       l.agreed_sale_price, l.deposit_amount, l.assigned_to,
                       COALESCE(l.delivered_at, l.converted_at, l.updated_at) AS sold_on,
                       c.offer_price, c.asking_price,
                       cl.name AS client_name, cl.phone AS client_phone,
                       cl.email AS client_email, cl.id_number AS client_id_no
                  FROM crm_leads l
             LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
             LEFT JOIN clients cl ON cl.id = l.client_id
                 WHERE l.stage = 'delivered'";
        $args = [];
        if ($onlyLead !== null) { $sql .= " AND l.id = ?"; $args[] = $onlyLead; }

        $st = $db->prepare($sql);
        $st->execute($args);
        $leads = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leads as $l) {
            $leadId = (int)$l['id'];
            $carId  = (int)($l['pinned_car_id'] ?? 0);

            // An import order with no vehicle attached yet cannot be a row in a
            // table whose car_id is NOT NULL. Counted, not swallowed.
            if ($carId <= 0) { $out['no_vehicle']++; continue; }

            // The price the parties agreed. The car's own prices are what the
            // yard hoped for, so they are a last resort — and a guess is never
            // one of the options.
            $price = (float)($l['agreed_sale_price'] ?: 0);
            if ($price <= 0) $price = (float)($l['offer_price']  ?: 0);
            if ($price <= 0) $price = (float)($l['asking_price'] ?: 0);
            if ($price <= 0) $out['no_price']++;

            $buyer = trim((string)($l['client_name'] ?: $l['name'] ?: ''));
            if ($buyer === '') $buyer = 'Buyer not recorded';

            $paid    = salesPaidOnLead($db, $leadId, (float)($l['deposit_amount'] ?? 0));
            $balance = max(0.0, round($price - $paid, 2));

            // Whether any of this is on credit decides how the sale is
            // described. It is the distinction finance asked for: a delivered
            // car counts as sold, but one still being paid for is not paid in
            // full, and the sales book should not imply that it is.
            $onCredit = salesLeadOnCredit($db, $leadId);

            if ($onCredit)                                $status = 'financed';
            elseif ($price > 0 && $paid + 0.01 >= $price) $status = 'paid_full';
            elseif ($paid > 0)                            $status = 'partial';
            else                                          $status = 'pending';

            $soldOn = substr((string)($l['sold_on'] ?: date('Y-m-d H:i:s')), 0, 19);
            $fields = [
                'sale_date'       => substr($soldOn, 0, 10),
                'sale_price'      => $price,
                'buyer_name'      => $buyer,
                'buyer_phone'     => (string)($l['client_phone'] ?: $l['phone'] ?: '') ?: null,
                'buyer_email'     => (string)($l['client_email'] ?: $l['email'] ?: '') ?: null,
                'buyer_id_number' => (string)($l['client_id_no'] ?: $l['id_number'] ?: '') ?: null,
                'payment_status'  => $status,
                'deposit_amount'  => $paid,
                'balance_amount'  => $balance,
                'delivered_at'    => $soldOn,
                'sold_by'         => ((int)($l['assigned_to'] ?? 0)) ?: null,
                'lead_id'         => $leadId,
            ];
            // Only claimed when it is known. Anything else keeps the column's
            // own default rather than asserting a method nobody recorded.
            if ($onCredit) $fields['payment_method'] = 'financing';

            // Already linked to this lead?
            $q = $db->prepare("SELECT id, updated_at, synced_at FROM car_sales WHERE lead_id = ? LIMIT 1");
            $q->execute([$leadId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // A row for this car, from before sales knew about leads.
                $q = $db->prepare("SELECT id, sold_by, buyer_name FROM car_sales
                                    WHERE car_id = ? AND lead_id IS NULL LIMIT 1");
                $q->execute([$carId]);
                $existing = $q->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    // The old sync signed its work: no agent, and a buyer name
                    // it made up out of the number plate. Anything else was
                    // typed in by a person and is left exactly as it is — a
                    // sync that overwrites human work is worse than none.
                    $machineMade = $existing['sold_by'] === null
                                && str_starts_with((string)$existing['buyer_name'], 'Client ');
                    if (!$machineMade) { $out['manual_kept']++; continue; }

                    salesApplyFields($db, (int)$existing['id'], $fields);
                    $out['adopted']++;
                    continue;
                }

                $cols = array_merge(
                    ['sale_number' => nextNumber('car_sales', 'sale_number', getSetting('sale_prefix', 'SALE')),
                     'car_id'      => $carId,
                     'status'      => 'active'],
                    $fields
                );
                $names = implode(',', array_keys($cols));
                $marks = implode(',', array_fill(0, count($cols), '?'));
                $db->prepare("INSERT INTO car_sales ($names, synced_at) VALUES ($marks, NOW())")
                   ->execute(array_values($cols));
                $out['created']++;
                continue;
            }

            // Linked already. If updated_at has moved past synced_at then the
            // sale was edited in the Sales module since, and that edit stands.
            if ($row['synced_at'] !== null
                && strtotime((string)$row['updated_at']) > strtotime((string)$row['synced_at']) + 1) {
                $out['manual_kept']++;
                continue;
            }
            salesApplyFields($db, (int)$row['id'], $fields);
            $out['updated']++;
        }
    } catch (\Throwable $e) {
        error_log('[salesSyncFromLeads] ' . $e->getMessage());
    }

    return $out;
}

/** Write the derived fields onto one sale and stamp it as machine-maintained. */
function salesApplyFields(PDO $db, int $saleId, array $fields): void
{
    $set = [];
    foreach (array_keys($fields) as $k) $set[] = "$k = ?";
    $args   = array_values($fields);
    $args[] = $saleId;
    $db->prepare("UPDATE car_sales SET " . implode(', ', $set) . ", synced_at = NOW() WHERE id = ?")
       ->execute($args);
}
