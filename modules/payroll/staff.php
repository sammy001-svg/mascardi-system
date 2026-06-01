<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payroll') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Payroll Profiles';
$db     = getDB();
$errors = [];

// Load all mechanics + drivers with their salary profiles
try {
    $mechanics = $db->query("
        SELECT m.id, m.name, m.phone, m.status,
               ss.id AS sal_id, ss.basic_salary, ss.house_allowance,
               ss.transport_allow, ss.effective_date, ss.status AS sal_status
        FROM mechanics m
        LEFT JOIN staff_salaries ss ON ss.staff_type='mechanic' AND ss.staff_id=m.id
        WHERE m.status='active'
        ORDER BY m.name
    ")->fetchAll();

    $drivers = $db->query("
        SELECT d.id, d.name, d.phone, d.status,
               ss.id AS sal_id, ss.basic_salary, ss.house_allowance,
               ss.transport_allow, ss.effective_date, ss.status AS sal_status
        FROM drivers d
        LEFT JOIN staff_salaries ss ON ss.staff_type='driver' AND ss.staff_id=d.id
        WHERE d.status='active'
        ORDER BY d.name
    ")->fetchAll();
} catch (\Throwable $e) { $mechanics = []; $drivers = []; }

// POST: save a salary profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('payroll')) {
    $staffType  = $_POST['staff_type'] ?? '';
    $staffId    = (int)($_POST['staff_id'] ?? 0);
    $basic      = (float)($_POST['basic_salary']    ?? 0);
    $house      = (float)($_POST['house_allowance'] ?? 0);
    $transport  = (float)($_POST['transport_allow'] ?? 0);
    $effDate    = $_POST['effective_date']  ?? date('Y-m-01');
    $notes      = trim($_POST['notes']      ?? '');

    if (!in_array($staffType, ['mechanic','driver']) || !$staffId) {
        $errors[] = 'Invalid staff selection.';
    } elseif ($basic < 0) {
        $errors[] = 'Basic salary cannot be negative.';
    } else {
        try {
            $db->prepare("
                INSERT INTO staff_salaries
                    (staff_type, staff_id, basic_salary, house_allowance, transport_allow, effective_date, notes, updated_by)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    basic_salary=VALUES(basic_salary),
                    house_allowance=VALUES(house_allowance),
                    transport_allow=VALUES(transport_allow),
                    effective_date=VALUES(effective_date),
                    notes=VALUES(notes),
                    updated_by=VALUES(updated_by),
                    updated_at=NOW()
            ")->execute([$staffType,$staffId,$basic,$house,$transport,$effDate,$notes?:null,authUser()['id']]);
            logActivity('update','staff_salaries',$staffId,"Updated salary for $staffType #$staffId");
            setFlash('success','Salary profile saved.');
        } catch (\Throwable $e) { $errors[] = 'Save failed: '.$e->getMessage(); }
        redirect(BASE_URL.'/modules/payroll/staff.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-users-gear me-2 text-primary"></i>Payroll Profiles</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Payroll Runs</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="alert alert-info small py-2">
    <i class="fa fa-info-circle me-1"></i>
    Set each staff member's basic salary, housing and transport allowances here.
    These are auto-loaded when you create a new payroll run.
</div>

<?php foreach ([['Mechanics', $mechanics, 'mechanic'], ['Drivers', $drivers, 'driver']] as [$title, $staff, $type]): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-users me-2"></i><?= $title ?></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13.5px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Name</th>
                    <th class="text-end">Basic Salary</th>
                    <th class="text-end">House Allow.</th>
                    <th class="text-end">Transport</th>
                    <th class="text-end">Total Package</th>
                    <th>Effective</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staff as $s):
                $gross = (float)$s['basic_salary'] + (float)$s['house_allowance'] + (float)$s['transport_allow'];
            ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($s['name']) ?></td>
                <td class="text-end"><?= $s['sal_id'] ? money((float)$s['basic_salary']) : '<span class="text-muted small">Not set</span>' ?></td>
                <td class="text-end"><?= $s['sal_id'] ? money((float)$s['house_allowance']) : '—' ?></td>
                <td class="text-end"><?= $s['sal_id'] ? money((float)$s['transport_allow']) : '—' ?></td>
                <td class="text-end fw-semibold text-success"><?= $s['sal_id'] ? money($gross) : '—' ?></td>
                <td class="text-muted small"><?= $s['effective_date'] ? fmtDate($s['effective_date'],'M Y') : '—' ?></td>
                <td class="pe-3">
                    <?php if (canWrite('payroll')): ?>
                    <button class="btn btn-xs btn-outline-primary"
                            onclick="openSalaryModal('<?= $type ?>',<?= $s['id'] ?>,'<?= e(addslashes($s['name'])) ?>',<?= (float)$s['basic_salary'] ?>,<?= (float)$s['house_allowance'] ?>,<?= (float)$s['transport_allow'] ?>,'<?= $s['effective_date'] ?: date('Y-m-01') ?>')">
                        <i class="fa fa-pen me-1"></i><?= $s['sal_id'] ? 'Edit' : 'Set Salary' ?>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($staff)): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">No active <?= strtolower($title) ?> found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- Salary Edit Modal -->
<div class="modal fade" id="salaryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="staff_type" id="m_type">
                <input type="hidden" name="staff_id"   id="m_id">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="fa fa-money-bill me-2 text-primary"></i>Set Salary — <span id="m_name"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Basic Salary (KES) <span class="text-danger">*</span></label>
                            <input type="number" name="basic_salary" id="m_basic" class="form-control" min="0" step="100" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">House Allowance (KES)</label>
                            <input type="number" name="house_allowance" id="m_house" class="form-control" min="0" step="100">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Transport Allowance (KES)</label>
                            <input type="number" name="transport_allow" id="m_transport" class="form-control" min="0" step="100">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Effective From</label>
                            <input type="date" name="effective_date" id="m_effdate" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g. Promotion from Jan 2025">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openSalaryModal(type,id,name,basic,house,transport,effdate){
    document.getElementById('m_type').value      = type;
    document.getElementById('m_id').value        = id;
    document.getElementById('m_name').textContent= name;
    document.getElementById('m_basic').value     = basic;
    document.getElementById('m_house').value     = house;
    document.getElementById('m_transport').value = transport;
    document.getElementById('m_effdate').value   = effdate;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('salaryModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
