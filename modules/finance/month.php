<?php
/**
 * Monthly collection — what fell due in a month, and what came in.
 *
 * The question asked at month end, and two different questions at that:
 *
 *   What was due this month, and how much of it was met? That is the arrears
 *   the month has added — a measure of the book's health.
 *
 *   How much money actually arrived this month, whatever instalment it paid?
 *   That is the cash, and it is what the bank statement will agree with.
 *
 * Both are here, side by side, because reporting either one alone is how a
 * month gets called good when it was not.
 */

require_once __DIR__ . '/_credit.php';
require_once __DIR__ . '/_figures.php';
requireLogin();

if (!creditCanView()) {
    setFlash('danger', 'You do not have access to the receivables book.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();
creditMigrate($db);

$m = creditMonth($db, (string)($_GET['m'] ?? ''));

// The twelve months up to this one, for the picker. Built in SQL so the list
// cannot drift from the figures by a timezone.
$months = finRowsSafe($db, "
    SELECT DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH), '%Y-%m') AS k,
           DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL n MONTH), '%b %Y') AS label
      FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
            UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8
            UNION SELECT 9 UNION SELECT 10 UNION SELECT 11) x
  ORDER BY k DESC");

$unpaid = array_values(array_filter($m['due'],
    static fn ($d) => (float)$d['amount'] - (float)$d['amount_paid'] > 0.009));

$pageTitle = 'Monthly collection';
include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
.mo-table{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
.mo-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--fin-muted);font-weight:600;padding:9px 12px;border-bottom:1px solid var(--fin-ring)}
.mo-table td{padding:10px 12px;border-bottom:1px solid var(--fin-ring);color:var(--fin-ink)}
.mo-table tr:last-child td{border-bottom:0}
.mo-table .num{text-align:right}
.mo-meter{height:10px;border-radius:5px;background:var(--fin-plane);overflow:hidden;margin-top:10px}
.mo-meter span{display:block;height:100%;border-radius:5px}
</style>

<div class="fin">

    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-calendar-days me-2" style="color:var(--fin-in)"></i>Monthly collection
            </h5>
            <div class="fin-asat"><?= e($m['label']) ?> · credit accounts only</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="get">
                <select name="m" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($months as $mo): ?>
                    <option value="<?= e((string)$mo['k']) ?>" <?= $m['ym'] === $mo['k'] ? 'selected' : '' ?>>
                        <?= e((string)$mo['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/receivables.php">
                <i class="fa fa-list me-1"></i>The book
            </a>
        </div>
    </div>

    <div class="fin-tiles mb-4">
        <div class="fin-tile">
            <div class="lbl">Fell due</div>
            <div class="val" title="<?= e(money($m['expected'])) ?>">KES <?= e(finShort($m['expected'])) ?></div>
            <div class="sub"><?= count($m['due']) ?> instalment<?= count($m['due']) === 1 ? '' : 's' ?></div>
        </div>
        <div class="fin-tile">
            <div class="lbl">Still owed on them</div>
            <div class="val" style="color:<?= $m['owed'] > 0 ? 'var(--fin-critical)' : 'inherit' ?>"
                 title="<?= e(money($m['owed'])) ?>">KES <?= e(finShort($m['owed'])) ?></div>
            <div class="sub"><?= count($unpaid) ?> not met in full</div>
        </div>
        <div class="fin-tile">
            <div class="lbl">Cash received</div>
            <div class="val" title="<?= e(money($m['collected'])) ?>">KES <?= e(finShort($m['collected'])) ?></div>
            <div class="sub"><?= count($m['payments']) ?> payment<?= count($m['payments']) === 1 ? '' : 's' ?>, any instalment</div>
        </div>
        <div class="fin-tile">
            <div class="lbl">Of what fell due, met</div>
            <div class="val"><?= $m['rate'] === null ? '—' : round($m['rate']) . '%' ?></div>
            <div class="sub"><?= e(money($m['met'])) ?> of <?= e(money($m['expected'])) ?></div>
            <?php if ($m['rate'] !== null): ?>
            <div class="mo-meter">
                <span style="width:<?= max(0, min(100, round($m['rate']))) ?>%;
                             background:<?= $m['rate'] >= 90 ? 'var(--fin-up-good)'
                                          : ($m['rate'] >= 60 ? 'var(--fin-warning)' : 'var(--fin-critical)') ?>"></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="fin-grid2">
        <div class="fin-card">
            <header>
                <h2>What fell due in <?= e($m['label']) ?></h2>
                <span class="hint"><?= count($m['due']) ?></span>
            </header>
            <?php if (!$m['due']): ?>
                <div class="fin-body"><p class="fin-empty mb-0">Nothing was scheduled for this month.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="mo-table">
                    <thead><tr><th>Due</th><th>Buyer</th><th class="num">Amount</th>
                               <th class="num">Paid</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($m['due'] as $d):
                        $owed = (float)$d['amount'] - (float)$d['amount_paid']; ?>
                        <tr>
                            <td><?= e(fmtDate((string)$d['due_date'], 'j M')) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/finance/account.php?id=<?= (int)$d['agreement_id'] ?>"
                                   class="text-decoration-none"><?= e((string)($d['buyer'] ?: 'Unnamed')) ?></a>
                                <?php if (!empty($d['registration_number'])): ?>
                                <div class="rb-sub"><?= e((string)$d['registration_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= e(number_format((float)$d['amount'])) ?></td>
                            <td class="num"><?= e(number_format((float)$d['amount_paid'])) ?></td>
                            <td>
                                <?php if ($owed <= 0.009): ?>
                                    <span style="color:var(--fin-up-good)"><i class="fa fa-check"></i> met</span>
                                <?php else: ?>
                                    <span class="rb-late"><?= e(number_format($owed)) ?> short</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="fin-card">
            <header>
                <h2>Cash received in <?= e($m['label']) ?></h2>
                <span class="hint"><?= count($m['payments']) ?></span>
            </header>
            <?php if (!$m['payments']): ?>
                <div class="fin-body"><p class="fin-empty mb-0">No credit payments were received this month.</p></div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="mo-table">
                    <thead><tr><th>Date</th><th>Buyer</th><th class="num">Amount</th><th>Receipt</th></tr></thead>
                    <tbody>
                    <?php foreach ($m['payments'] as $p): ?>
                        <tr>
                            <td><?= e(fmtDate((string)$p['paid_on'], 'j M')) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/finance/account.php?id=<?= (int)$p['agreement_id'] ?>"
                                   class="text-decoration-none"><?= e((string)($p['buyer'] ?: 'Unnamed')) ?></a>
                                <?php if (!empty($p['registration_number'])): ?>
                                <div class="rb-sub"><?= e((string)$p['registration_number']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= e(number_format((float)$p['amount'])) ?></td>
                            <td class="rb-sub"><?= e((string)$p['receipt_number']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
