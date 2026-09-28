<?php
/**
 * An account statement: open, every movement, close.
 *
 * Three ways out of here, because an accountant needs all three — read it on
 * screen, print it (or save it as a PDF, which is what the browser's print
 * dialogue does), or download the rows as a spreadsheet to reconcile against
 * the bank's own export.
 *
 * The CSV is written before any HTML so nothing can have been sent by the time
 * the headers go out, and it is a real download rather than a table copied off
 * the screen — an accountant reconciling three hundred lines is not going to
 * select them with a mouse.
 */

require_once __DIR__ . '/_accounts.php';
require_once __DIR__ . '/_figures.php';
requireLogin();

if (!acctCanView()) {
    setFlash('danger', 'You do not have access to the company accounts.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();
acctMigrate($db);

$id = (int)($_GET['id'] ?? 0);
$a  = acctOne($db, $id);

if (!$a) {
    setFlash('danger', 'That account could not be found.');
    redirect(BASE_URL . '/modules/finance/accounts.php');
}

// The period. Defaults to this month, which is what somebody opening a
// statement almost always wants.
$today = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$from  = trim((string)($_GET['from'] ?? '')) ?: (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
$to    = trim((string)($_GET['to'] ?? '')) ?: $today;

if (!strtotime($from)) $from = $today;
if (!strtotime($to))   $to   = $today;
if (strtotime($from) > strtotime($to)) [$from, $to] = [$to, $from];

$s  = acctStatement($db, $id, $from, $to);
$co = getSetting('company_name', 'Mascardi');

// ── The download ─────────────────────────────────────────────────────────────
if (($_GET['download'] ?? '') === 'csv') {
    $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string)$a['name']);
    $file = 'statement-' . trim($name, '-') . '-' . $from . '-to-' . $to . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    // A BOM, so Excel opens it as UTF-8 rather than mangling every name with an
    // accent in it.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [$co . ' — account statement']);
    fputcsv($out, ['Account', acctLabel($a)]);
    fputcsv($out, ['Period', $from . ' to ' . $to]);
    fputcsv($out, ['Printed', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Type', 'Reference', 'Party', 'Detail', 'Method', 'In', 'Out', 'Balance']);
    fputcsv($out, [$from, 'Opening balance', '', '', '', '', '', '', number_format($s['opening'], 2, '.', '')]);

    foreach ($s['rows'] as $r) {
        fputcsv($out, [
            (string)$r['moved_on'], (string)$r['kind'], (string)$r['ref'], (string)$r['party'],
            (string)$r['detail'], (string)$r['method'],
            $r['direction'] === 'in'  ? number_format((float)$r['amount'], 2, '.', '') : '',
            $r['direction'] === 'out' ? number_format((float)$r['amount'], 2, '.', '') : '',
            number_format((float)$r['balance'], 2, '.', ''),
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['', 'Totals', '', '', '', '',
                   number_format($s['in'], 2, '.', ''), number_format($s['out'], 2, '.', ''),
                   number_format($s['closing'], 2, '.', '')]);
    fclose($out);
    exit;
}

$print     = ($_GET['print'] ?? '') === '1';
$pageTitle = 'Statement — ' . $a['name'];

include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
.st-head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;
    padding:18px 20px;border:1px solid var(--fin-ring);border-radius:14px;
    background:var(--fin-surface);margin-bottom:16px}
.st-head h1{font-size:17px;font-weight:600;margin:0 0 3px;color:var(--fin-ink)}
.st-head .m{font-size:12.5px;color:var(--fin-muted)}
.st-sum{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--fin-ring);
    border:1px solid var(--fin-ring);border-radius:12px;overflow:hidden;margin-bottom:16px}
@media (max-width:700px){.st-sum{grid-template-columns:repeat(2,1fr)}}
.st-sum div{background:var(--fin-surface);padding:13px 16px}
.st-sum .l{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--fin-muted);font-weight:600}
.st-sum .v{font-size:17px;font-weight:600;color:var(--fin-ink);margin-top:3px;
    font-variant-numeric:tabular-nums}
.st-tbl{width:100%;border-collapse:collapse;font-size:12.5px;font-variant-numeric:tabular-nums}
.st-tbl th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--fin-muted);font-weight:600;padding:9px 10px;border-bottom:1px solid var(--fin-ring);
    white-space:nowrap}
.st-tbl td{padding:8px 10px;border-bottom:1px solid var(--fin-ring);color:var(--fin-ink)}
.st-tbl tr:last-child td{border-bottom:0}
.st-tbl .num{text-align:right;white-space:nowrap}
.st-tbl .open td,.st-tbl .close td{font-weight:600;background:var(--fin-plane)}
.st-in{color:var(--fin-up-good)}
.st-out{color:var(--fin-critical)}
.st-kind{font-size:10.5px;color:var(--fin-muted);text-transform:uppercase;letter-spacing:.03em}

@media print {
    .app-sidebar, .app-topbar, .no-print, .fin-filters .btn, form { display: none !important; }
    body, .fin { background: #fff !important; }
    .st-head, .st-sum div, .fin-card { border-color: #ccc !important; background: #fff !important; }
    .st-tbl { font-size: 11px }
    a[href]:after { content: none !important; }
}
</style>

<div class="fin">

    <div class="fin-filters no-print">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-file-lines me-2" style="color:var(--fin-in)"></i>Account statement
            </h5>
            <div class="fin-asat"><?= e(acctLabel($a)) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap align-items-end">
            <form method="get" class="d-flex gap-2 align-items-end flex-wrap">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div>
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>">
                </div>
                <div>
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>">
                </div>
                <button class="btn btn-outline-secondary btn-sm">Show</button>
            </form>
            <a class="btn btn-outline-secondary btn-sm"
               href="?id=<?= (int)$id ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>&amp;download=csv">
                <i class="fa fa-download me-1"></i>Download
            </a>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                <i class="fa fa-print me-1"></i>Print
            </button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/accounts.php">
                <i class="fa fa-arrow-left me-1"></i>Accounts
            </a>
        </div>
    </div>

    <!-- The masthead, which is what makes the printed page a document -->
    <div class="st-head">
        <div>
            <h1><?= e($co) ?></h1>
            <div class="m">Account statement</div>
            <div class="m"><?= e(acctLabel($a)) ?></div>
            <?php if (!empty($a['bank_name'])): ?>
            <div class="m"><?= e((string)$a['bank_name']) ?></div>
            <?php endif; ?>
        </div>
        <div class="text-end">
            <div class="m">Period</div>
            <div style="font-weight:600;color:var(--fin-ink)">
                <?= e(fmtDate($from, 'j M Y')) ?> — <?= e(fmtDate($to, 'j M Y')) ?>
            </div>
            <div class="m mt-2">Printed <?= e(date('j M Y, H:i')) ?></div>
        </div>
    </div>

    <div class="st-sum">
        <div>
            <div class="l">Opening</div>
            <div class="v"><?= e(number_format($s['opening'], 2)) ?></div>
        </div>
        <div>
            <div class="l">Money in</div>
            <div class="v st-in"><?= e(number_format($s['in'], 2)) ?></div>
        </div>
        <div>
            <div class="l">Money out</div>
            <div class="v st-out"><?= e(number_format($s['out'], 2)) ?></div>
        </div>
        <div>
            <div class="l">Closing</div>
            <div class="v"><?= e(number_format($s['closing'], 2)) ?></div>
        </div>
    </div>

    <div class="fin-card">
        <header class="no-print">
            <h2>Movements</h2>
            <span class="hint"><?= count($s['rows']) ?> in this period</span>
        </header>

        <div class="table-responsive">
            <table class="st-tbl">
                <thead>
                    <tr>
                        <th>Date</th><th>Description</th><th>Reference</th><th>Method</th>
                        <th class="num">In</th><th class="num">Out</th><th class="num">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="open">
                        <td><?= e(fmtDate($from, 'j M Y')) ?></td>
                        <td colspan="3">Opening balance</td>
                        <td class="num"></td><td class="num"></td>
                        <td class="num"><?= e(number_format($s['opening'], 2)) ?></td>
                    </tr>

                    <?php if (!$s['rows']): ?>
                    <tr><td colspan="7" style="color:var(--fin-muted)">
                        Nothing moved through this account in this period.
                    </td></tr>
                    <?php else: foreach ($s['rows'] as $r): ?>
                    <tr>
                        <td><?= e(fmtDate((string)$r['moved_on'], 'j M Y')) ?></td>
                        <td>
                            <div class="st-kind"><?= e((string)$r['kind']) ?></div>
                            <?= e((string)($r['party'] ?: '—')) ?>
                            <?php if (!empty($r['detail'])): ?>
                            <div style="color:var(--fin-muted);font-size:11.5px">
                                <?= e(mb_substr((string)$r['detail'], 0, 90)) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--fin-muted)"><?= e((string)($r['ref'] ?: '—')) ?></td>
                        <td style="color:var(--fin-muted)"><?= e((string)($r['method'] ?: '—')) ?></td>
                        <td class="num st-in">
                            <?= $r['direction'] === 'in' ? e(number_format((float)$r['amount'], 2)) : '' ?>
                        </td>
                        <td class="num st-out">
                            <?= $r['direction'] === 'out' ? e(number_format((float)$r['amount'], 2)) : '' ?>
                        </td>
                        <td class="num"><?= e(number_format((float)$r['balance'], 2)) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>

                    <tr class="close">
                        <td><?= e(fmtDate($to, 'j M Y')) ?></td>
                        <td colspan="3">Closing balance</td>
                        <td class="num st-in"><?= e(number_format($s['in'], 2)) ?></td>
                        <td class="num st-out"><?= e(number_format($s['out'], 2)) ?></td>
                        <td class="num"><?= e(number_format($s['closing'], 2)) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <p class="fin-asat mt-3">
        Unconfirmed payments are not counted — a payment keyed in but not confirmed is not money in
        the account, and a balance that counts it will not match the bank.
    </p>
</div>

<?php if ($print): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
