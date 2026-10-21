<?php
/**
 * Ancillary charges — the things billed on top of the car itself.
 *
 * Number plates, registration, transfer, insurance, tracking, a valuation.
 * They are not part of the agreed sale price and they are not the deposit, so
 * putting them in either would misstate both: the sale price is what the
 * vehicle was sold for and the deposit is what has been paid towards it.
 *
 * They belong to the CAR, not to the deal
 * ---------------------------------------
 * They were on the lead to begin with, which meant the only way to record one
 * was through a lead that had got as far as pinning a vehicle — so a car
 * sitting in the yard with 7,500 of plates and 12,000 of registration coming
 * had nowhere to say so until somebody reserved it.
 *
 * The plates belong to the Harrier, not to whoever happens to buy it. So the
 * items hang off the car and are recorded in inventory, and a lead reads the
 * items of the vehicle pinned to it. Nothing is copied or synced: reserving a
 * car makes its charges appear on the lead because they are the same rows, and
 * correcting one from either screen corrects it for both.
 *
 * Payments stay on the lead. Who paid is a fact about a deal, not about a
 * vehicle — the same car could be reserved, released and reserved again, and
 * the first buyer's receipt must not follow it to the second.
 */

require_once __DIR__ . '/_banks.php';

if (!function_exists('chargesSchema')) {

/** The usual items, offered as suggestions rather than enforced as a list:
 *  a yard bills things this list will not have thought of. */
function chargesCommonItems(): array
{
    return [
        'Number plates',
        'Registration',
        'Transfer of ownership',
        'Insurance',
        'Tracking device',
        'Valuation',
        'Inspection',
        'Logbook transfer',
        'Comprehensive cover',
        'Delivery / transport',
        'Detailing',
        'NTSA fees',
    ];
}

function chargesSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    foreach ([
        // Against the car. This is deliberately not car_costs, which is the
        // fixed landed-cost sheet — purchase price, freight, duty, clearing,
        // workshop — and is what the yard SPENT. These are what the buyer is
        // BILLED on top of the price. Two different questions about one car.
        "CREATE TABLE IF NOT EXISTS car_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            car_id INT NOT NULL,
            item VARCHAR(120) NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cc_car (car_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // A payment against them, by the buyer, so it hangs off the lead.
        // bank_key is one of the company's own accounts from _banks.php —
        // which account the money came into, so the receipt can say so and
        // finance can reconcile it.
        "CREATE TABLE IF NOT EXISTS crm_lead_charge_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            receipt_number VARCHAR(40) NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            paid_on DATE NOT NULL,
            method VARCHAR(30) NULL,
            bank_key VARCHAR(40) NULL,
            reference VARCHAR(120) NULL,
            notes VARCHAR(255) NULL,
            account_id INT NULL,
            recorded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            voided_at DATETIME NULL,
            voided_by INT NULL,
            void_reason VARCHAR(255) NULL,
            UNIQUE KEY uq_clcp_receipt (receipt_number),
            KEY idx_clcp_lead (lead_id, voided_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { error_log('chargesSchema: ' . $e->getMessage()); }
    }

    // Anything recorded while the items hung off the lead moves to that lead's
    // vehicle. Run once and only where the lead has a car to move it to; a
    // row with no vehicle is left where it is rather than guessed at, and the
    // old table is kept so nothing is destroyed by an upgrade.
    try {
        $has = $db->query("SELECT COUNT(*) FROM information_schema.TABLES
                            WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'crm_lead_charges'")->fetchColumn();
        if ($has) {
            $db->exec("INSERT INTO car_charges (car_id, item, amount, notes, created_by, created_at)
                       SELECT l.pinned_car_id, c.item, c.amount, c.notes, c.created_by, c.created_at
                         FROM crm_lead_charges c
                         JOIN crm_leads l ON l.id = c.lead_id
                        WHERE l.pinned_car_id IS NOT NULL
                          AND NOT EXISTS (
                              SELECT 1 FROM car_charges x
                               WHERE x.car_id = l.pinned_car_id
                                 AND x.item   = c.item
                                 AND x.amount = c.amount)");
        }
    } catch (\Throwable $e) { /* nothing to move, or no such table */ }
}

/** May this user record and edit charges? The same permission the rest of a
 *  lead and a car record already ask for. */
function chargesCanWrite(): bool
{
    return canWrite('crm') || canWrite('cars');
}

/** Who may take a payment back out again — a manager, not whoever took it,
 *  the same rule the receivables book uses for a reversal. */
function chargesCanVoid(?array $user = null): bool
{
    $user = $user ?: authUser();
    return $user && in_array($user['role'] ?? '', [
        'super_admin', 'admin', 'general_manager', 'finance_manager',
    ], true);
}

// ── Against the car ─────────────────────────────────────────────────────────

/** The charged items on a car, in the order they were added. */
function carChargesFor(PDO $db, int $carId): array
{
    chargesSchema($db);
    if ($carId <= 0) return [];
    try {
        $st = $db->prepare("SELECT * FROM car_charges WHERE car_id = ? ORDER BY id");
        $st->execute([$carId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('carChargesFor: ' . $e->getMessage());
        return [];
    }
}

/** What they come to. */
function carChargesTotal(PDO $db, int $carId): float
{
    chargesSchema($db);
    if ($carId <= 0) return 0.0;
    try {
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM car_charges WHERE car_id = ?");
        $st->execute([$carId]);
        return (float)$st->fetchColumn();
    } catch (\Throwable $e) { return 0.0; }
}

/** The totals for a list of cars at once, for the inventory list. */
function carChargesTotals(PDO $db, array $carIds): array
{
    chargesSchema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $carIds))));
    if (!$ids) return [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT car_id, COALESCE(SUM(amount),0) t, COUNT(*) n
                              FROM car_charges WHERE car_id IN ($in) GROUP BY car_id");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['car_id']] = ['total' => (float)$r['t'], 'count' => (int)$r['n']];
        }
        return $out;
    } catch (\Throwable $e) { return []; }
}

/**
 * Replace a car's charged items with what the form submitted.
 *
 * The form is a list the operator edits as a whole — add a line, correct an
 * amount, delete one — so it posts the whole list and this writes the whole
 * list. Replacing rather than diffing keeps the two in step; the payments are
 * a separate table and are untouched by it.
 *
 * Returns ['ok', 'error', 'count', 'total', 'changed'].
 */
function carChargesSave(PDO $db, int $carId, array $items, array $amounts,
                        array $notes, int $userId): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'count' => 0, 'total' => 0.0, 'changed' => false];
    if ($carId <= 0) { $out['error'] = 'No vehicle to charge this to.'; return $out; }

    $before = carChargesTotal($db, $carId);
    $beforeN = count(carChargesFor($db, $carId));

    $rows = [];
    foreach ($items as $i => $raw) {
        $item = trim((string)$raw);
        if ($item === '') continue;              // a blank line is a line nobody filled in
        $amt = (float)preg_replace('/[^0-9.\-]/', '', (string)($amounts[$i] ?? '0'));
        if ($amt < 0) { $out['error'] = 'An amount cannot be negative.'; return $out; }
        $rows[] = [
            'item'   => mb_substr($item, 0, 120),
            'amount' => round($amt, 2),
            'notes'  => mb_substr(trim((string)($notes[$i] ?? '')), 0, 255) ?: null,
        ];
    }

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM car_charges WHERE car_id = ?")->execute([$carId]);
        $ins = $db->prepare("INSERT INTO car_charges (car_id, item, amount, notes, created_by)
                             VALUES (?,?,?,?,?)");
        foreach ($rows as $r) {
            $ins->execute([$carId, $r['item'], $r['amount'], $r['notes'], $userId ?: null]);
            $out['total'] += $r['amount'];
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('carChargesSave: ' . $e->getMessage());
        $out['error'] = 'The charges could not be saved: ' . $e->getMessage();
        return $out;
    }

    $out['count']   = count($rows);
    $out['total']   = round($out['total'], 2);
    $out['ok']      = true;
    $out['changed'] = ($out['count'] !== $beforeN) || abs($out['total'] - $before) > 0.009;

    if ($out['changed']) {
        try {
            logActivity('update', 'cars', $carId,
                'Ancillary charges: ' . $out['count'] . ' item'
                . ($out['count'] === 1 ? '' : 's') . ' totalling ' . money($out['total']));
        } catch (\Throwable $e) {}
    }

    return $out;
}

/**
 * Read the charge rows out of a posted form.
 *
 * The add-a-car form, the edit form and the lead's pop-up all post the same
 * three arrays, so they all save the same way.
 */
function chargesFromPost(array $post): array
{
    return [
        (array)($post['charge_item']   ?? []),
        (array)($post['charge_amount'] ?? []),
        (array)($post['charge_note']   ?? []),
    ];
}

/** Does a posted form carry any charge line at all? Used so a form that does
 *  not show the rows never wipes what is already on the car. */
function chargesPosted(array $post): bool
{
    foreach ((array)($post['charge_item'] ?? []) as $v) {
        if (trim((string)$v) !== '') return true;
    }
    // An empty set of rows is still a submission when the field was present:
    // that is how the last item is removed.
    return array_key_exists('charge_item', $post);
}

// ── Against the lead ────────────────────────────────────────────────────────

/** Which vehicle a lead's charges belong to, or 0. */
function leadChargeCarId(PDO $db, int $leadId): int
{
    if ($leadId <= 0) return 0;
    try {
        $st = $db->prepare("SELECT pinned_car_id FROM crm_leads WHERE id = ?");
        $st->execute([$leadId]);
        return (int)$st->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/** The items a lead is looking at — its vehicle's. */
function chargesFor(PDO $db, int $leadId): array
{
    return carChargesFor($db, leadChargeCarId($db, $leadId));
}

/** Payments against the charges, newest last. Voided ones only when asked
 *  for — the lead page shows them, so the trail is visible. */
function chargePayments(PDO $db, int $leadId, bool $includeVoided = false): array
{
    chargesSchema($db);
    if ($leadId <= 0) return [];
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name, v.name AS voided_by_name
                              FROM crm_lead_charge_payments p
                         LEFT JOIN users u ON u.id = p.recorded_by
                         LEFT JOIN users v ON v.id = p.voided_by
                             WHERE p.lead_id = ?"
                           . ($includeVoided ? '' : ' AND p.voided_at IS NULL')
                           . " ORDER BY p.paid_on, p.id");
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('chargePayments: ' . $e->getMessage());
        return [];
    }
}

/**
 * What the charges come to, what has been paid, and what is left.
 *
 * One call, because every screen that shows one of these wants the others
 * beside it, and working them out separately is how two of them come to
 * disagree. 'car_id' comes back so a page can say "link a vehicle" rather
 * than showing a confident nought.
 */
function chargesSummary(PDO $db, int $leadId): array
{
    chargesSchema($db);
    $out = ['car_id' => 0, 'count' => 0, 'total' => 0.0, 'paid' => 0.0,
            'balance' => 0.0, 'payments' => 0, 'voided' => 0];
    if ($leadId <= 0) return $out;

    $out['car_id'] = leadChargeCarId($db, $leadId);
    $items         = carChargesFor($db, $out['car_id']);
    $out['count']  = count($items);
    foreach ($items as $i) $out['total'] += (float)$i['amount'];
    $out['total']  = round($out['total'], 2);

    try {
        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t
                              FROM crm_lead_charge_payments
                             WHERE lead_id = ? AND voided_at IS NULL");
        $st->execute([$leadId]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
        $out['payments'] = (int)$p['n'];
        $out['paid']     = (float)$p['t'];

        $st = $db->prepare("SELECT COUNT(*) FROM crm_lead_charge_payments
                             WHERE lead_id = ? AND voided_at IS NOT NULL");
        $st->execute([$leadId]);
        $out['voided'] = (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('chargesSummary: ' . $e->getMessage());
    }

    // Floored, because paying more than was charged is an overpayment to sort
    // out, not a negative amount owing that quietly offsets something else.
    $out['balance'] = max(0.0, round($out['total'] - $out['paid'], 2));
    return $out;
}

/** The next receipt number for a charge payment. Its own series, so it is
 *  never confused with a deposit receipt or a credit instalment receipt. */
function chargeNextReceipt(PDO $db): string
{
    chargesSchema($db);
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM crm_lead_charge_payments")->fetchColumn() + 1;
    } catch (\Throwable $e) { $n = 1; }
    return 'AC-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Take a payment against the charges.
 *
 * Returns ['ok', 'error', 'id', 'receipt', 'balance'].
 */
function chargeAddPayment(PDO $db, int $leadId, float $amount, string $paidOn,
                          string $method, string $bankKey, string $reference,
                          string $notes, int $userId, ?int $accountId = null): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'id' => 0, 'receipt' => '', 'balance' => 0.0];

    if ($leadId <= 0)  { $out['error'] = 'No lead to record this against.'; return $out; }
    if ($amount <= 0)  { $out['error'] = 'Enter the amount received.';      return $out; }

    $sum = chargesSummary($db, $leadId);
    if ($sum['car_id'] <= 0) {
        $out['error'] = 'Link a vehicle to this lead first — the charges belong to the car.';
        return $out;
    }
    if ($sum['total'] <= 0) {
        $out['error'] = 'There are no charges on this vehicle yet. Add the items first.';
        return $out;
    }
    // Taking more than is owed is nearly always a typing error — an extra
    // nought is the easiest mistake to make here and the hardest to unwind.
    if ($amount > $sum['balance'] + 0.009) {
        $out['error'] = 'That is more than the ' . money($sum['balance'])
                      . ' still owing on these charges. Check the amount.';
        return $out;
    }

    $paidOn = trim($paidOn);
    if ($paidOn === '' || !strtotime($paidOn)) {
        // MySQL's today, not PHP's: this host runs PHP on UTC and MySQL on EAT,
        // and the two disagree by three hours about when today started.
        $paidOn = (string)$db->query('SELECT CURDATE()')->fetchColumn();
    } else {
        $paidOn = date('Y-m-d', strtotime($paidOn));
    }

    // Only one of our own accounts, so a receipt cannot name a bank we do not
    // bank with.
    if ($bankKey !== '' && leadBank($bankKey) === null) $bankKey = '';

    $receipt = chargeNextReceipt($db);
    try {
        $db->prepare("INSERT INTO crm_lead_charge_payments
                          (lead_id, receipt_number, amount, paid_on, method, bank_key,
                           reference, notes, account_id, recorded_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$leadId, $receipt, round($amount, 2), $paidOn,
                      trim($method) ?: null, $bankKey ?: null,
                      mb_substr(trim($reference), 0, 120) ?: null,
                      mb_substr(trim($notes), 0, 255) ?: null,
                      $accountId, $userId ?: null]);
        $out['id'] = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('chargeAddPayment: ' . $e->getMessage());
        $out['error'] = 'The payment could not be recorded: ' . $e->getMessage();
        return $out;
    }

    $after = chargesSummary($db, $leadId);
    $out['ok']      = true;
    $out['receipt'] = $receipt;
    $out['balance'] = $after['balance'];

    try {
        logActivity('create', 'crm_lead_charge_payments', $out['id'],
            'Ancillary charge payment ' . $receipt . ' of ' . money($amount)
            . ($bankKey !== '' ? ' into ' . leadBankLabel($bankKey) : '')
            . '. Balance ' . money($after['balance']) . '.');
    } catch (\Throwable $e) {}

    return $out;
}

/**
 * Take a payment back out.
 *
 * Kept and marked rather than deleted, so the trail still shows the entry was
 * made and who took it back — the same convention as a voided deposit and a
 * reversed credit payment.
 */
function chargeVoidPayment(PDO $db, int $paymentId, string $reason, int $userId): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'receipt' => '', 'amount' => 0.0, 'lead_id' => 0];

    $reason = trim($reason);
    if ($reason === '') { $out['error'] = 'Say why it is being reversed.'; return $out; }

    try {
        $st = $db->prepare("SELECT * FROM crm_lead_charge_payments WHERE id = ?");
        $st->execute([$paymentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $out['error'] = 'That payment could not be read.'; return $out; }

    if (!$p)                     { $out['error'] = 'That payment does not exist.';            return $out; }
    if (!empty($p['voided_at'])) { $out['error'] = 'That payment has already been reversed.'; return $out; }

    try {
        $db->prepare("UPDATE crm_lead_charge_payments
                         SET voided_at = NOW(), voided_by = ?, void_reason = ?
                       WHERE id = ? AND voided_at IS NULL")
           ->execute([$userId ?: null, mb_substr($reason, 0, 255), $paymentId]);
    } catch (\Throwable $e) {
        error_log('chargeVoidPayment: ' . $e->getMessage());
        $out['error'] = 'The reversal could not be completed.';
        return $out;
    }

    $out['ok']      = true;
    $out['receipt'] = (string)($p['receipt_number'] ?? '');
    $out['amount']  = (float)$p['amount'];
    $out['lead_id'] = (int)$p['lead_id'];

    try {
        logActivity('update', 'crm_lead_charge_payments', $paymentId,
            'Reversed ancillary charge payment ' . $out['receipt'] . ' of '
            . money($out['amount']) . '. Reason: ' . $reason);
    } catch (\Throwable $e) {}

    return $out;
}

/** One payment, with the lead and car it belongs to — what the receipt needs. */
function chargePayment(PDO $db, int $paymentId): ?array
{
    chargesSchema($db);
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name,
                                   l.name AS lead_name, l.phone AS lead_phone,
                                   l.email AS lead_email, l.id_number, l.po_box,
                                   l.pinned_car_id,
                                   c.make, c.model, c.year, c.registration_number,
                                   c.chassis_number,
                                   cl.name AS client_name
                              FROM crm_lead_charge_payments p
                         LEFT JOIN users   u  ON u.id  = p.recorded_by
                         LEFT JOIN crm_leads l ON l.id = p.lead_id
                         LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
                         LEFT JOIN clients cl ON cl.id = l.client_id
                             WHERE p.id = ?");
        $st->execute([$paymentId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('chargePayment: ' . $e->getMessage());
        return null;
    }
}

/** How a payment was made, for the dropdown and the receipt. */
function chargeMethods(): array
{
    return ['bank' => 'Bank transfer', 'mpesa' => 'M-Pesa', 'cash' => 'Cash',
            'cheque' => 'Cheque', 'card' => 'Card'];
}

// ── The row editor, written once ────────────────────────────────────────────

/**
 * The repeatable item rows, with a running total.
 *
 * Drawn by the add-a-car form, the car's edit form and the lead's pop-up. One
 * copy, because three would have come to disagree about what a blank row
 * means or whether an amount may carry a comma. The ids are fixed rather than
 * prefixed: only one of these appears on a page.
 */
function chargesRowsTable(array $items, bool $showNote = true): void
{
    $draw = $items;
    // Always one spare, so there is somewhere to type without reaching for
    // Add first.
    $draw[] = ['item' => '', 'amount' => '', 'notes' => ''];
    ?>
    <datalist id="acItemList">
        <?php foreach (chargesCommonItems() as $ci): ?>
        <option value="<?= e($ci) ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2" style="font-size:13px">
            <thead class="table-light">
                <tr>
                    <th style="min-width:170px">Item</th>
                    <th style="width:150px">Amount (KES)</th>
                    <?php if ($showNote): ?>
                    <th style="min-width:140px">Note <span class="text-muted fw-normal">(optional)</span></th>
                    <?php endif; ?>
                    <th style="width:44px"></th>
                </tr>
            </thead>
            <tbody id="acRows">
            <?php foreach ($draw as $ci): ?>
                <tr class="ac-row">
                    <td>
                        <input type="text" name="charge_item[]" list="acItemList"
                               class="form-control form-control-sm"
                               placeholder="e.g. Number plates"
                               value="<?= e((string)($ci['item'] ?? '')) ?>">
                    </td>
                    <td>
                        <input type="text" inputmode="decimal" name="charge_amount[]"
                               class="form-control form-control-sm text-end ac-amt"
                               placeholder="0"
                               value="<?= ($ci['amount'] ?? '') !== ''
                                            ? e(number_format((float)$ci['amount'], 0, '.', '')) : '' ?>">
                    </td>
                    <?php if ($showNote): ?>
                    <td>
                        <input type="text" name="charge_note[]" class="form-control form-control-sm"
                               value="<?= e((string)($ci['notes'] ?? '')) ?>">
                    </td>
                    <?php else: ?>
                    <input type="hidden" name="charge_note[]" value="<?= e((string)($ci['notes'] ?? '')) ?>">
                    <?php endif; ?>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger ac-del"
                                title="Remove this line" tabindex="-1">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="text-end fw-bold">Total</td>
                    <td class="text-end fw-bold" id="acTotal"
                        style="font-size:15px;color:#075985;font-variant-numeric:tabular-nums">0</td>
                    <td colspan="<?= $showNote ? 2 : 1 ?>"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <button type="button" class="btn btn-sm btn-outline-primary" id="acAddRow">
        <i class="fa fa-plus me-1"></i>Add another item
    </button>
    <?php
}

/**
 * The script that runs those rows.
 *
 * Nothing in it touches the `bootstrap` global. It is emitted inside the page
 * body, before the footer loads the bundle, and reaching for bootstrap.Modal
 * here would throw and take every listener below it with it.
 */
function chargesRowsScript(): void
{
    ?>
    <script>
    (function () {
        var rows  = document.getElementById('acRows');
        var total = document.getElementById('acTotal');
        var add   = document.getElementById('acAddRow');
        if (!rows || !total) return;

        var fmt = new Intl.NumberFormat('en-KE', { maximumFractionDigits: 0 });

        function money(el) {
            // Commas and spaces are how people type a figure; strip them
            // rather than refuse the input.
            var v = parseFloat(String(el.value).replace(/[^0-9.\-]/g, ''));
            return isNaN(v) ? 0 : v;
        }
        function recalc() {
            var sum = 0;
            rows.querySelectorAll('.ac-amt').forEach(function (el) { sum += money(el); });
            total.textContent = fmt.format(Math.round(sum));
        }
        if (add) {
            add.addEventListener('click', function () {
                var tr = rows.querySelector('.ac-row');
                if (!tr) return;
                var copy = tr.cloneNode(true);
                copy.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                rows.appendChild(copy);
                var first = copy.querySelector('input');
                if (first) first.focus();
                recalc();
            });
        }
        rows.addEventListener('input', recalc);
        rows.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.ac-del');
            if (!btn) return;
            // Never remove the last row: the form would have nothing to type
            // into, and clearing the charges would mean deleting rows until it
            // broke. Emptying it is how the list is cleared.
            if (rows.querySelectorAll('.ac-row').length > 1) {
                btn.closest('.ac-row').remove();
            } else {
                btn.closest('.ac-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
            }
            recalc();
        });
        recalc();
    })();
    </script>
    <?php
}

} // function_exists('chargesSchema')
