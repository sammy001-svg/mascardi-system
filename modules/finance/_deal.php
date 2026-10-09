<?php
/**
 * The deal behind a credit account: what the car cost, what was put down, and
 * what paperwork exists for it.
 *
 * The receivables book answers "how much is still owed". Finance also has to
 * answer "owed against what", and that meant opening the lead in another tab.
 * The figures and the documents are gathered here so the account page can show
 * both without growing another four hundred lines of its own.
 *
 * Nothing here invents a figure. Where the system does not hold one it says so
 * rather than deriving something plausible — a made-up car value on a page
 * finance chases money from is worse than a blank.
 */

require_once __DIR__ . '/../crm/_deposits.php';
require_once __DIR__ . '/../crm/_documents.php';

/**
 * Every money figure of the deal, and how far they tie up.
 *
 * The agreement's principal is defaulted from "price less deposit" when it is
 * written, but the field is editable and often legitimately differs — a part
 * payment outside the schedule, a trade-in, a figure agreed in the room. So
 * the gap is reported rather than corrected, and only when it is big enough to
 * be a real difference rather than rounding.
 */
function finDealFigures(PDO $db, array $a, array $sum): array
{
    $leadId = (int)($a['lead_id'] ?? 0);

    $lead = null;
    if ($leadId) {
        $st = $db->prepare("SELECT agreed_sale_price, deposit_amount, stage, pinned_car_id
                              FROM crm_leads WHERE id = ?");
        $st->execute([$leadId]);
        $lead = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // What the car was sold for. The asking price is a fallback and is labelled
    // as one, because quoting it as the sale price overstates most deals.
    $price       = (float)($lead['agreed_sale_price'] ?? 0);
    $priceSource = 'agreed';
    if ($price <= 0) {
        $ask = 0.0;
        $carId = (int)($a['car_id'] ?? ($lead['pinned_car_id'] ?? 0));
        if ($carId) {
            $st = $db->prepare("SELECT asking_price FROM cars WHERE id = ?");
            $st->execute([$carId]);
            $ask = (float)$st->fetchColumn();
        }
        $price       = $ask;
        $priceSource = $ask > 0 ? 'asking' : 'none';
    }

    // The deposit on the lead plus every receipt banked against it. Voided
    // receipts are already excluded by leadDepositTotals().
    $deposit = (float)($lead['deposit_amount'] ?? 0)
             + ($leadId ? (leadDepositTotals($db, [$leadId])[$leadId] ?? 0.0) : 0.0);

    $principal = (float)($a['principal'] ?? 0);
    $repayable = (float)($a['total_repayable'] ?? 0) ?: $principal;
    $charges   = max(0.0, round($repayable - $principal, 2));

    $gap = $price > 0 ? round($price - $deposit - $principal, 2) : 0.0;

    return [
        // Carried through because creditBook() does not select the lead's
        // stage, and the documents list needs it to know whether the car has
        // actually been handed over.
        'stage'        => (string)($lead['stage'] ?? ''),
        'price'        => $price,
        'price_source' => $priceSource,
        'deposit'      => $deposit,
        'principal'    => $principal,
        'charges'      => $charges,
        'repayable'    => $repayable,
        'paid'         => (float)($sum['paid'] ?? 0),
        'balance'      => (float)($sum['balance'] ?? 0),
        // Everything the buyer has handed over against this car, deposit included.
        'in_hand'      => round($deposit + (float)($sum['paid'] ?? 0), 2),
        'gap'          => $gap,
        // Worth mentioning only when it is money rather than rounding.
        'gap_material' => abs($gap) > 1000,
    ];
}

/**
 * The documents this deal can produce, in the order the deal produces them.
 *
 * These are printed on demand from live data rather than stored, so they are
 * always current and there is nothing to upload. 'available' is false where
 * the deal has not reached that document yet; the button is still drawn,
 * disabled, carrying 'note' as its tooltip — "there is no delivery note yet"
 * is itself the answer finance wants, and a button that vanishes reads as a
 * missing feature.
 *
 * 'btn' is the Bootstrap class the lead page already uses for that document,
 * so the two screens colour the same paperwork the same way: proforma blue,
 * sales agreement green, credit agreement solid purple, deposit receipt
 * amber, sales receipt cyan. Statement and delivery note get their own, since
 * on the lead page they sit in separate cards and never had to be told apart
 * from the others. 'short' is the toolbar label, where the full name would
 * not fit.
 *
 * A per-payment receipt carries no 'btn': there can be thirty of them, so they
 * belong against their row in the payments table, not in a toolbar.
 */
function finIssuedDocs(PDO $db, array $a, array $figures, array $payments): array
{
    $leadId = (int)($a['lead_id'] ?? 0);
    $crm    = BASE_URL . '/modules/crm/';
    $q      = '?lead_id=' . $leadId;

    $live       = array_values(array_filter($payments, fn ($p) => empty($p['voided_at'])));
    $hasCredit  = (float)($a['principal'] ?? 0) > 0;
    $delivered  = (string)($figures['stage'] ?? '') === 'delivered';

    $docs = [
        ['key' => 'proforma', 'label' => 'Proforma invoice', 'icon' => 'fa-file-invoice',
         'url' => $crm . 'proforma.php' . $q, 'available' => $leadId > 0,
         'btn' => 'btn-outline-primary', 'short' => 'Proforma',
         'note' => $leadId > 0 ? 'Priced from the deal as it stands' : 'No lead attached to this imported account'],

        ['key' => 'sales_agreement', 'label' => 'Sales agreement', 'icon' => 'fa-file-signature',
         'url' => $crm . 'sales_agreement.php' . $q, 'available' => $leadId > 0,
         'btn' => 'btn-outline-success', 'short' => 'Agreement',
         'note' => $leadId > 0 ? 'The sale itself' : 'No lead attached to this imported account'],

        // Solid purple rather than an outline, exactly as on the lead page: this
        // is the contract the money is owed under, and it leads the row.
        ['key' => 'credit_agreement', 'label' => 'Credit payment agreement', 'icon' => 'fa-file-contract',
         'url' => $crm . 'credit_payment_agreement.php' . $q, 'available' => $leadId > 0 && $hasCredit,
         'btn' => 'btn-credit', 'short' => 'Credit Agreement',
         'note' => $leadId > 0 ? ($hasCredit ? 'The schedule, as signed' : 'No credit agreement on this account') : 'No lead attached to this imported account'],

        ['key' => 'deposit_receipt', 'label' => 'Deposit receipt', 'icon' => 'fa-receipt',
         'url' => $crm . 'deposit_receipt.php' . $q,
         'available' => $leadId > 0 && (float)$figures['deposit'] > 0,
         'btn' => 'btn-outline-warning', 'short' => 'Deposit Receipt',
         'note' => (float)$figures['deposit'] > 0
                    ? money((float)$figures['deposit']) . ' received up front'
                    : 'No deposit recorded'],

        ['key' => 'statement', 'label' => 'Statement of account', 'icon' => 'fa-file-lines',
         'url' => $crm . 'credit_statement.php' . $q, 'available' => $leadId > 0 && $hasCredit,
         'btn' => 'btn-outline-dark', 'short' => 'Statement',
         'note' => $leadId > 0 ? 'Every instalment and payment to date' : 'No lead attached to this imported account'],

        ['key' => 'sales_receipt', 'label' => 'Sales receipt', 'icon' => 'fa-receipt',
         'url' => $crm . 'sales_receipt.php' . $q,
         'available' => $leadId > 0 && (float)$figures['balance'] <= 0.009 && (float)$figures['paid'] > 0,
         'btn' => 'btn-outline-info', 'short' => 'Sales Receipt',
         'note' => (float)$figures['balance'] <= 0.009 && (float)$figures['paid'] > 0
                    ? 'Paid in full'
                    : 'Issued once the account is settled'],

        ['key' => 'delivery_note', 'label' => 'Delivery note', 'icon' => 'fa-truck-ramp-box',
         'url' => $crm . 'delivery_note.php' . $q, 'available' => $leadId > 0 && $delivered,
         'btn' => 'btn-outline-secondary', 'short' => 'Delivery Note',
         'note' => $delivered ? 'Handover certificate' : 'The car has not been handed over yet'],
    ];

    // One receipt per payment taken. These are the documents the buyer is most
    // likely to ask for again, so they are listed rather than hidden behind the
    // payments table.
    foreach ($live as $p) {
        $docs[] = [
            'key'       => 'receipt-' . (int)$p['id'],
            'label'     => 'Receipt ' . (string)($p['receipt_number'] ?: '#' . (int)$p['id']),
            'icon'      => 'fa-receipt',
            'url'       => $crm . 'credit_receipt.php' . $q . '&payment_id=' . (int)$p['id'],
            'available' => true,
            // No button: these live against their row in the payments table.
            'btn'       => '',
            'short'     => '',
            'note'      => money((float)$p['amount']) . ' on '
                           . fmtDate((string)$p['paid_on'], 'j M Y'),
        ];
    }

    return $docs;
}

/**
 * Just the documents that get a button, in toolbar order.
 *
 * Split out so the page does not filter the list inline and the set the
 * toolbar draws is the set a test can check.
 */
function finDocButtons(array $issued): array
{
    return array_values(array_filter($issued, fn ($d) => ($d['btn'] ?? '') !== ''));
}

/**
 * The paperwork actually on file for this deal, across every part of it.
 *
 * leadDocsFor() with no context returns the lot, which is what finance wants
 * here: the lead page shows documents per stage because that is where they are
 * collected, but an account being chased needs them in one list.
 */
function finFiledDocs(PDO $db, array $a): array
{
    $leadId = (int)($a['lead_id'] ?? 0);
    return $leadId ? leadDocsFor($db, $leadId) : [];
}
