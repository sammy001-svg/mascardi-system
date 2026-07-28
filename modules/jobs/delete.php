<?php
/**
 * Delete a workshop job card — Super Admin only.
 *
 * Job cards sit at the centre of the workshop paper trail, so this refuses to
 * delete one that still carries financial or procurement records:
 *
 *   quotations.job_id  FK RESTRICT  — MySQL would refuse anyway
 *   lpo.job_id         FK RESTRICT  — MySQL would refuse anyway
 *   invoices.job_id    NO FK        — nothing would stop the delete, and the
 *                                     invoice would silently point at a job
 *                                     that no longer exists. Checked explicitly.
 *   parts_requests.job_id  FK SET NULL — safely unlinked, not deleted.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

if (!isSuperAdmin()) {
    setFlash('error', 'Only a Super Admin can delete job cards.');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid job card.');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

$jStmt = $db->prepare("SELECT j.job_number, c.make, c.model, c.chassis_number
                       FROM workshop_jobs j
                       LEFT JOIN cars c ON c.id = j.car_id
                       WHERE j.id = ?");
$jStmt->execute([$id]);
$job = $jStmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    setFlash('error', 'Job card not found.');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

$jobLabel = $job['job_number'] ?: ('#' . $id);

// Records that must not be orphaned or silently destroyed.
$blockers = [];
foreach ([
    'quotations' => 'quotation(s)',
    'invoices'   => 'invoice(s)',
    'lpo'        => 'LPO(s)',
] as $table => $label) {
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE job_id = ?");
        $s->execute([$id]);
        $n = (int)$s->fetchColumn();
        if ($n > 0) $blockers[] = "{$n} {$label}";
    } catch (\Throwable $_) { /* table absent on this install */ }
}

if ($blockers) {
    setFlash('error', 'Cannot delete job card ' . $jobLabel . ' — it still has '
        . implode(', ', $blockers) . ' linked to it. Those are financial and procurement '
        . 'records that must keep their reference, so remove or reassign them first. '
        . 'To take the job out of circulation instead, set its status to Cancelled.');
    redirect(BASE_URL . '/modules/jobs/view.php?id=' . $id);
}

// parts_requests.job_id is ON DELETE SET NULL — count it so the confirmation
// message can say what was unlinked rather than leaving it a surprise.
$prCount = 0;
try {
    $s = $db->prepare("SELECT COUNT(*) FROM parts_requests WHERE job_id = ?");
    $s->execute([$id]);
    $prCount = (int)$s->fetchColumn();
} catch (\Throwable $_) {}

try {
    $db->prepare("DELETE FROM workshop_jobs WHERE id = ?")->execute([$id]);

    $vehicle = trim(($job['make'] ?? '') . ' ' . ($job['model'] ?? ''));
    logActivity('delete', 'workshop_jobs', $id,
        'Deleted job card ' . $jobLabel . ($vehicle ? ' (' . $vehicle . ')' : '')
        . ($prCount ? ' — ' . $prCount . ' parts request(s) unlinked' : ''));

    $msg = 'Job card ' . $jobLabel . ' deleted.';
    if ($prCount) $msg .= ' ' . $prCount . ' parts request(s) are now unlinked from any job.';
    setFlash('success', $msg);
} catch (\Throwable $e) {
    error_log('jobs/delete: ' . $e->getMessage());
    setFlash('error', 'Could not delete job card ' . $jobLabel . ' — it is still referenced by other records.');
}

redirect(BASE_URL . '/modules/jobs/index.php');
