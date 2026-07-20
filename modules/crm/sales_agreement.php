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
/* ── Document shell ──────────────────────────────────────────────────────── */
#salesDoc {
    max-width:800px; margin:0 auto;
    background:#fff; border:1px solid #ccc;
    border-radius:6px; overflow:hidden;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    font-family:Arial,Helvetica,sans-serif;
    font-size:12.5px; color:#1a1a1a; line-height:1.6;
}
/* ── Clause layout ───────────────────────────────────────────────────────── */
.sa-clause { margin-bottom:13px; }
.sa-clause-title { font-weight:700; font-size:13px; margin-bottom:5px; }
.sa-clause p { margin:0; font-size:12.5px; line-height:1.8; }
/* ── Data tables ─────────────────────────────────────────────────────────── */
.sa-table { width:100%; border-collapse:collapse; margin:5px 0; }
.sa-table td, .sa-table th {
    border:1px solid #ccc; padding:5px 10px;
    font-size:12.5px; vertical-align:top;
}
.sa-table th { background:#f0f0f0; font-weight:700; width:38%; white-space:nowrap; }
/* ── Signature lines ─────────────────────────────────────────────────────── */
.sig-line { border-bottom:1.5px solid #333; min-height:38px; margin-bottom:4px; }
/* ── Running print footer (position:fixed repeats on every printed page) ──── */
.sa-print-footer {
    position:fixed; left:0; right:0; bottom:6mm; text-align:center;
    font-size:8.5px; color:#999; letter-spacing:.02em;
    border-top:1px solid #ddd; padding-top:5px; font-family:Arial,Helvetica,sans-serif;
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

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRINTABLE SALES AGREEMENT DOCUMENT
════════════════════════════════════════════════════════════════════════════ -->
<div id="salesDoc">

    <!-- ══ HEADER: black background + layered grey diagonal accent (top-right) ══ -->
    <div style="background:#111111;position:relative;overflow:hidden;min-height:86px;display:flex;align-items:center">
        <!-- Grey diagonal bands — three layers for depth -->
        <div style="position:absolute;right:0;top:0;bottom:0;width:260px;
                    background:#666666;clip-path:polygon(28% 0,100% 0,100% 100%,0% 100%)"></div>
        <div style="position:absolute;right:0;top:0;bottom:0;width:180px;
                    background:#8a8a8a;clip-path:polygon(35% 0,100% 0,100% 100%,0% 100%)"></div>
        <div style="position:absolute;right:0;top:0;bottom:0;width:100px;
                    background:#b0b0b0;clip-path:polygon(40% 0,100% 0,100% 100%,0% 100%)"></div>
        <!-- Mascardi logo text -->
        <div style="position:relative;z-index:2;padding:18px 28px;color:#ffffff">
            <div style="font-size:30px;font-weight:900;letter-spacing:6px;line-height:1;
                        font-family:'Arial Black',Arial,sans-serif">MASCARDI</div>
            <div style="font-size:10px;letter-spacing:2px;color:#bbbbbb;margin-top:4px;
                        text-transform:uppercase">Ventures Limited</div>
        </div>
    </div>

    <!-- ══ DOCUMENT BODY ═══════════════════════════════════════════════════════ -->
    <div class="sa-body" style="padding:22px 30px 28px">

        <!-- Title -->
        <div style="text-align:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #111111">
            <div style="font-size:16px;font-weight:900;letter-spacing:3px;text-transform:uppercase">
                Car Sales Agreement
            </div>
        </div>

        <!-- Opening paragraph -->
        <p style="margin:0 0 16px;font-size:12.5px;line-height:1.8">
            This Car Sale Agreement (<strong>"Agreement"</strong>) is made and entered into on this
            <strong><?= e($agmtDate) ?></strong>, by and between:
        </p>

        <!-- ── Parties ──────────────────────────────────────────────────────── -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">

            <!-- Seller -->
            <div class="sa-avoid-break" style="border:1px solid #cccccc;padding:12px 14px">
                <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.08em;color:#777777;margin-bottom:7px">The Seller</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px">Mascardi Ventures Limited</div>
                <div style="font-size:12px;color:#333333;line-height:1.75">
                    Reg. No. PVT-ZQUXL55<br>
                    291 Kabete Lane Spring Valley<br>
                    P.O.Box 1391-00606<br>
                    Nairobi Kenya<br>
                    Sales@mascardi.co
                </div>
            </div>

            <!-- Buyer -->
            <div class="sa-avoid-break" style="border:1px solid #cccccc;padding:12px 14px">
                <div style="font-size:9.5px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.08em;color:#777777;margin-bottom:7px">The Buyer</div>
                <div style="font-weight:700;font-size:13px;margin-bottom:4px"><?= e($buyerName) ?></div>
                <div style="font-size:12px;color:#333333;line-height:1.75">
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
        <div class="sa-clause">
            <div class="sa-clause-title">2. Purchase Price</div>
            <p>
                <?php if ($agreedPrice > 0): ?>
                    <strong>KSH. <?= number_format($agreedPrice, 0) ?>/-
                    [Ksh <?= numWords($agreedPrice) ?>]</strong>
                <?php else: ?>
                    <em>To be confirmed</em>
                <?php endif; ?>
            </p>
        </div>

        <!-- ── 3. Payment Terms ─────────────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">3. Payment Terms</div>
            <table class="sa-table" style="margin-bottom:8px">
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
                                <span style="color:#555;font-size:11.5px">
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
            the event of withdrawal, default, or delay by the Purchaser. All payments received remain
            subject to standard company t&amp;c:
            https://www.mascardi.co/terms-of-service</p>
        </div>

        <!-- ── Page break: clauses 4–9 + signatures on page 2 ──────────────── -->
        <div class="sa-page-break"></div>

        <!-- ── 4. Delivery, Vehicle Insurance and Transfer of Risk ─────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">4. Delivery, Vehicle Insurance and Transfer of Risk</div>
            <p>Upon receipt of the agreed payment, the Seller shall deliver the vehicle to the Buyer.
            The risk and liability of the vehicle shall be transferred to the Buyer upon delivery, and the
            Buyer shall be responsible for obtaining comprehensive vehicle insurance from this point
            onwards prior to taking delivery of the vehicle.</p>
        </div>

        <!-- ── 5. Ownership and Retention of Title ─────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">5. Ownership and Retention of Title</div>
            <p>Title to the vehicle shall not pass to the Buyer until the full Purchase Price has been
            received in cleared funds by the Seller. Until such time, the Seller retains full ownership
            and the right to repossess the vehicle without prior notice if payment obligations are not
            met. The Buyer acknowledges and consents to the installation and/or retention of a tracking
            device on the vehicle, which the Seller reserves the right to activate for the purposes of
            locating and recovering the vehicle in the event of non-payment, default, or any breach of
            this Agreement by the Buyer.</p>
        </div>

        <!-- ── 6. Vehicle Condition Disclaimer ─────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">6. Vehicle Condition Disclaimer</div>
            <p>The vehicle is sold on an <strong>"AS IS, WHERE IS"</strong> basis. The Seller makes no
            representations or warranties, express or implied, as to the condition, merchantability, or
            fitness for any particular purpose of the vehicle. The Buyer confirms that they have had the
            opportunity to inspect the vehicle prior to executing this Agreement and accepts it in its
            present condition.</p>
        </div>

        <!-- ── 7. Transfer of Ownership ─────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">7. Transfer of Ownership</div>
            <p>Upon receipt of full payment, the Seller shall provide the Buyer with the duly executed
            transfer forms and the vehicle's Logbook (Registration Certificate). The Buyer shall bear all
            costs associated with effecting the transfer of ownership at the relevant government authority,
            including registration fees, stamp duties, and any other applicable charges.</p>
        </div>

        <!-- ── 8. Amendments or Attachments ─────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">8. Amendments or Attachments</div>
            <p>Any amendment, modification, or addendum to this Agreement must be made in writing and
            duly signed by both parties. Any schedules or attachments appended hereto shall form an
            integral part of this Agreement and shall be equally binding on both parties.</p>
        </div>

        <!-- ── 9. Binding Agreement ──────────────────────────────────────────── -->
        <div class="sa-clause">
            <div class="sa-clause-title">9. Binding Agreement</div>
            <p>This Agreement constitutes the entire and binding agreement between the parties with
            respect to the subject matter hereof and supersedes all prior negotiations, representations,
            warranties, and understandings. This Agreement shall be governed by, and construed in
            accordance with, the laws of the Republic of Kenya.</p>
        </div>

        <!-- ── Signatures ─────────────────────────────────────────────────────── -->
        <div class="sa-avoid-break" style="margin-top:18px;border-top:2px solid #111111;padding-top:16px">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:18px">

                <!-- Buyer signature block -->
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:11.5px;margin-top:4px"><strong>Buyer Signature</strong></div>
                    <div style="margin-top:12px;font-size:11.5px">Date: _____________________</div>
                    <div style="margin-top:16px">
                        <div class="sig-line"></div>
                        <div style="font-size:11.5px;margin-top:4px">
                            Name: <?= e($buyerName) ?>
                        </div>
                        <div style="font-size:11.5px;margin-top:3px">
                            ID No.: <?= e($buyerIdNo ?: '_______________________') ?>
                        </div>
                    </div>
                </div>

                <!-- Seller signature block -->
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:11.5px;margin-top:4px"><strong>Seller Signature</strong></div>
                    <div style="margin-top:12px;font-size:11.5px">Date: _____________________</div>
                    <div style="margin-top:16px">
                        <div class="sig-line"></div>
                        <div style="font-size:11.5px;margin-top:4px">Name: _____________________</div>
                        <div style="font-size:11.5px;margin-top:3px">ID No.: ___________________</div>
                    </div>
                </div>
            </div>

            <!-- Witness row -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;
                        padding-top:12px;border-top:1px dashed #bbbbbb">
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:11px;margin-top:4px;color:#444444">Witness (Buyer)</div>
                    <div style="font-size:11px;margin-top:8px;color:#444444">
                        ID No.: ___________________
                    </div>
                </div>
                <div>
                    <div class="sig-line"></div>
                    <div style="font-size:11px;margin-top:4px;color:#444444">Witness (Seller)</div>
                    <div style="font-size:11px;margin-top:8px;color:#444444">
                        ID No.: ___________________
                    </div>
                </div>
            </div>

        </div><!-- /signatures -->

        <!-- ── Attachments: Buyer ID card (front/back) ─────────────────────────── -->
        <?php if (!empty($lead['id_card_front'])): ?>
        <div class="sa-page-break"></div>
        <div class="sa-avoid-break" style="padding-top:10px">
            <div style="text-align:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #111111">
                <div style="font-size:16px;font-weight:900;letter-spacing:3px;text-transform:uppercase">
                    Attachment — ID Card (Front)
                </div>
            </div>
            <div style="text-align:center">
                <img src="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_front']) ?>"
                     style="max-width:100%;max-height:650px;border:1px solid #ccc;border-radius:4px">
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($lead['id_card_back'])): ?>
        <div class="sa-page-break"></div>
        <div class="sa-avoid-break" style="padding-top:10px">
            <div style="text-align:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #111111">
                <div style="font-size:16px;font-weight:900;letter-spacing:3px;text-transform:uppercase">
                    Attachment — ID Card (Back)
                </div>
            </div>
            <div style="text-align:center">
                <img src="<?= BASE_URL ?>/uploads/leads/<?= e($lead['id_card_back']) ?>"
                     style="max-width:100%;max-height:650px;border:1px solid #ccc;border-radius:4px">
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /body padding -->
</div><!-- /#salesDoc -->

<!-- Print-only running footer — position:fixed repeats it on every printed page -->
<div class="sa-print-footer">Mascardi Ventures Limited &middot; Car Sales Agreement &middot; Ref: <?= e($agmtRef) ?></div>

<div class="d-print-none mt-4 mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
