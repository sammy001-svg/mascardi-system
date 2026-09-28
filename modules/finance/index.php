<?php
/**
 * Finance & Accounts — the dashboard.
 *
 * Deliberately not another version of Reports → Financial. That screen answers
 * "how did we do", over a period, with year-on-year and profit per vehicle. It
 * is for looking back.
 *
 * This one answers "what is the position, and what needs doing" — the questions
 * an accountant has at nine in the morning:
 *
 *   How much came in, how much went out, what is left.
 *   Who owes us, and for how long.
 *   How much of the bank balance is somebody else's — deposits on cars we have
 *     not handed over, which is a liability nothing else in the system names.
 *   What have we committed to buy and not yet received.
 *   What is sitting waiting for somebody to confirm it.
 *
 * The last of those is the only figure here that is a job rather than a fact,
 * and it is on the screen because a pile of unconfirmed payments means the
 * day's takings shown everywhere else are wrong and nobody knows it.
 */

require_once __DIR__ . '/_figures.php';
requireLogin();

if (!finCanUse()) {
    setFlash('danger', 'You do not have access to the finance dashboard.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();

// ── The period ───────────────────────────────────────────────────────────────
// Answered in SQL, because PHP runs UTC on this host and MySQL runs EAT: a
// "today" worked out in PHP is three hours out, which on a day's takings is the
// difference between right and wrong.
$today = (string)$db->query('SELECT CURDATE()')->fetchColumn();

$ranges = [
    'today' => ['Today',        $today, $today],
    'week'  => ['This week',    (string)$db->query("SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)")->fetchColumn(), $today],
    'month' => ['This month',   (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn(), $today],
    'year'  => ['This year',    (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-01-01')")->fetchColumn(), $today],
];

$key  = array_key_exists($_GET['period'] ?? '', $ranges) ? $_GET['period'] : 'month';
[$periodLabel, $from, $to] = $ranges[$key];

// ── The figures ──────────────────────────────────────────────────────────────
$cash       = finCashFlow($db, $from, $to);
$byMethod   = finByMethod($db, $from, $to);
$recv       = finReceivables($db);
$overdue    = finOverdueInvoices($db, 8);
$arrears    = finArrears($db, 6);
$held       = finDepositsHeld($db);
$committed  = finCommitments($db);
$pending    = finPendingPayments($db);
$categories = finExpenseCategories($db, $from, $to, 6);
$series     = finMonthly($db, 12);

$methodLabels = ['mpesa' => 'M-Pesa', 'bank' => 'Bank', 'cheque' => 'Cheque', 'cash' => 'Cash'];
$methodIcons  = ['mpesa' => 'fa-mobile-screen', 'bank' => 'fa-building-columns',
                 'cheque' => 'fa-money-check', 'cash' => 'fa-money-bill-wave'];

$pageTitle = 'Finance & Accounts';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.fin-kpi{border:1px solid var(--border,#e2e8f0);border-radius:12px;padding:15px 17px;height:100%;background:var(--surface,#fff)}
.fin-kpi .lbl{font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--text-3,#94a3b8);font-weight:600}
.fin-kpi .val{font-size:22px;font-weight:800;line-height:1.25;margin-top:4px}
.fin-kpi .sub{font-size:12px;color:var(--text-3,#94a3b8);margin-top:2px}
.fin-age{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
@media (max-width:767px){.fin-age{grid-template-columns:repeat(2,1fr)}}
.fin-age div{border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:11px 13px}
.fin-age .n{font-size:16px;font-weight:700}
.fin-age .t{font-size:11px;color:var(--text-3,#94a3b8);text-transform:uppercase;letter-spacing:.03em}
.fin-bar{height:7px;border-radius:4px;background:var(--surface-alt,#f1f5f9);overflow:hidden;margin-top:7px}
.fin-bar span{display:block;height:100%}
.fin-late{color:#b91c1c;font-weight:600}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-scale-balanced me-2 text-primary"></i>Finance &amp; Accounts</h5>
        <p class="text-muted small mb-0">Where the money stands, and what is waiting on somebody.</p>
    </div>
    <div class="btn-group btn-group-sm">
        <?php foreach ($ranges as $k => [$lbl, , ]): ?>
        <a class="btn btn-<?= $k === $key ? 'primary' : 'outline-secondary' ?>"
           href="?period=<?= $k ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- What needs doing, if anything does -->
<?php if ($pending['count'] > 0 || $recv['d90'] > 0): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="fa fa-triangle-exclamation mt-1"></i>
    <div>
        <?php if ($pending['count'] > 0): ?>
        <div>
            <strong><?= $pending['count'] ?> payment<?= $pending['count'] === 1 ? '' : 's' ?></strong>
            worth <?= e(money($pending['total'])) ?> <?= $pending['count'] === 1 ? 'is' : 'are' ?>
            keyed in but not confirmed, so <?= $pending['count'] === 1 ? 'it is' : 'they are' ?>
            not counted as cash anywhere on this page.
            <a href="<?= BASE_URL ?>/modules/payments/index.php?status=pending">Confirm <?= $pending['count'] === 1 ? 'it' : 'them' ?></a>.
        </div>
        <?php endif; ?>
        <?php if ($recv['d90'] > 0): ?>
        <div><strong><?= e(money($recv['d90'])) ?></strong> has been owed for more than sixty days.</div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- The position -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="fin-kpi">
            <div class="lbl">In — <?= e($periodLabel) ?></div>
            <div class="val text-success"><?= e(money($cash['in'])) ?></div>
            <div class="sub">Confirmed payments only</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fin-kpi">
            <div class="lbl">Out — <?= e($periodLabel) ?></div>
            <div class="val text-danger"><?= e(money($cash['out'])) ?></div>
            <div class="sub">Recorded expenses</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fin-kpi">
            <div class="lbl">Net</div>
            <div class="val <?= $cash['net'] >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= e(money($cash['net'])) ?>
            </div>
            <div class="sub"><?= $cash['net'] >= 0 ? 'More in than out' : 'More out than in' ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="fin-kpi">
            <div class="lbl">Owed to us</div>
            <div class="val"><?= e(money($recv['owed'])) ?></div>
            <div class="sub"><?= $recv['count'] ?> unpaid invoice<?= $recv['count'] === 1 ? '' : 's' ?></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Ageing -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-hourglass-half me-2 text-primary"></i>How long it has been owed</span>
                <a class="small" href="<?= BASE_URL ?>/modules/invoices/index.php?status=unpaid">All unpaid</a>
            </div>
            <div class="card-body">
                <?php if ($recv['owed'] <= 0): ?>
                    <p class="text-muted small mb-0">Nothing is outstanding.</p>
                <?php else: ?>
                <div class="fin-age">
                    <?php foreach ([
                        ['Not yet due', $recv['current'], '#16a34a'],
                        ['1–30 days',   $recv['d30'],     '#d97706'],
                        ['31–60 days',  $recv['d60'],     '#ea580c'],
                        ['60+ days',    $recv['d90'],     '#dc2626'],
                    ] as [$lbl, $amt, $colour]): ?>
                    <div>
                        <div class="t"><?= e($lbl) ?></div>
                        <div class="n" style="color:<?= $colour ?>"><?= e(money($amt)) ?></div>
                        <div class="fin-bar">
                            <span style="width:<?= $recv['owed'] > 0 ? round($amt / $recv['owed'] * 100) : 0 ?>%;
                                         background:<?= $colour ?>"></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Cash in and out over the year -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-chart-column me-2 text-primary"></i>In and out, last twelve months
            </div>
            <div class="card-body"><canvas id="finFlow" height="110"></canvas></div>
        </div>

        <!-- Who owes, worst first -->
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-file-invoice-dollar me-2 text-danger"></i>Overdue invoices
            </div>
            <?php if (!$overdue): ?>
                <div class="card-body text-muted small">Nothing is overdue.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle" style="font-size:13px">
                    <thead class="table-light">
                        <tr><th class="ps-3">Invoice</th><th>Customer</th>
                            <th class="text-end">Owed</th><th class="text-end pe-3">Late by</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overdue as $o): ?>
                        <tr>
                            <td class="ps-3">
                                <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= (int)$o['id'] ?>">
                                    <?= e((string)$o['invoice_number']) ?>
                                </a>
                            </td>
                            <td><?= e((string)($o['customer_name'] ?: '—')) ?></td>
                            <td class="text-end"><?= e(money((float)$o['owed'])) ?></td>
                            <td class="text-end pe-3 fin-late"><?= (int)$o['days_over'] ?>d</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <!-- Not ours -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-hand-holding-dollar me-2 text-warning"></i>Money we are holding
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-baseline">
                    <div>
                        <div class="fw-bold" style="font-size:20px"><?= e(money($held['total'])) ?></div>
                        <div class="text-muted small">
                            Deposits on <?= $held['count'] ?> reserved vehicle<?= $held['count'] === 1 ? '' : 's' ?>
                        </div>
                    </div>
                    <a class="btn btn-outline-secondary btn-sm"
                       href="<?= BASE_URL ?>/modules/reservations/index.php">View</a>
                </div>
                <p class="text-muted small mb-0 mt-3">
                    This is the customer's money until the car is handed over. If a reservation falls
                    through it goes back, so it is not revenue and should not be spent as though it were.
                </p>
            </div>
        </div>

        <!-- How it arrived -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-wallet me-2 text-primary"></i>How it came in — <?= e($periodLabel) ?>
            </div>
            <div class="card-body">
                <?php if (!$byMethod): ?>
                    <p class="text-muted small mb-0">Nothing came in over this period.</p>
                <?php else: foreach ($byMethod as $method => $d): ?>
                <div class="d-flex justify-content-between align-items-center py-2"
                     style="border-bottom:1px solid var(--border,#e2e8f0)">
                    <span>
                        <i class="fa <?= $methodIcons[$method] ?? 'fa-coins' ?> me-2 text-muted"></i>
                        <?= e($methodLabels[$method] ?? ucfirst((string)$method)) ?>
                        <span class="text-muted small">· <?= (int)$d['n'] ?></span>
                    </span>
                    <strong><?= e(money($d['total'])) ?></strong>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Committed -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-file-contract me-2 text-secondary"></i>Ordered, not yet received
            </div>
            <div class="card-body d-flex justify-content-between align-items-baseline">
                <div>
                    <div class="fw-bold" style="font-size:18px"><?= e(money($committed['total'])) ?></div>
                    <div class="text-muted small">
                        <?= $committed['count'] ?> purchase order<?= $committed['count'] === 1 ? '' : 's' ?> out
                    </div>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/lpo/index.php">View</a>
            </div>
        </div>

        <!-- Where it went -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-arrow-trend-down me-2 text-danger"></i>Where it went — <?= e($periodLabel) ?>
            </div>
            <div class="card-body">
                <?php if (!$categories): ?>
                    <p class="text-muted small mb-0">Nothing was recorded over this period.</p>
                <?php else:
                    $biggest = (float)($categories[0]['total'] ?? 0);
                    foreach ($categories as $c): ?>
                <div class="py-2">
                    <div class="d-flex justify-content-between">
                        <span class="small"><?= e(ucfirst(str_replace('_', ' ', (string)$c['category']))) ?></span>
                        <span class="small fw-semibold"><?= e(money((float)$c['total'])) ?></span>
                    </div>
                    <div class="fin-bar">
                        <span style="width:<?= $biggest > 0 ? round((float)$c['total'] / $biggest * 100) : 0 ?>%;
                                     background:#dc2626"></span>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Instalments behind -->
        <?php if ($arrears): ?>
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-calendar-xmark me-2 text-danger"></i>Instalments in arrears
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="font-size:13px">
                    <thead class="table-light">
                        <tr><th class="ps-3">Due</th><th class="text-end">Owed</th><th class="text-end pe-3">Late</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($arrears as $a): ?>
                        <tr>
                            <td class="ps-3"><?= e(fmtDate((string)$a['due_date'], 'j M Y')) ?></td>
                            <td class="text-end"><?= e(money((float)$a['owed'])) ?></td>
                            <td class="text-end pe-3 fin-late"><?= (int)$a['days_over'] ?>d</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white text-end py-2">
                <a class="small" href="<?= BASE_URL ?>/modules/installments/index.php">All instalments</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var el = document.getElementById('finFlow');
    if (!el || typeof Chart === 'undefined') return;

    var data = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;

    new Chart(el, {
        type: 'bar',
        data: {
            labels: data.map(function (d) { return d.label; }),
            datasets: [
                { label: 'In',  data: data.map(function (d) { return d.in;  }), backgroundColor: '#16a34a' },
                { label: 'Out', data: data.map(function (d) { return d.out; }), backgroundColor: '#dc2626' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (v) {
                            // Millions and thousands, because the raw figures
                            // make the axis unreadable at this width.
                            if (v >= 1000000) return (v / 1000000) + 'M';
                            if (v >= 1000)    return (v / 1000) + 'K';
                            return v;
                        }
                    }
                }
            }
        }
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
