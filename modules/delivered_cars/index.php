<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

foreach ([
    "ALTER TABLE crm_leads ADD COLUMN delivered_at           DATETIME      NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price      DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount         DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN import_vehicle_details TEXT          NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN id_number              VARCHAR(30)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

$agentWhere = $isCrmAgent ? " AND l.assigned_to = $uid" : '';

$deliveries = $db->query("
    SELECT
        l.id                AS lead_id,
        l.name              AS lead_name,
        l.phone             AS lead_phone,
        l.email             AS lead_email,
        l.deposit_amount,
        l.agreed_sale_price,
        l.delivered_at,
        l.converted_at,
        l.import_vehicle_details,
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
    LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
    LEFT JOIN clients cl ON cl.id = l.client_id
    LEFT JOIN users   u  ON u.id  = l.assigned_to
    WHERE l.stage = 'delivered'$agentWhere
    ORDER BY COALESCE(l.delivered_at, l.converted_at, l.updated_at) DESC
")->fetchAll();

$total      = count($deliveries);
$totalSales = array_sum(array_map(fn($d) => (float)($d['agreed_sale_price'] ?: $d['offer_price'] ?: $d['asking_price'] ?: 0), $deliveries));

$pageTitle = 'Delivered Cars';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.dlv-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(440px, 1fr));
    gap: 20px;
}
.dlv-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.05);
    display: flex;
    flex-direction: column;
}
.dlv-card-header {
    display: flex;
    gap: 0;
    align-items: stretch;
}
.dlv-car-img {
    width: 160px;
    min-height: 130px;
    flex-shrink: 0;
    position: relative;
    overflow: hidden;
    background: #f1f5f9;
}
.dlv-car-img img {
    width: 100%; height: 100%; object-fit: cover;
}
.dlv-car-img .no-img {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #cbd5e1;
}
.dlv-badge {
    position: absolute; top: 8px; left: 8px;
    background: #16a34a; color: #fff;
    font-size: 10px; font-weight: 700;
    padding: 3px 9px; border-radius: 20px;
    letter-spacing: .3px;
}
.dlv-car-info {
    flex: 1;
    padding: 14px 16px;
    border-left: 1px solid #f1f5f9;
}
.dlv-car-title { font-size: 16px; font-weight: 800; color: #0f172a; letter-spacing: -.3px; margin-bottom: 3px; }
.dlv-car-sub { font-size: 12px; color: #64748b; margin-bottom: 8px; }
.dlv-body { padding: 14px 16px; border-top: 1px solid #f1f5f9; }
.dlv-section-title {
    font-size: 10px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .8px; color: #94a3b8; margin-bottom: 8px;
}
.dlv-dl { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 16px; }
.dlv-dt { font-size: 11px; color: #94a3b8; font-weight: 600; }
.dlv-dd { font-size: 12.5px; color: #0f172a; font-weight: 600; }
.dlv-actions {
    display: flex; flex-wrap: wrap; gap: 6px;
    padding: 12px 16px; border-top: 1px solid #f1f5f9;
    background: #f8fafc;
}
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="fa fa-truck me-2 text-success"></i>Delivered Cars</h4>
        <div class="text-muted small mt-1"><?= $total ?> vehicle<?= $total !== 1 ? 's' : '' ?> delivered</div>
    </div>
    <div class="d-flex gap-2">
        <div class="card border-0 shadow-sm px-3 py-2 text-center">
            <div class="fw-bold text-success" style="font-size:18px"><?= $total ?></div>
            <div class="text-muted" style="font-size:11px">Delivered</div>
        </div>
        <?php if ($totalSales > 0): ?>
        <div class="card border-0 shadow-sm px-3 py-2 text-center">
            <div class="fw-bold text-primary" style="font-size:18px">
                KES <?= number_format($totalSales, 0) ?>
            </div>
            <div class="text-muted" style="font-size:11px">Total Sales</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$deliveries): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div style="font-size:56px;margin-bottom:16px">🚚</div>
        <h5 class="fw-bold mb-1">No Delivered Cars Yet</h5>
        <p class="text-muted mb-3">
            When a lead is marked as Delivered, the vehicle will appear here.
        </p>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="btn btn-primary btn-sm">
            <i class="fa fa-users me-1"></i>Go to Leads
        </a>
    </div>
</div>
<?php else: ?>

<div class="dlv-grid">
<?php foreach ($deliveries as $d):
    $buyerName  = $d['client_name']  ?: $d['lead_name']  ?: '—';
    $buyerPhone = $d['client_phone'] ?: $d['lead_phone'] ?: '';
    $buyerEmail = $d['client_email'] ?: $d['lead_email'] ?: '';
    $buyerIdNo  = $d['client_id_no'] ?? '';

    if ($d['import_vehicle_details']) {
        $carTitle = $d['import_vehicle_details'];
        $carSub   = 'Import Order';
    } elseif ($d['car_id']) {
        $carTitle = trim(($d['year']??'').' '.($d['make']??'').' '.($d['model']??''));
        $carSub   = ($d['color'] ? ucfirst($d['color']) : '') . ($d['registration_number'] ? ' · '.$d['registration_number'] : '');
    } else {
        $carTitle = '—';
        $carSub   = '';
    }

    $agreedPrice = (float)($d['agreed_sale_price'] ?? 0);
    if (!$agreedPrice) {
        $agreedPrice = (float)($d['offer_price'] ?? 0) ?: (float)($d['asking_price'] ?? 0);
    }

    $dlvDate = $d['delivered_at'] ?? $d['converted_at'] ?? null;
    $dlvFmt  = $dlvDate ? (new DateTime(substr($dlvDate,0,10)))->format('d M Y') : '—';

    $imgUrl = $d['primary_image'] ? thumbUrl('cars', $d['primary_image']) : null;
?>
<div class="dlv-card">

    <!-- Car image + info -->
    <div class="dlv-card-header">
        <div class="dlv-car-img">
            <?php if ($imgUrl): ?>
            <img src="<?= e($imgUrl) ?>" alt="<?= e($carTitle) ?>" loading="lazy">
            <?php else: ?>
            <div class="no-img"><i class="fa fa-truck"></i></div>
            <?php endif; ?>
            <span class="dlv-badge"><i class="fa fa-truck me-1"></i>Delivered</span>
        </div>
        <div class="dlv-car-info">
            <div class="dlv-car-title"><?= e($carTitle) ?></div>
            <?php if ($carSub): ?>
            <div class="dlv-car-sub"><?= e($carSub) ?></div>
            <?php endif; ?>
            <?php if ($d['chassis_number']): ?>
            <div style="font-size:11px;color:#64748b;margin-bottom:4px">
                <i class="fa fa-barcode me-1"></i><?= e($d['chassis_number']) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:13px;font-weight:700;color:#15803d;margin-top:4px">
                <i class="fa fa-calendar-check me-1 text-muted" style="font-size:11px"></i><?= $dlvFmt ?>
            </div>
            <?php if ($agreedPrice > 0): ?>
            <div style="font-size:14px;font-weight:800;color:#2563eb;margin-top:3px">
                KES <?= number_format($agreedPrice) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Buyer info -->
    <div class="dlv-body">
        <div class="dlv-section-title"><i class="fa fa-user me-1"></i>Buyer Details</div>
        <div class="dlv-dl">
            <div>
                <div class="dlv-dt">Name</div>
                <div class="dlv-dd"><?= e($buyerName) ?></div>
            </div>
            <?php if ($buyerPhone): ?>
            <div>
                <div class="dlv-dt">Phone</div>
                <div class="dlv-dd">
                    <a href="tel:<?= e($buyerPhone) ?>" class="text-decoration-none"><?= e($buyerPhone) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerEmail): ?>
            <div>
                <div class="dlv-dt">Email</div>
                <div class="dlv-dd" style="word-break:break-all">
                    <a href="mailto:<?= e($buyerEmail) ?>" class="text-decoration-none"><?= e($buyerEmail) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerIdNo): ?>
            <div>
                <div class="dlv-dt">ID / Passport</div>
                <div class="dlv-dd"><?= e($buyerIdNo) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($d['agent_name']): ?>
            <div>
                <div class="dlv-dt">Sales Agent</div>
                <div class="dlv-dd"><?= e($d['agent_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="dlv-actions">
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-eye me-1"></i>View Lead
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/delivery_note.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-success" target="_blank">
            <i class="fa fa-truck me-1"></i>Delivery Note
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_receipt.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-info" target="_blank">
            <i class="fa fa-file-invoice-dollar me-1"></i>Sales Rcpt
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="fa fa-file-invoice me-1"></i>Proforma
        </a>
        <?php if ($d['car_id']): ?>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $d['car_id'] ?>"
           class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-car me-1"></i>Car Record
        </a>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
