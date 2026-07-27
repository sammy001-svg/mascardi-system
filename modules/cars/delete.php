<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT make, model, chassis_number FROM cars WHERE id=?");
    $stmt->execute([$id]);
    $car = $stmt->fetch();

    if (!$car) {
        setFlash('danger', 'Car not found.');
        redirect(BASE_URL . '/modules/cars/index.php');
    }

    // These tables RESTRICT deletion (invoices/quotations/jobs/assessments are
    // financial/audit records — cascading their deletion would be data loss).
    // Check them up front so the error names exactly what's blocking it,
    // instead of a generic guess after the DELETE fails.
    $blockers = [
        'car_assessments' => 'assessment(s)',
        'car_transfers'   => 'transfer record(s)',
        'invoices'        => 'invoice(s)',
        'quotations'      => 'quotation(s)',
        'workshop_jobs'   => 'workshop job(s)',
        // consignments has no FK to cars (by design — see modules/trade_in/_bootstrap.php),
        // so it wouldn't stop the DELETE on its own; it's checked here to stop it
        // anyway, since deleting the car would orphan the trade-in/sale-on-behalf
        // deal record (consignmentFind()'s INNER JOIN to cars would make it
        // permanently unreachable, silently losing the owner/commission record).
        'consignments'    => 'trade-in/sale-on-behalf record(s)',
    ];
    $found = [];
    foreach ($blockers as $table => $label) {
        try {
            $c = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE car_id=?");
            $c->execute([$id]);
            $n = (int)$c->fetchColumn();
            if ($n > 0) $found[] = "{$n} {$label}";
        } catch (\Throwable $_) {}
    }

    if ($found) {
        $carName = trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? '')) ?: 'This car';
        setFlash('danger', "{$carName} ({$car['chassis_number']}) still has " . implode(', ', $found) .
            " linked to it, so it can't be deleted. Remove/cancel those records first, or keep the car " .
            "and set \"Show on website\" off if you just want to hide it.");
        redirect(BASE_URL . '/modules/cars/index.php');
    }

    try {
        $db->prepare("DELETE FROM cars WHERE id=?")->execute([$id]);
        $carName = trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? '')) ?: 'Car';
        logActivity('delete', 'cars', $id, "Deleted car: $carName");
        setFlash('success', 'Car deleted successfully.');
    } catch (\PDOException $e) {
        if ($e->getCode() == '23000') {
            setFlash('danger', 'Cannot delete this car because it still has other linked records in the system.');
        } else {
            setFlash('danger', 'Database error: ' . $e->getMessage());
        }
    }
}
redirect(BASE_URL . '/modules/cars/index.php');
