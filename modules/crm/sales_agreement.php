<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error','No lead specified.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

$lead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$lead->execute([$leadId]); $lead = $lead->fetch();
if (!$lead) { setFlash('error','Lead not found.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error','You can only view leads assigned to you.');
    redirect(BASE_URL.'/modules/crm/my_dashboard.php');
}

// Load pinned car
$car = null; $carImage = null;
if (!empty($lead['pinned_car_id'])) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([(int)$lead['pinned_car_id']]); $car = $s->fetch() ?: null;
        if ($car) {
            $si = $db->prepare("SELECT file_path FROM car_images WHERE car_id = ? AND is_primary=1 LIMIT 1");
            $si->execute([(int)$lead['pinned_car_id']]); $carImage = $si->fetchColumn() ?: null;
            if (!$carImage) {
                $si2 = $db->prepare("SELECT file_path FROM car_images WHERE car_id = ? LIMIT 1");
                $si2->execute([(int)$lead['pinned_car_id']]); $carImage = $si2->fetchColumn() ?: null;
            }
        }
    } catch (\Throwable $_) {}
}

// Load client if converted
$client = null;
if (!empty($lead['client_id'])) {
    $sc = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $sc->execute([(int)$lead['client_id']]); $client = $sc->fetch() ?: null;
}

// Agent
$agent = null;
if (!empty($lead['assigned_to'])) {
    $sa = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $sa->execute([(int)$lead['assigned_to']]); $agent = $sa->fetch() ?: null;
}

// Company settings
$companyName    = getSetting('company_name', APP_NAME);
$companyPhone   = getSetting('company_phone', '');
$companyEmail   = getSetting('company_email', '');
$companyAddress = getSetting('company_address', '');
$companyLogo    = getSetting('company_logo', '');

// Pricing
$carPrice  = 0;
if ($car) {
    $carPrice = (float)($car['selling_price'] ?? 0) ?: (float)($car['asking_price'] ?? 0);
}
$deposit   = (float)($lead['deposit_amount'] ?? 0);
$balance   = max(0, $carPrice - $deposit);
$depDate   = $lead['deposit_date'] ?? date('Y-m-d');

// Customer info
$buyerName  = $client['name']  ?? $lead['name'];
$buyerPhone = $client['phone'] ?? $lead['phone'] ?? '';
$buyerEmail = $client['email'] ?? $lead['email'] ?? '';
$buyerIdNo  = $client['id_number'] ?? '';

// Document references
$agmtRef  = 'AGR-' . str_pad($leadId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');
$agmtDate = date('d F Y');
$today    = date('d/m/Y');

$pageTitle = 'Sales Agreement — ' . $lead['name'];

$extraCss = '<style>
@media print {
    .d-print-none { display:none !important; }
    .app-sidebar, .topbar, .sidebar-overlay, .app-topbar,
    header.app-topbar, #sidebarBackdrop, .fab-wa, .fab-chat,
    #pwaOverlay, #toastStack { display:none !important; }
    .main-wrap { margin:0 !important; padding:0 !important; }
    .main-content, .page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; font-size:11pt; }
    #salesAgreement { border:none !important; box-shadow:none !important; }
    @page { margin:1.2cm; }
}
.agmt-section-hdr {
    font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;
    color:#6b7280;margin-bottom:12px;display:flex;align-items:center;gap:6px
}
.agmt-table { width:100%;border-collapse:collapse }
.agmt-table td,.agmt-table th { padding:8px 12px;border:1px solid #e5e7eb;font-size:13px }
.agmt-table th { background:#f8fafc;font-weight:700;color:#374151;white-space:nowrap }
.sig-line { border-bottom:1.5px solid #374151;min-height:40px;margin-bottom:6px }
</style>';

include __DIR__ . '/../../includes/header.php';
?>
<?= $extraCss ?>

<!-- Action bar -->
<div class="d-print-none mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
            <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-arrow-left me-1"></i>Back to Lead
            </a>
            <span class="text-muted" style="font-size:13px">/ <?= e($lead['name']) ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="px-3 py-2 rounded-3"
                 style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);font-size:12.5px">
                <span class="text-muted fw-semibold">Agreement Ref:</span>
                <span class="fw-bold ms-1 text-success"><?= e($agmtRef) ?></span>
            </div>
            <a href="proforma.php?lead_id=<?= $leadId ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                <i class="fa fa-file-invoice me-1"></i>Proforma Invoice
            </a>
            <button type="button" class="btn btn-success btn-sm" onclick="window.print()">
                <i class="fa fa-print me-1"></i>Print / Save PDF
            </button>
        </div>
    </div>
</div>

<!-- ── Printable Agreement ──────────────────────────────────────────────────── -->
<div id="salesAgreement" style="max-width:860px;margin:0 auto">
    <div style="background:#fff;border:1px solid #d1d5db;border-radius:12px;
                box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden">

        <!-- ══ HEADER ══════════════════════════════════════════════════════════ -->
        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a8a 100%);padding:28px 32px;color:#fff">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <?php if ($companyLogo): ?>
                    <img src="<?= BASE_URL ?>/uploads/<?= e($companyLogo) ?>"
                         alt="<?= e($companyName) ?>"
                         style="height:56px;width:auto;object-fit:contain;background:#fff;border-radius:8px;padding:5px">
                    <?php else: ?>
                    <div style="width:56px;height:56px;background:rgba(255,255,255,.15);border-radius:10px;
                                display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;
                                border:2px solid rgba(255,255,255,.3)">
                        <?= strtoupper(substr($companyName,0,1)) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:20px;font-weight:800"><?= e($companyName) ?></div>
                        <?php if ($companyAddress): ?>
                        <div style="font-size:11.5px;opacity:.8;margin-top:2px"><?= e($companyAddress) ?></div>
                        <?php endif; ?>
                        <div style="font-size:11.5px;opacity:.75;margin-top:1px">
                            <?php if ($companyPhone): ?><span><?= e($companyPhone) ?></span><?php endif; ?>
                            <?php if ($companyEmail): ?><span class="ms-2"><?= e($companyEmail) ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:10px;letter-spacing:.15em;text-transform:uppercase;opacity:.7;margin-bottom:4px">Legal Document</div>
                    <div style="font-size:22px;font-weight:900;letter-spacing:.5px">VEHICLE SALE AGREEMENT</div>
                    <div style="font-size:12px;opacity:.8;margin-top:5px">Ref: <?= e($agmtRef) ?></div>
                    <div style="font-size:12px;opacity:.8;margin-top:2px">Date: <?= e($agmtDate) ?></div>
                </div>
            </div>
        </div>

        <!-- ══ RESERVATION BADGE ════════════════════════════════════════════════ -->
        <?php if ($lead['stage'] === 'reserved'): ?>
        <div style="background:#fefce8;border-bottom:2px solid #fde047;padding:10px 28px;
                    display:flex;align-items:center;gap:12px">
            <i class="fa fa-bookmark" style="color:#ca8a04;font-size:15px"></i>
            <span style="font-weight:700;color:#854d0e;font-size:13px">RESERVATION AGREEMENT</span>
            <span style="color:#92400e;font-size:12px">
                · Vehicle reserved with deposit on <?= fmtDate($depDate,'d M Y') ?>
            </span>
        </div>
        <?php endif; ?>

        <div style="padding:28px 32px">

            <!-- ══ PARTIES ═══════════════════════════════════════════════════════ -->
            <div style="margin-bottom:24px">
                <div class="agmt-section-hdr">
                    <i class="fa fa-handshake" style="color:#2563eb"></i> Parties to This Agreement
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <!-- Seller -->
                    <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#f8fafc">
                        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#6b7280;margin-bottom:8px">The Seller</div>
                        <div style="font-weight:800;font-size:16px;color:#0f172a;margin-bottom:4px"><?= e($companyName) ?></div>
                        <?php if ($companyAddress): ?>
                        <div style="font-size:12.5px;color:#374151;margin-bottom:2px"><?= e($companyAddress) ?></div>
                        <?php endif; ?>
                        <?php if ($companyPhone): ?>
                        <div style="font-size:12.5px;color:#374151;margin-bottom:2px"><i class="fa fa-phone me-1" style="font-size:10px"></i><?= e($companyPhone) ?></div>
                        <?php endif; ?>
                        <?php if ($companyEmail): ?>
                        <div style="font-size:12.5px;color:#374151"><i class="fa fa-envelope me-1" style="font-size:10px"></i><?= e($companyEmail) ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Buyer -->
                    <div style="border:2px solid #2563eb;border-radius:10px;padding:16px;background:#eff6ff">
                        <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#2563eb;margin-bottom:8px">The Buyer</div>
                        <div style="font-weight:800;font-size:16px;color:#0f172a;margin-bottom:4px"><?= e($buyerName) ?></div>
                        <?php if ($buyerPhone): ?>
                        <div style="font-size:12.5px;color:#374151;margin-bottom:2px"><i class="fa fa-phone me-1" style="font-size:10px"></i><?= e($buyerPhone) ?></div>
                        <?php endif; ?>
                        <?php if ($buyerEmail): ?>
                        <div style="font-size:12.5px;color:#374151;margin-bottom:2px"><i class="fa fa-envelope me-1" style="font-size:10px"></i><?= e($buyerEmail) ?></div>
                        <?php endif; ?>
                        <?php if ($buyerIdNo): ?>
                        <div style="font-size:12.5px;color:#374151">ID/Passport: <?= e($buyerIdNo) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ══ VEHICLE DETAILS ════════════════════════════════════════════════ -->
            <div style="margin-bottom:24px">
                <div class="agmt-section-hdr">
                    <i class="fa fa-car" style="color:#2563eb"></i> Vehicle Description
                </div>
                <?php if ($car): ?>
                <table class="agmt-table">
                    <tbody>
                    <?php
                    $vRows = [];
                    $yearMakeModel = trim(($car['year']??'').' '.($car['make']??'').' '.($car['model']??''));
                    if ($yearMakeModel) $vRows['Year / Make / Model'] = $yearMakeModel;
                    if (!empty($car['registration_number'])) $vRows['Registration Number'] = $car['registration_number'];
                    if (!empty($car['chassis_number']))      $vRows['Chassis / VIN']       = $car['chassis_number'];
                    if (!empty($car['engine']))              $vRows['Engine Number']        = $car['engine'];
                    if (!empty($car['color']))               $vRows['Colour']               = $car['color'];
                    if (!empty($car['body_type']))           $vRows['Body Type']            = ucfirst(str_replace('_',' ',$car['body_type']));
                    if (!empty($car['transmission']))        $vRows['Transmission']         = ucfirst($car['transmission']);
                    if (!empty($car['fuel_type']))           $vRows['Fuel Type']            = ucfirst($car['fuel_type']);
                    if (!empty($car['mileage']))             $vRows['Mileage at Sale']      = number_format((int)$car['mileage']).' km';
                    foreach ($vRows as $label => $val):
                    ?>
                    <tr>
                        <th style="width:34%"><?= e($label) ?></th>
                        <td><?= e($val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;font-size:14px;color:#374151">
                    Vehicle of Interest: <strong><?= e($lead['interested_in'] ?? 'To be confirmed') ?></strong>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══ FINANCIAL TERMS ════════════════════════════════════════════════ -->
            <div style="margin-bottom:24px">
                <div class="agmt-section-hdr">
                    <i class="fa fa-coins" style="color:#16a34a"></i> Financial Terms
                </div>
                <table class="agmt-table">
                    <tbody>
                    <tr>
                        <th style="width:34%">Agreed Purchase Price</th>
                        <td style="font-weight:700;font-size:15px;color:#0f172a">
                            <?= $carPrice > 0 ? money($carPrice) : 'To be confirmed' ?>
                        </td>
                    </tr>
                    <?php if ($deposit > 0): ?>
                    <tr>
                        <th>Deposit / Reservation Fee</th>
                        <td style="color:#16a34a;font-weight:600">
                            <?= money($deposit) ?>
                            <span style="font-size:11px;color:#6b7280;margin-left:8px">
                                received <?= fmtDate($depDate,'d M Y') ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Balance Payable</th>
                        <td style="font-weight:800;font-size:15px;color:#c2410c">
                            <?= $carPrice > 0 ? money($balance) : 'To be confirmed' ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Payment Method</th>
                        <td><?= e($lead['deposit_notes'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <th>Transfer &amp; Registration Costs</th>
                        <td>Payable by the Buyer</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <!-- ══ TERMS & CONDITIONS ═════════════════════════════════════════════ -->
            <div style="margin-bottom:24px">
                <div class="agmt-section-hdr">
                    <i class="fa fa-file-contract" style="color:#7c3aed"></i> Terms &amp; Conditions
                </div>
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:16px 20px;background:#fafafa">
                    <ol style="margin:0;padding-left:18px;line-height:2.1;font-size:12.5px;color:#374151">
                        <li>The Buyer agrees to purchase and the Seller agrees to sell the vehicle described above at the agreed purchase price.</li>
                        <li>The deposit paid constitutes a reservation fee and shall be applied toward the total purchase price.</li>
                        <li>The balance of the purchase price shall be paid in full before transfer of ownership is effected.</li>
                        <li>The Seller shall retain the vehicle until full payment is received. The deposit is <strong>non-refundable</strong> if the Buyer defaults.</li>
                        <li>If the Seller is unable to deliver the vehicle, the deposit shall be refunded in full to the Buyer.</li>
                        <li>The vehicle is sold <em>as is</em> unless otherwise agreed in writing between both parties.</li>
                        <li>All transfer of ownership costs, registration fees, and stamp duties are the sole responsibility of the Buyer.</li>
                        <li>The Seller warrants that the vehicle is free of any encumbrances, hire-purchase agreements, or liens at the time of sale.</li>
                        <li>Risk of loss or damage to the vehicle passes to the Buyer upon full payment and collection.</li>
                        <li>Any modification of this agreement must be in writing and signed by both parties.</li>
                        <li>This agreement is governed by the laws of the Republic of Kenya.</li>
                        <li>Any disputes shall be resolved amicably. Failing resolution, the parties submit to the jurisdiction of the Kenyan courts.</li>
                    </ol>
                </div>
            </div>

            <!-- ══ SIGNATURES ═════════════════════════════════════════════════════ -->
            <div style="margin-bottom:8px">
                <div class="agmt-section-hdr">
                    <i class="fa fa-pen-nib" style="color:#0f172a"></i> Signatures
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px">
                    <!-- Seller -->
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px">
                            For and on behalf of the Seller
                        </div>
                        <div class="sig-line"></div>
                        <div style="font-size:12px;color:#374151;font-weight:600">
                            <?= e($agent['name'] ?? $me['name']) ?>
                        </div>
                        <div style="font-size:11.5px;color:#6b7280"><?= e($companyName) ?></div>
                        <div style="margin-top:14px">
                            <span style="font-size:11px;color:#9ca3af">Date: </span>
                            <span style="font-size:12px;font-weight:600;color:#374151"><?= $today ?></span>
                        </div>
                    </div>
                    <!-- Buyer -->
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px">
                            Buyer's Signature
                        </div>
                        <div class="sig-line"></div>
                        <div style="font-size:12px;color:#374151;font-weight:600"><?= e($buyerName) ?></div>
                        <?php if ($buyerIdNo): ?>
                        <div style="font-size:11.5px;color:#6b7280">ID/Passport: <?= e($buyerIdNo) ?></div>
                        <?php endif; ?>
                        <div style="margin-top:14px">
                            <span style="font-size:11px;color:#9ca3af">Date: </span>
                            <span style="font-size:13px;color:#374151">____________________</span>
                        </div>
                    </div>
                </div>

                <!-- Witness row -->
                <div style="margin-top:28px;padding-top:20px;border-top:1px dashed #d1d5db;
                            display:grid;grid-template-columns:1fr 1fr;gap:32px">
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px">
                            Witness Signature
                        </div>
                        <div class="sig-line"></div>
                        <div style="font-size:12px;color:#6b7280">Full Name: ____________________________</div>
                    </div>
                    <div>
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px">
                            Witness ID / Passport No.
                        </div>
                        <div class="sig-line"></div>
                        <div style="font-size:12px;color:#6b7280">Date: ________________________________</div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ══ FOOTER ═══════════════════════════════════════════════════════════ -->
        <div style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e5e7eb;
                    display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:11px;color:#9ca3af">
                <?= e($agmtRef) ?> &bull; Generated <?= e($agmtDate) ?> by <?= e($companyName) ?> CRM
            </div>
            <div style="font-size:11px;color:#9ca3af">
                Page 1 of 1
            </div>
        </div>

    </div>
</div>

<div class="d-print-none mt-4 mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
