<?php
/**
 * Finance & Accounts — the dashboard.
 *
 * Deliberately not another version of Reports → Financial. That screen answers
 * "how did we do", over a period, with year-on-year and profit per vehicle. It
 * is for looking back. This one answers "what is the position, and what needs
 * doing" — the questions an accountant has at nine in the morning.
 *
 * ON THE SHAPE OF IT
 *
 * One filter row at the top scoping everything below it, then one hero figure,
 * then four tiles, then the charts. Every tile carries a delta against the
 * previous window of the same length, because a figure on its own says almost
 * nothing here: 625,000 in is good or bad entirely depending on what the month
 * before did.
 *
 * ON THE COLOUR
 *
 * Two jobs, two treatments, and neither picked by eye:
 *
 *   Cash in and cash out are two identities, so they take categorical slots 1
 *   and 2 — blue and orange, in fixed order, meaning the same thing in every
 *   chart on the page. Orange is money leaving, everywhere.
 *
 *   The ageing bands are ordered — not yet due, 1–30, 31–60, 60+ — so they take
 *   a single-hue ordinal ramp rather than four unrelated colours, which would
 *   claim they are four separate things rather than one thing getting worse.
 *
 * Both were run through the palette validator against this system's own
 * surfaces (#ffffff light, #1e293b dark) rather than assumed. The dark ordinal
 * ramp is a different set of steps from the light one, because the light one's
 * darkest step measures 1.81:1 against the dark surface and disappears into it.
 *
 * Status colours — good, warning, critical — are reserved for state and always
 * ship with an icon and a word, so nothing on this page is carried by colour
 * alone. Every chart has a table underneath it holding the same numbers.
 */

require_once __DIR__ . '/_figures.php';
requireLogin();

if (!finCanUse()) {
    setFlash('danger', 'You do not have access to the finance dashboard.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();

// ── The period ───────────────────────────────────────────────────────────────
// Every date comes from MySQL, because PHP runs UTC on this host and MySQL runs
// EAT: a "today" worked out in PHP is three hours out, which on a day's takings
// is the difference between right and wrong.
$now   = (string)$db->query("SELECT DATE_FORMAT(NOW(), '%e %b %Y, %H:%i')")->fetchColumn();
$today = (string)$db->query('SELECT CURDATE()')->fetchColumn();

$ranges = [
    'today' => ['Today',      $today, $today],
    'week'  => ['This week',  (string)$db->query("SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)")->fetchColumn(), $today],
    'month' => ['This month', (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn(), $today],
    'year'  => ['This year',  (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-01-01')")->fetchColumn(), $today],
];

$key = array_key_exists($_GET['period'] ?? '', $ranges) ? $_GET['period'] : 'month';
[$periodLabel, $from, $to] = $ranges[$key];

$prev = finPrevPeriod($db, $from, $to);

// ── The figures ──────────────────────────────────────────────────────────────
$cash     = finCashFlow($db, $from, $to);
$cashPrev = finCashFlow($db, $prev['from'], $prev['to']);

$dIn  = finDelta($cash['in'],  $cashPrev['in']);
$dOut = finDelta($cash['out'], $cashPrev['out']);
$dNet = finDelta($cash['net'], $cashPrev['net']);

$sparkIn  = finSpark($db, 'in',  $from, $to);
$sparkOut = finSpark($db, 'out', $from, $to);

$byMethod   = finByMethod($db, $from, $to);
$recv       = finReceivables($db);
$overdue    = finOverdueInvoices($db, 8);
$arrears    = finArrears($db, 6);
$held       = finDepositsHeld($db);
$committed  = finCommitments($db);
$pending    = finPendingPayments($db);
$categories = finExpenseCategories($db, $from, $to, 6);
$series     = finMonthly($db, 12);

$methodLabels = ['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'cash' => 'Cash'];
$methodIcons  = ['mpesa' => 'fa-mobile-screen', 'bank' => 'fa-building-columns',
                 'cheque' => 'fa-money-check', 'cash' => 'fa-money-bill-wave'];

$ageBands = [
    ['Not yet due', $recv['current'], 'The invoice date has not passed'],
    ['1–30 days',   $recv['d30'],     'A month or less overdue'],
    ['31–60 days',  $recv['d60'],     'Between one and two months overdue'],
    ['60+ days',    $recv['d90'],     'More than two months overdue'],
];

/** A signed delta, in words a person reads, with its direction. */
$delta = function (array $d, bool $upIsGood = true): array {
    if ($d['pct'] === null) {
        return ['text' => 'no earlier figure', 'tone' => 'flat', 'icon' => 'fa-minus'];
    }
    if ($d['dir'] === 'flat') {
        return ['text' => 'level', 'tone' => 'flat', 'icon' => 'fa-minus'];
    }

    $up   = $d['dir'] === 'up';
    $good = $up === $upIsGood;

    return [
        'text' => sprintf('%+.0f%%', $d['pct']),
        'tone' => $good ? 'good' : 'bad',
        'icon' => $up ? 'fa-arrow-up' : 'fa-arrow-down',
    ];
};

$pageTitle = 'Finance & Accounts';
include __DIR__ . '/../../includes/header.php';
?>

<style>
/*
 * Colour roles, defined once and referenced by role throughout, so the
 * light/dark values swap in one place. Both sets were run through the palette
 * validator against this system's own surfaces.
 */
.fin {
    --fin-surface:  #ffffff;
    --fin-plane:    #f8fafc;
    --fin-ink:      #0f172a;
    --fin-ink-2:    #52514e;
    --fin-muted:    #898781;
    --fin-grid:     #e1e0d9;
    --fin-axis:     #c3c2b7;
    --fin-ring:     rgba(11,11,11,.10);

    /* Categorical: 1 = money in, 2 = money out. Fixed order, never cycled. */
    --fin-in:       #2a78d6;
    --fin-out:      #eb6834;

    /* Ordinal ramp for the ageing bands: one hue, light to dark. */
    --fin-age-1:    #86b6ef;
    --fin-age-2:    #5598e7;
    --fin-age-3:    #2a78d6;
    --fin-age-4:    #184f95;

    /* Status. Fixed, never themed, never used for a series. */
    --fin-good:     #0ca30c;
    --fin-warning:  #fab219;
    --fin-critical: #d03b3b;
    --fin-up-good:  #006300;
}
[data-theme="dark"] .fin {
    --fin-surface:  #1e293b;
    --fin-plane:    #172033;
    --fin-ink:      #e2e8f0;
    --fin-ink-2:    #c3c2b7;
    --fin-muted:    #898781;
    --fin-grid:     #2c3a52;
    --fin-axis:     #384a66;
    --fin-ring:     rgba(255,255,255,.10);

    --fin-in:       #3987e5;
    --fin-out:      #d95926;

    /* Not a flip of the light ramp: the light ramp's darkest step measures
       1.81:1 against this surface and vanishes into it. These four were
       validated against #1e293b. */
    --fin-age-1:    #b7d3f6;
    --fin-age-2:    #86b6ef;
    --fin-age-3:    #5598e7;
    --fin-age-4:    #2a78d6;

    --fin-up-good:  #0ca30c;
}

.fin { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }

/* The filter row: one row, above everything it scopes. */
.fin-filters{display:flex;justify-content:space-between;align-items:center;gap:14px;
    flex-wrap:wrap;padding:12px 0 18px}
.fin-asat{font-size:12px;color:var(--fin-muted)}

/* Hero — exactly one per view. */
.fin-hero{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:16px;
    padding:22px 24px;display:flex;justify-content:space-between;align-items:flex-start;
    gap:20px;flex-wrap:wrap}
.fin-hero .lbl{font-size:12px;letter-spacing:.06em;text-transform:uppercase;
    color:var(--fin-muted);font-weight:600}
.fin-hero .fig{font-size:clamp(34px,5vw,52px);font-weight:600;line-height:1.05;margin-top:6px;
    color:var(--fin-ink);letter-spacing:-.02em}
.fin-hero .note{font-size:13px;color:var(--fin-ink-2);margin-top:7px}

/* Stat tiles. */
.fin-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:14px}
@media (max-width:1100px){.fin-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:520px){.fin-tiles{grid-template-columns:1fr}}
.fin-tile{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;
    padding:15px 17px;display:flex;flex-direction:column;gap:3px;min-width:0}
.fin-tile .lbl{font-size:12px;color:var(--fin-muted);font-weight:600}
.fin-tile .val{font-size:25px;font-weight:600;color:var(--fin-ink);line-height:1.15;
    letter-spacing:-.01em}
.fin-tile .sub{font-size:12px;color:var(--fin-ink-2)}
.fin-d{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600}
.fin-d.good{color:var(--fin-up-good)}
.fin-d.bad{color:var(--fin-critical)}
.fin-d.flat{color:var(--fin-muted)}
.fin-spark{margin-top:7px;height:26px}

/* Cards. */
.fin-card{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;
    overflow:hidden}
.fin-card > header{padding:13px 17px;border-bottom:1px solid var(--fin-ring);
    display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.fin-card > header h2{font-size:14px;font-weight:600;margin:0;color:var(--fin-ink)}
.fin-card > header .hint{font-size:12px;color:var(--fin-muted)}
.fin-body{padding:17px}

/* A chart container tall enough to include its axis band. */
.fin-plot{position:relative;height:280px}
.fin-plot-sm{position:relative;height:210px}

/* Ageing: horizontal bars with the value beside the bar, never inside it. */
.fin-age{display:flex;flex-direction:column;gap:12px}
.fin-age-row{display:grid;grid-template-columns:104px minmax(0,1fr) auto;gap:11px;align-items:center}
.fin-age-row .band{font-size:12.5px;color:var(--fin-ink-2)}
.fin-age-row .track{height:9px;border-radius:5px;background:var(--fin-plane);overflow:hidden}
.fin-age-row .track span{display:block;height:100%;border-radius:5px}
.fin-age-row .amt{font-size:13px;font-weight:600;color:var(--fin-ink);
    font-variant-numeric:tabular-nums}

/* Tables — the twin of every chart. tabular figures belong here. */
.fin-table{width:100%;font-size:13px;border-collapse:collapse;font-variant-numeric:tabular-nums}
.fin-table th{text-align:left;font-weight:600;color:var(--fin-muted);font-size:11.5px;
    text-transform:uppercase;letter-spacing:.04em;padding:8px 12px;
    border-bottom:1px solid var(--fin-ring)}
.fin-table td{padding:9px 12px;border-bottom:1px solid var(--fin-ring);color:var(--fin-ink)}
.fin-table tr:last-child td{border-bottom:0}
.fin-table .num{text-align:right}
.fin-late{color:var(--fin-critical);font-weight:600}
.fin-swatch{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:6px}

/* A status note. Icon and words, so colour never carries it alone. */
.fin-note{display:flex;gap:10px;align-items:flex-start;padding:12px 15px;border-radius:12px;
    font-size:13px;line-height:1.5;border:1px solid var(--fin-ring);background:var(--fin-surface)}
.fin-note i{margin-top:2px}
.fin-note.warning i{color:var(--fin-warning)}
.fin-note.critical i{color:var(--fin-critical)}
.fin-note strong{color:var(--fin-ink)}
.fin-note{color:var(--fin-ink-2)}

.fin-grid2{display:grid;grid-template-columns:minmax(0,7fr) minmax(0,5fr);gap:16px;margin-top:16px}
@media (max-width:1100px){.fin-grid2{grid-template-columns:1fr}}
.fin-stack{display:flex;flex-direction:column;gap:16px}
.fin-meth{display:flex;justify-content:space-between;align-items:center;padding:10px 0;
    border-bottom:1px solid var(--fin-ring);font-size:13px;color:var(--fin-ink)}
.fin-meth:last-child{border-bottom:0}
.fin-empty{font-size:13px;color:var(--fin-muted)}
details.fin-tv{margin-top:14px}
details.fin-tv > summary{font-size:12px;color:var(--fin-muted);cursor:pointer}
</style>

<div class="fin">

    <!-- One filter row, scoping everything below it -->
    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-scale-balanced me-2" style="color:var(--fin-in)"></i>Finance &amp; Accounts
            </h5>
            <div class="fin-asat">As at <?= e($now) ?></div>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="btn-group btn-group-sm" role="group" aria-label="Period">
                <?php foreach ($ranges as $k => [$lbl, , ]): ?>
                <a class="btn btn-<?= $k === $key ? 'primary' : 'outline-secondary' ?>"
                   href="?period=<?= $k ?>"><?= e($lbl) ?></a>
                <?php endforeach; ?>
            </div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/reports/financial.php">
                <i class="fa fa-chart-line me-1"></i>Full report
            </a>
        </div>
    </div>

    <!-- What is waiting on somebody. Icon + words, never colour alone. -->
    <?php if ($pending['count'] > 0 || $recv['d90'] > 0): ?>
    <div class="d-flex flex-column gap-2 mb-3">
        <?php if ($pending['count'] > 0): ?>
        <div class="fin-note warning">
            <i class="fa fa-triangle-exclamation"></i>
            <div>
                <strong>Waiting to be confirmed.</strong>
                <?= $pending['count'] ?> payment<?= $pending['count'] === 1 ? '' : 's' ?>
                worth <?= e(money($pending['total'])) ?>
                <?= $pending['count'] === 1 ? 'is' : 'are' ?> keyed in but not confirmed, so
                <?= $pending['count'] === 1 ? 'it is' : 'they are' ?> not counted as cash
                anywhere on this page.
                <a href="<?= BASE_URL ?>/modules/payments/index.php?status=pending">Confirm
                    <?= $pending['count'] === 1 ? 'it' : 'them' ?></a>.
            </div>
        </div>
        <?php endif; ?>
        <?php if ($recv['d90'] > 0): ?>
        <div class="fin-note critical">
            <i class="fa fa-circle-exclamation"></i>
            <div>
                <strong>Owed for more than two months.</strong>
                <?= e(money($recv['d90'])) ?> has been outstanding beyond sixty days.
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- The hero figure: exactly one -->
    <div class="fin-hero">
        <div>
            <div class="lbl">Net cash · <?= e($periodLabel) ?></div>
            <div class="fig" title="<?= e(money($cash['net'])) ?>">
                KES <?= e(finShort($cash['net'])) ?>
            </div>
            <div class="note">
                <?php $dh = $delta($dNet); ?>
                <span class="fin-d <?= $dh['tone'] ?>">
                    <i class="fa <?= $dh['icon'] ?>"></i><?= e($dh['text']) ?>
                </span>
                against <?= e(money($cashPrev['net'])) ?> over the
                <?= e(finRangeWords($db, $prev['from'], $prev['to'])) ?> before
            </div>
        </div>
        <div class="text-end">
            <div class="lbl">Owed to us</div>
            <div style="font-size:23px;font-weight:600;color:var(--fin-ink);margin-top:5px"
                 title="<?= e(money($recv['owed'])) ?>">
                KES <?= e(finShort($recv['owed'])) ?>
            </div>
            <div class="note">
                across <?= (int)$recv['count'] ?> unpaid invoice<?= $recv['count'] === 1 ? '' : 's' ?>
            </div>
        </div>
    </div>

    <!-- Tiles -->
    <div class="fin-tiles">
        <?php
        $tiles = [
            ['Money in',            $cash['in'],  $delta($dIn,  true),  'Confirmed payments',  $sparkIn,  'in'],
            ['Money out',           $cash['out'], $delta($dOut, false), 'Recorded expenses',   $sparkOut, 'out'],
            ['Held for customers',  $held['total'], null,
                $held['count'] . ' reserved vehicle' . ($held['count'] === 1 ? '' : 's'), [], 'in'],
            ['Ordered, not received', $committed['total'], null,
                $committed['count'] . ' purchase order' . ($committed['count'] === 1 ? '' : 's'), [], 'out'],
        ];
        foreach ($tiles as [$lbl, $val, $d, $sub, $spark, $hue]): ?>
        <div class="fin-tile">
            <div class="lbl"><?= e($lbl) ?></div>
            <div class="val" title="<?= e(money($val)) ?>">KES <?= e(finShort($val)) ?></div>
            <div class="sub">
                <?php if ($d): ?>
                <span class="fin-d <?= $d['tone'] ?>"><i class="fa <?= $d['icon'] ?>"></i><?= e($d['text']) ?></span>
                <span class="text-nowrap">· <?= e($sub) ?></span>
                <?php else: ?>
                <?= e($sub) ?>
                <?php endif; ?>
            </div>
            <?php if ($spark && count($spark) > 1): ?>
            <div class="fin-spark" data-spark="<?= e(implode(',', array_map('intval', $spark))) ?>"
                 data-hue="<?= e($hue) ?>"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="fin-grid2">
        <div class="fin-stack">

            <!-- Cash in and out: two identities, two categorical slots, one axis -->
            <div class="fin-card">
                <header>
                    <h2>Money in and out</h2>
                    <span class="hint">Last twelve months</span>
                </header>
                <div class="fin-body">
                    <div class="fin-plot"><canvas id="finFlow"></canvas></div>
                    <details class="fin-tv">
                        <summary>Show these figures as a table</summary>
                        <table class="fin-table mt-2">
                            <thead><tr><th>Month</th><th class="num">In</th><th class="num">Out</th><th class="num">Net</th></tr></thead>
                            <tbody>
                            <?php foreach (array_reverse($series) as $m): ?>
                                <tr>
                                    <td><?= e($m['label']) ?></td>
                                    <td class="num"><?= e(number_format($m['in'])) ?></td>
                                    <td class="num"><?= e(number_format($m['out'])) ?></td>
                                    <td class="num"><?= e(number_format($m['in'] - $m['out'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                </div>
            </div>

            <!-- Ageing: ordered bands, one hue, light to dark -->
            <div class="fin-card">
                <header>
                    <h2>How long it has been owed</h2>
                    <a class="hint" href="<?= BASE_URL ?>/modules/invoices/index.php?status=unpaid">All unpaid</a>
                </header>
                <div class="fin-body">
                    <?php if ($recv['owed'] <= 0): ?>
                        <p class="fin-empty mb-0">Nothing is outstanding.</p>
                    <?php else: ?>
                    <div class="fin-age">
                        <?php foreach ($ageBands as $i => [$lbl, $amt, $why]): ?>
                        <div class="fin-age-row" title="<?= e($why) ?>">
                            <span class="band"><?= e($lbl) ?></span>
                            <span class="track">
                                <span style="width:<?= $recv['owed'] > 0 ? max(1, round($amt / $recv['owed'] * 100)) : 0 ?>%;
                                             background:var(--fin-age-<?= $i + 1 ?>)"></span>
                            </span>
                            <span class="amt"><?= e($amt > 0 ? money($amt) : '—') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Who owes, worst first -->
            <div class="fin-card">
                <header><h2>Overdue invoices</h2><span class="hint">Oldest first</span></header>
                <?php if (!$overdue): ?>
                    <div class="fin-body"><p class="fin-empty mb-0">Nothing is overdue.</p></div>
                <?php else: ?>
                <table class="fin-table">
                    <thead><tr><th>Invoice</th><th>Customer</th><th class="num">Owed</th><th class="num">Late by</th></tr></thead>
                    <tbody>
                    <?php foreach ($overdue as $o): ?>
                        <tr>
                            <td><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= (int)$o['id'] ?>"><?= e((string)$o['invoice_number']) ?></a></td>
                            <td><?= e((string)($o['customer_name'] ?: '—')) ?></td>
                            <td class="num"><?= e(money((float)$o['owed'])) ?></td>
                            <td class="num fin-late"><?= (int)$o['days_over'] ?> days</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="fin-stack">

            <!-- Where it went: one series, one colour -->
            <div class="fin-card">
                <header><h2>Where it went</h2><span class="hint"><?= e($periodLabel) ?></span></header>
                <div class="fin-body">
                    <?php if (!$categories): ?>
                        <p class="fin-empty mb-0">Nothing was recorded over this period.</p>
                    <?php else: ?>
                    <div class="fin-plot-sm"><canvas id="finCats"></canvas></div>
                    <details class="fin-tv">
                        <summary>Show these figures as a table</summary>
                        <table class="fin-table mt-2">
                            <thead><tr><th>Category</th><th class="num">Amount</th><th class="num">Entries</th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $c): ?>
                                <tr>
                                    <td><?= e(ucfirst(str_replace('_', ' ', (string)$c['category']))) ?></td>
                                    <td class="num"><?= e(number_format((float)$c['total'])) ?></td>
                                    <td class="num"><?= (int)$c['n'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </details>
                    <?php endif; ?>
                </div>
            </div>

            <!-- How it arrived -->
            <div class="fin-card">
                <header><h2>How it came in</h2><span class="hint"><?= e($periodLabel) ?></span></header>
                <div class="fin-body">
                    <?php if (!$byMethod): ?>
                        <p class="fin-empty mb-0">Nothing came in over this period.</p>
                    <?php else: foreach ($byMethod as $method => $d): ?>
                    <div class="fin-meth">
                        <span>
                            <i class="fa <?= $methodIcons[$method] ?? 'fa-coins' ?> me-2" style="color:var(--fin-muted)"></i>
                            <?= e($methodLabels[$method] ?? ucfirst((string)$method)) ?>
                            <span style="color:var(--fin-muted)">· <?= (int)$d['n'] ?></span>
                        </span>
                        <strong style="font-variant-numeric:tabular-nums"><?= e(money($d['total'])) ?></strong>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Not ours -->
            <div class="fin-card">
                <header><h2>Money we are holding</h2></header>
                <div class="fin-body">
                    <p style="font-size:13px;color:var(--fin-ink-2);margin:0">
                        <?= e(money($held['total'])) ?> in deposits on
                        <?= (int)$held['count'] ?> reserved vehicle<?= $held['count'] === 1 ? '' : 's' ?>.
                        This is the customer's money until the car is handed over — if a reservation
                        falls through it goes back, so it is not revenue and should not be spent as
                        though it were.
                    </p>
                    <a class="btn btn-outline-secondary btn-sm mt-3"
                       href="<?= BASE_URL ?>/modules/reservations/index.php">Reservations</a>
                </div>
            </div>

            <?php if ($arrears): ?>
            <div class="fin-card">
                <header><h2>Instalments in arrears</h2></header>
                <table class="fin-table">
                    <thead><tr><th>Due</th><th class="num">Owed</th><th class="num">Late by</th></tr></thead>
                    <tbody>
                    <?php foreach ($arrears as $a): ?>
                        <tr>
                            <td><?= e(fmtDate((string)$a['due_date'], 'j M Y')) ?></td>
                            <td class="num"><?= e(money((float)$a['owed'])) ?></td>
                            <td class="num fin-late"><?= (int)$a['days_over'] ?> days</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var root = document.querySelector('.fin');
    if (!root) return;

    /* Colours are read from the CSS roles rather than written twice, so the
       theme toggle changes one place and the charts follow. */
    function role(name) {
        return getComputedStyle(root).getPropertyValue('--fin-' + name).trim();
    }

    function shortKes(v) {
        var a = Math.abs(v), s = v < 0 ? '-' : '';
        if (a >= 1e6) return s + (a / 1e6).toFixed(a >= 1e7 ? 0 : 1).replace(/\.0$/, '') + 'M';
        if (a >= 1e3) return s + Math.round(a / 1e3) + 'K';
        return s + a;
    }
    function fullKes(v) {
        return 'KES ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
    }

    var charts = [];

    function baseScales() {
        return {
            x: {
                grid: { display: false },
                border: { color: role('axis') },
                ticks: { color: role('muted'), font: { size: 11 } }
            },
            y: {
                beginAtZero: true,
                /* Hairline, solid, one shade off the surface. Never dashed. */
                grid: { color: role('grid'), drawTicks: false },
                border: { display: false },
                ticks: { color: role('muted'), font: { size: 11 }, callback: shortKes }
            }
        };
    }

    function tooltip() {
        return {
            backgroundColor: role('surface'),
            titleColor: role('ink'),
            bodyColor: role('ink-2'),
            borderColor: role('ring'),
            borderWidth: 1,
            padding: 10,
            displayColors: true,
            callbacks: {
                label: function (c) { return ' ' + c.dataset.label + ': ' + fullKes(c.parsed.y ?? c.parsed.x); }
            }
        };
    }

    function build() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        var flow = document.getElementById('finFlow');
        if (flow) {
            var data = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
            charts.push(new Chart(flow, {
                type: 'bar',
                data: {
                    labels: data.map(function (d) { return d.label; }),
                    datasets: [
                        { label: 'In',  data: data.map(function (d) { return d.in; }),
                          backgroundColor: role('in'),
                          /* 4px rounded data-ends, anchored to the baseline. */
                          borderRadius: 4, borderSkipped: 'bottom',
                          /* A 2px surface gap between adjacent bars, not a border. */
                          borderColor: role('surface'), borderWidth: { top: 0, left: 1, right: 1, bottom: 0 } },
                        { label: 'Out', data: data.map(function (d) { return d.out; }),
                          backgroundColor: role('out'),
                          borderRadius: 4, borderSkipped: 'bottom',
                          borderColor: role('surface'), borderWidth: { top: 0, left: 1, right: 1, bottom: 0 } }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        /* Two series, so a legend is always present. */
                        legend: { position: 'bottom', labels: { color: role('ink-2'), boxWidth: 10,
                                  boxHeight: 10, usePointStyle: true, pointStyle: 'rectRounded' } },
                        tooltip: tooltip()
                    },
                    scales: baseScales()
                }
            }));
        }

        var cats = document.getElementById('finCats');
        if (cats) {
            var c = <?= json_encode(array_map(static fn ($r) => [
                'label' => ucfirst(str_replace('_', ' ', (string)$r['category'])),
                'total' => (float)$r['total'],
            ], $categories), JSON_UNESCAPED_UNICODE) ?>;

            charts.push(new Chart(cats, {
                type: 'bar',
                data: {
                    labels: c.map(function (d) { return d.label; }),
                    /* One series, so one colour for every bar — never a ramp,
                       which would double-encode the length as hue. */
                    datasets: [{ label: 'Spent', data: c.map(function (d) { return d.total; }),
                                 backgroundColor: role('out'), borderRadius: 4,
                                 borderSkipped: 'start' }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        /* One series needs no legend box — the card title names it. */
                        legend: { display: false },
                        tooltip: tooltip()
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: role('grid'), drawTicks: false },
                             border: { display: false },
                             ticks: { color: role('muted'), font: { size: 11 }, callback: shortKes } },
                        y: { grid: { display: false }, border: { color: role('axis') },
                             ticks: { color: role('ink-2'), font: { size: 11.5 } } }
                    }
                }
            }));
        }

        /* Sparklines: a de-emphasised line with the last point accented. Drawn
           as inline SVG rather than a Chart.js instance per tile — four more
           canvases for twenty-six pixels of trend is not a fair trade. */
        document.querySelectorAll('[data-spark]').forEach(function (el) {
            var pts = el.getAttribute('data-spark').split(',').map(Number);
            if (pts.length < 2) return;

            var w = el.clientWidth || 160, h = 26, max = Math.max.apply(null, pts) || 1;
            var step = w / (pts.length - 1);
            var d = pts.map(function (p, i) {
                return (i ? 'L' : 'M') + (i * step).toFixed(1) + ' ' + (h - (p / max) * (h - 3) - 1.5).toFixed(1);
            }).join(' ');

            var hue  = role(el.getAttribute('data-hue') === 'out' ? 'out' : 'in');
            var last = pts[pts.length - 1];

            el.innerHTML =
                '<svg width="100%" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" ' +
                'preserveAspectRatio="none" aria-hidden="true">' +
                '<path d="' + d + '" fill="none" stroke="' + hue + '" stroke-opacity=".35" ' +
                'stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' +
                '<circle cx="' + (w - 2) + '" cy="' + (h - (last / max) * (h - 3) - 1.5).toFixed(1) +
                '" r="2.5" fill="' + hue + '"/></svg>';
        });
    }

    build();

    /* The theme toggle swaps the CSS roles; the charts have to be told. */
    new MutationObserver(build).observe(document.documentElement, {
        attributes: true, attributeFilter: ['data-theme']
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
