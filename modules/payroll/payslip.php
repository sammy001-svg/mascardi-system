<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payroll') || die('Access denied.');

$db     = getDB();
$itemId = (int)($_GET['item_id'] ?? 0);
if (!$itemId) die('Invalid request.');

$item = $db->prepare("
    SELECT pi.*, pr.run_number, pr.period_month, pr.period_year, pr.status AS run_status,
           pr.working_days, pr.paid_at
    FROM payroll_items pi
    JOIN payroll_runs pr ON pr.id = pi.run_id
    WHERE pi.id = ?
");
$item->execute([$itemId]); $item = $item->fetch();
if (!$item) die('Payslip not found.');

$months      = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$companyName = getSetting('company_name','Mascardi Car Yard');
$companyAddr = getSetting('company_address','');
$companyPhone= getSetting('company_phone','');

$allowances  = (float)$item['house_allowance'] + (float)$item['transport_allow'] + (float)$item['other_allowance'];
$deductions  = (float)$item['paye'] + (float)$item['nhif'] + (float)$item['nssf'] + (float)$item['other_deduction'];
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Payslip — <?= e($item['staff_name']) ?> — <?= $months[$item['period_month']] ?> <?= $item['period_year'] ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:12px;background:#fff;color:#1a1a1a}
.page{max-width:680px;margin:24px auto;padding:32px;border:1px solid #e2e8f0;border-radius:8px}
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #1e40af}
.co-name{font-size:18px;font-weight:800;color:#1e40af}
.co-info{font-size:11px;color:#64748b;margin-top:4px}
.slip-title{text-align:right}
.slip-title h2{font-size:16px;font-weight:700;color:#1e40af}
.slip-title .period{font-size:13px;color:#475569;margin-top:2px}
.emp-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px 16px;margin-bottom:20px}
.emp-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;font-size:12px}
.emp-grid .lbl{color:#64748b}
.emp-grid .val{font-weight:600}
.section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin:16px 0 8px}
table{width:100%;border-collapse:collapse}
th{background:#1e40af;color:#fff;padding:7px 12px;text-align:left;font-size:11px;font-weight:600}
th.right{text-align:right}
td{padding:6px 12px;border-bottom:1px solid #f1f5f9;font-size:12px}
td.right{text-align:right}
tr.subtotal td{background:#f8fafc;font-weight:600}
tr.total td{background:#1e40af;color:#fff;font-weight:700;font-size:13px}
.net-box{background:#dcfce7;border:2px solid #16a34a;border-radius:8px;padding:16px;text-align:center;margin-top:20px}
.net-label{color:#166534;font-size:12px;font-weight:600;margin-bottom:4px}
.net-amount{color:#15803d;font-size:26px;font-weight:800}
.sig-section{display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
.sig-box h4{font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:8px}
.sig-line{border-top:1px solid #1a1a1a;margin-top:36px;padding-top:4px;font-size:10px;color:#64748b}
.status-badge{display:inline-block;padding:2px 10px;border-radius:10px;font-size:10px;font-weight:700}
.badge-paid{background:#dcfce7;color:#15803d}
.badge-draft{background:#f1f5f9;color:#475569}
@media print{.no-print{display:none}.page{border:none;margin:0;padding:20px}}
</style>
</head>
<body>
<div class="page">
    <div class="no-print" style="margin-bottom:16px;text-align:right">
        <button onclick="window.print()" style="background:#1e40af;color:#fff;border:none;padding:7px 16px;border-radius:6px;cursor:pointer;font-size:13px">&#128438; Print Payslip</button>
        <a href="run.php?id=<?= $item['run_id'] ?>" style="margin-left:10px;color:#64748b;font-size:13px;text-decoration:none">← Back to Run</a>
    </div>

    <div class="header">
        <div>
            <div class="co-name"><?= e($companyName) ?></div>
            <div class="co-info">
                <?= $companyAddr  ? e($companyAddr).'<br>' : '' ?>
                <?= $companyPhone ? e($companyPhone) : '' ?>
            </div>
        </div>
        <div class="slip-title">
            <h2>PAYSLIP</h2>
            <div class="period"><?= $months[$item['period_month']] ?> <?= $item['period_year'] ?></div>
            <div style="margin-top:4px">
                <span class="status-badge <?= $item['run_status']==='paid'?'badge-paid':'badge-draft' ?>">
                    <?= strtoupper($item['run_status']) ?>
                </span>
            </div>
            <div style="font-size:10px;color:#94a3b8;margin-top:4px"><?= e($item['run_number']) ?></div>
        </div>
    </div>

    <div class="emp-box">
        <div class="emp-grid">
            <div>
                <div class="lbl">Employee Name</div>
                <div class="val" style="font-size:14px"><?= e($item['staff_name']) ?></div>
            </div>
            <div>
                <div class="lbl">Staff Category</div>
                <div class="val"><?= ucfirst($item['staff_type']) ?></div>
            </div>
            <div>
                <div class="lbl">Days Worked</div>
                <div class="val"><?= $item['days_worked'] ?> / <?= $item['days_worked'] ?> days</div>
            </div>
            <div>
                <div class="lbl">Payment Status</div>
                <div class="val"><?= $item['status'] === 'paid' ? 'Paid' . ($item['paid_at'] ? ' — '.date('d M Y',strtotime($item['paid_at'])) : '') : 'Pending' ?></div>
            </div>
        </div>
    </div>

    <!-- Earnings -->
    <div class="section-title">Earnings</div>
    <table>
        <thead><tr><th>Description</th><th class="right">Amount (KES)</th></tr></thead>
        <tbody>
            <tr><td>Basic Salary</td><td class="right"><?= number_format((float)$item['basic_salary'],2) ?></td></tr>
            <?php if ($item['house_allowance'] > 0): ?>
            <tr><td>House Allowance</td><td class="right"><?= number_format((float)$item['house_allowance'],2) ?></td></tr>
            <?php endif; ?>
            <?php if ($item['transport_allow'] > 0): ?>
            <tr><td>Transport Allowance</td><td class="right"><?= number_format((float)$item['transport_allow'],2) ?></td></tr>
            <?php endif; ?>
            <?php if ($item['other_allowance'] > 0): ?>
            <tr><td><?= e($item['other_allow_note'] ?: 'Other Allowance') ?></td><td class="right"><?= number_format((float)$item['other_allowance'],2) ?></td></tr>
            <?php endif; ?>
            <tr class="subtotal"><td>GROSS PAY</td><td class="right"><?= number_format((float)$item['gross_pay'],2) ?></td></tr>
        </tbody>
    </table>

    <!-- Deductions -->
    <div class="section-title">Statutory Deductions</div>
    <table>
        <thead><tr><th>Description</th><th class="right">Amount (KES)</th></tr></thead>
        <tbody>
            <tr><td>PAYE (Income Tax)</td><td class="right"><?= number_format((float)$item['paye'],2) ?></td></tr>
            <tr><td>NHIF (Health Insurance)</td><td class="right"><?= number_format((float)$item['nhif'],2) ?></td></tr>
            <tr><td>NSSF (Pension)</td><td class="right"><?= number_format((float)$item['nssf'],2) ?></td></tr>
            <?php if ($item['other_deduction'] > 0): ?>
            <tr><td><?= e($item['other_deduct_note'] ?: 'Other Deduction') ?></td><td class="right"><?= number_format((float)$item['other_deduction'],2) ?></td></tr>
            <?php endif; ?>
            <tr class="subtotal"><td>TOTAL DEDUCTIONS</td><td class="right"><?= number_format((float)$item['total_deductions'],2) ?></td></tr>
        </tbody>
    </table>

    <div class="net-box">
        <div class="net-label">NET PAY</div>
        <div class="net-amount">KES <?= number_format((float)$item['net_pay'],2) ?></div>
    </div>

    <div class="sig-section">
        <div class="sig-box">
            <h4>Employee Acknowledgement</h4>
            <div class="sig-line">Signature &amp; Date</div>
        </div>
        <div class="sig-box">
            <h4>Authorised By</h4>
            <div class="sig-line">Signature &amp; Date</div>
        </div>
    </div>

    <div style="margin-top:20px;text-align:center;font-size:10px;color:#94a3b8">
        <?= e($companyName) ?> · Payslip generated <?= date('d M Y') ?> · This is a computer-generated document
    </div>
</div>
</body>
</html>
