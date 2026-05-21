<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('clients') || die('Access denied.');
$pageTitle = 'Clients';
$db = getDB();

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
    ORDER BY c.name
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
                        <?php if (canEditDelete()): ?>
                        <a href="edit.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
