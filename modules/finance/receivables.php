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
$cfg       = creditReminderConfig();
$pageTitle = 'Receivables';

include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
.rb-table{width:100%;border-collapse:collapse;font-size:13px}
.rb-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--fin-muted);font-weight:600;padding:9px 12px;border-bottom:1px solid var(--fin-ring);
    white-space:nowrap}
.rb-table td{padding:11px 12px;border-bottom:1px solid var(--fin-ring);color:var(--fin-ink);
    vertical-align:top}
.rb-table tr:last-child td{border-bottom:0}
.rb-table tr:hover td{background:var(--fin-plane)}
.rb-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.rb-buyer{font-weight:600}
.rb-sub{font-size:11.5px;color:var(--fin-muted)}
.rb-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;
    padding:2px 8px;border-radius:999px;white-space:nowrap;border:1px solid transparent}
.rb-pill.good{color:var(--fin-up-good);border-color:var(--fin-up-good)}
.rb-pill.critical{color:var(--fin-critical);border-color:var(--fin-critical)}
.rb-pill.warning{color:#8a6100;border-color:var(--fin-warning)}
.rb-pill.legal{color:#6d28d9;border-color:#6d28d9}
.rb-pill.neutral{color:var(--fin-muted);border-color:var(--fin-axis)}
[data-theme="dark"] .rb-pill.warning{color:var(--fin-warning)}
[data-theme="dark"] .rb-pill.legal{color:#c4b5fd;border-color:#8b5cf6}
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
        <div class="table-responsive">
            <table class="rb-table">
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th>Vehicle</th>
                        <th>Standing</th>
                        <th class="num">Outstanding</th>
                        <th class="num">Overdue</th>
                        <th>Next due</th>
                        <th>Last payment</th>
                        <th>Manager</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($book as $r):
                    $st = $r['standing'];
                    $icon = match ($st['tone']) {
                        'good'     => 'fa-circle-check',
                        'critical' => 'fa-circle-exclamation',
                        'warning'  => 'fa-clock',
                        'legal'    => 'fa-gavel',
                        default    => 'fa-circle-dot',
                    };
                ?>
                    <tr>
                        <td>
                            <a class="rb-buyer text-decoration-none"
                               href="<?= BASE_URL ?>/modules/finance/account.php?id=<?= (int)$r['id'] ?>">
                                <?= e((string)($r['buyer'] ?: 'Unnamed')) ?>
                            </a>
                            <div class="rb-sub">
                                <?= e((string)($r['reference'] ?: '')) ?>
                                <?php if (!empty($r['phone'])): ?> · <?= e((string)$r['phone']) ?><?php endif; ?>
                                <?php if (empty($r['email'])): ?>
                                    · <span title="No email address, so no reminders can be sent"
                                            style="color:var(--fin-warning)"><i class="fa fa-envelope-circle-check"></i> no email</span>
                                <?php elseif (!(int)$r['reminders_enabled']): ?>
                                    · <span title="Reminders switched off for this account"
                                            style="color:var(--fin-muted)"><i class="fa fa-bell-slash"></i> muted</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?= e($r['car'] ?: '—') ?>
                            <?php if (!empty($r['registration_number'])): ?>
                            <div class="rb-sub"><?= e((string)$r['registration_number']) ?>
                                <?php if ((int)$r['logbook_held']): ?>
                                    · <i class="fa fa-book" title="Logbook held"></i> logbook held
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="rb-pill <?= e($st['tone']) ?>">
                                <i class="fa <?= $icon ?>"></i><?= e($st['label']) ?>
                            </span>
                        </td>
                        <td class="num"><?= e(number_format((float)$r['balance'])) ?></td>
                        <td class="num">
                            <?php if ((float)$r['overdue_amount'] > 0.009): ?>
                                <span class="rb-late"><?= e(number_format((float)$r['overdue_amount'])) ?></span>
                                <div class="rb-sub rb-late"><?= (int)$r['days_over'] ?> days</div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['next_due']): ?>
                                <?= e(fmtDate((string)$r['next_due'], 'j M Y')) ?>
                                <div class="rb-sub"><?= e(number_format((float)$r['next_amount'])) ?></div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['last_paid_on']): ?>
                                <?= e(fmtDate((string)$r['last_paid_on'], 'j M Y')) ?>
                                <div class="rb-sub"><?= e(number_format((float)$r['last_paid_amount'])) ?></div>
                            <?php else: ?><span class="rb-sub">nothing yet</span><?php endif; ?>
                        </td>
                        <td class="rb-sub"><?= e((string)($r['manager_name'] ?: '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
