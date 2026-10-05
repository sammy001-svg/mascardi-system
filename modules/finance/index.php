<?php
/**
 * The finance dashboard.
 *
 * It used to answer everything: cash flow, expense composition, payment
 * methods, deposits held, supplier commitments, sales splits. Finance did not
 * read most of it, because the question they arrive with each morning is
 * narrower and more urgent — who owes us money on credit, how much came in
 * this month, and who has stopped paying. Four figures and four pictures, all
 * of them about the credit book, and nothing else on the page.
 *
 * Expenses, suppliers, vehicle costs and the full reports still exist on their
 * own screens. A dashboard that tries to be all of them is one nobody reads.
 *
 * Two of the four pictures are canvases and two are plain HTML bars. That is
 * not inconsistency: four ageing bands and five standings are short, ordered
 * lists where the label and the figure have to be legible anyway, and once
 * they are, a canvas adds a dependency and takes away selectable text.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_credit.php';
require_once __DIR__ . '/_dash.php';
requireLogin();

// The same gate as the rest of the credit book: whoever may read Receivables
// may read the summary of it.
(creditCanView() || canAccess('payments')) || die('Access denied.');

$pageTitle = 'Finance';
$db = getDB();

$due      = dashDueSoon($db, 7);
$overdue  = dashOverdue($db);
$got      = dashCollected($db);
$book     = dashOutstanding($db);
$months   = dashMonths($db, 12);
$ageing   = dashAgeing($db);
$standing = dashStanding($db);
$top      = dashTopClients($db, 8);
$asAt     = (string)($db->query("SELECT DATE_FORMAT(NOW(),'%W %e %M %Y, %H:%i')")->fetchColumn() ?: '');

$ageMax   = max(array_map(static fn ($b) => (float)$b['amount'], $ageing)) ?: 1.0;
$standMax = max(array_map(static fn ($b) => (int)$b['n'], $standing)) ?: 1;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_style.php';
?>

<div class="fin fin-body">

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
    <div>
        <h5 class="mb-1"><i class="fa fa-file-contract me-2"></i>The credit book</h5>
        <div class="fin-asat">As at <?= e($asAt) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/modules/finance/receivables.php" class="btn btn-sm btn-primary">
            <i class="fa fa-list me-1"></i>Receivables
        </a>
        <a href="<?= BASE_URL ?>/modules/finance/month.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-calendar-check me-1"></i>This month
        </a>
    </div>
</div>

<?php // Four numbers, not four charts: a single value has no shape to show. ?>
<div class="fin-tiles">
    <div class="fin-tile">
        <div class="lbl">Due in the next <?= (int)$due['days'] ?> days</div>
        <div class="val"><?= e(dashShort($due['amount'])) ?></div>
        <div class="sub">
            <?= (int)$due['count'] ?> instalment<?= $due['count'] === 1 ? '' : 's' ?>
            across <?= (int)$due['accounts'] ?> account<?= $due['accounts'] === 1 ? '' : 's' ?>
        </div>
    </div>

    <div class="fin-tile">
        <div class="lbl">Collected this month</div>
        <div class="val"><?= e(dashShort($got['amount'])) ?></div>
        <div class="sub">
            <?= (int)$got['payments'] ?> payment<?= $got['payments'] === 1 ? '' : 's' ?>
            <?php if ($got['delta'] !== null): ?>
            &middot;
            <span class="fin-d <?= $got['delta'] >= 0 ? 'good' : 'bad' ?>">
                <i class="fa fa-arrow-<?= $got['delta'] >= 0 ? 'up' : 'down' ?>"></i><?php
                ?><?= number_format(abs($got['delta']), 0) ?>%
            </span>
            on last month
            <?php else: ?>
            &middot; nothing last month to compare with
            <?php endif; ?>
        </div>
    </div>

    <?php // Status colour, with the word beside it — never colour alone. ?>
    <div class="fin-tile">
        <div class="lbl">
            <?php if ($overdue['amount'] > 0.009): ?>
            <i class="fa fa-triangle-exclamation me-1" style="color:var(--fin-critical)"></i>
            <?php endif; ?>
            Overdue
        </div>
        <div class="val" <?= $overdue['amount'] > 0.009 ? 'style="color:var(--fin-critical)"' : '' ?>>
            <?= e(dashShort($overdue['amount'])) ?>
        </div>
        <div class="sub">
            <?php if ($overdue['amount'] > 0.009): ?>
            <?= (int)$overdue['accounts'] ?> account<?= $overdue['accounts'] === 1 ? '' : 's' ?>
            &middot; worst <?= (int)$overdue['worst'] ?> days late
            <?php else: ?>
            Nothing is late
            <?php endif; ?>
        </div>
    </div>

    <div class="fin-tile">
        <div class="lbl">Still owed on credit</div>
        <div class="val"><?= e(dashShort($book['amount'])) ?></div>
        <div class="sub">
            across <?= (int)$book['accounts'] ?> live agreement<?= $book['accounts'] === 1 ? '' : 's' ?>
        </div>
    </div>
</div>

<?php // ── Twelve months ─────────────────────────────────────────────────────
      // Two measures in the same unit, so one axis. Collected and still-owed
      // are different things rather than more or less of one thing, so the
      // colour job is identity and the pair is the validated categorical one. ?>
<div class="fin-card">
    <header>
        <h2>Collected each month, and what that month is still owed</h2>
        <span class="hint">
            <span class="fin-key"><i class="fin-swatch" style="background:var(--fin-in)"></i>Collected</span>
            <span class="fin-key"><i class="fin-swatch" style="background:var(--fin-out)"></i>Still owed</span>
        </span>
    </header>
    <div class="fin-pad">
        <div class="fin-plot"><canvas id="finMonths"></canvas></div>
    </div>
    <details class="fin-tv">
        <summary>Read it as a table</summary>
        <table class="fin-table">
            <thead><tr><th>Month</th><th class="num">Collected</th><th class="num">Still owed</th></tr></thead>
            <tbody>
            <?php foreach ($months as $m): ?>
                <tr>
                    <td><?= e($m['label']) ?></td>
                    <td class="num"><?= number_format($m['collected']) ?></td>
                    <td class="num"><?= number_format($m['owed']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</div>

<div class="fin-grid2">

    <?php // ── Arrears ageing ────────────────────────────────────────────────
          // Ordered magnitude, so one hue darkening with lateness. Drawn as
          // rows rather than on a canvas: four bands need their band name and
          // their figure legible regardless, and once both are text the colour
          // is reinforcement rather than the only thing carrying the meaning. ?>
    <div class="fin-card">
        <header><h2>How late the arrears are</h2></header>
        <div class="fin-pad">
            <?php if ($overdue['amount'] > 0.009): ?>
            <div class="fin-age">
                <?php foreach ($ageing as $i => $b): ?>
                <div class="fin-age-row">
                    <div class="band">
                        <i class="fin-swatch" style="background:var(--fin-age-<?= $i + 1 ?>)"></i><?php
                        ?><?= e($b['label']) ?>
                    </div>
                    <div class="track" role="img"
                         aria-label="<?= e($b['label']) ?>: KES <?= number_format($b['amount']) ?>">
                        <span style="width:<?= round($b['amount'] / $ageMax * 100, 1) ?>%;
                                     background:var(--fin-age-<?= $i + 1 ?>)"></span>
                    </div>
                    <div class="amt"><?= number_format($b['amount']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="fin-foot">
                <?= (int)$overdue['count'] ?> unpaid instalment<?= $overdue['count'] === 1 ? '' : 's' ?>
                behind, oldest <?= (int)$overdue['worst'] ?> days
            </div>
            <?php else: ?>
            <div class="fin-empty"><i class="fa fa-circle-check me-2"></i>Nothing is overdue.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php // ── Where the accounts stand ──────────────────────────────────────
          // State, so the reserved status colours, and every row says in words
          // which state it is. ?>
    <div class="fin-card">
        <header><h2>Where the credit accounts stand</h2></header>
        <div class="fin-pad">
            <?php if (array_sum(array_column($standing, 'n')) > 0): ?>
            <div class="fin-age">
                <?php foreach ($standing as $b): ?>
                <div class="fin-age-row">
                    <div class="band">
                        <i class="fin-swatch fin-tone-<?= e($b['tone']) ?>"></i><?= e($b['label']) ?>
                    </div>
                    <div class="track" role="img"
                         aria-label="<?= e($b['label']) ?>: <?= (int)$b['n'] ?> account<?= $b['n'] === 1 ? '' : 's' ?>">
                        <span class="fin-tone-<?= e($b['tone']) ?>"
                              style="width:<?= round($b['n'] / $standMax * 100, 1) ?>%"></span>
                    </div>
                    <div class="amt"><?= (int)$b['n'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="fin-empty"><i class="fa fa-circle-info me-2"></i>No credit agreements yet.</div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php // ── Who owes the most ─────────────────────────────────────────────────
      // One series, so no legend: the heading names the measure. The table
      // underneath is both the way into each account and the table view the
      // chart owes a reader who cannot use it. ?>
<div class="fin-card">
    <header>
        <h2>Credit clients who owe the most</h2>
        <span class="hint"><a href="<?= BASE_URL ?>/modules/finance/receivables.php">See all &rarr;</a></span>
    </header>
    <?php if ($top): ?>
    <div class="fin-pad">
        <div style="position:relative;height:<?= max(150, count($top) * 34) ?>px">
            <canvas id="finTop"></canvas>
        </div>
    </div>
    <table class="fin-table">
        <thead><tr><th>Client</th><th class="num">Still owed</th><th class="num">Of which overdue</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($top as $r): ?>
            <tr>
                <td><?= e((string)$r['name']) ?></td>
                <td class="num"><?= number_format((float)$r['owed']) ?></td>
                <td class="num">
                    <?php if ((float)$r['overdue'] > 0.009): ?>
                    <span class="fin-late"><i class="fa fa-triangle-exclamation me-1"></i><?php
                        ?><?= number_format((float)$r['overdue']) ?></span>
                    <?php else: ?>&mdash;<?php endif; ?>
                </td>
                <td class="num">
                    <a href="<?= BASE_URL ?>/modules/finance/account.php?id=<?= (int)$r['id'] ?>">Open</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="fin-pad">
        <div class="fin-empty"><i class="fa fa-circle-info me-2"></i>Nobody is on credit at the moment.</div>
    </div>
    <?php endif; ?>
</div>

</div><!-- /fin -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var root = document.querySelector('.fin');
    if (!root) return;

    /* The colour roles are read off the stylesheet rather than repeated here.
       Dark mode redefines the same names, so the charts follow the theme
       without a second set of hexes to keep in step with the first. */
    function tone(n) { return getComputedStyle(root).getPropertyValue('--fin-' + n).trim(); }
    function shortKes(v) {
        var a = Math.abs(v), s = v < 0 ? '-' : '';
        if (a >= 1e6) return s + (a / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (a >= 1e3) return s + Math.round(a / 1e3) + 'K';
        return s + Math.round(a);
    }
    function fullKes(v) { return 'KES ' + Math.round(v).toLocaleString(); }

    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size   = 11.5;
    Chart.defaults.color       = tone('muted');
    Chart.defaults.animation   = { duration: 300 };

    /* Grid and axes stay recessive: the data is the thing with colour in it. */
    function xy(extra) {
        return Object.assign({
            grid:   { color: tone('grid'), drawTicks: false },
            border: { display: false },
            ticks:  { color: tone('muted'), padding: 6 }
        }, extra || {});
    }
    var hover = {
        backgroundColor: tone('ink'), titleColor: tone('surface'),
        bodyColor: tone('surface'), padding: 9, cornerRadius: 6, boxPadding: 4
    };

    // ── Twelve months, collected against what that month still owes ────────
    var m = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
    var elM = document.getElementById('finMonths');
    if (elM && m.length) {
        new Chart(elM, {
            type: 'bar',
            data: {
                labels: m.map(function (x) { return x.label; }),
                datasets: [
                    { label: 'Collected',  data: m.map(function (x) { return x.collected; }),
                      backgroundColor: tone('in'),  borderRadius: 4, borderSkipped: 'bottom',
                      maxBarThickness: 15 },
                    { label: 'Still owed', data: m.map(function (x) { return x.owed; }),
                      backgroundColor: tone('out'), borderRadius: 4, borderSkipped: 'bottom',
                      maxBarThickness: 15 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                /* Surface left between neighbouring bars, so two fills never
                   touch and read as one block. */
                datasets: { bar: { categoryPercentage: 0.72, barPercentage: 0.84 } },
                plugins: {
                    legend:  { display: false },   // drawn in the card header instead
                    tooltip: Object.assign({}, hover, { callbacks: { label: function (c) {
                        return c.dataset.label + ': ' + fullKes(c.parsed.y); } } })
                },
                scales: {
                    x: xy({ grid: { display: false } }),
                    y: xy({ beginAtZero: true, ticks: { color: tone('muted'), padding: 6,
                            callback: function (v) { return shortKes(v); } } })
                }
            }
        });
    }

    // ── Who owes the most: one series, one hue, no legend ──────────────────
    var tp = <?= json_encode(array_map(static fn ($r) => [
                  'name' => (string)$r['name'], 'owed' => (float)$r['owed'],
              ], $top), JSON_UNESCAPED_UNICODE) ?>;
    var elT = document.getElementById('finTop');
    if (elT && tp.length) {
        new Chart(elT, {
            type: 'bar',
            data: {
                labels: tp.map(function (x) { return x.name; }),
                datasets: [{
                    label: 'Still owed', data: tp.map(function (x) { return x.owed; }),
                    backgroundColor: tone('age-3'), borderRadius: 4,
                    borderSkipped: 'start', maxBarThickness: 17
                }]
            },
            options: {
                indexAxis: 'y', maintainAspectRatio: false,
                plugins: {
                    legend:  { display: false },
                    tooltip: Object.assign({}, hover, { callbacks: { label: function (c) {
                        return fullKes(c.parsed.x); } } })
                },
                scales: {
                    x: xy({ beginAtZero: true, ticks: { color: tone('muted'), padding: 6,
                            callback: function (v) { return shortKes(v); } } }),
                    y: xy({ grid: { display: false },
                            ticks: { color: tone('ink-2'), padding: 6 } })
                }
            }
        });
    }
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
