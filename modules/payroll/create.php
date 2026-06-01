<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('payroll') || redirect(BASE_URL . '/index.php');

$pageTitle = 'New Payroll Run';
$db     = getDB();
$errors = [];

$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// Kenya statutory deduction calculators
function calcPAYE(float $gross): float {
    // Personal relief: KES 2,400/month
    $relief = 2400;
    if ($gross <= 24000)  return max(0, $gross * 0.10 - $relief);
    if ($gross <= 32333)  return max(0, 2400 + ($gross - 24000) * 0.25 - $relief);
    if ($gross <= 500000) return max(0, 2400 + 2083.25 + ($gross - 32333) * 0.30 - $relief);
    return max(0, 2400 + 2083.25 + 140300.10 + ($gross - 500000) * 0.325 - $relief);
}

function calcNHIF(float $gross): float {
    if ($gross < 6000)   return 150;
    if ($gross < 8000)   return 300;
    if ($gross < 12000)  return 400;
    if ($gross < 15000)  return 500;
    if ($gross < 20000)  return 600;
    if ($gross < 25000)  return 750;
    if ($gross < 30000)  return 850;
    if ($gross < 35000)  return 900;
    if ($gross < 40000)  return 950;
    if ($gross < 45000)  return 1000;
    if ($gross < 50000)  return 1100;
    if ($gross < 60000)  return 1200;
    if ($gross < 70000)  return 1300;
    if ($gross < 80000)  return 1400;
    if ($gross < 90000)  return 1500;
    if ($gross < 100000) return 1600;
    return 1700;
}

function calcNSSF(float $gross): float {
    $lel = 7000; $uel = 36000;
    $tier1 = min($gross, $lel) * 0.06;
    $tier2 = max(0, min($gross, $uel) - $lel) * 0.06;
    return round($tier1 + $tier2, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month       = (int)($_POST['period_month']  ?? 0);
    $year        = (int)($_POST['period_year']   ?? 0);
    $workingDays = (int)($_POST['working_days']  ?? 26);
    $notes       = trim($_POST['notes']          ?? '');

    if ($month < 1 || $month > 12)   $errors[] = 'Invalid month.';
    if ($year < 2020 || $year > 2099) $errors[] = 'Invalid year.';

    // Check not duplicate
    if (empty($errors)) {
        $dup = $db->prepare("SELECT id FROM payroll_runs WHERE period_month=? AND period_year=?");
        $dup->execute([$month, $year]);
        if ($dup->fetch()) $errors[] = "A payroll run for {$months[$month]} {$year} already exists.";
    }

    if (empty($errors)) {
        try {
            // Fetch all staff with salary profiles
            $staffList = $db->query("
                SELECT ss.*, m.name AS staff_name
                FROM staff_salaries ss
                JOIN mechanics m ON m.id = ss.staff_id AND ss.staff_type='mechanic'
                WHERE ss.status='active'
                UNION ALL
                SELECT ss.*, d.name AS staff_name
                FROM staff_salaries ss
                JOIN drivers d ON d.id = ss.staff_id AND ss.staff_type='driver'
                WHERE ss.status='active'
                ORDER BY staff_name
            ")->fetchAll();

            if (empty($staffList)) {
                $errors[] = 'No staff with salary profiles found. Set up salaries first.';
            } else {
                $db->beginTransaction();

                $runNum = nextNumber('payroll_runs','run_number','PAY');
                $db->prepare("INSERT INTO payroll_runs (run_number, period_month, period_year, working_days, status, notes, created_by) VALUES (?,?,?,?,'draft',?,?)")
                   ->execute([$runNum, $month, $year, $workingDays, $notes?:null, authUser()['id']]);
                $runId = (int)$db->lastInsertId();

                $ins = $db->prepare("
                    INSERT INTO payroll_items
                        (run_id, staff_type, staff_id, staff_name, basic_salary, house_allowance, transport_allow,
                         gross_pay, paye, nhif, nssf, total_deductions, net_pay, days_worked)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");

                $totalGross = 0; $totalDed = 0; $totalNet = 0;

                foreach ($staffList as $s) {
                    // Adjust for attendance if records exist
                    $attStmt = $db->prepare("
                        SELECT SUM(CASE WHEN status IN ('present','late') THEN 1 WHEN status='half_day' THEN 0.5 ELSE 0 END)
                        FROM attendance_records
                        WHERE staff_type=? AND staff_id=?
                          AND MONTH(attendance_date)=? AND YEAR(attendance_date)=?
                    ");
                    $attStmt->execute([$s['staff_type'], $s['staff_id'], $month, $year]);
                    $daysFromAtt = $attStmt->fetchColumn();
                    $daysWorked = ($daysFromAtt !== null && $daysFromAtt > 0) ? (float)$daysFromAtt : $workingDays;

                    $gross = (float)$s['basic_salary'] + (float)$s['house_allowance'] + (float)$s['transport_allow'];
                    // Pro-rate if less days worked
                    if ($daysWorked < $workingDays) {
                        $gross = round($gross / $workingDays * $daysWorked, 2);
                    }
                    $paye  = round(calcPAYE($gross), 2);
                    $nhif  = round(calcNHIF($gross), 2);
                    $nssf  = round(calcNSSF($gross), 2);
                    $totalDedRow = $paye + $nhif + $nssf;
                    $net   = round($gross - $totalDedRow, 2);

                    $ins->execute([
                        $runId, $s['staff_type'], $s['staff_id'], $s['staff_name'],
                        $s['basic_salary'], $s['house_allowance'], $s['transport_allow'],
                        $gross, $paye, $nhif, $nssf, $totalDedRow, $net, $daysWorked
                    ]);

                    $totalGross += $gross;
                    $totalDed   += $totalDedRow;
                    $totalNet   += $net;
                }

                $db->prepare("UPDATE payroll_runs SET total_gross=?, total_deductions=?, total_net=? WHERE id=?")
                   ->execute([round($totalGross,2), round($totalDed,2), round($totalNet,2), $runId]);

                $db->commit();
                logActivity('create','payroll_runs',$runId,"Created payroll run $runNum for {$months[$month]} $year");
                setFlash('success',"Payroll run $runNum created for {$months[$month]} $year with ".count($staffList)." staff members.");
                redirect(BASE_URL.'/modules/payroll/run.php?id='.$runId);
            }
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Failed: '.$e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-plus me-2 text-success"></i>New Payroll Run</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-calendar me-2"></i>Payroll Period</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Month <span class="text-danger">*</span></label>
                            <select name="period_month" class="form-select" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $m == (int)date('n') ? 'selected' : '' ?>><?= $months[$m] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Year <span class="text-danger">*</span></label>
                            <select name="period_year" class="form-select" required>
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Working Days in Month</label>
                            <input type="number" name="working_days" class="form-control" min="1" max="31" value="26">
                            <div class="form-text">Used for pro-rating. Attendance data auto-adjusts if available.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes for this payroll run…"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fa fa-calculator me-1"></i>Generate Payroll Run
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>How Payroll is Calculated</div>
            <div class="card-body small text-muted">
                <ul class="ps-3 mb-0">
                    <li class="mb-2"><strong>Gross Pay</strong> = Basic + House Allowance + Transport Allowance (pro-rated by days worked if attendance records exist)</li>
                    <li class="mb-2"><strong>PAYE</strong> — Kenya progressive income tax (with KES 2,400 personal relief)</li>
                    <li class="mb-2"><strong>NHIF</strong> — National Hospital Insurance Fund contribution (income-based bands)</li>
                    <li class="mb-2"><strong>NSSF</strong> — National Social Security Fund (6% of Tier 1 + 6% of Tier 2)</li>
                    <li class="mb-2"><strong>Net Pay</strong> = Gross − PAYE − NHIF − NSSF − other deductions</li>
                    <li>You can adjust allowances and deductions per employee after the run is created.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
