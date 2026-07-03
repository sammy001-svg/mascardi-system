<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
requirePortalLogin();
$pageTitle = 'My Purchase';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

// Fetch all car purchases linked to this client
try {
    $salesStmt = $db->prepare("
        SELECT cs.*, c.make, c.model, c.year, c.color, c.body_type, c.transmission,
               c.fuel_type, c.chassis_number, c.registration_number, c.mileage,
               c.engine_cc, l.name AS location_name,
               (SELECT ci.file_path FROM car_images ci WHERE ci.car_id = c.id AND ci.is_primary = 1 LIMIT 1) AS primary_image
        FROM car_sales cs
        JOIN cars c ON c.id = cs.car_id
        LEFT JOIN locations l ON l.id = c.location_id
        WHERE cs.client_id = ? AND cs.status = 'active'
        ORDER BY cs.sale_date DESC
    ");
    $salesStmt->execute([$cid]);
    $purchases = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $purchases = []; }

include __DIR__ . '/header.php';
?>

<div class="no-print page-hero">
<div class="container">
    <div class="d-flex align-items-center gap-3">
        <div>
            <h4 class="mb-0"><i class="fa fa-tag me-2"></i>My Purchase<?= count($purchases) > 1 ? 's' : '' ?></h4>
            <nav aria-label="breadcrumb" style="margin-top:4px">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/portal/index.php" class="text-white-50">Dashboard</a></li>
                    <li class="breadcrumb-item active">My Purchase</li>
                </ol>
            </nav>
        </div>
    </div>
</div>
</div>

<div style="margin-top:1.5rem">

<?php if (empty($purchases)): ?>
<div class="p-card p-card-body text-center py-5">
    <i class="fa fa-car fa-3x mb-3 d-block" style="color:#cbd5e1"></i>
    <div class="fw-semibold mb-1">No purchases on record</div>
    <div class="text-muted small mb-3">When your vehicle purchase is recorded and linked to your account, it will appear here.</div>
    <a href="<?= BASE_URL ?>/portal/index.php" class="btn btn-sm btn-outline-primary">Back to Dashboard</a>
</div>
<?php endif; ?>

<?php foreach ($purchases as $i => $sale):
    $payBadge = match($sale['payment_status'] ?? '') {
        'paid_full' => ['#16a34a','Paid in Full'],
        'partial'   => ['#d97706','Partial Payment'],
        'financed'  => ['#2563eb','Financed'],
        default     => ['#64748b','Pending'],
    };
    $balance = (float)$sale['balance_amount'];
    $imgSrc  = !empty($sale['primary_image']) ? BASE_URL . '/' . ltrim($sale['primary_image'], '/') : null;
?>
<div class="p-card mb-4">
    <div class="p-card-header">
        <span><i class="fa fa-tag me-2 text-primary"></i>Sale # <?= e($sale['sale_number']) ?></span>
        <span class="badge" style="background:<?= $payBadge[0] ?>"><?= $payBadge[1] ?></span>
    </div>
    <div class="p-card-body">

        <div class="row g-4">
            <!-- Vehicle photo + overview -->
            <div class="col-md-4">
                <?php if ($imgSrc): ?>
                <img src="<?= e($imgSrc) ?>" alt="<?= e($sale['make'].' '.$sale['model']) ?>"
                     class="img-fluid rounded-3 mb-3 w-100" style="object-fit:cover;height:200px">
                <?php else: ?>
                <div class="rounded-3 d-flex align-items-center justify-content-center mb-3"
                     style="height:200px;background:#f1f5f9;color:#94a3b8">
                    <i class="fa fa-car fa-3x"></i>
                </div>
                <?php endif; ?>
                <h5 class="fw-bold mb-1"><?= e($sale['year'] . ' ' . $sale['make'] . ' ' . $sale['model']) ?></h5>
                <?php if ($sale['color']): ?><div class="text-muted small"><?= e($sale['color']) ?></div><?php endif; ?>
                <?php if ($sale['body_type']): ?><div class="text-muted small"><?= e($sale['body_type']) ?></div><?php endif; ?>
            </div>

            <!-- Sale details + specs -->
            <div class="col-md-8">
                <div class="row g-3">
                    <!-- Payment summary -->
                    <div class="col-12">
                        <div class="row g-2">
                            <div class="col-4 text-center p-3 rounded-3" style="background:#f0fdf4">
                                <div class="fw-bold text-success" style="font-size:18px"><?= money((float)$sale['sale_price']) ?></div>
                                <div class="text-muted" style="font-size:11.5px">Sale Price</div>
                            </div>
                            <div class="col-4 text-center p-3 rounded-3" style="background:#eff6ff">
                                <div class="fw-bold text-primary" style="font-size:18px"><?= money((float)$sale['deposit_amount']) ?></div>
                                <div class="text-muted" style="font-size:11.5px">Deposit Paid</div>
                            </div>
                            <div class="col-4 text-center p-3 rounded-3" style="background:<?= $balance > 0 ? '#fef2f2' : '#f0fdf4' ?>">
                                <div class="fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:18px"><?= money($balance) ?></div>
                                <div class="text-muted" style="font-size:11.5px">Balance</div>
                            </div>
                        </div>
                    </div>

                    <!-- Specs -->
                    <div class="col-12">
                        <dl class="row mb-0" style="font-size:13.5px">
                            <dt class="col-5 text-muted">Sale Date</dt>
                            <dd class="col-7"><?= fmtDate($sale['sale_date'], 'd F Y') ?></dd>

                            <dt class="col-5 text-muted">Payment Method</dt>
                            <dd class="col-7"><?= ucwords(str_replace('_', ' ', $sale['payment_method'] ?? '—')) ?></dd>

                            <?php if ($sale['finance_company']): ?>
                            <dt class="col-5 text-muted">Finance Company</dt>
                            <dd class="col-7"><?= e($sale['finance_company']) ?></dd>
                            <?php endif; ?>

                            <?php if ($sale['chassis_number']): ?>
                            <dt class="col-5 text-muted">Chassis #</dt>
                            <dd class="col-7"><code><?= e($sale['chassis_number']) ?></code></dd>
                            <?php endif; ?>

                            <?php if ($sale['registration_number']): ?>
                            <dt class="col-5 text-muted">Registration</dt>
                            <dd class="col-7 fw-semibold"><?= e($sale['registration_number']) ?></dd>
                            <?php endif; ?>

                            <?php if ($sale['transmission']): ?>
                            <dt class="col-5 text-muted">Transmission</dt>
                            <dd class="col-7"><?= e(ucfirst($sale['transmission'])) ?></dd>
                            <?php endif; ?>

                            <?php if ($sale['fuel_type']): ?>
                            <dt class="col-5 text-muted">Fuel</dt>
                            <dd class="col-7"><?= e(ucfirst($sale['fuel_type'])) ?></dd>
                            <?php endif; ?>

                            <?php if ($sale['mileage']): ?>
                            <dt class="col-5 text-muted">Mileage</dt>
                            <dd class="col-7"><?= number_format((int)$sale['mileage']) ?> km</dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($sale['notes'])): ?>
        <div class="mt-3 p-3 rounded-3" style="background:#f8fafc;font-size:13.5px">
            <strong class="text-muted small d-block mb-1">Notes</strong>
            <?= nl2br(e($sale['notes'])) ?>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endforeach; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
