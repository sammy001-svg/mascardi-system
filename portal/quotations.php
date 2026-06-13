<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'My Quotations';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

// Accept a pending quotation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['accept_id'])) {
    $acceptId = (int)$_POST['accept_id'];
    $db->prepare("UPDATE quotations SET status='approved', updated_at=NOW() WHERE id=? AND client_id=? AND status='pending'")
       ->execute([$acceptId, $cid]);
    setFlash('success', 'Quotation accepted. Our team will be in touch to schedule the work.');
    redirect(BASE_URL . '/portal/quotations.php');
}

// Quotations for this client
$quotes = $db->prepare("
    SELECT q.*, ca.make, ca.model, ca.year, ca.registration_number,
           (SELECT COUNT(*) FROM quotation_items qi WHERE qi.quotation_id=q.id) AS item_count
    FROM quotations q
    LEFT JOIN cars ca ON ca.id = q.car_id
    WHERE q.client_id = ?
    ORDER BY q.created_at DESC
");
$quotes->execute([$cid]); $quotes = $quotes->fetchAll();

$statusColors = [
    'pending'  => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'invoiced' => 'primary',
    'expired'  => 'secondary',
];

include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-1"><i class="fa fa-file-lines me-2 text-primary"></i>My Quotations</h5>
        <div class="text-muted small"><?= count($quotes) ?> quotation<?= count($quotes) !== 1 ? 's' : '' ?> on your account</div>
    </div>
</div>

<?php
$pendingQuotes = array_filter($quotes, fn($q) => $q['status'] === 'pending');
if ($pendingQuotes):
?>
<div class="alert alert-warning d-flex gap-3 align-items-start mb-4" style="border-radius:10px">
    <i class="fa fa-triangle-exclamation mt-1 flex-shrink-0"></i>
    <div>
        <strong>Action required:</strong> You have <?= count($pendingQuotes) ?> pending quotation<?= count($pendingQuotes) !== 1 ? 's' : '' ?> awaiting your approval.
        Review below and click <strong>Accept</strong> to proceed with the work.
    </div>
</div>
<?php endif; ?>

<?php if (empty($quotes)): ?>
<div class="p-card text-center py-5">
    <i class="fa fa-file-lines fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="fw-semibold mb-1">No quotations yet</p>
    <p class="text-muted small mb-3">When we prepare a service quotation for your vehicle, it will appear here.</p>
    <a href="<?= BASE_URL ?>/portal/bookings.php?action=new" class="btn btn-sm btn-primary">
        <i class="fa fa-calendar-plus me-1"></i>Book a Service
    </a>
</div>
<?php else: ?>

<!-- Mobile: cards; Desktop: table -->
<div class="d-none d-md-block">
<div class="p-card">
    <table class="table table-hover mb-0" style="font-size:13px">
        <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
            <tr>
                <th class="ps-4 py-3">Quote #</th>
                <th class="py-3">Vehicle</th>
                <th class="py-3">Date</th>
                <th class="py-3">Valid Until</th>
                <th class="py-3">Items</th>
                <th class="py-3 text-end">Total</th>
                <th class="py-3">Status</th>
                <th class="py-3 pe-4 text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($quotes as $q):
            $isExpired = $q['status'] === 'pending' && $q['valid_until'] && strtotime($q['valid_until']) < time();
            $balance   = $q['valid_until'] ? (strtotime($q['valid_until']) - time()) : null;
            $daysLeft  = $balance ? ceil($balance / 86400) : null;
        ?>
        <tr>
            <td class="ps-4 py-3 fw-semibold"><?= e($q['quotation_number']) ?></td>
            <td class="py-3">
                <?php if ($q['make']): ?>
                <div class="fw-medium small"><?= e($q['make'].' '.$q['model'].' '.$q['year']) ?></div>
                <?php if ($q['registration_number']): ?>
                <div style="font-size:11px;color:#94a3b8"><?= e($q['registration_number']) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-muted small"><?= e($q['customer_name'] ?? '—') ?></span>
                <?php endif; ?>
            </td>
            <td class="py-3 text-muted small"><?= fmtDate($q['date']) ?></td>
            <td class="py-3 small">
                <?php if ($q['valid_until']): ?>
                    <?php if ($isExpired): ?>
                    <span class="text-danger small"><?= fmtDate($q['valid_until']) ?> <em>(expired)</em></span>
                    <?php elseif ($daysLeft !== null && $daysLeft <= 7 && $q['status'] === 'pending'): ?>
                    <span class="text-warning fw-semibold small"><?= fmtDate($q['valid_until']) ?> <em>(<?= $daysLeft ?>d left)</em></span>
                    <?php else: ?>
                    <span class="text-muted small"><?= fmtDate($q['valid_until']) ?></span>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="py-3 text-muted small"><?= $q['item_count'] ?> item<?= $q['item_count'] != 1 ? 's' : '' ?></td>
            <td class="py-3 text-end fw-bold"><?= money((float)$q['total']) ?></td>
            <td class="py-3">
                <span class="badge bg-<?= $statusColors[$q['status']] ?? 'secondary' ?>">
                    <?= ucfirst($q['status']) ?>
                </span>
            </td>
            <td class="py-3 pe-4 text-end">
                <div class="d-flex gap-2 justify-content-end">
                    <a href="<?= BASE_URL ?>/modules/quotations/print.php?id=<?= $q['id'] ?>"
                       target="_blank"
                       class="btn btn-xs btn-outline-secondary" title="View / Print">
                        <i class="fa fa-eye me-1"></i>View
                    </a>
                    <?php if ($q['status'] === 'pending' && !$isExpired): ?>
                    <form method="POST" onsubmit="return confirm('Accept this quotation? Our team will contact you to arrange the work.')">
                        <input type="hidden" name="accept_id" value="<?= $q['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-success">
                            <i class="fa fa-circle-check me-1"></i>Accept
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>

<!-- Mobile cards -->
<div class="d-md-none">
<?php foreach ($quotes as $q):
    $isExpired = $q['status'] === 'pending' && $q['valid_until'] && strtotime($q['valid_until']) < time();
?>
<div class="p-card mb-3">
    <div class="p-card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <div class="fw-bold small"><?= e($q['quotation_number']) ?></div>
                <?php if ($q['make']): ?>
                <div class="text-muted" style="font-size:12px"><?= e($q['make'].' '.$q['model'].' '.$q['year']) ?></div>
                <?php endif; ?>
            </div>
            <span class="badge bg-<?= $statusColors[$q['status']] ?? 'secondary' ?>"><?= ucfirst($q['status']) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-3">
            <div>
                <div class="fw-bold"><?= money((float)$q['total']) ?></div>
                <div class="text-muted" style="font-size:11px"><?= fmtDate($q['date']) ?></div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/modules/quotations/print.php?id=<?= $q['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary"><i class="fa fa-eye"></i></a>
                <?php if ($q['status'] === 'pending' && !$isExpired): ?>
                <form method="POST" onsubmit="return confirm('Accept this quotation?')">
                    <input type="hidden" name="accept_id" value="<?= $q['id'] ?>">
                    <button class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i>Accept</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
