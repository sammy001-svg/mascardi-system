<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Column migrations — silent no-op if already exist
foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id     INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount    DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_date      DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_notes     TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN due_date          DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN id_number         VARCHAR(50)   NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN kra_pin           VARCHAR(20)   NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN po_box            VARCHAR(100)  NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN id_card_front     VARCHAR(255)  NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN id_card_back      VARCHAR(255)  NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin           VARCHAR(20)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error', 'No lead specified.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

$stLead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$stLead->execute([$leadId]);
$lead = $stLead->fetch();
if (!$lead) { setFlash('error', 'Lead not found.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

// Load pinned car
$car = null;
if (!empty($lead['pinned_car_id'])) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([(int)$lead['pinned_car_id']]);
        $car = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Load client if lead was converted
$client = null;
if (!empty($lead['client_id'])) {
    try {
        $s2 = $db->prepare("SELECT * FROM clients WHERE id = ?");
        $s2->execute([(int)$lead['client_id']]);
        $client = $s2->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Buyer details — prefer client record when available
$buyerName   = trim($client['name']      ?? $lead['name']  ?? '');
$buyerPhone  = trim($client['phone']     ?? $lead['phone'] ?? '');
$buyerEmail  = trim($client['email']     ?? $lead['email'] ?? '');
// KYC fields: prefer what was captured directly on the lead, fall back to the linked client
$buyerKraPin = trim($lead['kra_pin']   ?? '') ?: trim($client['kra_pin']   ?? '');
$buyerIdNo   = trim($lead['id_number'] ?? '') ?: trim($client['id_number'] ?? '');
$buyerPoBox  = trim($lead['po_box']    ?? '');

// Purchase price: agreed_sale_price → offer_price → asking_price
$agreedPrice = (float)($lead['agreed_sale_price'] ?? 0);
if (!$agreedPrice && $car) {
    $offerPrice  = (float)($car['offer_price']  ?? 0);
    $askingPrice = (float)($car['asking_price'] ?? 0);
    $agreedPrice = $offerPrice > 0 ? $offerPrice : $askingPrice;
}
$deposit = (float)($lead['deposit_amount'] ?? 0);
$depDate = $lead['deposit_date'] ?? date('Y-m-d');

// ── Helpers ──────────────────────────────────────────────────────────────────

function _ordSuffix(int $n): string {
    if ($n >= 11 && $n <= 13) return 'th';
    return match($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
}

function _hw(int $n): string {
    static $o = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                 'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                 'Seventeen','Eighteen','Nineteen'];
    static $t = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $w = '';
    if ($n >= 100) { $w .= $o[(int)($n / 100)] . ' Hundred '; $n %= 100; }
    $w .= ($n < 20) ? $o[$n] : ($t[(int)($n / 10)] . ($n % 10 ? ' ' . $o[$n % 10] : ''));
    return trim($w);
}

function numWords(float $amt): string {
    $n = (int)round($amt);
    if (!$n) return 'Zero Only';
    $parts = [];
    foreach ([['Billion', 1_000_000_000], ['Million', 1_000_000], ['Thousand', 1_000]] as [$label, $div]) {
        if ($n >= $div) { $parts[] = _hw((int)($n / $div)) . ' ' . $label; $n %= $div; }
    }
    if ($n) $parts[] = _hw($n);
    return implode(' ', array_filter($parts)) . ' Only';
}

// Agreement date derived from deposit_date (or today)
$dateObj  = new DateTime($depDate ?: 'now');
$day      = (int)$dateObj->format('j');
$agmtDate = $day . _ordSuffix($day) . ' day of ' . $dateObj->format('F Y');

// Due date from manually entered value; fall back to blank if not yet set
$dueDateRaw = $lead['due_date'] ?? '';
$dueDate    = $dueDateRaw ? (new DateTime($dueDateRaw))->format('d/m/Y') : '';

$agmtRef  = 'AGR-' . str_pad($leadId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');

$pageTitle = 'Sales Agreement — ' . ($buyerName ?: 'Lead #' . $leadId);

include __DIR__ . '/../../includes/header.php';
?>
<style>
/* @page margin repeats on EVERY printed page (unlike element padding, which
   only applies to the first fragment of a box) — this is what keeps page 2+
   content from starting flush against the paper edge and getting clipped. */
@page { size: A4; margin: 14mm 15mm 18mm; }
/* ── Print suppression ───────────────────────────────────────────────────── */
@media print {
    /* Neutralize dark-mode's color-scheme:dark on <html>, which otherwise
       paints the @page margin area with a dark UA canvas fill — the "black
       margin around every page" bug. */
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    #salesDoc {
        box-shadow:none !important; border:none !important; border-radius:0 !important;
        max-width:100% !important;
    }
    .sa-body { padding:8px 0 6px !important; }
    .sa-page-break { break-after: page; page-break-after: always; }
    /* Keep clauses, table rows, party boxes, signatures and attachments from
       being sliced across a page boundary. */
    .sa-clause, .sa-table tr, .sa-avoid-break {
        break-inside: avoid; page-break-inside: avoid;
    }
    .sa-print-footer { display:block; }
}
.sa-print-footer { display:none; }
/* ── Design tokens ────────────────────────────────────────────────────────── */
#salesDoc {
    --ink: #0f172a; --ink-2: #475569; --ink-3: #94a3b8;
    --line: #e2e8f0; --surface: #f8fafc; --accent: #b45309;
}
/* ── Document shell ──────────────────────────────────────────────────────── */
#salesDoc {
    max-width:800px; margin:0 auto;
    background:#fff; border:1px solid var(--line);
    border-radius:10px; overflow:hidden;
    box-shadow:0 10px 40px rgba(15,23,42,.08);
    font-family:'Helvetica Neue',Arial,Helvetica,sans-serif;
    font-size:11px; color:var(--ink); line-height:1.42;
}
/* ── Clause layout ───────────────────────────────────────────────────────── */
.sa-clause { margin-bottom:8px; }
.sa-clause-title {
    font-weight:700; font-size:9.5px; margin-bottom:4px;
    text-transform:uppercase; letter-spacing:.07em; color:var(--ink);
    padding-left:9px; border-left:3px solid var(--accent);
}
.sa-clause p { margin:0; font-size:11px; line-height:1.48; color:var(--ink-2); }
/* ── Data tables ─────────────────────────────────────────────────────────── */
.sa-table { width:100%; border-collapse:collapse; margin:4px 0; }
.sa-table td, .sa-table th {
    border:none; border-bottom:1px solid var(--line); padding:4px 6px;
    font-size:11px; vertical-align:top;
}
.sa-table th {
    background:none; font-weight:600; width:34%; white-space:nowrap;
    color:var(--ink-2); text-transform:uppercase; font-size:8.5px; letter-spacing:.06em;
}
.sa-table td { color:var(--ink); font-weight:500; }
.sa-table tr:last-child td, .sa-table tr:last-child th { border-bottom:none; }
/* ── Signature lines ─────────────────────────────────────────────────────── */
.sig-line { border-bottom:1.5px solid var(--ink); min-height:26px; margin-bottom:3px; }
/* ── Running print footer (position:fixed repeats on every printed page) ──── */
.sa-print-footer {
    position:fixed; left:0; right:0; bottom:6mm; text-align:center;
    font-size:8.5px; color:var(--ink-3); letter-spacing:.02em;
    border-top:1px solid var(--line); padding-top:5px; font-family:Arial,Helvetica,sans-serif;
}
</style>

<!-- ── Action bar (screen only) ─────────────────────────────────────────────── -->
<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Lead
        </a>
        <span class="text-muted" style="font-size:12.5px">/ <?= e($lead['name']) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span class="badge bg-light text-dark border" style="font-size:12px">Ref: <?= e($agmtRef) ?></span>
        <a href="proforma.php?lead_id=<?= $leadId ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa fa-file-invoice me-1"></i>Proforma Invoice
        </a>
        <button class="btn btn-success btn-sm" onclick="window.print()">
            <i class="fa fa-print me-1"></i>Print / Save PDF
        </button>
    </div>
</div>

<div class="d-print-none alert alert-light border small mb-4" style="font-size:12px">
    <i class="fa fa-circle-info me-1 text-muted"></i>
    For a clean printout with no browser date/title line, open <strong>More settings</strong> in the print
    dialog and untick <strong>Headers and footers</strong>.
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRINTABLE SALES AGREEMENT DOCUMENT
════════════════════════════════════════════════════════════════════════════ -->
<div id="salesDoc">

    <!-- ══ HEADER: clean wordmark + document title/ref ═══════════════════════ -->
    <div style="padding:14px 26px 10px;display:flex;justify-content:space-between;
                align-items:flex-start;gap:16px;border-bottom:2px solid var(--ink)">
        <div>
            <div style="font-size:19px;font-weight:800;letter-spacing:3px;color:var(--ink)">MASCARDI</div>
            <div style="font-size:8.5px;letter-spacing:.22em;color:var(--ink-3);
                        text-transform:uppercase;margin-top:2px">Ventures Limited</div>
        </div>
        <div style="text-align:right;flex-shrink:0">
            <div style="display:inline-block;background:var(--ink);color:#fff;font-size:9.5px;
                        font-weight:700;letter-spacing:.09em;text-transform:uppercase;
                        padding:5px 13px;border-radius:3px;white-space:nowrap">
                Car Sales Agreement
            </div>
            <div style="font-size:9.5px;color:var(--ink-2);margin-top:5px">Ref: <?= e($agmtRef) ?></div>
        </div>
    </div>

    <!-- ══ DOCUMENT BODY ═══════════════════════════════════════════════════════ -->
    <div class="sa-body" style="padding:12px 26px 14px">

        <!-- Opening paragraph -->
        <p style="margin:0 0 9px;font-size:11px;line-height:1.5">
            This Car Sale Agreement (<strong>"Agreement"</strong>) is made and entered into on this
            <strong><?= e($agmtDate) ?></strong>, by and between:
        </p>

        <!-- ── Parties ──────────────────────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">

            <!-- Seller -->
            <div class="sa-avoid-break" style="border:1px solid var(--line);border-top:3px solid var(--ink);
                        border-radius:6px;padding:8px 11px">
                <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.1em;color:var(--ink-3);margin-bottom:4px">The Seller</div>
                <div style="font-weight:700;font-size:11.5px;margin-bottom:2px;color:var(--ink)">Mascardi Ventures Limited</div>
                <div style="font-size:10.5px;color:var(--ink-2);line-height:1.45">
                    Reg. No. PVT-ZQUXL55<br>
                    291 Kabete Lane Spring Valley<br>
                    P.O.Box 1391-00606<br>
                    Nairobi Kenya<br>
                    Sales@mascardi.co
                </div>
            </div>

            <!-- Buyer -->
            <div class="sa-avoid-break" style="border:1px solid var(--line);border-top:3px solid var(--accent);
                        border-radius:6px;padding:8px 11px">
                <div style="font-size:8.5px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.1em;color:var(--ink-3);margin-bottom:4px">The Buyer</div>
                <div style="font-weight:700;font-size:11.5px;margin-bottom:2px;color:var(--ink)"><?= e($buyerName) ?></div>
                <div style="font-size:10.5px;color:var(--ink-2);line-height:1.45">
                    <?php if ($buyerKraPin): ?>Pin: <?= e($buyerKraPin) ?><br><?php endif; ?>
                    P.O Box: <?= e($buyerPoBox ?: '___________________') ?><br>
                    <?php if ($buyerEmail): ?><?= e($buyerEmail) ?><br><?php endif; ?>
                    <?php if ($buyerPhone): ?>Phone Number: <?= e($buyerPhone) ?><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── 1. Vehicle Details ───────────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">1. Vehicle Details</div>
            <table class="sa-table">
                <tr>
                    <th>Make &amp; Model</th>
                    <td><?= $car ? e(trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) : '—' ?></td>
                </tr>
                <tr>
                    <th>Year</th>
                    <td><?= $car ? e($car['year'] ?? '—') : '—' ?></td>
                </tr>
                <tr>
                    <th>Registration No.</th>
                    <td><?= $car ? e($car['registration_number'] ?? '—') : '—' ?></td>
                </tr>
                <tr>
                    <th>Chassis No.</th>
                    <td><?= $car ? e($car['chassis_number'] ?? '—') : '—' ?></td>
                </tr>
                <tr>
                    <th>Engine No.</th>
                    <td><?= $car ? e($car['engine_number'] ?? '—') : '—' ?></td>
                </tr>
                <tr>
                    <th>Rating (cc)</th>
                    <td><?= ($car && !empty($car['engine_cc'])) ? e($car['engine_cc']) . ' cc' : '—' ?></td>
                </tr>
            </table>
        </div>

        <!-- ── 2. Purchase Price ────────────────────────────────────────────── -->
        <div class="sa-clause sa-avoid-break">
            <div class="sa-clause-title">2. Purchase Price</div>
            <?php if ($agreedPrice > 0): ?>
            <div style="background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:6px 11px">
                <div style="font-size:13.5px;font-weight:800;color:var(--ink)">KSH <?= number_format($agreedPrice, 0) ?>/-</div>
                <div style="font-size:10px;color:var(--ink-2);margin-top:1px">[Ksh <?= numWords($agreedPrice) ?>]</div>
            </div>
            <?php else: ?>
            <p><em>To be confirmed</em></p>
            <?php endif; ?>
        </div>

        <!-- ── 3. Payment Terms ─────────────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">3. Payment Terms</div>
            <table class="sa-table" style="margin-bottom:4px">
                <tr>
                    <th>Full Amount</th>
                    <td><?= $agreedPrice > 0 ? 'KSH ' . number_format($agreedPrice, 0) . '/-' : '—' ?></td>
                </tr>
                <tr>
                    <th>Deposit Paid</th>
                    <td>
                        <?php if ($deposit > 0): ?>
                            KSH <?= number_format($deposit, 0) ?>/-
                            <?php if ($depDate): ?>
                                <span style="color:var(--ink-2);font-size:10px">
                                    (received <?= (new DateTime($depDate))->format('d M Y') ?>)
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Balance</th>
                    <td style="font-weight:700">
                        <?php
                            $saBalance = max(0, $agreedPrice - $deposit);
                            echo $agreedPrice > 0 ? 'KSH ' . number_format($saBalance, 0) . '/-' : '—';
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>Due Date</th>
                    <td><?= $dueDate ? e($dueDate) : '<span style="color:#999">—</span>' ?></td>
                </tr>
            </table>
            <p>The Buyer agrees to pay the full Purchase Price to the Seller as per the schedule above.
            Failure to do so grants the Seller the right to withhold possession of the vehicle.
            All payments made by the Purchaser shall be deemed as commitment toward securing the Vehicle
            and reserving the agreed price and allocation and shall not be refunded without deductions in
            the event of withdrawal, default, or delay by the Purchaser. Incase of non-payment by the client 
            as per the agreed schedule, Mascardi retains the right to sell the car to other interested parties. 
            All payments received remain subject to standard company T&Cs: https://www.mascardi.co/terms-of-service</p>
        </div>

        <!-- ── 4. Delivery, Vehicle Insurance and Transfer of Risk ─────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">4. Delivery, Vehicle Insurance and Transfer of Risk</div>
            <p>Upon receipt of the agreed payment, the Seller shall deliver the vehicle to the Buyer. 
            The risk and liability of the vehicle shall be transferred to the Buyer upon delivery, and 
            the Buyer shall be responsible for obtaining comprehensive vehicle insurance from this 
            point onwards prior to taking delivery of the vehicle.</p>
        </div>

        <!-- ── 5. Ownership and Retention of Title ─────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">5. Ownership and Retention of Title</div>
            <p>The Seller shall retain full ownership and title of the vehicle until the Buyer 
            has completed all payments in full as agreed. Should the Buyer fail to meet the 
            payment terms by the specified due date, the Seller retains the right to immediately 
            repossess the vehicle without prior notice or legal proceedings. The Buyer shall bear 
            all costs associated with the repossession, including but not limited to legal fees, 
            transportation, and administrative expenses. Furthermore, any partial payments made by 
            the Buyer prior to repossession shall be deemed non-refundable and retained by the 
            Seller as liquidated damages. In the case of any pending credit payment, both parties 
            hereby agree that a tracking device will be installed in the vehicle at the expense of 
            the buyer.</p>
        </div>

        <!-- ── 6. Vehicle Condition Disclaimer ─────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">6. Vehicle Condition Disclaimer</div>
            <p>The vehicle is sold on an <strong>"AS IS, WHERE IS"</strong> IS' basis, with no 
            warranties, either express or implied, as to the condition or functionality of the 
            vehicle after it leaves the Seller’s premises. The Buyer acknowledges and accepts 
            that the vehicle meets their requirements upon purchase. The Seller confirms the 
            car to be in abidance with the Kenyan roadworthy regulations as of the date of 
            sale.</p>
        </div>

        <!-- ── 7. Transfer of Ownership ─────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">7. Transfer of Ownership</div>
            <p>Upon receipt of full payment, the Seller shall provide the Buyer with duly executed 
            transfer forms and the Logbook. The Buyer shall be responsible for any transfer charges.</p>
        </div>

        <!-- ── 8. Amendments or Attachments ─────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">8. Amendments or Attachments</div>
            <p>Any amendments to this agreement or any additional agreements may be attached as 
            an addendum to this agreement. These addenda shall be considered part of this Agreement 
            and enforceable as such.</p>
        </div>

        <!-- ── 9. Binding Agreement ──────────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">9. Binding Agreement</div>
            <p>This Agreement represents the entire understanding between the Parties regarding this 
            transaction. Both Parties have entered into this Agreement freely and accept to be bound 
            by its terms.</p>
        </div>

        <!-- ── Signatures ─────────────────────────────────────────────────────── -->
        <div class="sa-avoid-break" style="margin-top:10px;border-top:2px solid var(--ink);padding-top:10px">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:10px">

                <!-- Buyer signature block -->
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:10.5px;margin-top:3px;color:var(--ink)"><strong>Buyer Signature</strong></div>
                    <div style="margin-top:7px;font-size:10.5px;color:var(--ink-2)">Date: _____________________</div>
                    <div style="margin-top:9px">
                        <div class="sig-line"></div>
                        <div style="font-size:10.5px;margin-top:3px;color:var(--ink-2)">
                            Name: <?= e($buyerName) ?>
                        </div>
                        <div style="font-size:10.5px;margin-top:2px;color:var(--ink-2)">
                            ID No.: <?= e($buyerIdNo ?: '_______________________') ?>
                        </div>
                    </div>
                </div>

                <!-- Seller signature block -->
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:10.5px;margin-top:3px;color:var(--ink)"><strong>Seller Signature</strong></div>
                    <div style="margin-top:7px;font-size:10.5px;color:var(--ink-2)">Date: _____________________</div>
                    <div style="margin-top:9px">
                        <div class="sig-line"></div>
                        <div style="font-size:10.5px;margin-top:3px;color:var(--ink-2)">Name: _____________________</div>
                        <div style="font-size:10.5px;margin-top:2px;color:var(--ink-2)">ID No.: ___________________</div>
                    </div>
                </div>
            </div>

            <!-- Witness row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:22px;
                        padding-top:8px;border-top:1px dashed var(--line)">
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:10px;margin-top:3px;color:var(--ink-2)">Witness (Buyer)</div>
                    <div style="font-size:10px;margin-top:5px;color:var(--ink-2)">
                        ID No.: ___________________
                    </div>
                </div>
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:10px;margin-top:3px;color:var(--ink-2)">Witness (Seller)</div>
                    <div style="font-size:10px;margin-top:5px;color:var(--ink-2)">
                        ID No.: ___________________
                    </div>
                </div>
            </div>

        </div><!-- /signatures -->

        <!-- ── Attachments: Buyer ID card (front + back share a single page) ───── -->
        <?php if (!empty($lead['id_card_front']) || !empty($lead['id_card_back'])): ?>
        <div class="sa-page-break"></div>
        <div class="sa-avoid-break" style="padding-top:10px">
            <div style="text-align:center;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid var(--ink)">
                <div style="font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:var(--ink)">
                    Attachment — Buyer ID Card
                </div>
            </div>
        </div>
        <?php if (!empty($lead['id_card_front'])): ?>
        <div class="sa-avoid-break" style="text-align:center;margin-bottom:14px">
            <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-3);margin-bottom:5px">Front</div>
            <img src="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_front']) ?>"
                 style="max-width:100%;max-height:400px;border:1px solid var(--line);border-radius:6px">
        </div>
        <?php endif; ?>
        <?php if (!empty($lead['id_card_back'])): ?>
        <div class="sa-avoid-break" style="text-align:center">
            <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--ink-3);margin-bottom:5px">Back</div>
            <img src="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_back']) ?>"
                 style="max-width:100%;max-height:400px;border:1px solid var(--line);border-radius:6px">
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- /body padding -->
</div><!-- /#salesDoc -->

<!-- Print-only running footer — position:fixed repeats it on every printed page -->
<div class="sa-print-footer">Mascardi Ventures Limited &middot; Car Sales Agreement &middot; Ref: <?= e($agmtRef) ?></div>

<div class="d-print-none mt-4 mb-4"></div>

<script>
// Browsers print their own date/title header line using document.title when
// "Headers and footers" is left on — shorten it to just the doc name so that
// fallback line reads clean instead of "Sales Agreement — Name — Company".
(function () {
    var original = document.title;
    var printTitle = 'Car Sales Agreement — ' + <?= json_encode($agmtRef) ?>;
    window.addEventListener('beforeprint', function () { document.title = printTitle; });
    window.addEventListener('afterprint', function () { document.title = original; });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
