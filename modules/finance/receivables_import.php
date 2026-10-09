<?php
/**
 * Import credit accounts from Excel — for buyers who were never in the system.
 *
 * The finance team's book predates the system: plenty of buyers on it bought
 * before there was a lead to hang a credit agreement on. Typing each of those
 * through the CRM would mean inventing a lead, a client and a car for a sale
 * that finished years ago. Instead the sheet is uploaded as it is, every row is
 * checked and shown back, and only then written — an account with a wrong
 * balance is worse than an account not yet imported.
 *
 * Three steps, one page:
 *   GET  ?template=1       a blank sheet with the right headings
 *   POST step=preview      read the file, validate, show what would happen
 *   POST step=commit       write the rows that passed (held in the session)
 *
 * Each imported account gets a normal equal-instalment schedule from its first
 * due date, and anything already paid is recorded as one opening payment and
 * spread oldest-first — so overdue, due-soon and reminders all work exactly as
 * they do for an account created from a lead. No receipt email is sent for the
 * opening payment: the buyer paid that money long ago.
 */

require_once __DIR__ . '/_credit.php';
require_once __DIR__ . '/_figures.php';
require_once __DIR__ . '/../../includes/xlsx_read.php';
requireLogin();

if (!creditCanRecord()) {
    setFlash('danger', 'You do not have permission to import receivables.');
    redirect(BASE_URL . '/modules/finance/receivables.php');
}

$db = getDB();
creditMigrate($db);
$me = (int)(authUser()['id'] ?? 0);

// ── The columns, and the headings people actually write for them ─────────────
$COLS = [
    'name'      => ['label' => 'Client name',          'req' => true,  'aka' => ['client name','client','buyer','customer','customer name','name','buyer name']],
    'phone'     => ['label' => 'Phone',                'req' => false, 'aka' => ['phone','phone number','telephone','tel','mobile','contact']],
    'email'     => ['label' => 'Email',                'req' => false, 'aka' => ['email','email address','e-mail']],
    'vehicle'   => ['label' => 'Vehicle',              'req' => false, 'aka' => ['vehicle','car','make','make/model','make & model','model','unit']],
    'reg'       => ['label' => 'Registration',         'req' => false, 'aka' => ['registration','reg','reg no','reg. no','reg number','registration number','plate','number plate']],
    'agr_date'  => ['label' => 'Agreement date',       'req' => false, 'aka' => ['agreement date','sale date','date sold','date of sale','date']],
    'principal' => ['label' => 'Amount on credit',     'req' => true,  'aka' => ['amount on credit','principal','amount financed','credit amount','total owed','total','balance','outstanding','amount']],
    'paid'      => ['label' => 'Paid to date',         'req' => false, 'aka' => ['paid to date','amount paid','paid','total paid','payments']],
    'monthly'   => ['label' => 'Monthly instalment',   'req' => true,  'aka' => ['monthly instalment','monthly installment','monthly','instalment','installment','monthly payment']],
    'first_due' => ['label' => 'First due date',       'req' => true,  'aka' => ['first due date','first due','start date','due date','next due','next due date']],
    'manager'   => ['label' => 'Account manager',      'req' => false, 'aka' => ['account manager','manager','sales person','salesperson','agent']],
    'notes'     => ['label' => 'Notes',                'req' => false, 'aka' => ['notes','note','comments','remarks']],
];

// ── Template download ────────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    require_once __DIR__ . '/../../includes/xlsx.php';
    $head = $ex = [];
    $sample = ['John Kamau', '0712345678', 'john@example.com', '2019 Toyota Fielder', 'KDA 123A',
               '15/01/2026', 1200000, 300000, 100000, '15/02/2026', '', 'Logbook held'];
    $i = 0;
    foreach ($COLS as $c) {
        $head[] = xlsxText($c['label'] . ($c['req'] ? ' *' : ''), 'h');
        $v = $sample[$i++];
        $ex[] = is_int($v) ? xlsxNumber($v, 'n') : xlsxText($v);
    }
    $path = xlsxWrite('Receivables', [$head, $ex],
        [24, 14, 24, 22, 14, 15, 17, 15, 18, 15, 18, 28],
        ['h' => ['bold' => true, 'fill' => '1E3A8A', 'color' => 'FFFFFF', 'wrap' => true],
         'n' => ['fmt' => '#,##0']]);
    if ($path) { xlsxSend($path, 'receivables_import_template.xlsx'); exit; }
    setFlash('danger', 'The template could not be created on this server.');
    redirect(BASE_URL . '/modules/finance/receivables_import.php');
}

// ── Helpers ──────────────────────────────────────────────────────────────────
$norm = static fn (string $s): string => trim(preg_replace('/[^a-z0-9&\/.]+/', ' ', strtolower(str_replace('*', '', $s))));

/** "1,200,000", "KES 1.2m"-free plain numbers → float, or null. */
$money = static function (string $s): ?float {
    $s = trim(preg_replace('/^(kes|ksh|kshs)\.?\s*/i', '', trim($s)));
    $s = str_replace([',', ' '], '', $s);
    if ($s === '') return null;
    return is_numeric($s) ? round((float)$s, 2) : null;
};

/** The date cell as YYYY-MM-DD, whichever way it was typed. */
$date = static function (string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (is_numeric($s)) return xlsxSerialToDate((float)$s);   // a date cell Excel did not format
    $d = creditReadDate($s);
    if ($d) return $d;
    $t = strtotime($s);                                       // "15 Feb 2026"
    return $t ? date('Y-m-d', $t) : null;
};

$users = finRowsSafe($db, "SELECT id, name, email FROM users");
$findManager = static function (string $s) use ($users): ?array {
    $s = strtolower(trim($s));
    if ($s === '') return null;
    foreach ($users as $u) if (strtolower((string)$u['email']) === $s || strtolower((string)$u['name']) === $s) return $u;
    foreach ($users as $u) if (str_contains(strtolower((string)$u['name']), $s)) return $u;
    return null;
};

$step    = (string)($_POST['step'] ?? '');
$preview = null;
$error   = '';

// ── Step 1: read and check ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview') {
    verifyCsrf();
    $f = $_FILES['sheet'] ?? null;

    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $error = $f && $f['error'] === UPLOAD_ERR_INI_SIZE
            ? 'That file is larger than the server accepts.' : 'Choose an Excel (.xlsx) or CSV file to upload.';
    } elseif ($f['size'] > 10 * 1048576) {
        $error = 'That file is over 10 MB — a receivables book is far smaller. Check it is the right file.';
    } elseif (!in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), ['xlsx', 'csv', 'txt', 'xls'], true)) {
        $error = 'Upload an Excel .xlsx or a .csv file.';
    } else {
        $read = sheetReadFile($f['tmp_name'], $f['name']);
        if (!$read['ok']) {
            $error = $read['error'];
        } else {
            $rows = $read['rows'];

            // The heading row is the first row that names a client column —
            // sheets often have a title or a blank row or two above it.
            $map = [];
            $headAt = -1;
            foreach (array_slice($rows, 0, 10, true) as $ri => $r) {
                $try = [];
                foreach ($r as $ci => $cell) {
                    $h = $norm((string)$cell);
                    if ($h === '') continue;
                    foreach ($COLS as $key => $c) {
                        if (!isset($try[$key]) && in_array($h, $c['aka'], true)) { $try[$key] = $ci; break; }
                    }
                }
                if (isset($try['name'])) { $map = $try; $headAt = $ri; break; }
            }

            $missing = [];
            foreach ($COLS as $key => $c) if ($c['req'] && !isset($map[$key])) $missing[] = $c['label'];

            if ($headAt < 0 || $missing) {
                $error = 'The sheet is missing ' . ($missing ? 'these columns: ' . implode(', ', $missing) : 'a heading row')
                       . '. Download the template below to see the headings expected.';
            } else {
                // What is already in the book, so the same sheet uploaded twice
                // does not double every debt.
                $existing = [];
                foreach (finRowsSafe($db, "SELECT LOWER(TRIM(ext_name)) n, LOWER(REPLACE(COALESCE(ext_registration,''),' ','')) r
                                             FROM credit_agreements WHERE source = 'import' AND status <> 'cancelled'") as $e) {
                    $existing[$e['n'] . '|' . $e['r']] = true;
                }
                $seen  = [];
                $today = (string)$db->query('SELECT CURDATE()')->fetchColumn();
                $out   = [];

                foreach (array_slice($rows, $headAt + 1, null, true) as $ri => $r) {
                    $get = static fn (string $k) => isset($map[$k]) ? trim((string)($r[$map[$k]] ?? '')) : '';
                    if (!$r || implode('', array_map('strval', $r)) === '') continue;

                    $row = [
                        'line'      => $ri + 1,
                        'name'      => mb_substr($get('name'), 0, 150),
                        'phone'     => mb_substr($get('phone'), 0, 40),
                        'email'     => mb_substr($get('email'), 0, 150),
                        'vehicle'   => mb_substr($get('vehicle'), 0, 150),
                        'reg'       => mb_substr(strtoupper($get('reg')), 0, 30),
                        'agr_date'  => $date($get('agr_date')) ?? $today,
                        'principal' => $money($get('principal')),
                        'paid'      => $money($get('paid')) ?? 0.0,
                        'monthly'   => $money($get('monthly')),
                        'first_due' => $date($get('first_due')),
                        'manager'   => $get('manager'),
                        'manager_id'=> null,
                        'notes'     => mb_substr($get('notes'), 0, 4000),
                        'client_id' => null,
                        'errors'    => [],
                        'warnings'  => [],
                    ];

                    if ($row['name'] === '')                                $row['errors'][] = 'No client name';
                    if ($row['principal'] === null || $row['principal'] <= 0) $row['errors'][] = 'Amount on credit missing or not a number';
                    if ($row['monthly'] === null || $row['monthly'] <= 0)     $row['errors'][] = 'Monthly instalment missing or not a number';
                    if ($row['first_due'] === null)                          $row['errors'][] = 'First due date missing or unreadable';
                    if ($get('paid') !== '' && $money($get('paid')) === null) $row['errors'][] = 'Paid to date is not a number';
                    if ($row['paid'] < 0)                                    $row['errors'][] = 'Paid to date is negative';
                    if ($row['principal'] && $row['paid'] > $row['principal'] + 0.009)
                                                                             $row['errors'][] = 'Paid to date is more than the amount on credit';
                    if ($row['email'] !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                        $row['warnings'][] = 'Email looks wrong — it will be left out';
                        $row['email'] = '';
                    }
                    if ($row['principal'] && $row['monthly'] && $row['principal'] / $row['monthly'] > 600) {
                        $row['errors'][] = 'Monthly instalment is too small for the amount (over 50 years)';
                    }
                    if ($row['principal'] && $row['paid'] >= $row['principal'] - 0.009 && !$row['errors']) {
                        $row['warnings'][] = 'Fully paid — will be imported as cleared';
                    }

                    if ($row['manager'] !== '') {
                        $u = $findManager($row['manager']);
                        if ($u) { $row['manager_id'] = (int)$u['id']; $row['manager'] = (string)$u['name']; }
                        else      $row['warnings'][] = 'No user called "' . $row['manager'] . '" — no account manager set';
                    }

                    // A client who IS on file under this phone number is linked
                    // rather than duplicated as free text.
                    if ($row['phone'] !== '') {
                        $digits = substr(preg_replace('/\D+/', '', $row['phone']), -9);
                        if (strlen($digits) === 9) {
                            $hit = finRowsSafe($db, "SELECT id, name FROM clients
                                                      WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''), 9) = ? LIMIT 1", [$digits]);
                            if ($hit) {
                                $row['client_id'] = (int)$hit[0]['id'];
                                $row['warnings'][] = 'Phone matches existing client "' . $hit[0]['name'] . '" — will be linked';
                            }
                        }
                    }

                    $key = strtolower(trim($row['name'])) . '|' . strtolower(str_replace(' ', '', $row['reg']));
                    if (isset($existing[$key]))  $row['errors'][] = 'Already imported (same client and registration)';
                    elseif (isset($seen[$key]))  $row['errors'][] = 'Appears twice in this file (line ' . $seen[$key] . ')';
                    else                         $seen[$key] = $row['line'];

                    $out[] = $row;
                }

                if (!$out) {
                    $error = 'No rows were found under the heading row.';
                } else {
                    $token = bin2hex(random_bytes(8));
                    $_SESSION['rb_import'] = ['token' => $token, 'file' => $f['name'], 'rows' => $out];
                    $preview = $_SESSION['rb_import'];
                }
            }
        }
    }
}

// ── Step 2: write ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'commit') {
    verifyCsrf();
    $held = $_SESSION['rb_import'] ?? null;

    if (!$held || !hash_equals((string)$held['token'], (string)($_POST['token'] ?? ''))) {
        setFlash('danger', 'That import has expired. Upload the file again.');
        redirect(BASE_URL . '/modules/finance/receivables_import.php');
    }

    $ok = 0; $failed = [];
    foreach ($held['rows'] as $r) {
        if ($r['errors']) continue;

        $sched = creditBuildSchedule((float)$r['principal'], (float)$r['monthly'], (string)$r['first_due']);
        if (!$sched['rows']) { $failed[] = 'line ' . $r['line'] . ' (schedule could not be built)'; continue; }

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO credit_agreements
                             (lead_id, client_id, account_manager_id, reference, agreement_date, principal,
                              monthly_payment, first_due_date, installments, completion_date, total_repayable,
                              interest_rate, status, notes, created_by, schedule_type, source,
                              ext_name, ext_phone, ext_email, ext_vehicle, ext_registration)
                          VALUES (NULL,?,?,?,?,?,?,?,?,?,?,0,'active',?,?,'equal','import',?,?,?,?,?)")
               ->execute([
                   $r['client_id'], $r['manager_id'], creditNextReference($db), $r['agr_date'],
                   $r['principal'], $r['monthly'], $r['first_due'], $sched['count'], $sched['completion_date'],
                   $sched['total'], $r['notes'] ?: null, $me ?: null,
                   $r['name'], $r['phone'] ?: null, $r['email'] ?: null, $r['vehicle'] ?: null, $r['reg'] ?: null,
               ]);
            $agrId = (int)$db->lastInsertId();
            creditWriteSchedule($db, $agrId, $sched);

            // Money already received before the system knew about the account:
            // one opening payment, spread oldest-first, so the schedule shows
            // exactly what is still owed. No receipt email — it is history.
            if ((float)$r['paid'] > 0) {
                $db->prepare("INSERT INTO credit_payments
                                 (agreement_id, receipt_number, amount, paid_on, method, notes, recorded_by)
                              VALUES (?,?,?,?, 'Opening balance', 'Paid before the account was imported', ?)")
                   ->execute([$agrId, creditNextReceipt($db), $r['paid'], $r['agr_date'], $me ?: null]);
                creditApplyPayment($db, $agrId, (float)$r['paid'], (int)$db->lastInsertId());
            }
            creditRefreshStatus($db, $agrId);

            if ($r['notes'] !== '') creditAddNote($db, $agrId, 'note', 'Imported from ' . $held['file'] . ': ' . $r['notes'], $me);

            $db->commit();
            $ok++;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('receivables import: ' . $e->getMessage());
            $failed[] = 'line ' . $r['line'] . ' (' . $e->getMessage() . ')';
        }
    }

    unset($_SESSION['rb_import']);
    try { logActivity('import', 'credit_agreements', null, "Imported {$ok} credit account(s) from {$held['file']}."); } catch (\Throwable $e) {}

    setFlash($failed ? 'warning' : 'success',
        "Imported {$ok} account" . ($ok === 1 ? '' : 's') . ' into the receivables book.'
        . ($failed ? ' Could not import: ' . implode('; ', $failed) . '.' : ''));
    redirect(BASE_URL . '/modules/finance/receivables.php');
}

$pageTitle = 'Import receivables';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_style.php';

$good = $preview ? count(array_filter($preview['rows'], static fn ($r) => !$r['errors'])) : 0;
$bad  = $preview ? count($preview['rows']) - $good : 0;
$sum  = $preview ? array_sum(array_map(static fn ($r) => $r['errors'] ? 0 : (float)$r['principal'] - (float)$r['paid'], $preview['rows'])) : 0;
?>
<style>
.ri-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;text-align:center;
    padding:38px 20px;border:2px dashed var(--fin-ring);border-radius:14px;background:var(--fin-plane);
    cursor:pointer;transition:border-color .15s,background .15s}
.ri-drop:hover,.ri-drop.is-over{border-color:var(--fin-in);background:var(--fin-surface)}
.ri-drop i{font-size:34px;color:var(--fin-in)}
.ri-drop strong{color:var(--fin-ink)}
.ri-drop small{color:var(--fin-muted)}
.ri-file{font-weight:600;color:var(--fin-in)}
.ri-cols{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none}
.ri-cols li{font-size:11.5px;padding:3px 9px;border-radius:999px;border:1px solid var(--fin-ring);color:var(--fin-ink-2)}
.ri-cols li.req{border-color:var(--fin-in);color:var(--fin-in);font-weight:600}
.ri-table{width:100%;font-size:12.5px;border-collapse:collapse}
.ri-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--fin-muted);
    text-align:left;padding:8px 10px;border-bottom:1px solid var(--fin-ring);white-space:nowrap}
.ri-table td{padding:8px 10px;border-bottom:1px solid var(--fin-ring);vertical-align:top;color:var(--fin-ink)}
.ri-table td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.ri-table tr.is-bad td{background:rgba(220,38,38,.05)}
.ri-msg{display:block;font-size:11.5px;margin-top:2px}
.ri-msg.err{color:var(--fin-critical)}
.ri-msg.warn{color:#9a6a00}
[data-theme="dark"] .ri-msg.warn{color:var(--fin-warning)}
</style>

<div class="fin">
    <div class="fin-filters">
        <div>
            <h1 class="h5 mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-file-excel me-2" style="color:var(--fin-in)"></i>Import receivables from Excel
            </h1>
            <div class="fin-asat">For credit buyers who are not in the system yet</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" id="ri-template" href="?template=1">
                <i class="fa fa-download me-1"></i>Download template
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/receivables.php">
                <i class="fa fa-arrow-left me-1"></i>Back to receivables
            </a>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="fin-note warning mb-3"><i class="fa fa-triangle-exclamation"></i><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <?php if (!$preview): ?>
    <div class="fin-card mb-3">
        <header><h2>Upload the sheet</h2><span class="hint">.xlsx or .csv · first worksheet only</span></header>
        <div class="fin-body">
            <form method="post" enctype="multipart/form-data" id="ri-form">
                <?= csrfField() ?>
                <input type="hidden" name="step" value="preview">
                <label class="ri-drop" id="ri-drop" for="ri-input">
                    <i class="fa fa-cloud-arrow-up"></i>
                    <strong>Drop the Excel file here, or click to choose</strong>
                    <small id="ri-name">Nothing is saved until you have checked the preview.</small>
                </label>
                <input type="file" name="sheet" id="ri-input" accept=".xlsx,.csv" hidden required>
                <div class="d-flex justify-content-end mt-3">
                    <button class="btn btn-primary btn-sm" id="ri-submit" disabled>
                        <i class="fa fa-magnifying-glass me-1"></i>Check the file
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="fin-card">
        <header><h2>What the sheet needs</h2></header>
        <div class="fin-body">
            <ul class="ri-cols mb-3">
                <?php foreach ($COLS as $c): ?>
                <li class="<?= $c['req'] ? 'req' : '' ?>"><?= e($c['label']) ?><?= $c['req'] ? ' *' : '' ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="mb-1" style="font-size:13px;color:var(--fin-ink-2)">
                One row per buyer. Headings can be in any order and close variations are recognised
                (e.g. <em>Reg No</em>, <em>Customer</em>, <em>Installment</em>). Dates can be written
                <em>15/02/2026</em>, <em>2026-02-15</em> or as Excel dates.
            </p>
            <p class="mb-0" style="font-size:13px;color:var(--fin-muted)">
                <strong>Amount on credit</strong> is what was lent; <strong>Paid to date</strong> is recorded as a single
                opening payment. If you only know the current balance, put that as the amount on credit, leave paid
                blank, and use the next due date as the first due date.
            </p>
        </div>
    </div>

    <?php else: ?>

    <div class="fin-tiles mb-3">
        <div class="fin-tile"><div class="lbl">Ready to import</div><div class="val" style="color:var(--fin-in)"><?= $good ?></div><div class="sub">from <?= e($preview['file']) ?></div></div>
        <div class="fin-tile"><div class="lbl">Will be skipped</div><div class="val" style="color:<?= $bad ? 'var(--fin-critical)' : 'inherit' ?>"><?= $bad ?></div><div class="sub">rows with problems</div></div>
        <div class="fin-tile"><div class="lbl">Outstanding added</div><div class="val" title="<?= e(money($sum)) ?>">KES <?= e(finShort($sum)) ?></div><div class="sub">across the good rows</div></div>
    </div>

    <div class="fin-card">
        <header>
            <h2>Preview</h2>
            <span class="hint">Nothing has been saved yet</span>
        </header>
        <div style="overflow-x:auto">
        <table class="ri-table">
            <thead><tr>
                <th>Line</th><th>Client</th><th>Vehicle</th><th class="num">On credit</th><th class="num">Paid</th>
                <th class="num">Monthly</th><th>First due</th><th>Manager</th><th>Check</th>
            </tr></thead>
            <tbody>
            <?php foreach ($preview['rows'] as $r): ?>
            <tr class="<?= $r['errors'] ? 'is-bad' : '' ?>">
                <td><?= (int)$r['line'] ?></td>
                <td><strong><?= e($r['name'] ?: '—') ?></strong>
                    <?php if ($r['phone'] || $r['email']): ?><span class="ri-msg" style="color:var(--fin-muted)"><?= e(trim($r['phone'] . ' ' . $r['email'])) ?></span><?php endif; ?></td>
                <td><?= e($r['vehicle'] ?: '—') ?><?php if ($r['reg']): ?><span class="ri-msg" style="color:var(--fin-muted)"><?= e($r['reg']) ?></span><?php endif; ?></td>
                <td class="num"><?= $r['principal'] !== null ? e(number_format((float)$r['principal'], 2)) : '—' ?></td>
                <td class="num"><?= e(number_format((float)$r['paid'], 2)) ?></td>
                <td class="num"><?= $r['monthly'] !== null ? e(number_format((float)$r['monthly'], 2)) : '—' ?></td>
                <td><?= $r['first_due'] ? e(fmtDate($r['first_due'], 'j M Y')) : '—' ?></td>
                <td><?= e($r['manager_id'] ? $r['manager'] : '—') ?></td>
                <td>
                    <?php if (!$r['errors'] && !$r['warnings']): ?><span class="ri-msg" style="color:#16a34a"><i class="fa fa-circle-check me-1"></i>OK</span><?php endif; ?>
                    <?php foreach ($r['errors'] as $m): ?><span class="ri-msg err"><i class="fa fa-circle-xmark me-1"></i><?= e($m) ?></span><?php endforeach; ?>
                    <?php foreach ($r['warnings'] as $m): ?><span class="ri-msg warn"><i class="fa fa-circle-exclamation me-1"></i><?= e($m) ?></span><?php endforeach; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="fin-body d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="receivables_import.php"><i class="fa fa-rotate-left me-1"></i>Upload a different file</a>
            <form method="post" class="m-0" onsubmit="return confirm('Import <?= $good ?> account<?= $good === 1 ? '' : 's' ?> into the receivables book?')">
                <?= csrfField() ?>
                <input type="hidden" name="step" value="commit">
                <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <button class="btn btn-primary btn-sm" id="ri-commit" <?= $good ? '' : 'disabled' ?>>
                    <i class="fa fa-file-import me-1"></i>Import <?= $good ?> account<?= $good === 1 ? '' : 's' ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const drop = document.getElementById('ri-drop'), input = document.getElementById('ri-input');
    if (!drop) return;
    const name = document.getElementById('ri-name'), btn = document.getElementById('ri-submit');
    const show = () => {
        const f = input.files[0];
        btn.disabled = !f;
        if (f) name.innerHTML = '<span class="ri-file">' + f.name.replace(/[<>&]/g, '') + '</span> · ' + Math.ceil(f.size / 1024) + ' KB';
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('is-over'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
