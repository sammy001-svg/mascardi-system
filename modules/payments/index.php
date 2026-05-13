<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payments') || die('Access denied.');
$pageTitle = 'Payments';
$db = getDB();

// ── Filters ──────────────────────────────────────────────────────────────────
$fMethod  = $_GET['method']  ?? '';
$fStatus  = $_GET['status']  ?? '';
$fDateFrom= $_GET['from']    ?? '';
$fDateTo  = $_GET['to']      ?? '';
$fSearch  = trim($_GET['q']  ?? '');

$where  = ['1=1'];
$params = [];

if ($fMethod)   { $where[] = 'p.payment_method = ?'; $params[] = $fMethod; }
if ($fStatus)   { $where[] = 'p.status = ?'; $params[] = $fStatus; }
if ($fDateFrom) { $where[] = 'p.payment_date >= ?'; $params[] = $fDateFrom; }
if ($fDateTo)   { $where[] = 'p.payment_date <= ?'; $params[] = $fDateTo; }
if ($fSearch)   { $where[] = '(p.client_name LIKE ? OR p.payment_number LIKE ? OR p.reference_number LIKE ?)'; $s = "%{$fSearch}%"; $params = array_merge($params, [$s,$s,$s]); }

$sql = "SELECT p.*, i.invoice_number, sb.booking_number
        FROM payments p
        LEFT JOIN invoices i ON i.id = p.invoice_id
        LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary by method (confirmed only) ───────────────────────────────────────
$summary = $db->query("
    SELECT payment_method,
           COUNT(*) AS cnt,
           SUM(amount) AS total
    FROM payments WHERE status='confirmed'
    GROUP BY payment_method
")->fetchAll(PDO::FETCH_ASSOC);
$summaryMap = array_column($summary, null, 'payment_method');

$methodMeta = [
    'mpesa'  => ['M-Pesa',        'success', 'fa-mobile-screen'],
    'bank'   => ['Bank Transfer', 'primary', 'fa-building-columns'],
    'cheque' => ['Cheque',        'warning', 'fa-money-check'],
    'cash'   => ['Cash',          'dark',    'fa-money-bill-wave'],
];

$totalConfirmed   = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed'")->fetchColumn();
$totalPending     = $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='pending'")->fetchColumn();
$countPending     = $db->query("SELECT COUNT(*) FROM payments WHERE status='pending'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1"><i class="fa fa-money-bill-transfer me-2 text-primary"></i>Payments</h5>
        <div class="text-muted small"><?= count($payments) ?> record<?= count($payments) != 1 ? 's' : '' ?> found</div>
    </div>
    <?php if (canWrite('payments')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Record Payment</a>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-circle-check fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:22px;font-weight:700;line-height:1;color:#16a34a"><?= money($totalConfirmed) ?></div>
                    <div class="text-muted small">Confirmed Total</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-clock fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:22px;font-weight:700;line-height:1;color:#d97706"><?= money($totalPending) ?></div>
                    <div class="text-muted small"><?= $countPending ?> Pending</div>
                </div>
            </div>
        </div>
    </div>
    <?php foreach ($methodMeta as $key => [$label, $color, $icon]): ?>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px" class="bg-<?= $color ?> bg-opacity-10 text-<?= $color ?> d-flex align-items-center justify-content-center flex-shrink-0">
                    <i class="fa <?= $icon ?> fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:18px;font-weight:700;line-height:1"><?= money($summaryMap[$key]['total'] ?? 0) ?></div>
                    <div class="text-muted small"><?= $label ?> (<?= $summaryMap[$key]['cnt'] ?? 0 ?>)</div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name / ref / #" value="<?= e($fSearch) ?>">
            </div>
            <div class="col-sm-2">
                <select name="method" class="form-select form-select-sm">
                    <option value="">All Methods</option>
                    <?php foreach ($methodMeta as $k => [$lbl]): ?>
                    <option value="<?= $k ?>" <?= $fMethod === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="pending"   <?= $fStatus === 'pending'   ? 'selected' : '' ?>>Pending</option>
                    <option value="confirmed" <?= $fStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="reversed"  <?= $fStatus === 'reversed'  ? 'selected' : '' ?>>Reversed</option>
                </select>
            </div>
            <div class="col-sm-2">
                <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fDateFrom) ?>" title="From date">
            </div>
            <div class="col-sm-2">
                <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fDateTo) ?>" title="To date">
            </div>
            <div class="col-sm-1 d-flex gap-1">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="fa fa-filter"></i></button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-xmark"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Payments table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13px">
            <thead style="background:#f8fafc;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
                <tr>
                    <th class="ps-3 py-2">Payment #</th>
                    <th class="py-2">Date</th>
                    <th class="py-2">Client</th>
                    <th class="py-2">Amount</th>
                    <th class="py-2">Method</th>
                    <th class="py-2">Reference</th>
                    <th class="py-2">Linked To</th>
                    <th class="py-2">Status</th>
                    <th class="py-2 pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $p):
                    [$mlabel, $mcolor, $micon] = $methodMeta[$p['payment_method']] ?? [$p['payment_method'], 'secondary', 'fa-circle'];
                    $statusMap = ['pending'=>['warning','clock'],'confirmed'=>['success','circle-check'],'reversed'=>['danger','rotate-left']];
                    [$sc, $si] = $statusMap[$p['status']] ?? ['secondary','circle'];
                ?>
                <tr>
                    <td class="ps-3 py-2 fw-semibold"><?= e($p['payment_number']) ?></td>
                    <td class="py-2"><?= fmtDate($p['payment_date']) ?></td>
                    <td class="py-2">
                        <div class="fw-medium"><?= e($p['client_name']) ?></div>
                        <?php if ($p['client_phone']): ?><div class="text-muted" style="font-size:11px"><?= e($p['client_phone']) ?></div><?php endif; ?>
                    </td>
                    <td class="py-2 fw-bold"><?= money($p['amount']) ?></td>
                    <td class="py-2">
                        <span class="badge bg-<?= $mcolor ?> bg-opacity-75">
                            <i class="fa <?= $micon ?> me-1"></i><?= $mlabel ?>
                        </span>
                    </td>
                    <td class="py-2">
                        <?= $p['reference_number'] ? '<code style="font-size:11px">'.e($p['reference_number']).'</code>' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="py-2" style="font-size:11px">
                        <?php if ($p['invoice_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $p['invoice_id'] ?>" class="text-decoration-none">
                            <i class="fa fa-file-invoice-dollar me-1 text-muted"></i><?= e($p['invoice_number']) ?>
                        </a>
                        <?php elseif ($p['booking_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $p['service_booking_id'] ?>" class="text-decoration-none">
                            <i class="fa fa-calendar-check me-1 text-muted"></i><?= e($p['booking_number']) ?>
                        </a>
                        <?php elseif ($p['description']): ?>
                        <span class="text-muted"><?= e($p['description']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2">
                        <span class="badge bg-<?= $sc ?>">
                            <i class="fa fa-<?= $si ?> me-1"></i><?= ucfirst($p['status']) ?>
                        </span>
                        <?php if ($p['confirmed_by']): ?>
                        <div class="text-muted" style="font-size:10px">by <?= e($p['confirmed_by']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 pe-3">
                        <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete ms-1">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
