<?php
/**
 * The receivables book — every car sold on credit, and what is still owed.
 *
 * This is the finance team's spreadsheet, kept by the system. That sheet has a
 * row per buyer with the account manager, the registration, the last payment,
 * the balance and a column of notes, and it is maintained by hand because the
 * credit agreements could only ever be reached one at a time from inside their
 * own lead. Everything here already existed in the database; what did not exist
 * was a page that showed it all at once.
 *
 * Ordered worst first, which is the order the work is done in: the accounts
 * with the lawyers, then the longest overdue, then whatever falls due next.
 * Nothing is paginated — the book is the size of the book, and an accountant
 * scrolling one list beats an accountant clicking through pages of it.
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

$f = [
    'q'        => trim((string)($_GET['q'] ?? '')),
    'standing' => (string)($_GET['standing'] ?? ''),
    'manager'  => (int)($_GET['manager'] ?? 0),
];

$book   = creditBook($db, $f);
$all    = creditBook($db);              // unfiltered, for the tiles and the counts
$totals = creditBookTotals($all);

$standings = [
    ''            => 'Every account',
    'overdue'     => 'Overdue',
    'due_soon'    => 'Due soon',
    'on_schedule' => 'On schedule',
    'legal'       => 'With lawyers',
    'cleared'     => 'Cleared',
];

$counts = [];
foreach ($all as $r) {
    $k = $r['standing']['key'];
    $counts[$k] = ($counts[$k] ?? 0) + 1;
}

$managers = finRowsSafe($db, "SELECT DISTINCT u.id, u.name FROM credit_agreements a
                                JOIN users u ON u.id = a.account_manager_id
                            ORDER BY u.name");

$now       = (string)$db->query("SELECT DATE_FORMAT(NOW(), '%e %b %Y, %H:%i')")->fetchColumn();
// Today, once. Days-late is worked out per card and a query inside that loop
// would be one round trip per account. MySQL's date, not PHP's: this host runs
// PHP on UTC and MySQL on EAT, so the two disagree by three hours about when
// today started.
$todayTs   = strtotime((string)$db->query("SELECT CURDATE()")->fetchColumn());
$cfg       = creditReminderConfig();
$pageTitle = 'Receivables';

include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
/* ── One card per account ────────────────────────────────────────────────────
   The book was a table of figures. It is now a card per agreement, each led by
   the car the money was lent against, because "who is 90 days late" is a
   question people answer by picture faster than by registration number.

   auto-fill rather than a fixed column count, so the grid thins to one column
   on a phone and widens to four on a desk without a breakpoint for each. */
.rb-cards{display:grid;gap:16px;
    grid-template-columns:repeat(auto-fill,minmax(264px,1fr))}
.rb-card{display:flex;flex-direction:column;overflow:hidden;text-decoration:none;
    background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;
    transition:box-shadow .14s,border-color .14s}
/* No lift on hover: a card that moves out from under the pointer at its own
   edge flickers, and a dense grid is all edges. */
.rb-card:hover{border-color:var(--fin-in);box-shadow:0 6px 22px rgba(11,11,11,.09)}
.rb-card.is-done{opacity:.68}
.rb-card.is-done:hover{opacity:1}

.rb-shot{position:relative;aspect-ratio:16/10;background:var(--fin-plane);overflow:hidden}
.rb-shot img{width:100%;height:100%;object-fit:cover;display:block}
.rb-noshot{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
    font-size:40px;color:var(--fin-axis)}

.rb-body{padding:13px 15px 15px;display:flex;flex-direction:column;gap:3px;min-width:0}
.rb-buyer{font-size:14.5px;font-weight:600;color:var(--fin-ink);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rb-sub{font-size:12px;color:var(--fin-muted);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.rb-figs{display:flex;gap:18px;flex-wrap:wrap;margin-top:10px}
.rb-fig{display:flex;flex-direction:column;gap:1px;min-width:0}
.rb-fig span{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;
    color:var(--fin-muted)}
.rb-fig strong{font-size:15px;font-weight:600;color:var(--fin-ink);
    font-variant-numeric:tabular-nums;white-space:nowrap}

.rb-meta{display:flex;gap:12px;flex-wrap:wrap;margin-top:11px;padding-top:10px;
    border-top:1px solid var(--fin-ring);font-size:11.5px;color:var(--fin-muted)}
.rb-meta span{display:inline-flex;align-items:center;gap:5px;min-width:0}
.rb-meta i{opacity:.75}

/* The standing, over the photograph. Backed so it stays legible whatever the
   picture behind it happens to be, and it always carries its own word. */
.rb-pill{position:absolute;top:9px;right:9px;
    display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:700;
    padding:3px 9px;border-radius:999px;white-space:nowrap;
    background:rgba(255,255,255,.94);color:var(--fin-ink-2);
    box-shadow:0 1px 4px rgba(11,11,11,.18)}
.rb-pill.good{color:#0a6b0a}
.rb-pill.critical{color:#a61b1b}
.rb-pill.warning{color:#7a5c00}
.rb-pill.legal{color:#5b21b6}
.rb-pill.neutral{color:var(--fin-ink-2)}
[data-theme="dark"] .rb-pill{background:rgba(13,20,33,.9)}
[data-theme="dark"] .rb-pill.good{color:#5ed95e}
[data-theme="dark"] .rb-pill.critical{color:#f58a8a}
[data-theme="dark"] .rb-pill.warning{color:var(--fin-warning)}
[data-theme="dark"] .rb-pill.legal{color:#c4b5fd}
.rb-late{color:var(--fin-critical);font-weight:600}
.rb-filters{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.rb-chip{font-size:12px;padding:5px 12px;border-radius:999px;text-decoration:none;
    border:1px solid var(--fin-ring);color:var(--fin-ink-2);background:var(--fin-surface)}
.rb-chip.on{background:var(--fin-in);border-color:var(--fin-in);color:#fff;font-weight:600}
</style>

<div class="fin">

    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-file-invoice-dollar me-2" style="color:var(--fin-in)"></i>Receivables
            </h5>
            <div class="fin-asat">Cars sold on credit · as at <?= e($now) ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/month.php">
                <i class="fa fa-calendar-days me-1"></i>Monthly collection
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/index.php">
                <i class="fa fa-scale-balanced me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php if (!$cfg['enabled']): ?>
    <div class="fin-note warning mb-3">
        <i class="fa fa-bell-slash"></i>
        <div>
            <strong>Automatic reminders are switched off.</strong> Nobody is being emailed before a
            payment falls due, or chased after it. You can still send one by hand from an account.
            <?php if (canAccess('settings')): ?>
            <a href="<?= BASE_URL ?>/modules/finance/settings.php">Switch them on</a>.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- The position -->
    <div class="fin-tiles mb-4">
        <div class="fin-tile">
            <div class="lbl">Outstanding</div>
            <div class="val" title="<?= e(money($totals['outstanding'])) ?>">KES <?= e(finShort($totals['outstanding'])) ?></div>
            <div class="sub"><?= (int)$totals['accounts'] ?> open account<?= $totals['accounts'] === 1 ? '' : 's' ?></div>
        </div>
        <div class="fin-tile">
            <div class="lbl">Overdue</div>
            <div class="val" style="color:<?= $totals['overdue'] > 0 ? 'var(--fin-critical)' : 'inherit' ?>"
                 title="<?= e(money($totals['overdue'])) ?>">KES <?= e(finShort($totals['overdue'])) ?></div>
            <div class="sub"><?= (int)$totals['overdue_accounts'] ?> account<?= $totals['overdue_accounts'] === 1 ? '' : 's' ?> behind</div>
        </div>
        <div class="fin-tile">
            <div class="lbl">With lawyers</div>
            <div class="val" title="<?= e(money($totals['legal'])) ?>">KES <?= e(finShort($totals['legal'])) ?></div>
            <div class="sub"><?= (int)$totals['legal_accounts'] ?> case<?= $totals['legal_accounts'] === 1 ? '' : 's' ?></div>
        </div>
        <div class="fin-tile">
            <div class="lbl">Cleared</div>
            <div class="val"><?= (int)$totals['cleared'] ?></div>
            <div class="sub">paid off in full</div>
        </div>
    </div>

    <!-- One filter row -->
    <form method="get" class="rb-filters">
        <?php foreach ($standings as $k => $lbl): ?>
        <a class="rb-chip <?= $f['standing'] === $k ? 'on' : '' ?>"
           href="?<?= e(http_build_query(array_filter(['standing' => $k, 'q' => $f['q'], 'manager' => $f['manager']]))) ?>">
            <?= e($lbl) ?><?= $k !== '' && isset($counts[$k]) ? ' · ' . (int)$counts[$k] : '' ?>
        </a>
        <?php endforeach; ?>

        <?php if ($managers): ?>
        <select name="manager" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">Any account manager</option>
            <?php foreach ($managers as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $f['manager'] === (int)$m['id'] ? 'selected' : '' ?>>
                <?= e((string)$m['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <input type="hidden" name="standing" value="<?= e($f['standing']) ?>">
        <input type="search" name="q" class="form-control form-control-sm" style="width:auto;min-width:200px"
               value="<?= e($f['q']) ?>" placeholder="Buyer, registration or reference">
        <button class="btn btn-outline-secondary btn-sm"><i class="fa fa-magnifying-glass"></i></button>
        <?php if ($f['q'] !== '' || $f['manager']): ?>
        <a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query(array_filter(['standing' => $f['standing']]))) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <div class="fin-card">
        <header>
            <h2><?= e($standings[$f['standing']] ?? 'Accounts') ?></h2>
            <span class="hint"><?= count($book) ?> of <?= count($all) ?></span>
        </header>

        <?php if (!$book): ?>
            <div class="fin-body"><p class="fin-empty mb-0">
                <?= count($all) ? 'Nothing matches that.' : 'No car has been sold on credit yet. A credit agreement is created from a lead once it is reserved.' ?>
            </p></div>
        <?php else: ?>

        <?php /* One card per account, each led by the car the money was lent
                 against. A row of figures told you the balance; the photograph
                 tells you which car is sitting on the forecourt unpaid for, and
                 that is the thing a finance conversation actually turns on.

                 The whole card is the link. A card with one small "open" link
                 on it wastes the other ninety per cent of a target that is
                 already the right shape. */ ?>
        <div class="rb-cards">
            <?php foreach ($book as $r):
                $st    = $r['standing'];
                $icon  = match ($st['tone']) {
                    'good'     => 'fa-circle-check',
                    'critical' => 'fa-triangle-exclamation',
                    'warning'  => 'fa-clock',
                    'legal'    => 'fa-gavel',
                    default    => 'fa-circle',
                };
                $car   = trim(($r['year'] ? $r['year'] . ' ' : '')
                            . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
                $photo = trim((string)($r['car_photo'] ?? ''));
                $over  = (float)($r['overdue_amount'] ?? 0);
                $late  = $over > 0.009;
            ?>
            <a class="rb-card<?= $st['key'] === 'cleared' ? ' is-done' : '' ?>"
               href="<?= BASE_URL ?>/modules/finance/account.php?id=<?= (int)$r['id'] ?>">

                <div class="rb-shot">
                    <?php if ($photo !== ''): ?>
                    <img src="<?= e(thumbUrl('cars', $photo)) ?>"
                         alt="<?= e($car !== '' ? $car : 'The vehicle') ?>"
                         loading="lazy" decoding="async">
                    <?php else: ?>
                    <?php /* No photograph on file. A grey panel with a car in it
                             rather than a broken image or an empty hole, and the
                             card keeps its shape in the grid either way. */ ?>
                    <div class="rb-noshot"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>

                    <span class="rb-pill <?= e($st['tone']) ?>">
                        <i class="fa <?= $icon ?>"></i><?= e($st['label']) ?>
                    </span>
                </div>

                <div class="rb-body">
                    <div class="rb-buyer"><?= e((string)($r['buyer'] ?? 'Unnamed')) ?></div>
                    <div class="rb-sub">
                        <?= $car !== '' ? e($car) : 'Vehicle not recorded' ?>
                        <?php if (!empty($r['registration_number'])): ?>
                        &middot; <?= e((string)$r['registration_number']) ?>
                        <?php endif; ?>
                    </div>

                    <div class="rb-figs">
                        <div class="rb-fig">
                            <span>Outstanding</span>
                            <strong><?= e(money((float)$r['balance'])) ?></strong>
                        </div>
                        <?php if ($late): ?>
                        <div class="rb-fig">
                            <span>Overdue</span>
                            <strong class="rb-late"><?= e(money($over)) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="rb-meta">
                        <?php if (!empty($r['next_due']) && $st['key'] !== 'cleared'): ?>
                        <span><i class="fa fa-calendar-day"></i>
                            <?php if ($late && !empty($r['oldest_overdue'])): ?>
                            <?= e(fmtDate((string)$r['oldest_overdue'], 'j M')) ?> &mdash;
                            <?= (int)max(0, (int)round(($todayTs - strtotime((string)$r['oldest_overdue'])) / 86400)) ?> days late
                            <?php else: ?>
                            due <?= e(fmtDate((string)$r['next_due'], 'j M')) ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($r['last_paid_on'])): ?>
                        <span><i class="fa fa-receipt"></i>paid <?= e(fmtDate((string)$r['last_paid_on'], 'j M')) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($r['manager_name'])): ?>
                        <span><i class="fa fa-user"></i><?= e((string)$r['manager_name']) ?></span>
                        <?php endif; ?>
                        <?php if ((int)($r['note_count'] ?? 0) > 0): ?>
                        <span><i class="fa fa-note-sticky"></i><?= (int)$r['note_count'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
