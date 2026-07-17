<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

// Inline migrations — silent if columns already exist
foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id     INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount    DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_date      DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_notes     TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN due_date          DATE          NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin           VARCHAR(20)   NULL",
    "ALTER TABLE clients   ADD COLUMN id_number         VARCHAR(30)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

// CRM agents only see their own reservations
$agentWhere  = $isCrmAgent ? " AND l.assigned_to = $uid" : '';

$reservations = $db->query("
    SELECT
        l.id                AS lead_id,
        l.name              AS lead_name,
        l.phone             AS lead_phone,
        l.email             AS lead_email,
        l.deposit_amount,
        l.deposit_date,
        l.deposit_notes,
        l.agreed_sale_price,
        l.due_date,
        l.updated_at,
        c.id                AS car_id,
        c.make, c.model, c.year, c.color,
        c.chassis_number, c.registration_number,
        c.asking_price, c.offer_price,
        cl.name             AS client_name,
        cl.phone            AS client_phone,
        cl.email            AS client_email,
        cl.id_number        AS client_id_no,
        u.name              AS agent_name,
        (SELECT ci.file_path FROM car_images ci
         WHERE ci.car_id = c.id AND ci.is_primary = 1 LIMIT 1) AS primary_image
    FROM crm_leads l
    LEFT JOIN cars     c  ON c.id  = l.pinned_car_id
    LEFT JOIN clients  cl ON cl.id = l.client_id
    LEFT JOIN users    u  ON u.id  = l.assigned_to
    WHERE l.stage = 'reserved'$agentWhere
    ORDER BY l.updated_at DESC
")->fetchAll();

$total = count($reservations);
$totalDeposit = array_sum(array_column($reservations, 'deposit_amount'));

$pageTitle = 'Reservations';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.res-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(440px, 1fr));
    gap: 20px;
}
.res-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    display: flex;
    flex-direction: column;
}
.res-card-header {
    display: flex;
    gap: 0;
    align-items: stretch;
}
.res-car-img {
    width: 160px;
    min-height: 130px;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
    background: #f1f5f9;
}
.res-car-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.res-car-img .no-img {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #cbd5e1;
}
.res-badge {
    position: absolute; top: 8px; left: 8px;
    background: #7c3aed; color: #fff;
    font-size: 10px; font-weight: 700;
    padding: 3px 9px; border-radius: 20px;
    letter-spacing: .3px;
}
.res-car-info {
    flex: 1;
    padding: 14px 16px;
    border-left: 1px solid #f1f5f9;
}
.res-car-title {
    font-size: 16px; font-weight: 800;
    color: #0f172a; letter-spacing: -.3px;
    margin-bottom: 3px;
}
.res-car-sub { font-size: 12px; color: #64748b; margin-bottom: 8px; }
.res-meta-row {
    display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px;
}
.res-chip {
    font-size: 11px; background: #f8fafc;
    border: 1px solid #e2e8f0; border-radius: 6px;
    padding: 2px 8px; color: #475569; font-weight: 600;
}
.res-body { padding: 14px 16px; border-top: 1px solid #f1f5f9; }
.res-section-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .8px; color: #94a3b8; margin-bottom: 8px;
}
.res-dl { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.res-dt { font-size: 11px; color: #94a3b8; font-weight: 600; }
.res-dd { font-size: 12.5px; color: #0f172a; font-weight: 600; }
.res-amount { font-size: 18px; font-weight: 900; color: #15803d; }
.res-balance { font-size: 16px; font-weight: 700; color: #c2410c; }
.res-actions {
    display: flex; flex-wrap: wrap; gap: 6px;
    padding: 12px 16px; border-top: 1px solid #f1f5f9;
    background: #f8fafc;
}
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa fa-bookmark me-2 text-purple"></i>Reservations</h4>
        <div class="text-muted small mt-1"><?= $total ?> vehicle<?= $total !== 1 ? 's' : '' ?> currently reserved</div>
    </div>
    <div class="d-flex gap-2">
        <div class="card border-0 shadow-sm px-3 py-2 text-center">
            <div class="fw-bold text-purple" style="font-size:18px"><?= $total ?></div>
            <div class="text-muted" style="font-size:11px">Reserved</div>
        </div>
        <div class="card border-0 shadow-sm px-3 py-2 text-center">
            <div class="fw-bold text-success" style="font-size:18px">
                KES <?= number_format($totalDeposit, 0) ?>
            </div>
            <div class="text-muted" style="font-size:11px">Total Deposits</div>
        </div>
    </div>
</div>

<?php if (!$reservations): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div style="font-size:56px;margin-bottom:16px">🔖</div>
        <h5 class="fw-bold mb-1">No Reservations Yet</h5>
        <p class="text-muted mb-3">
            When a lead is reserved and linked to a vehicle, it will appear here.
        </p>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="btn btn-primary btn-sm">
            <i class="fa fa-user-plus me-1"></i>Go to Leads
        </a>
    </div>
</div>
<?php else: ?>

<div class="res-grid">
<?php foreach ($reservations as $r):
    $buyerName   = $r['client_name']  ?: $r['lead_name']  ?: '—';
    $buyerPhone  = $r['client_phone'] ?: $r['lead_phone'] ?: '';
    $buyerEmail  = $r['client_email'] ?: $r['lead_email'] ?: '';
    $buyerIdNo   = $r['client_id_no'] ?? '';
    $carTitle    = $r['car_id']
                 ? trim(($r['year'] ?? '') . ' ' . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''))
                 : '—';
    $deposit     = (float)($r['deposit_amount'] ?? 0);
    $agreed      = (float)($r['agreed_sale_price'] ?? 0);
    if (!$agreed) {
        $agreed = (float)($r['offer_price'] ?? 0) ?: (float)($r['asking_price'] ?? 0);
    }
    $balance     = max(0, $agreed - $deposit);
    $depDateFmt  = $r['deposit_date']  ? (new DateTime($r['deposit_date']))->format('d M Y')  : '—';
    $dueDateFmt  = $r['due_date']      ? (new DateTime($r['due_date']))->format('d M Y')      : '—';
    $imgUrl      = $r['primary_image'] ? thumbUrl('cars', $r['primary_image'])                 : null;
?>
<div class="res-card">

    <!-- Car image + info -->
    <div class="res-card-header">
        <div class="res-car-img">
            <?php if ($imgUrl): ?>
            <img src="<?= e($imgUrl) ?>" alt="<?= e($carTitle) ?>" loading="lazy">
            <?php else: ?>
            <div class="no-img"><i class="fa fa-car-side"></i></div>
            <?php endif; ?>
            <span class="res-badge"><i class="fa fa-bookmark me-1"></i>Reserved</span>
        </div>
        <div class="res-car-info">
            <div class="res-car-title"><?= e($carTitle) ?></div>
            <div class="res-car-sub">
                <?= $r['color'] ? ucfirst($r['color']) : '' ?>
                <?php if ($r['registration_number']): ?>
                &bull; <?= e($r['registration_number']) ?>
                <?php endif; ?>
            </div>
            <div class="res-meta-row">
                <?php if ($r['chassis_number']): ?>
                <span class="res-chip"><i class="fa fa-barcode me-1"></i><?= e($r['chassis_number']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($agreed > 0): ?>
            <div class="mt-2" style="font-size:14px;font-weight:800;color:#2563eb">
                KES <?= number_format($agreed) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Buyer info -->
    <div class="res-body">
        <div class="res-section-title"><i class="fa fa-user me-1"></i>Buyer Details</div>
        <div class="res-dl">
            <div>
                <div class="res-dt">Name</div>
                <div class="res-dd"><?= e($buyerName) ?></div>
            </div>
            <?php if ($buyerPhone): ?>
            <div>
                <div class="res-dt">Phone</div>
                <div class="res-dd">
                    <a href="tel:<?= e($buyerPhone) ?>" class="text-decoration-none"><?= e($buyerPhone) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerEmail): ?>
            <div>
                <div class="res-dt">Email</div>
                <div class="res-dd" style="word-break:break-all">
                    <a href="mailto:<?= e($buyerEmail) ?>" class="text-decoration-none"><?= e($buyerEmail) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerIdNo): ?>
            <div>
                <div class="res-dt">ID / Passport</div>
                <div class="res-dd"><?= e($buyerIdNo) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($r['agent_name']): ?>
            <div>
                <div class="res-dt">Sales Agent</div>
                <div class="res-dd"><?= e($r['agent_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment summary -->
    <div class="res-body" style="border-top:1px solid #f1f5f9">
        <div class="res-section-title"><i class="fa fa-money-bill-wave me-1"></i>Payment</div>
        <div class="res-dl" style="grid-template-columns:1fr 1fr 1fr 1fr">
            <div>
                <div class="res-dt">Deposit</div>
                <div class="res-amount" style="font-size:15px">KES <?= number_format($deposit) ?></div>
                <div style="font-size:10.5px;color:#94a3b8"><?= $depDateFmt ?></div>
            </div>
            <div>
                <div class="res-dt">Balance</div>
                <div class="res-balance">KES <?= number_format($balance) ?></div>
            </div>
            <div>
                <div class="res-dt">Due Date</div>
                <div class="res-dd"><?= e($dueDateFmt) ?></div>
            </div>
            <?php if ($r['deposit_notes']): ?>
            <div style="grid-column:1/-1;margin-top:6px">
                <div class="res-dt">Notes</div>
                <div class="res-dd" style="font-weight:400;color:#475569"><?= e($r['deposit_notes']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="res-actions">
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-eye me-1"></i>View Lead
        </a>
        <?php if ($r['car_id']): ?>
        <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="fa fa-file-alt me-1"></i>Proforma
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_agreement.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-file-contract me-1"></i>Agreement
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/deposit_receipt.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-success" target="_blank">
            <i class="fa fa-receipt me-1"></i>Deposit
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_receipt.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-warning" target="_blank">
            <i class="fa fa-receipt me-1"></i>Sales Rcpt
        </a>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $r['car_id'] ?>"
           class="btn btn-sm btn-outline-info" target="_blank">
            <i class="fa fa-car me-1"></i>Car
        </a>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
