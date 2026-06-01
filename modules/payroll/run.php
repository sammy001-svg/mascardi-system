<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payroll') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Payroll Run';
$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL.'/modules/payroll/index.php');

$run = $db->prepare("
    SELECT pr.*, u.name AS created_name, a.name AS approved_name
    FROM payroll_runs pr
    LEFT JOIN users u ON u.id = pr.created_by
    LEFT JOIN users a ON a.id = pr.approved_by
    WHERE pr.id = ?
");
$run->execute([$id]); $run = $run->fetch();
if (!$run) { setFlash('error','Run not found.'); redirect(BASE_URL.'/modules/payroll/index.php'); }

$pageTitle = 'Payroll — '.$run['run_number'];
$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('payroll')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_item') {
        $itemId  = (int)($_POST['item_id'] ?? 0);
        $houseA  = (float)($_POST['house_allowance']   ?? 0);
        $transA  = (float)($_POST['transport_allow']   ?? 0);
        $otherA  = (float)($_POST['other_allowance']   ?? 0);
        $otherAN = trim($_POST['other_allow_note']      ?? '');
        $paye    = (float)($_POST['paye']               ?? 0);
        $nhif    = (float)($_POST['nhif']               ?? 0);
        $nssf    = (float)($_POST['nssf']               ?? 0);
        $otherD  = (float)($_POST['other_deduction']    ?? 0);
        $otherDN = trim($_POST['other_deduct_note']     ?? '');
        $daysW   = (int)($_POST['days_worked']          ?? 26);

        $item = $db->prepare("SELECT * FROM payroll_items WHERE id=? AND run_id=?");
        $item->execute([$itemId,$id]); $item = $item->fetch();
        if ($item) {
            $gross  = (float)$item['basic_salary'] + $houseA + $transA + $otherA;
            $totalD = $paye + $nhif + $nssf + $otherD;
            $net    = max(0, $gross - $totalD);
            $db->prepare("UPDATE payroll_items SET
                house_allowance=?, transport_allow=?, other_allowance=?, other_allow_note=?,
                gross_pay=?, paye=?, nhif=?, nssf=?, other_deduction=?, other_deduct_note=?,
                total_deductions=?, net_pay=?, days_worked=?
                WHERE id=?")
               ->execute([$houseA,$transA,$otherA,$otherAN?:null,$gross,$paye,$nhif,$nssf,$otherD,$otherDN?:null,$totalD,$net,$daysW,$itemId]);

            // Recalculate run totals
            $totals = $db->prepare("SELECT COALESCE(SUM(gross_pay),0), COALESCE(SUM(total_deductions),0), COALESCE(SUM(net_pay),0) FROM payroll_items WHERE run_id=?");
            $totals->execute([$id]); [$tg,$td,$tn] = $totals->fetch(PDO::FETCH_NUM);
            $db->prepare("UPDATE payroll_runs SET total_gross=?,total_deductions=?,total_net=? WHERE id=?")->execute([$tg,$td,$tn,$id]);
            setFlash('success','Item updated.');
        }
        redirect(BASE_URL.'/modules/payroll/run.php?id='.$id);
    }

    if ($action === 'approve' && hasRole(['admin','manager'])) {
        $db->prepare("UPDATE payroll_runs SET status='approved',approved_by=?,approved_at=NOW() WHERE id=? AND status='draft'")
           ->execute([authUser()['id'],$id]);
        setFlash('success','Payroll approved.');
        redirect(BASE_URL.'/modules/payroll/run.php?id='.$id);
    }

    if ($action === 'mark_paid' && hasRole(['admin','manager'])) {
        $db->prepare("UPDATE payroll_runs SET status='paid',paid_at=NOW() WHERE id=? AND status='approved'")->execute([$id]);
        $db->prepare("UPDATE payroll_items SET status='paid' WHERE run_id=?")->execute([$id]);
        setFlash('success','Payroll marked as paid.');
        redirect(BASE_URL.'/modules/payroll/run.php?id='.$id);
    }
}

$items = $db->prepare("SELECT * FROM payroll_items WHERE run_id=? ORDER BY staff_name ASC");
$items->execute([$id]); $items = $items->fetchAll();

$statusColors = ['draft'=>'secondary','approved'=>'primary','paid'=>'success'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-money-bill-wave me-2 text-success"></i>
            <?= e($run['run_number']) ?> — <?= $months[(int)$run['period_month']] ?> <?= $run['period_year'] ?>
        </h5>
        <span class="badge bg-<?= $statusColors[$run['status']] ?? 'secondary' ?> me-2"><?= ucfirst($run['status']) ?></span>
        <span class="text-muted small"><?= count($items) ?> staff · <?= $run['working_days'] ?> working days</span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($run['status'] === 'draft' && hasRole(['admin','manager'])): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Approve this payroll run?')">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-sm btn-primary"><i class="fa fa-circle-check me-1"></i>Approve</button>
        </form>
        <?php elseif ($run['status'] === 'approved' && hasRole(['admin','manager'])): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Mark entire payroll as paid?')">
            <input type="hidden" name="action" value="mark_paid">
            <button class="btn btn-sm btn-success"><i class="fa fa-money-bill-transfer me-1"></i>Mark as Paid</button>
        </form>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Totals summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-users"></i></div>
            <div class="stat-info"><div class="stat-label">Staff on Payroll</div><div class="stat-value"><?= count($items) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-money-bill"></i></div>
            <div class="stat-info"><div class="stat-label">Total Gross</div><div class="stat-value stat-value-sm"><?= money((float)$run['total_gross']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-arrow-down"></i></div>
            <div class="stat-info"><div class="stat-label">Total Deductions</div><div class="stat-value stat-value-sm"><?= money((float)$run['total_deductions']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-hand-holding-dollar"></i></div>
            <div class="stat-info"><div class="stat-label">Total Net Pay</div><div class="stat-value stat-value-sm"><?= money((float)$run['total_net']) ?></div></div>
        </div>
    </div>
</div>

<!-- Payroll items table -->
<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-table me-2"></i>Payslip Summary</span>
        <?php if ($run['approved_by']): ?>
        <span class="text-muted small">Approved by <?= e($run['approved_name']) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Type</th>
                    <th class="text-end">Basic</th>
                    <th class="text-end">Allowances</th>
                    <th class="text-end">Gross</th>
                    <th class="text-end">PAYE</th>
                    <th class="text-end">NHIF</th>
                    <th class="text-end">NSSF</th>
                    <th class="text-end">Net Pay</th>
                    <th class="text-center">Days</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item):
                $allowances = (float)$item['house_allowance'] + (float)$item['transport_allow'] + (float)$item['other_allowance'];
            ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($item['staff_name']) ?></td>
                <td><span class="badge bg-light text-dark border" style="font-size:10px"><?= ucfirst($item['staff_type']) ?></span></td>
                <td class="text-end"><?= money((float)$item['basic_salary']) ?></td>
                <td class="text-end"><?= money($allowances) ?></td>
                <td class="text-end fw-semibold"><?= money((float)$item['gross_pay']) ?></td>
                <td class="text-end text-danger small"><?= money((float)$item['paye']) ?></td>
                <td class="text-end text-danger small"><?= money((float)$item['nhif']) ?></td>
                <td class="text-end text-danger small"><?= money((float)$item['nssf']) ?></td>
                <td class="text-end fw-bold text-success"><?= money((float)$item['net_pay']) ?></td>
                <td class="text-center"><span class="badge bg-light text-dark border"><?= $item['days_worked'] ?></span></td>
                <td class="pe-3">
                    <div class="d-flex gap-1">
                        <a href="payslip.php?item_id=<?= $item['id'] ?>" target="_blank"
                           class="btn btn-xs btn-outline-secondary" title="Print payslip">
                            <i class="fa fa-print"></i>
                        </a>
                        <?php if ($run['status'] === 'draft' && canWrite('payroll')): ?>
                        <button class="btn btn-xs btn-outline-primary"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode($item)) ?>)"
                                title="Edit">
                            <i class="fa fa-pen"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <td colspan="2" class="ps-3 fw-bold">TOTALS</td>
                    <td class="text-end fw-bold"><?= money(array_sum(array_column($items,'basic_salary'))) ?></td>
                    <td class="text-end fw-bold"><?= money(array_sum(array_map(fn($i)=>$i['house_allowance']+$i['transport_allow']+$i['other_allowance'],$items))) ?></td>
                    <td class="text-end fw-bold"><?= money((float)$run['total_gross']) ?></td>
                    <td class="text-end fw-bold"><?= money(array_sum(array_column($items,'paye'))) ?></td>
                    <td class="text-end fw-bold"><?= money(array_sum(array_column($items,'nhif'))) ?></td>
                    <td class="text-end fw-bold"><?= money(array_sum(array_column($items,'nssf'))) ?></td>
                    <td class="text-end fw-bold text-success"><?= money((float)$run['total_net']) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<!-- Edit item modal -->
<?php if ($run['status'] === 'draft'): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_item">
                <input type="hidden" name="item_id" id="e_id">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold"><i class="fa fa-pen me-2"></i>Edit Payslip — <span id="e_name"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Days Worked</label>
                            <input type="number" name="days_worked" id="e_days" class="form-control form-control-sm" min="0" max="31">
                        </div>
                        <div class="col-md-12"><hr class="my-1"><p class="text-muted small mb-2 fw-semibold">ALLOWANCES</p></div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">House Allowance</label>
                            <input type="number" name="house_allowance" id="e_house" class="form-control form-control-sm" min="0" step="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Transport Allow.</label>
                            <input type="number" name="transport_allow" id="e_trans" class="form-control form-control-sm" min="0" step="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Other Allowance</label>
                            <input type="number" name="other_allowance" id="e_otherA" class="form-control form-control-sm" min="0" step="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Other Allow. Note</label>
                            <input type="text" name="other_allow_note" id="e_otherAN" class="form-control form-control-sm" placeholder="e.g. Overtime bonus">
                        </div>
                        <div class="col-md-12"><hr class="my-1"><p class="text-muted small mb-2 fw-semibold">DEDUCTIONS (override auto-calculated if needed)</p></div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">PAYE</label>
                            <input type="number" name="paye" id="e_paye" class="form-control form-control-sm" min="0" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">NHIF</label>
                            <input type="number" name="nhif" id="e_nhif" class="form-control form-control-sm" min="0" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">NSSF</label>
                            <input type="number" name="nssf" id="e_nssf" class="form-control form-control-sm" min="0" step="0.01">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Other Deduction</label>
                            <input type="number" name="other_deduction" id="e_otherD" class="form-control form-control-sm" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Other Deduction Note</label>
                            <input type="text" name="other_deduct_note" id="e_otherDN" class="form-control form-control-sm" placeholder="e.g. Salary advance recovery">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function openEditModal(item) {
    document.getElementById('e_id').value    = item.id;
    document.getElementById('e_name').textContent = item.staff_name;
    document.getElementById('e_days').value  = item.days_worked;
    document.getElementById('e_house').value = item.house_allowance;
    document.getElementById('e_trans').value = item.transport_allow;
    document.getElementById('e_otherA').value= item.other_allowance;
    document.getElementById('e_otherAN').value=item.other_allow_note||'';
    document.getElementById('e_paye').value  = item.paye;
    document.getElementById('e_nhif').value  = item.nhif;
    document.getElementById('e_nssf').value  = item.nssf;
    document.getElementById('e_otherD').value= item.other_deduction;
    document.getElementById('e_otherDN').value=item.other_deduct_note||'';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
