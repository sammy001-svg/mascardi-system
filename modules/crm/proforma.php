<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// ── Ensure pinned_car_id column exists ────────────────────────────────────────
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN pinned_car_id INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// ── Load lead ─────────────────────────────────────────────────────────────────
$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) {
    setFlash('error', 'No lead specified.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

$stLead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$stLead->execute([$leadId]);
$lead = $stLead->fetch();

if (!$lead) {
    setFlash('error', 'Lead not found.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

// CRM agent isolation: agents may only view their own leads
if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

// ── POST: send notification ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_notification') {
    $leadName = $lead['name'];
    $link     = BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId;
    notifyRoles(
        ['admin', 'sales_manager'],
        'info',
        "CRM Proforma sent for {$leadName}",
        "Proforma quote prepared by {$me['name']}. Lead: {$leadName}.",
        $link
    );

    // Log activity on the lead
    try {
        $db->prepare(
            "INSERT INTO crm_activities (lead_id, type, summary, created_by, created_at) VALUES (?, 'note', ?, ?, NOW())"
        )->execute([$leadId, "Proforma quote sent to sales team for {$leadName}.", $uid]);
    } catch (\Throwable $_) {}

    logActivity('send_proforma', 'crm_leads', $leadId, "Proforma sent for lead: {$leadName}");
    setFlash('success', 'Sales team has been notified.');
    redirect(BASE_URL . '/modules/crm/proforma.php?lead_id=' . $leadId);
}

// ── Load related records ──────────────────────────────────────────────────────
// Client (if lead was converted)
$client = null;
if (!empty($lead['client_id'])) {
    $stClient = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $stClient->execute([(int)$lead['client_id']]);
    $client = $stClient->fetch() ?: null;
}

// Pinned car
$car      = null;
$carImage = null;
if (!empty($lead['pinned_car_id'])) {
    try {
        $stCar = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $stCar->execute([(int)$lead['pinned_car_id']]);
        $car = $stCar->fetch() ?: null;

        if ($car) {
            $stImg = $db->prepare(
                "SELECT file_path FROM car_images WHERE car_id = ? AND is_primary = 1 LIMIT 1"
            );
            $stImg->execute([(int)$lead['pinned_car_id']]);
            $carImage = $stImg->fetchColumn() ?: null;

            // Fallback: any image if no primary set
            if (!$carImage) {
                $stImg2 = $db->prepare("SELECT file_path FROM car_images WHERE car_id = ? LIMIT 1");
                $stImg2->execute([(int)$lead['pinned_car_id']]);
                $carImage = $stImg2->fetchColumn() ?: null;
            }
        }
    } catch (\Throwable $_) {}
}

// Company settings
$companyName    = getSetting('company_name', APP_NAME);
$companyPhone   = getSetting('company_phone', '');
$companyEmail   = getSetting('company_email', '');
$companyAddress = getSetting('company_address', '');
$companyLogo    = getSetting('company_logo', '');

// Agent user
$agentUser = null;
if (!empty($lead['assigned_to'])) {
    $stAgent = $db->prepare("SELECT name, email FROM users WHERE id = ?");
    $stAgent->execute([(int)$lead['assigned_to']]);
    $agentUser = $stAgent->fetch() ?: null;
}

// Quote reference and date
$quoteRef  = 'PRF-' . $leadId . '-' . date('ymd');
$quoteDate = date('d F Y');

// Customer display info — prefer converted client data
$customerName  = $client['name']  ?? $lead['name'];
$customerPhone = $client['phone'] ?? $lead['phone'] ?? '';
$customerEmail = $client['email'] ?? $lead['email'] ?? '';

$pageTitle = 'Proforma — ' . $lead['name'];

$extraCss = '<style>
@media print {
    .d-print-none { display: none !important; }
    .app-sidebar, .topbar, .sidebar-overlay, .app-topbar,
    header.app-topbar, #sidebarBackdrop, .fab-wa, .fab-chat,
    #pwaOverlay, #toastStack { display: none !important; }
    .main-wrap { margin: 0 !important; padding: 0 !important; }
    .main-content, .page-body { margin: 0 !important; padding: 0 !important; }
    #proforma { border: none !important; box-shadow: none !important; margin: 0 !important; }
    body { background: white !important; font-size: 12pt; }
    .proforma-card { box-shadow: none !important; border: none !important; }
    .no-print { display: none !important; }
    @page { margin: 1cm; }
}
</style>';

include __DIR__ . '/../../includes/header.php';
?>

<?= $extraCss ?>

<!-- ── Action bar (screen only, hidden on print) ──────────────────────────── -->
<div class="d-print-none mb-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
            <a href="view_lead.php?id=<?= $leadId ?>"
               class="btn btn-outline-secondary btn-sm">
                <i class="fa fa-arrow-left me-1"></i> Back to Lead
            </a>
            <span class="text-muted" style="font-size:13px">
                / <?= e($lead['name']) ?>
            </span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="px-3 py-2 rounded-3"
                 style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);font-size:12.5px">
                <span class="text-muted fw-semibold">Quote Ref:</span>
                <span class="fw-bold ms-1 text-primary"><?= e($quoteRef) ?></span>
            </div>
            <!-- Notify Sales Team -->
            <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_notification">
                <button type="submit" class="btn btn-outline-success btn-sm"
                        onclick="return confirm('Notify the sales team about this proforma?')">
                    <i class="fa fa-bell me-1"></i> Notify Sales Team
                </button>
            </form>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fa fa-print me-1"></i> Print / Save PDF
            </button>
        </div>
    </div>
</div>

<!-- ── Printable Proforma ──────────────────────────────────────────────────── -->
<div id="proforma" style="max-width:860px;margin:0 auto">
    <div class="proforma-card"
         style="background:#fff;border:1px solid #d1d5db;border-radius:12px;
                box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden">

        <!-- ══ HEADER ══════════════════════════════════════════════════════════ -->
        <div style="background:linear-gradient(135deg,#1e3a8a 0%,#2563eb 100%);padding:28px 32px;color:#fff">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <!-- Company identity -->
                <div class="d-flex align-items-center gap-3">
                    <?php if ($companyLogo): ?>
                    <img src="<?= BASE_URL ?>/uploads/<?= e($companyLogo) ?>"
                         alt="<?= e($companyName) ?>"
                         style="height:60px;width:auto;object-fit:contain;
                                background:#fff;border-radius:8px;padding:6px">
                    <?php else: ?>
                    <div style="width:60px;height:60px;background:rgba(255,255,255,.15);
                                border-radius:12px;display:flex;align-items:center;
                                justify-content:center;font-size:26px;font-weight:900;
                                border:2px solid rgba(255,255,255,.3)">
                        <?= strtoupper(substr($companyName, 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px">
                            <?= e($companyName) ?>
                        </div>
                        <?php if ($companyAddress): ?>
                        <div style="font-size:12px;opacity:.85;margin-top:2px">
                            <?= e($companyAddress) ?>
                        </div>
                        <?php endif; ?>
                        <div style="font-size:12px;opacity:.8;margin-top:1px">
                            <?php if ($companyPhone): ?>
                            <span><i class="fa fa-phone me-1"></i><?= e($companyPhone) ?></span>
                            <?php endif; ?>
                            <?php if ($companyEmail): ?>
                            <span class="ms-2"><i class="fa fa-envelope me-1"></i><?= e($companyEmail) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Quote label + meta -->
                <div style="text-align:right">
                    <div style="font-size:26px;font-weight:900;letter-spacing:1px;
                                text-transform:uppercase;opacity:.95">
                        Proforma Quote
                    </div>
                    <div style="font-size:13px;opacity:.85;margin-top:4px">
                        <i class="fa fa-hashtag me-1"></i><?= e($quoteRef) ?>
                    </div>
                    <div style="font-size:13px;opacity:.85;margin-top:2px">
                        <i class="fa fa-calendar me-1"></i><?= e($quoteDate) ?>
                    </div>
                    <div style="margin-top:8px">
                        <span style="background:rgba(255,255,255,.2);border-radius:20px;
                                     padding:4px 14px;font-size:11.5px;font-weight:700;
                                     border:1px solid rgba(255,255,255,.35)">
                            Valid for 7 days
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($lead['stage'] === 'reserved'): ?>
        <!-- Reservation status strip -->
        <div style="background:#fefce8;border-bottom:2px solid #fde047;padding:10px 28px;display:flex;align-items:center;gap:12px">
            <i class="fa fa-bookmark" style="color:#ca8a04;font-size:16px"></i>
            <span style="font-weight:700;color:#854d0e;font-size:13.5px">VEHICLE RESERVED</span>
            <?php if (!empty($lead['deposit_date'])): ?>
            <span style="color:#92400e;font-size:12px">· Deposit received <?= fmtDate($lead['deposit_date'],'d M Y') ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ══ PREPARED FOR / SALES EXEC ══════════════════════════════════════ -->
        <div style="display:grid;grid-template-columns:1fr 1fr;border-bottom:2px solid #e5e7eb">
            <!-- Customer -->
            <div style="padding:22px 28px;border-right:1px solid #e5e7eb">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;
                            letter-spacing:.1em;color:#6b7280;margin-bottom:10px">
                    Prepared For
                </div>
                <div style="font-size:17px;font-weight:700;color:#0f172a;margin-bottom:6px">
                    <?= e($customerName) ?>
                </div>
                <?php if ($customerPhone): ?>
                <div style="font-size:13px;color:#374151;margin-bottom:3px">
                    <i class="fa fa-phone me-2 text-muted" style="font-size:11px"></i><?= e($customerPhone) ?>
                </div>
                <?php endif; ?>
                <?php if ($customerEmail): ?>
                <div style="font-size:13px;color:#374151;margin-bottom:3px">
                    <i class="fa fa-envelope me-2 text-muted" style="font-size:11px"></i><?= e($customerEmail) ?>
                </div>
                <?php endif; ?>
                <?php if ($client && !empty($client['kra_pin'])): ?>
                <div style="font-size:12px;color:#6b7280;margin-top:4px">
                    <span style="font-weight:600">KRA PIN:</span> <?= e($client['kra_pin']) ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- Sales agent -->
            <div style="padding:22px 28px;background:#f9fafb">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;
                            letter-spacing:.1em;color:#6b7280;margin-bottom:10px">
                    Sales Executive
                </div>
                <?php if ($agentUser): ?>
                <div style="font-size:17px;font-weight:700;color:#0f172a;margin-bottom:6px">
                    <?= e($agentUser['name']) ?>
                </div>
                <?php if (!empty($agentUser['email'])): ?>
                <div style="font-size:13px;color:#374151;margin-bottom:3px">
                    <i class="fa fa-envelope me-2 text-muted" style="font-size:11px"></i><?= e($agentUser['email']) ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div style="font-size:13px;color:#6b7280">Not assigned</div>
                <?php endif; ?>
                <div style="margin-top:10px;font-size:12px;color:#6b7280">
                    <?= e($companyName) ?>
                </div>
            </div>
        </div>

        <!-- ══ VEHICLE DETAILS ════════════════════════════════════════════════ -->
        <div style="padding:24px 28px;border-bottom:2px solid #e5e7eb">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;
                        letter-spacing:.1em;color:#6b7280;margin-bottom:16px">
                <i class="fa fa-car me-2" style="color:#2563eb"></i>Vehicle Details
            </div>

            <?php if ($car): ?>
            <!-- Full car spec block -->
            <div style="position:relative;overflow:hidden">
                <?php if ($carImage): ?>
                <!-- Car image — floated right -->
                <div style="float:right;margin:0 0 16px 20px">
                    <img src="<?= BASE_URL ?>/uploads/cars/<?= e($carImage) ?>"
                         alt="<?= e(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? '')) ?>"
                         style="width:220px;height:155px;object-fit:cover;
                                border-radius:10px;border:2px solid #e5e7eb;
                                box-shadow:0 2px 10px rgba(0,0,0,.1)">
                </div>
                <?php endif; ?>

                <!-- Year / Make / Model headline -->
                <div style="font-size:22px;font-weight:800;color:#0f172a;margin-bottom:8px;line-height:1.2">
                    <?= e(trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?>
                </div>

                <!-- Spec grid -->
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px 20px">
                    <?php
                    $specs = [];
                    if (!empty($car['registration_number'])) $specs['Registration'] = $car['registration_number'];
                    if (!empty($car['color']))               $specs['Colour']       = $car['color'];
                    if (!empty($car['engine']))              $specs['Engine']       = $car['engine'];
                    if (!empty($car['transmission']))        $specs['Transmission'] = ucfirst($car['transmission']);
                    if (!empty($car['fuel_type']))           $specs['Fuel Type']    = ucfirst($car['fuel_type']);
                    if (!empty($car['mileage']))             $specs['Mileage']      = number_format((int)$car['mileage']) . ' km';
                    if (!empty($car['body_type']))           $specs['Body Type']    = ucfirst(str_replace('_', ' ', $car['body_type']));
                    if (!empty($car['drive_type']))          $specs['Drive']        = strtoupper($car['drive_type']);
                    foreach ($specs as $label => $val):
                    ?>
                    <div style="font-size:13px;padding:5px 0;border-bottom:1px solid #f1f5f9">
                        <span style="font-weight:700;color:#374151;min-width:110px;display:inline-block">
                            <?= e($label) ?>:
                        </span>
                        <span style="color:#0f172a"><?= e($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($car['description'])): ?>
                <div style="margin-top:12px;font-size:13px;color:#4b5563;line-height:1.6;
                            background:#f8fafc;border-radius:8px;padding:12px 16px;
                            border:1px solid #e5e7eb;clear:both">
                    <?= nl2br(e($car['description'])) ?>
                </div>
                <?php else: ?>
                <div style="clear:both"></div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- No pinned car — show interested_in text -->
            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;
                        padding:18px 20px;font-size:15px;color:#374151">
                <i class="fa fa-circle-info me-2 text-muted"></i>
                <span style="font-weight:600">Vehicle of Interest:</span>
                <?= e($lead['interested_in'] ?? 'Not specified') ?>
            </div>
            <?php if ($lead['budget']): ?>
            <div style="margin-top:10px;font-size:13px;color:#6b7280">
                Approximate budget: <span style="font-weight:700;color:#16a34a"><?= money((float)$lead['budget']) ?></span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ══ PRICING ════════════════════════════════════════════════════════ -->
        <div style="padding:24px 28px;border-bottom:2px solid #e5e7eb;background:#fafafa">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;
                        letter-spacing:.1em;color:#6b7280;margin-bottom:16px">
                <i class="fa fa-receipt me-2" style="color:#16a34a"></i>Pricing
            </div>

            <?php
            $price = 0;
            if ($car && !empty($car['selling_price'])) {
                $price = (float)$car['selling_price'];
            } elseif ($car && !empty($car['asking_price'])) {
                $price = (float)$car['asking_price'];
            } elseif (!empty($lead['budget'])) {
                $price = (float)$lead['budget'];
            }
            $deposit = (float)($lead['deposit_amount'] ?? 0);
            $balance = $deposit > 0 ? max(0, $price - $deposit) : 0;
            $isReserved = ($lead['stage'] === 'reserved' && $deposit > 0);
            ?>

            <table style="width:100%;border-collapse:collapse">
                <?php if ($car): ?>
                <tr>
                    <td style="padding:8px 0;font-size:14px;color:#374151;font-weight:500">
                        <?= e(trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?>
                    </td>
                    <td style="padding:8px 0;text-align:right;font-size:14px;font-weight:600;color:#0f172a">
                        <?= $price > 0 ? money($price) : '—' ?>
                    </td>
                </tr>
                <?php elseif ($lead['budget']): ?>
                <tr>
                    <td style="padding:8px 0;font-size:14px;color:#374151;font-weight:500">
                        Estimated Budget
                    </td>
                    <td style="padding:8px 0;text-align:right;font-size:14px;font-weight:600;color:#0f172a">
                        <?= money((float)$lead['budget']) ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($isReserved): ?>
                <!-- Deposit paid row -->
                <tr>
                    <td style="padding:8px 0;font-size:14px;color:#16a34a;font-weight:500">
                        <i class="fa fa-bookmark me-2" style="font-size:12px"></i>
                        Deposit Paid
                        <?php if ($lead['deposit_date']): ?>
                        <span style="font-size:11px;color:#6b7280;margin-left:6px">(<?= fmtDate($lead['deposit_date'],'d M Y') ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 0;text-align:right;font-size:14px;font-weight:600;color:#16a34a">
                        – <?= money($deposit) ?>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- Divider -->
                <tr>
                    <td colspan="2">
                        <div style="border-top:2px dashed #d1d5db;margin:8px 0"></div>
                    </td>
                </tr>

                <?php if ($isReserved && $price > 0): ?>
                <!-- Balance due row -->
                <tr style="background:#fff7ed;border-radius:8px">
                    <td style="padding:12px 16px;font-size:16px;font-weight:800;color:#c2410c;border-radius:8px 0 0 8px">
                        <i class="fa fa-hourglass-half me-2"></i>BALANCE DUE
                    </td>
                    <td style="padding:12px 16px;text-align:right;font-size:18px;font-weight:900;color:#c2410c;border-radius:0 8px 8px 0">
                        <?= money($balance) ?>
                    </td>
                </tr>
                <tr><td colspan="2" style="padding:6px 0"></td></tr>
                <?php endif; ?>

                <!-- Total -->
                <tr style="background:#f0fdf4;border-radius:8px">
                    <td style="padding:12px 16px;font-size:16px;font-weight:800;
                                color:#0f172a;border-radius:8px 0 0 8px">
                        <i class="fa fa-equals me-2 text-success"></i><?= $isReserved ? 'VEHICLE PRICE' : 'TOTAL' ?>
                    </td>
                    <td style="padding:12px 16px;text-align:right;font-size:18px;font-weight:900;
                                color:#16a34a;border-radius:0 8px 8px 0">
                        <?php if ($price > 0): ?>
                        <?= money($price) ?>
                        <?php elseif (!empty($lead['budget'])): ?>
                        <?= money((float)$lead['budget']) ?>
                        <?php else: ?>
                        <span style="font-size:13px;font-weight:600;color:#6b7280">
                            To be confirmed
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php if ($price > 0): ?>
            <div style="margin-top:10px;font-size:12px;color:#6b7280;font-style:italic">
                <?= numberToWords($isReserved ? $balance : $price) ?>
                <?= $isReserved ? ' (balance remaining)' : '' ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ══ TERMS & CONDITIONS ════════════════════════════════════════════ -->
        <div style="padding:24px 28px;border-bottom:2px solid #e5e7eb">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;
                        letter-spacing:.1em;color:#6b7280;margin-bottom:14px">
                <i class="fa fa-file-contract me-2" style="color:#7c3aed"></i>Terms &amp; Conditions
            </div>
            <ul style="margin:0;padding-left:20px;line-height:2;font-size:13px;color:#374151">
                <li>This proforma is valid for <strong>7 days</strong> from the date above.</li>
                <li>Prices are subject to change without prior notice.</li>
                <li>A deposit is required to reserve the vehicle.</li>
                <li>Full payment must be completed before delivery.</li>
                <li>All vehicles are sold subject to availability at the time of purchase.</li>
                <li>Transfer of ownership costs and registration fees are payable by the buyer.</li>
            </ul>
        </div>

        <!-- ══ SIGNATURE BLOCK ═══════════════════════════════════════════════ -->
        <div style="padding:28px 28px 32px;background:#f9fafb">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
                <!-- Agent signature -->
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.06em;color:#6b7280;margin-bottom:8px">
                        Sales Executive Signature
                    </div>
                    <div style="border-bottom:1.5px solid #374151;margin-bottom:6px;height:36px"></div>
                    <div style="font-size:12px;color:#6b7280">
                        <?= e($agentUser['name'] ?? $me['name']) ?>
                        &nbsp;&mdash;&nbsp;
                        <?= e($companyName) ?>
                    </div>
                </div>
                <!-- Date -->
                <div>
                    <div style="font-size:11px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.06em;color:#6b7280;margin-bottom:8px">
                        Date
                    </div>
                    <div style="border-bottom:1.5px solid #374151;margin-bottom:6px;height:36px;
                                display:flex;align-items:flex-end;padding-bottom:4px">
                        <span style="font-size:14px;font-weight:600;color:#0f172a"><?= e($quoteDate) ?></span>
                    </div>
                    <div style="font-size:12px;color:#6b7280">Proforma Ref: <?= e($quoteRef) ?></div>
                </div>
            </div>

            <!-- Footer note -->
            <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;
                        text-align:center;font-size:11.5px;color:#9ca3af">
                This document was generated by <?= e($companyName) ?> CRM System.
                For queries, contact us at
                <?php if ($companyEmail): ?>
                <a href="mailto:<?= e($companyEmail) ?>" style="color:#2563eb"><?= e($companyEmail) ?></a>
                <?php endif; ?>
                <?php if ($companyPhone): ?>
                or call <?= e($companyPhone) ?>.
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /proforma-card -->
</div><!-- /#proforma -->

<div class="d-print-none mt-4 mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
