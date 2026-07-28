<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('clients') || die('Access denied.');
$pageTitle = 'Clients';
$db = getDB();

// ── Backfill: delivered leads that never became clients ──────────────────────
// Conversion on delivery was added later, so buyers delivered before that are
// still only CRM leads — the front desk has to re-register them and their
// vehicle on the next service visit. This finds and converts them.
require_once __DIR__ . '/../crm/crm_helpers.php';

$unconvertedDelivered = 0;
try {
    $unconvertedDelivered = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads
        WHERE stage = 'delivered'
          AND (client_id IS NULL OR client_id = 0)
          AND name IS NOT NULL AND name <> ''
    ")->fetchColumn();
} catch (\Throwable $_) { $unconvertedDelivered = 0; }

// POST only — verifyCsrf() ignores GET, and this creates client records in bulk.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'convert_delivered'
    && $unconvertedDelivered > 0 && isSuperAdmin()) {
    verifyCsrf();
    $made = 0; $cars = 0; $failed = 0;
    try {
        $rows = $db->query("
            SELECT * FROM crm_leads
            WHERE stage = 'delivered'
              AND (client_id IS NULL OR client_id = 0)
              AND name IS NOT NULL AND name <> ''
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            // Reuses the same helper as live deliveries, so an existing customer
            // is matched on phone/email instead of being duplicated.
            $cid = crmDeliverLeadToClient($db, $row);
            if ($cid) { $made++; if (!empty($row['pinned_car_id'])) $cars++; }
            else      { $failed++; }
        }

        logActivity('update', 'clients', 0,
            "Backfill: converted {$made} delivered lead(s) to clients ({$cars} vehicle(s) registered)");

        $msg = "{$made} delivered lead(s) converted to clients";
        if ($cars)   $msg .= ", {$cars} vehicle(s) registered to them";
        if ($failed) $msg .= ". {$failed} could not be converted (missing name or contact details)";
        setFlash($failed ? 'warning' : 'success', $msg . '.');
    } catch (\Throwable $e) {
        error_log('clients convert_delivered: ' . $e->getMessage());
        setFlash('error', 'Conversion failed: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/clients/index.php');
}

$clients = $db->query("
    SELECT c.*,
           COUNT(DISTINCT ca.id) AS car_count,
           COUNT(DISTINCT i.id)  AS invoice_count,
           COUNT(DISTINCT sb.id) AS booking_count
    FROM clients c
    LEFT JOIN cars ca ON ca.client_id = c.id
    LEFT JOIN invoices i ON i.client_id = c.id
    LEFT JOIN service_bookings sb ON sb.client_id = c.id
    GROUP BY c.id
    ORDER BY c.created_at DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1">Clients</h5>
        <div class="text-muted small"><?= count($clients) ?> registered client<?= count($clients) !== 1 ? 's' : '' ?></div>
    </div>
    <?php if (canWrite('clients')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Client</a>
    <?php endif; ?>
</div>

<?php if ($unconvertedDelivered > 0): ?>
<div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap gap-2 py-2">
    <span class="small">
        <i class="fa fa-user-plus me-1"></i>
        <strong><?= $unconvertedDelivered ?></strong> delivered lead<?= $unconvertedDelivered !== 1 ? 's are' : ' is' ?>
        not yet in your client list. Converting <?= $unconvertedDelivered !== 1 ? 'them' : 'it' ?> registers the buyer
        and their vehicle, so a return service visit needs no re-registration.
    </span>
    <?php if (isSuperAdmin()): ?>
    <form method="POST" class="d-inline"
          onsubmit="return confirm('Convert <?= $unconvertedDelivered ?> delivered lead(s) into clients?\n\nEach buyer is added to Clients and their linked vehicle registered to them.\nExisting customers are matched on phone/email rather than duplicated.\n\nNothing is deleted.')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="convert_delivered">
        <button type="submit" class="btn btn-sm btn-info">
            <i class="fa fa-wand-magic-sparkles me-1"></i>Convert to clients
        </button>
    </form>
    <?php else: ?>
    <span class="small text-muted">Ask a Super Admin to run the conversion.</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Cars</th>
                    <th>Invoices</th>
                    <th>Bookings</th>
                    <th>Portal</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $c): ?>
                <tr>
                    <td class="ps-3 fw-medium"><?= e($c['name']) ?></td>
                    <td class="text-muted small"><?= e($c['email']) ?></td>
                    <td class="text-muted small"><?= e($c['phone'] ?? '—') ?></td>
                    <td><span class="badge bg-light text-dark border"><?= $c['car_count'] ?></span></td>
                    <td><span class="badge bg-light text-dark border"><?= $c['invoice_count'] ?></span></td>
                    <td><span class="badge bg-light text-dark border"><?= $c['booking_count'] ?></span></td>
                    <td>
                        <?php if ($c['portal_enabled'] && $c['portal_password']): ?>
                        <span class="badge bg-success">Active</span>
                        <?php elseif ($c['portal_enabled']): ?>
                        <span class="badge bg-warning text-dark">No password</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($c['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (canWrite('clients')): ?>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (hasRole('admin')): ?>
                        <a href="delete.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete client &quot;<?= e($c['name']) ?>&quot;? This cannot be undone.')">
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
