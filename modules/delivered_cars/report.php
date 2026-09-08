<?php
/**
 * Monthly delivery report — the tabular view, matching the sheet the yard keeps.
 *
 * The card gallery next door answers "what have we delivered lately". This
 * answers "what did we sell in August, for how much, and by whom" — which is a
 * different question and wants a different shape. Same data, same filters.
 *
 * Payment type and the commentary are edited here rather than on the lead,
 * because reviewing the month is the moment somebody actually knows what to
 * write in them.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_report.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canFilter  = in_array($me['role'], ['admin', 'super_admin', 'general_manager'], true);

deliveredReportEnsure($db);

// A CRM agent sees their own deliveries, here as everywhere else.
$scopeWhere = $isCrmAgent ? "AND l.assigned_to = $uid" : '';

// ── Saving a payment type or a commentary ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $back   = BASE_URL . '/modules/delivered_cars/report.php?' . http_build_query([
        'month' => $_GET['month'] ?? ($_POST['month'] ?? ''),
        'agent' => $_GET['agent'] ?? '',
        'make'  => $_GET['make']  ?? '',
    ]);

    if (!canWrite('crm')) {
        setFlash('error', 'You do not have permission to change the report.');
        redirect($back);
    }

    // The agent scope applies to writing too, not only to reading.
    $own = $db->prepare("SELECT id FROM crm_leads WHERE id = ? AND stage = 'delivered'"
                        . ($isCrmAgent ? " AND assigned_to = $uid" : ''));
    $own->execute([$leadId]);
    if (!$own->fetchColumn()) {
        setFlash('error', 'That delivery is not one you can edit.');
        redirect($back);
    }

    $pay = trim($_POST['payment_type'] ?? '');
    if ($pay !== '' && !in_array($pay, deliveredPaymentTypes(), true)) $pay = '';
    $note = trim($_POST['sale_commentary'] ?? '');

    try {
        $db->prepare("UPDATE crm_leads SET payment_type = ?, sale_commentary = ?, updated_at = NOW()
                       WHERE id = ?")
           ->execute([$pay !== '' ? $pay : null, $note !== '' ? mb_substr($note, 0, 255) : null, $leadId]);
        logActivity('update', 'crm_leads', $leadId,
            'Delivery report updated — payment: ' . ($pay ?: 'not recorded')
            . ($note !== '' ? '; commentary added' : ''));
        setFlash('success', 'Saved.');
    } catch (\Throwable $e) {
        error_log('delivery report save: ' . $e->getMessage());
        setFlash('error', 'That could not be saved.');
    }
    redirect($back);
}

// ── Filters ─────────────────────────────────────────────────────────────────
$months = deliveredReportMonths($db, $scopeWhere);
// Opens on the newest month that has anything in it: the report is a monthly
// report, and an unfiltered dump is not the thing that was asked for. The rule
// lives in _report.php so the download cannot decide differently.
$month = deliveredReportMonth($_GET, $months);

$filters = [
    'month' => $month === 'all' ? '' : $month,
    'make'  => trim($_GET['make'] ?? ''),
    'agent' => $canFilter ? (int)($_GET['agent'] ?? 0) : 0,
    'q'     => trim($_GET['q'] ?? ''),
];
$rows = deliveredReportRows($db, $filters, $scopeWhere);

// ── Totals and the two colour scales ────────────────────────────────────────
$totalSales = 0.0;
$countPaid  = [];
foreach ($rows as $r) {
    $totalSales += deliveredReportFinal($r);
    $p = trim((string)($r['payment_type'] ?? '')) ?: 'Not recorded';
    $countPaid[$p] = ($countPaid[$p] ?? 0) + 1;
}
arsort($countPaid);
[$milMin, $milMax] = deliveredReportRange($rows, fn($r) => (float)($r['mileage'] ?? 0));
[$sellMin, $sellMax] = deliveredReportRange($rows, 'deliveredReportFinal');

$incomplete = 0; $noPay = 0;
foreach ($rows as $r) {
    $fl = deliveredReportFlags($r);
    if ($fl['incomplete']) $incomplete++;
    if ($fl['no_payment']) $noPay++;
}

$agents = $canFilter ? $db->query("
    SELECT DISTINCT u.id, u.name FROM crm_leads l
      JOIN users u ON u.id = l.assigned_to
     WHERE l.stage = 'delivered' ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC) : [];

$makes = $db->query("
    SELECT DISTINCT c.make FROM crm_leads l
    LEFT JOIN cars c ON c.id = l.pinned_car_id
     WHERE l.stage = 'delivered' AND c.make IS NOT NULL AND c.make <> '' $scopeWhere
     ORDER BY c.make")->fetchAll(PDO::FETCH_COLUMN);

$qs = array_filter([
    'month' => $month, 'make' => $filters['make'],
    'agent' => $filters['agent'] ?: '', 'q' => $filters['q'],
], fn($v) => $v !== '' && $v !== 0);

$pageTitle = 'Monthly Delivery Report';
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* The sheet's own look: a dark header band, green where the numbers are good,
   pink where the record has a hole in it. Colour never carries a meaning on its
   own here — the legend says each rule in words, and the report gets printed. */
.dr-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;
    gap:12px;margin-bottom:18px}
.dr-head h1{font-size:21px;font-weight:700;color:var(--text);margin:0;display:flex;
    align-items:center;gap:10px}
.dr-head h1 i{width:38px;height:38px;border-radius:10px;background:#d1fae5;display:flex;
    align-items:center;justify-content:center;font-size:16px;color:#059669}

.dr-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;
    margin-bottom:18px}
.dr-tile{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    padding:14px 16px;box-shadow:var(--sh)}
.dr-tile .v{font-size:22px;font-weight:700;color:var(--text);line-height:1}
.dr-tile .k{font-size:12px;color:var(--text-2);margin-top:4px}
.dr-tile.warn .v{color:#b45309}
.dr-tile.bad  .v{color:#b91c1c}

.dr-filters{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    padding:14px 16px;box-shadow:var(--sh);margin-bottom:16px}

.dr-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    box-shadow:var(--sh);overflow:hidden}
.dr-scroll{overflow-x:auto}
table.dr{width:100%;border-collapse:collapse;font-size:12.5px;white-space:nowrap}
table.dr th{background:#0f6b5c;color:#fff;font-weight:700;font-size:11.5px;text-align:left;
    padding:10px 10px;position:sticky;top:0;z-index:2;border-right:1px solid rgba(255,255,255,.16)}
table.dr td{padding:8px 10px;border-top:1px solid var(--border);border-right:1px solid var(--border)}
table.dr tbody tr:hover td{background:var(--brand-soft)}
table.dr td.num{text-align:right;font-variant-numeric:tabular-nums}
table.dr td.idx{text-align:center;color:var(--text-3);width:40px}

/* Pink: the record has a hole in it. */
tr.dr-hole td{background:#f7e4f5}
tr.dr-hole:hover td{background:#f2d6ef}
/* Red: nobody wrote down how they paid. */
tr.dr-nopay td.dr-key{color:#b91c1c;font-weight:600}

/* One hue, four steps, light to dark — lower mileage and higher price read greener. */
.g1{background:#e8f6ee}.g2{background:#c9ead6}.g3{background:#a3dcbe}.g4{background:#79cba4}
tr.dr-hole td.g1,tr.dr-hole td.g2,tr.dr-hole td.g3,tr.dr-hole td.g4{background:#f7e4f5}
td.sell{font-weight:700}
.dr-na{color:var(--text-3);font-style:italic}

.dr-legend{display:flex;flex-wrap:wrap;gap:16px;padding:12px 16px;border-top:1px solid var(--border);
    font-size:11.5px;color:var(--text-2);align-items:center}
.dr-legend span{display:inline-flex;align-items:center;gap:6px}
.dr-swatch{width:13px;height:13px;border-radius:3px;border:1px solid rgba(0,0,0,.12);display:inline-block}

.dr-edit{border:1px solid var(--border);border-radius:6px;font-size:12px;padding:3px 6px;
    background:var(--surface);color:var(--text);max-width:190px}
.dr-edit:focus{outline:2px solid var(--brand-ring);border-color:var(--brand)}

[data-theme="dark"] table.dr th{background:#0b4d43}
[data-theme="dark"] tr.dr-hole td{background:rgba(192,38,211,.14)}
[data-theme="dark"] tr.dr-hole:hover td{background:rgba(192,38,211,.2)}
[data-theme="dark"] tr.dr-nopay td.dr-key{color:#fca5a5}
[data-theme="dark"] .g1{background:rgba(22,163,74,.12)}
[data-theme="dark"] .g2{background:rgba(22,163,74,.22)}
[data-theme="dark"] .g3{background:rgba(22,163,74,.34)}
[data-theme="dark"] .g4{background:rgba(22,163,74,.46)}
[data-theme="dark"] tr.dr-hole td.g1,[data-theme="dark"] tr.dr-hole td.g2,
[data-theme="dark"] tr.dr-hole td.g3,[data-theme="dark"] tr.dr-hole td.g4{background:rgba(192,38,211,.14)}
@media print{.dr-filters,.app-sidebar,.dr-edit{display:none!important}}
</style>

<div class="dr-head">
    <h1><i class="fa fa-table-list"></i>Monthly Delivery Report</h1>
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/delivered_cars/index.php">
            <i class="fa fa-images me-1"></i>Card view</a>
        <a class="btn btn-success btn-sm"
           href="<?= BASE_URL ?>/modules/delivered_cars/export.php?<?= http_build_query($qs) ?>">
            <i class="fa fa-file-excel me-1"></i>Download Excel</a>
    </div>
</div>

<div class="dr-tiles">
    <div class="dr-tile">
        <div class="v"><?= count($rows) ?></div>
        <div class="k"><?= $month !== 'all'
            ? 'Delivered in ' . e(deliveredReportMonthLabel($month)) : 'Delivered, all time' ?></div>
    </div>
    <div class="dr-tile">
        <div class="v" style="font-size:17px"><?= 'KES ' . number_format($totalSales) ?></div>
        <div class="k">Total sales value</div>
    </div>
    <div class="dr-tile">
        <div class="v" style="font-size:17px"><?= $rows
            ? 'KES ' . number_format($totalSales / max(1, count($rows))) : '—' ?></div>
        <div class="k">Average per vehicle</div>
    </div>
    <div class="dr-tile <?= $incomplete ? 'warn' : '' ?>">
        <div class="v"><?= $incomplete ?></div>
        <div class="k">Rows with something missing</div>
    </div>
    <div class="dr-tile <?= $noPay ? 'bad' : '' ?>">
        <div class="v"><?= $noPay ?></div>
        <div class="k">No payment type recorded</div>
    </div>
</div>

<form class="dr-filters" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Month</label>
            <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="all"<?= $month === 'all' ? ' selected' : '' ?>>All months</option>
                <?php foreach ($months as $ym => $m): ?>
                <option value="<?= e($ym) ?>"<?= $month === $ym ? ' selected' : '' ?>>
                    <?= e($m['label']) ?> — <?= (int)$m['count'] ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">Make</label>
            <select name="make" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All makes</option>
                <?php foreach ($makes as $mk): ?>
                <option value="<?= e($mk) ?>"<?= $filters['make'] === $mk ? ' selected' : '' ?>><?= e($mk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($canFilter): ?>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Salesperson</label>
            <select name="agent" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Everyone</option>
                <?php foreach ($agents as $a): ?>
                <option value="<?= (int)$a['id'] ?>"<?= $filters['agent'] === (int)$a['id'] ? ' selected' : '' ?>>
                    <?= e($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Search</label>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" class="form-control form-control-sm"
                   placeholder="Customer, plate or model">
        </div>
        <div class="col-md-1">
            <button class="btn btn-primary btn-sm w-100"><i class="fa fa-filter"></i></button>
        </div>
    </div>
</form>

<div class="dr-wrap">
<div class="dr-scroll">
<table class="dr">
    <thead><tr>
        <th>#</th><th>Vehicle Make</th><th>Model</th><th>Model Year</th><th>Registration</th>
        <th>Mileage (Kms)</th><th>List Price (KES)</th><th>Final Sale Price (KES)</th>
        <th>Sales PI / Salesperson</th><th>Date Sold/Delivered</th><th>Payment Type</th>
        <th>Customer Name</th><th>Commentary</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="13" style="padding:34px;text-align:center;color:var(--text-3)">
            Nothing was delivered in this period.
        </td></tr>
    <?php else: foreach ($rows as $i => $r):
        $v     = deliveredReportVehicle($r);
        $fl    = deliveredReportFlags($r);
        $final = deliveredReportFinal($r);
        $list  = deliveredReportList($r);
        $mil   = (float)($r['mileage'] ?? 0);
        $gMil  = $mil > 0 ? 'g' . deliveredReportShade($mil, $milMin, $milMax, true) : '';
        $gSell = $final > 0 ? 'g' . deliveredReportShade($final, $sellMin, $sellMax) : '';
        $cust  = trim((string)($r['client_name'] ?? '')) ?: trim((string)($r['lead_name'] ?? ''));
        $trCls = trim(($fl['incomplete'] ? 'dr-hole ' : '') . ($fl['no_payment'] ? 'dr-nopay' : ''));
    ?>
        <tr class="<?= $trCls ?>"<?= $fl['reasons']
            ? ' title="Incomplete: ' . e(implode(', ', $fl['reasons'])) . '"' : '' ?>>
            <td class="idx"><?= $i + 1 ?></td>
            <td class="dr-key"><?= $v['make'] !== '' ? e($v['make']) : '<span class="dr-na">N/A</span>' ?></td>
            <td class="dr-key"><?= $v['model'] !== '' ? e($v['model']) : '<span class="dr-na">N/A</span>' ?></td>
            <td><?= $v['year'] !== '' ? e($v['year']) : '<span class="dr-na">N/A</span>' ?></td>
            <td class="dr-key"><?= $v['reg'] !== '' ? e($v['reg']) : '<span class="dr-na">N/A</span>' ?></td>
            <td class="num <?= $gMil ?>"><?= $mil > 0
                ? number_format($mil) : '<span class="dr-na">N/A</span>' ?></td>
            <td class="num"><?= $list > 0
                ? number_format($list) : '<span class="dr-na">N/A</span>' ?></td>
            <td class="num sell <?= $gSell ?>"><?= $final > 0
                ? number_format($final) : '<span class="dr-na">N/A</span>' ?></td>
            <td><?= e($r['agent_name'] ?? '') ?: '<span class="dr-na">—</span>' ?></td>
            <td><?= $r['sold_at'] ? e((new DateTime($r['sold_at']))->format('j-M-y')) : '—' ?></td>
            <?php if (canWrite('crm')): ?>
            <td>
                <form method="post" style="margin:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="lead_id" value="<?= (int)$r['lead_id'] ?>">
                    <input type="hidden" name="sale_commentary" value="<?= e((string)$r['sale_commentary']) ?>">
                    <select name="payment_type" class="dr-edit" onchange="this.form.submit()">
                        <option value="">N/A</option>
                        <?php foreach (deliveredPaymentTypes() as $pt): ?>
                        <option value="<?= e($pt) ?>"<?= $r['payment_type'] === $pt ? ' selected' : '' ?>>
                            <?= e($pt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
            <?php else: ?>
            <td class="dr-key"><?= $r['payment_type']
                ? e($r['payment_type']) : '<span class="dr-na">N/A</span>' ?></td>
            <?php endif; ?>
            <td><?= e($cust) ?: '<span class="dr-na">N/A</span>' ?></td>
            <td style="white-space:normal;min-width:220px">
                <?php if (canWrite('crm')): ?>
                <form method="post" style="margin:0">
                    <?= csrfField() ?>
                    <input type="hidden" name="lead_id" value="<?= (int)$r['lead_id'] ?>">
                    <input type="hidden" name="payment_type" value="<?= e((string)$r['payment_type']) ?>">
                    <input type="text" name="sale_commentary" class="dr-edit" style="max-width:100%;width:100%"
                           value="<?= e((string)$r['sale_commentary']) ?>" maxlength="255"
                           placeholder="Add a note…" onblur="if(this.defaultValue!==this.value)this.form.submit()">
                </form>
                <?php else: ?>
                    <?= e((string)$r['sale_commentary']) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot>
        <tr style="font-weight:700;background:var(--surface-alt)">
            <td colspan="7" style="text-align:right">Total for <?=
                e(deliveredReportMonthLabel($month)) ?></td>
            <td class="num"><?= number_format($totalSales) ?></td>
            <td colspan="5"><?= count($rows) ?> vehicles</td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>
</div>

<div class="dr-legend">
    <span><i class="fa fa-circle-info"></i>How to read this:</span>
    <span><i class="dr-swatch" style="background:#79cba4"></i>greener mileage = lower;
          greener price = higher</span>
    <span><i class="dr-swatch" style="background:#f7e4f5"></i>pink row = something missing on the record</span>
    <span><i class="dr-swatch" style="background:#fff;border-color:#b91c1c"></i>
          <b style="color:#b91c1c">red text</b> = no payment type recorded</span>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
