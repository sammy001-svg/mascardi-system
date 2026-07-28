<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin']);
$db = getDB();

$id    = (int)($_GET['id'] ?? 0);
$force = !empty($_GET['force']);
if (!$id) {
    setFlash('error', 'Invalid location.');
    redirect('index.php');
}

$locStmt = $db->prepare("SELECT name FROM locations WHERE id = ?");
$locStmt->execute([$id]);
$locName = $locStmt->fetchColumn();
if ($locName === false) {
    setFlash('error', 'Location not found.');
    redirect('index.php');
}

// Only a Super Admin may bypass the vehicle / sub-location guards. Re-checked
// here and not just in the UI, since ?force=1 is trivially typed by hand.
if ($force && !isSuperAdmin()) {
    setFlash('error', 'Only a Super Admin can force-delete a location that is still in use.');
    redirect('index.php');
}

$carStmt = $db->prepare("SELECT COUNT(*) FROM cars WHERE location_id = ?");
$carStmt->execute([$id]);
$carCount = (int)$carStmt->fetchColumn();

$subStmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE parent_id = ?");
$subStmt->execute([$id]);
$subCount = (int)$subStmt->fetchColumn();

if (!$force) {
    if ($carCount > 0) {
        setFlash('error', 'Cannot delete: this location still has ' . $carCount . ' vehicle(s) assigned to it.');
        redirect('index.php');
    }
    if ($subCount > 0) {
        setFlash('error', 'Cannot delete: this location has ' . $subCount . ' sub-location(s). Delete or reassign them first.');
        redirect('index.php');
    }
}

// Movement history (key handovers, showroom transfers) references locations with
// ON DELETE RESTRICT — deliberately, since those are audit records of physical
// movements. MySQL will refuse the delete, so detect it first and explain why,
// rather than letting a raw constraint error reach the user.
$auditBlocks = [];
foreach ([
    'key_handovers'      => ['from_location_id', 'to_location_id', 'key handover record(s)'],
    'showroom_transfers' => ['from_location_id', 'to_location_id', 'showroom transfer record(s)'],
] as $table => [$colA, $colB, $label]) {
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$colA} = ? OR {$colB} = ?");
        $s->execute([$id, $id]);
        $n = (int)$s->fetchColumn();
        if ($n > 0) $auditBlocks[] = "{$n} {$label}";
    } catch (\Throwable $_) { /* table may not exist on this install */ }
}

if ($auditBlocks) {
    setFlash('error', 'Cannot delete "' . $locName . '": it is referenced by '
        . implode(' and ', $auditBlocks) . '. Those are movement-history records kept for audit, '
        . 'so the location has to stay. Deactivate it instead to keep it out of new activity.');
    redirect('index.php');
}

try {
    $db->prepare("DELETE FROM locations WHERE id = ?")->execute([$id]);

    // Every other reference is ON DELETE SET NULL, so vehicles, staff and
    // bookings are unassigned rather than deleted, and sub-locations are
    // promoted to top level. Nothing is destroyed.
    $detail = 'Deleted location: ' . $locName;
    if ($force) {
        $detail .= ' (force delete by Super Admin — ' . $carCount . ' vehicle(s) unassigned, '
                 . $subCount . ' sub-location(s) promoted to top level)';
    }
    logActivity('delete', 'locations', $id, $detail);

    $msg = 'Location "' . $locName . '" deleted.';
    if ($force && ($carCount || $subCount)) {
        $parts = [];
        if ($carCount) $parts[] = $carCount . ' vehicle(s) are now unassigned';
        if ($subCount) $parts[] = $subCount . ' sub-location(s) moved to top level';
        $msg .= ' ' . ucfirst(implode(' and ', $parts)) . '.';
    }
    setFlash('success', $msg);
} catch (\Throwable $e) {
    error_log('locations/delete: ' . $e->getMessage());
    setFlash('error', 'Failed to delete "' . $locName . '" — it is still referenced by other records.');
}

redirect('index.php');
