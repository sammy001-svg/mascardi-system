<?php
/**
 * One credit account: the schedule, what has been paid, and the trail.
 *
 * Everything the finance team does to an account happens here — take a
 * payment, send a reminder by hand, write down what the buyer said, change who
 * is chasing it, mark it with the lawyers. The printed documents stay where
 * they were built, on the lead, and are linked to rather than rebuilt.
 *
 * Recording a payment goes through creditRecordPayment(), the same call the
 * lead page uses, so the two screens cannot drift into taking money in two
 * different ways.
 *
 * The deal behind the account — what the car cost, what was put down, and the
 * paperwork for it — comes from _deal.php. The printed documents are still
 * built where they always were, on the lead, and are linked rather than
 * rebuilt; what is new is that the ones on file can be read and added to from
 * here, so chasing an account no longer means opening the lead in another tab.
 */

require_once __DIR__ . '/_credit.php';
require_once __DIR__ . '/_deal.php';
require_once __DIR__ . '/_figures.php';
require_once __DIR__ . '/_accounts.php';
requireLogin();

if (!creditCanView()) {
    setFlash('danger', 'You do not have access to the receivables book.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();
creditMigrate($db);

$id  = (int)($_GET['id'] ?? 0);
$me  = authUser();
$uid = (int)$me['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $id     = (int)($_POST['id'] ?? $id);
    $action = (string)($_POST['action'] ?? '');
    $back   = BASE_URL . '/modules/finance/account.php?id=' . $id;

    if (!creditCanRecord()) {
        setFlash('danger', 'You do not have permission to change this account.');
        redirect($back);
    }

    if ($action === 'payment') {
        $r = creditRecordPayment($db, $id,
            (float)str_replace(',', '', (string)($_POST['amount'] ?? 0)),
            (string)($_POST['paid_on'] ?? ''), (string)($_POST['method'] ?? ''),
            (string)($_POST['reference'] ?? ''), (string)($_POST['notes'] ?? ''), $uid,
            acctFromRequest($db));

        if (!$r['ok']) {
            setFlash('danger', $r['error']);
        } else {
            $msg = 'Payment recorded — receipt ' . $r['receipt'] . '. '
                 . ($r['balance'] > 0.009 ? 'Balance now ' . money($r['balance']) . '.'
                                          : 'This account is now settled in full.');
            // Whether the buyer was told is part of the outcome, not a detail.
            setFlash($r['emailed'] ? 'success' : 'warning',
                     $msg . ' ' . ($r['emailed'] ? 'A confirmation has been emailed.' : $r['email_note']));
        }
        redirect($back);
    }

    // Reversing an entry is gated harder than taking one: creditCanReverse()
    // is a manager, not whoever can record a payment.
    if ($action === 'reverse_payment') {
        if (!creditCanReverse()) {
            setFlash('danger', 'Only a manager can reverse a payment.');
            redirect($back);
        }
        $r = creditReversePayment($db, (int)($_POST['payment_id'] ?? 0),
                                  (string)($_POST['reason'] ?? ''), $uid);
        if (!$r['ok']) {
            setFlash('danger', $r['error']);
        } else {
            setFlash('success', 'Payment ' . ($r['receipt'] ?: 'entry') . ' of '
                . money((float)$r['amount']) . ' reversed. Balance is now '
                . money((float)$r['balance']) . '.');
        }
        redirect($back);
    }

    // The documents belong to the lead, so the lead is what they are filed
    // against — this page is only another way in. leadDocsPanel() posts these
    // two actions, and they are handled here exactly as the lead page does.
    if ($action === 'upload_lead_doc') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $ctx    = (string)($_POST['context'] ?? 'other');
        $res    = leadDocsStore($db, $leadId, $ctx,
            (string)($_POST['doc_type'] ?? 'other'),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['notes'] ?? ''),
            $_FILES['document'] ?? [], $uid);
        setFlash($res['ok'] ? 'success' : 'danger',
                 $res['ok'] ? 'Document attached to this deal.' : $res['error']);
        redirect($back . '#paperwork');
    }

    if ($action === 'delete_lead_doc') {
        $res = leadDocsDelete($db, (int)($_POST['doc_id'] ?? 0),
                                   (int)($_POST['lead_id'] ?? 0));
        setFlash($res['ok'] ? 'success' : 'danger',
                 $res['ok'] ? 'Document removed.' : $res['error']);
        redirect($back . '#paperwork');
    }

    if ($action === 'note') {
        creditAddNote($db, $id, (string)($_POST['kind'] ?? 'note'), (string)($_POST['body'] ?? ''), $uid)
            ? setFlash('success', 'Note added.')
            : setFlash('warning', 'Write something first.');
        redirect($back);
    }

    if ($action === 'remind') {
        $inst = finRowsSafe($db, "SELECT ci.*, DATEDIFF(ci.due_date, CURDATE()) AS days_until,
                                         GREATEST(DATEDIFF(CURDATE(), ci.due_date), 0) AS days_over
                                    FROM credit_installments ci
                                   WHERE ci.agreement_id = ? AND ci.amount_paid < ci.amount
                                ORDER BY ci.seq LIMIT 1", [$id]);

        if (!$inst) {
            setFlash('warning', 'There is nothing outstanding to remind them about.');
        } else {
            // 'manual' is its own stage so a reminder sent by hand never uses up
            // one of the automatic ones, and can be sent again if needed.
            $r = creditEmailReminder($db, $inst[0], 'manual');
            setFlash($r['sent'] ? 'success' : 'danger', $r['note']);
        }
        redirect($back);
    }

    /* Amending the figures, and correcting a payment.

       Both exist for the accounts that came in from the spreadsheet. Those were
       typed by hand into a workbook over years, and the first job after an
       import is fixing the rows that were already wrong in the workbook. Until
       now the only way was to delete the account and import it again. */
    if ($action === 'amend_terms') {
        if (!creditCanAmend()) {
            setFlash('danger', 'Only a manager can amend an account.');
            redirect($back);
        }
        $r = creditEditTerms($db, $id, [
            'principal'       => $_POST['principal']       ?? null,
            'monthly_payment' => $_POST['monthly_payment'] ?? null,
            'installments'    => $_POST['installments']    ?? null,
            'first_due_date'  => $_POST['first_due_date']  ?? null,
            'interest_rate'   => $_POST['interest_rate']   ?? null,
            'total_repayable' => $_POST['total_repayable'] ?? null,
            'agreement_date'  => $_POST['agreement_date']  ?? null,
        ], $uid);
        if (!$r['ok']) {
            setFlash('danger', $r['error']);
        } elseif (!$r['changes']) {
            setFlash('info', 'Nothing was changed on that account.');
        } else {
            setFlash('success', 'Account amended — ' . implode('; ', $r['changes'])
                . '. The schedule has been rebuilt and the payments re-applied.');
        }
        redirect($back);
    }

    if ($action === 'edit_payment') {
        if (!creditCanAmend()) {
            setFlash('danger', 'Only a manager can correct a payment.');
            redirect($back);
        }
        $r = creditEditPayment($db, (int)($_POST['payment_id'] ?? 0), [
            'amount'    => $_POST['amount']    ?? null,
            'paid_on'   => $_POST['paid_on']   ?? null,
            'method'    => $_POST['method']    ?? null,
            'reference' => $_POST['reference'] ?? '',
        ], $uid);
        setFlash($r['ok'] ? ($r['changes'] ? 'success' : 'info') : 'danger',
            $r['ok'] ? ($r['changes']
                ? 'Payment corrected — ' . implode('; ', $r['changes']) . '.'
                : 'Nothing was changed on that payment.')
            : $r['error']);
        redirect($back);
    }

    /* Signed paperwork on an account with no lead. The documents table takes
       either owner now, so an imported account holds its own agreement instead
       of being told to go and find a lead that does not exist. */
    if ($action === 'upload_account_doc') {
        if (!creditCanRecord()) {
            setFlash('danger', 'You cannot file documents on this account.');
            redirect($back . '#paperwork');
        }
        $r = leadDocsStore($db, 0, 'credit',
            (string)($_POST['doc_type'] ?? 'other'),
            trim((string)($_POST['title'] ?? '')),
            trim((string)($_POST['notes'] ?? '')),
            $_FILES['document'] ?? [], $uid, $id);
        setFlash($r['ok'] ? 'success' : 'danger',
            $r['ok'] ? 'Document filed against this account.' : $r['error']);
        redirect($back . '#paperwork');
    }

    if ($action === 'delete_account_doc') {
        /* Not creditCanRecord(). That is canWrite('installments'), which a
           cashier holds because taking payments is their work -- and filing a
           scan on that authority is fine. Destroying a signed credit agreement
           is not: it is the document the money is owed under. So removal sits
           with the same people who may amend the account. */
        if (!creditCanAmend()) {
            setFlash('danger', 'Only a manager can remove paperwork from an account.');
            redirect($back . '#paperwork');
        }
        $r = creditDocsDelete($db, (int)($_POST['doc_id'] ?? 0), $id);
        setFlash($r['ok'] ? 'success' : 'danger',
            $r['ok'] ? 'Document removed.' : $r['error']);
        redirect($back . '#paperwork');
    }

    if ($action === 'settings') {
        try {
            $status = in_array($_POST['status'] ?? '', array_keys(creditStatuses()), true)
                    ? $_POST['status'] : 'active';
            $db->prepare("UPDATE credit_agreements
                             SET account_manager_id = ?, logbook_held = ?, reminders_enabled = ?, status = ?
                           WHERE id = ?")
               ->execute([(int)($_POST['account_manager_id'] ?? 0) ?: null,
                          !empty($_POST['logbook_held']) ? 1 : 0,
                          !empty($_POST['reminders_enabled']) ? 1 : 0,
                          $status, $id]);
            setFlash('success', 'Account updated.');
        } catch (\Throwable $e) {
            setFlash('danger', 'That could not be saved: ' . $e->getMessage());
        }
        redirect($back);
    }

    redirect($back);
}

$a = creditAccount($db, $id);
if (!$a) {
    setFlash('danger', 'That credit account could not be found.');
    redirect(BASE_URL . '/modules/finance/receivables.php');
}

$sum      = creditSummary($db, $id);
$schedule = creditInstallments($db, $id);
// Reversed entries are asked for here and nowhere else: this is the one
// screen where a reversal has to be visible rather than simply gone.
$payments = creditPayments($db, $id, true);
$notes    = creditNotes($db, $id);
$sent     = creditSentLog($db, $id, 12);
$to       = creditRecipient($db, $id);
$staff    = finRowsSafe($db, "SELECT id, name FROM users WHERE status='active' ORDER BY name");
$today    = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$cfg      = creditReminderConfig();
$deal     = finDealFigures($db, $a, $sum);
$issued   = finIssuedDocs($db, $a, $deal, $payments);
$filed    = finFiledDocs($db, $a);
$canVoid  = creditCanReverse();
$canAmend = creditCanAmend();
// Paperwork filed against the account itself rather than a lead. Only ever
// populated for an imported account; a lead-backed one keeps using $filed.
$acctDocs = creditDocsFor($db, $id);

$standing = $a['standing'] ?? creditStanding($a + ['balance' => $sum['balance']], $today, $cfg['before']);
$paidPct  = $sum['due'] > 0 ? min(100, round($sum['paid'] / $sum['due'] * 100)) : 0;

$pageTitle = 'Credit account';
include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
.ca-head{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;align-items:flex-start}
.ca-meter{height:9px;border-radius:5px;background:var(--fin-plane);overflow:hidden;margin-top:9px}
.ca-meter span{display:block;height:100%;border-radius:5px;background:var(--fin-in)}
.ca-sched{width:100%;border-collapse:collapse;font-size:13px;font-variant-numeric:tabular-nums}
.ca-sched th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--fin-muted);font-weight:600;padding:8px 12px;border-bottom:1px solid var(--fin-ring)}
.ca-sched td{padding:9px 12px;border-bottom:1px solid var(--fin-ring)}
.ca-sched tr:last-child td{border-bottom:0}
.ca-sched .num{text-align:right}
.ca-sched tr.done td{color:var(--fin-muted)}
.ca-sched tr.late td{background:rgba(208,59,59,.06)}
.ca-sched tr.next td{box-shadow:inset 3px 0 0 var(--fin-in)}
.ca-note{padding:11px 0;border-bottom:1px solid var(--fin-ring);font-size:13px}
.ca-note:last-child{border-bottom:0}
.ca-note .who{font-size:11.5px;color:var(--fin-muted);margin-bottom:3px}
.ca-kind{display:inline-block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;
    font-weight:700;padding:1px 6px;border-radius:4px;background:var(--fin-plane);color:var(--fin-ink-2)}
.ca-log{font-size:12px;color:var(--fin-ink-2);padding:7px 0;border-bottom:1px solid var(--fin-ring)}
.ca-log:last-child{border-bottom:0}

/* ── What the car cost ──────────────────────────────────────────────────────
   auto-fit rather than a column count, so seven figures wrap to two rows on a
   laptop and one on a wide screen without a breakpoint for each. */
.ca-deal{display:grid;gap:14px 22px;
    grid-template-columns:repeat(auto-fit,minmax(118px,1fr))}
.ca-fig{display:flex;flex-direction:column;gap:2px;min-width:0}
.ca-fig .lbl{font-size:10.5px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;
    color:var(--fin-muted)}
.ca-fig .val{font-size:15.5px;font-weight:600;color:var(--fin-ink);
    font-variant-numeric:tabular-nums;white-space:nowrap}
.ca-fig .val.dim{color:var(--fin-muted);font-weight:500}
.ca-fig .sub{font-size:11px;color:var(--fin-muted)}
/* The two that matter most on a page about money owed. */
.ca-fig.lead .val{font-size:18px}
.ca-fig.owing .val{color:var(--fin-critical)}

/* ── Signed documents ───────────────────────────────────────────────────────
   What came back signed and was scanned in. What the system prints is a row of
   buttons at the top of the page instead, since those always exist and a list
   of seven permanent links is a worse way to offer them than seven buttons. */
.ca-docs{display:grid;gap:7px}
.ca-doc{display:flex;align-items:center;gap:11px;padding:9px 11px;
    border:1px solid var(--fin-ring);border-radius:9px;background:var(--fin-surface);
    font-size:13px;color:var(--fin-ink);text-decoration:none}
a.ca-doc:hover{border-color:var(--fin-in);background:var(--fin-plane)}
.ca-doc-ico{width:17px;text-align:center;color:var(--fin-in);flex:0 0 auto}
.ca-doc-main{min-width:0;flex:1}
.ca-doc-t{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ca-doc-n{font-size:11.5px;color:var(--fin-muted);
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ca-doc-go{font-size:11px;color:var(--fin-muted);flex:0 0 auto}

/* ── The document toolbar ───────────────────────────────────────────────────
   One row of buttons at the top, coloured the way the lead page colours the
   same paperwork, so somebody moving between the two screens reaches for the
   same colour. Wraps rather than scrolls: on a phone these become two or
   three rows, which is readable, where a scrolling strip hides half of them. */
.ca-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center;
    padding:0 0 18px}
.ca-acts .btn{font-size:12.5px}
/* A disabled document still shows, and its tooltip says what is missing. A
   button that disappears reads as a missing feature. */
.ca-acts .btn.disabled{opacity:.45}
/* The contract the money is owed under, solid purple as on the lead page. */
.btn-credit{background:#7e22ce;border-color:#7e22ce;color:#fff}
.btn-credit:hover,.btn-credit:focus{background:#6b1fae;border-color:#6b1fae;color:#fff}
.btn-credit.disabled{background:#7e22ce;border-color:#7e22ce;color:#fff}
/* Lifted for the dark surface, and the hover goes darker rather than lighter:
   #a855f7 put the white label at 3.96:1, under the floor. #8a2bd9 is 6.13:1
   and still plainly a different shade from the 5.38:1 base. */
[data-theme="dark"] .btn-credit{background:#9333ea;border-color:#9333ea}
[data-theme="dark"] .btn-credit:hover,
[data-theme="dark"] .btn-credit:focus{background:#8a2bd9;border-color:#8a2bd9}
/* A thin rule under the row, so it reads as a toolbar rather than as part of
   the figures below it. */
.ca-acts-wrap{border-bottom:1px solid var(--fin-ring);margin-bottom:18px}

/* A reversed payment stays on the page. Struck through rather than removed,
   because the reason it is still here is to show that it happened. */
.ca-sched tr.void td{color:var(--fin-muted)}
.ca-sched tr.void td .amt,
.ca-sched tr.void td .rcpt{text-decoration:line-through}
.ca-void-why{font-size:11px;color:var(--fin-critical);margin-top:2px}
.ca-rev{border:0;background:none;padding:2px 5px;border-radius:5px;
    color:var(--fin-muted);font-size:12px;cursor:pointer}
.ca-rev:hover{color:var(--fin-critical);background:var(--fin-plane)}
</style>

<div class="fin">

    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-file-contract me-2" style="color:var(--fin-in)"></i>
                <?= e((string)($a['buyer'] ?? 'Credit account')) ?>
            </h5>
            <div class="fin-asat">
                <?= e((string)($a['reference'] ?? '')) ?>
                <?php if (!empty($a['car'])): ?> · <?= e((string)$a['car']) ?><?php endif; ?>
                <?php if (!empty($a['registration_number'])): ?> · <?= e((string)$a['registration_number']) ?><?php endif; ?>
            </div>
        </div>
        <!-- Navigation only. The documents are a row of their own below, so
             the Statement button that used to sit here is in the toolbar. -->
        <div class="d-flex gap-2 flex-wrap">
            <?php if (!empty($a['lead_id'])): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$a['lead_id'] ?>#credit">
                <i class="fa fa-user me-1"></i>The lead
            </a>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/receivables.php">
                <i class="fa fa-arrow-left me-1"></i>Book
            </a>
        </div>
    </div>

    <!-- Everything this account can print, and the one thing it can be sent.
         Each document keeps the colour the lead page gives it. -->
    <div class="ca-acts-wrap">
        <div class="ca-acts">
            <?php foreach (finDocButtons($issued) as $d): ?>
                <?php if ($d['available']): ?>
                <a href="<?= e($d['url']) ?>" target="_blank" rel="noopener"
                   class="btn btn-sm <?= e($d['btn']) ?>" title="<?= e($d['note']) ?>">
                    <i class="fa <?= e($d['icon']) ?> me-1"></i><?= e($d['short']) ?>
                </a>
                <?php else: ?>
                <a class="btn btn-sm <?= e($d['btn']) ?> disabled" tabindex="-1"
                   aria-disabled="true" title="<?= e($d['note']) ?>">
                    <i class="fa <?= e($d['icon']) ?> me-1"></i><?= e($d['short']) ?>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if (creditCanRecord() && (float)$sum['balance'] > 0.009): ?>
            <!-- Solid, because it is the one button here that changes something
                 rather than printing something. -->
            <button type="button" class="btn btn-sm btn-success"
                    data-bs-toggle="modal" data-bs-target="#payModal">
                <i class="fa fa-coins me-1"></i>Record Payment
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Where it stands -->
    <div class="fin-hero mb-4">
        <div style="min-width:240px;flex:1">
            <div class="lbl">Outstanding</div>
            <div class="fig" title="<?= e(money((float)$sum['balance'])) ?>">
                KES <?= e(finShort((float)$sum['balance'])) ?>
            </div>
            <div class="note">
                <?= e(money((float)$sum['paid'])) ?> paid of <?= e(money((float)$sum['due'])) ?>
                · <?= (int)$sum['paid_count'] ?> of <?= (int)$sum['count'] ?> instalments
            </div>
            <div class="ca-meter" style="max-width:340px"><span style="width:<?= (int)$paidPct ?>%"></span></div>
        </div>
        <div class="text-end">
            <span class="rb-pill <?= e($standing['tone']) ?>" style="font-size:12px">
                <?= e($standing['label']) ?>
            </span>
            <?php if ($sum['next_due']): ?>
            <div class="note mt-2">
                Next: <strong><?= e(money((float)$sum['next_amount'])) ?></strong><br>
                due <?= e(fmtDate((string)$sum['next_due'], 'j F Y')) ?>
            </div>
            <?php endif; ?>
            <?php if ((float)$sum['overdue_amount'] > 0.009): ?>
            <div class="note mt-2" style="color:var(--fin-critical)">
                <?= e(money((float)$sum['overdue_amount'])) ?> overdue
                across <?= (int)$sum['overdue_count'] ?> instalment<?= $sum['overdue_count'] === 1 ? '' : 's' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($to['email'] === ''): ?>
    <div class="fin-note warning mb-3">
        <i class="fa fa-triangle-exclamation"></i>
        <div><strong>No email address on file.</strong> No reminder or receipt can reach this buyer
            until one is added to their client record.</div>
    </div>
    <?php elseif (!(int)$a['reminders_enabled']): ?>
    <div class="fin-note warning mb-3">
        <i class="fa fa-bell-slash"></i>
        <div><strong>Reminders are switched off for this account.</strong> Nothing automatic will be
            sent to <?= e($to['email']) ?>. You can still send one by hand below.</div>
    </div>
    <?php endif; ?>

    <!-- What the car cost, and where that money has got to.
         The hero above answers "how much is still owed"; finance also has to
         answer "owed against what", and that used to mean opening the lead. -->
    <div class="fin-card mb-4">
        <header>
            <h2>The deal</h2>
            <?php /* The way into the figures. On the card that shows them, because
                     that is where somebody notices one is wrong. */ ?>
            <?php if ($canAmend): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto"
                    data-bs-toggle="modal" data-bs-target="#amendModal">
                <i class="fa fa-pen me-1"></i>Amend
            </button>
            <?php endif; ?>
            <span class="hint">
                <?php if (!empty($a['car'])): ?><?= e((string)$a['car']) ?><?php endif; ?>
                <?php if (!empty($a['registration_number'])): ?>
                    · <?= e((string)$a['registration_number']) ?><?php endif; ?>
            </span>
        </header>
        <div class="fin-body">
            <div class="ca-deal">
                <div class="ca-fig lead">
                    <span class="lbl">Car value</span>
                    <?php if ($deal['price'] > 0): ?>
                    <span class="val"><?= e(number_format((float)$deal['price'])) ?></span>
                    <span class="sub"><?= $deal['price_source'] === 'agreed'
                        ? 'agreed sale price' : 'asking price — no agreed price recorded' ?></span>
                    <?php else: ?>
                    <span class="val dim">not recorded</span>
                    <span class="sub">no price on the lead or the car</span>
                    <?php endif; ?>
                </div>
                <div class="ca-fig">
                    <span class="lbl">Deposit</span>
                    <span class="val<?= $deal['deposit'] > 0 ? '' : ' dim' ?>">
                        <?= $deal['deposit'] > 0 ? e(number_format((float)$deal['deposit'])) : '—' ?>
                    </span>
                    <span class="sub">paid up front</span>
                </div>
                <div class="ca-fig">
                    <span class="lbl">Financed</span>
                    <span class="val"><?= e(number_format((float)$deal['principal'])) ?></span>
                    <span class="sub">principal</span>
                </div>
                <?php if ($deal['charges'] > 0): ?>
                <div class="ca-fig">
                    <span class="lbl">Interest</span>
                    <span class="val"><?= e(number_format((float)$deal['charges'])) ?></span>
                    <span class="sub">over <?= (int)$sum['count'] ?> instalments</span>
                </div>
                <?php endif; ?>
                <div class="ca-fig">
                    <span class="lbl">Repayable</span>
                    <span class="val"><?= e(number_format((float)$deal['repayable'])) ?></span>
                    <span class="sub">total on the schedule</span>
                </div>
                <div class="ca-fig">
                    <span class="lbl">Paid</span>
                    <span class="val"><?= e(number_format((float)$deal['paid'])) ?></span>
                    <span class="sub"><?= (int)$sum['paid_count'] ?> of <?= (int)$sum['count'] ?> instalments</span>
                </div>
                <div class="ca-fig lead owing">
                    <span class="lbl">Outstanding</span>
                    <span class="val"><?= e(number_format((float)$deal['balance'])) ?></span>
                    <span class="sub">still to collect</span>
                </div>
            </div>

            <div class="fin-foot">
                <?= e(money((float)$deal['in_hand'])) ?> has been received against this car
                in total, deposit included.
                <?php if ($deal['gap_material']): ?>
                <br>
                <!-- The principal is defaulted from "price less deposit" when the
                     agreement is written but the field is editable, so a gap is
                     reported rather than corrected. It is often deliberate. -->
                <i class="fa fa-circle-info me-1" style="color:var(--fin-warning)"></i>
                Car value less deposit comes to
                <?= e(money((float)$deal['price'] - (float)$deal['deposit'])) ?>,
                but <?= e(money((float)$deal['principal'])) ?> was financed —
                a difference of <?= e(money(abs((float)$deal['gap']))) ?>.
                That is normal where a trade-in or a payment outside the schedule
                was part of the deal; worth a look otherwise.
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="fin-grid2">
        <div class="fin-stack">

            <!-- The schedule -->
            <div class="fin-card">
                <header>
                    <h2>The schedule</h2>
                    <span class="hint"><?= (int)$sum['count'] ?> instalments<?php
                        if (($a['schedule_type'] ?? '') === 'custom'): ?> · irregular<?php endif; ?></span>
                </header>
                <?php if (!$schedule): ?>
                    <div class="fin-body"><p class="fin-empty mb-0">No schedule has been written for this account.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="ca-sched">
                        <thead><tr><th>#</th><th>Due</th><th class="num">Amount</th>
                                   <th class="num">Paid</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php $nextSeen = false; foreach ($schedule as $s):
                            $owed = (float)$s['amount'] - (float)$s['amount_paid'];
                            $late = $owed > 0.009 && strtotime((string)$s['due_date']) < strtotime($today);
                            $isNext = !$nextSeen && $owed > 0.009;
                            if ($isNext) $nextSeen = true;
                            $cls = $owed <= 0.009 ? 'done' : ($late ? 'late' : ($isNext ? 'next' : ''));
                        ?>
                            <tr class="<?= $cls ?>">
                                <td><?= (int)$s['seq'] ?></td>
                                <td><?= e(fmtDate((string)$s['due_date'], 'j M Y')) ?></td>
                                <td class="num"><?= e(number_format((float)$s['amount'])) ?></td>
                                <td class="num"><?= e(number_format((float)$s['amount_paid'])) ?></td>
                                <td>
                                    <?php if ($owed <= 0.009): ?>
                                        <span style="color:var(--fin-up-good)"><i class="fa fa-check"></i> paid</span>
                                    <?php elseif ($late): ?>
                                        <span class="rb-late">
                                            <?= (int)((strtotime($today) - strtotime((string)$s['due_date'])) / 86400) ?> days late
                                        </span>
                                    <?php elseif ((float)$s['amount_paid'] > 0.009): ?>
                                        part paid
                                    <?php else: ?>
                                        <span style="color:var(--fin-muted)">pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Payments -->
            <div class="fin-card">
                <?php $livePays = array_values(array_filter($payments, fn ($p) => empty($p['voided_at'])));
                      $voidPays = count($payments) - count($livePays); ?>
                <header><h2>Payments received</h2>
                    <span class="hint"><?= count($livePays) ?><?php
                        if ($voidPays): ?> · <?= $voidPays ?> reversed<?php endif; ?></span>
                </header>
                <?php if (!$payments): ?>
                    <div class="fin-body"><p class="fin-empty mb-0">Nothing has been paid yet.</p></div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="ca-sched">
                        <thead><tr><th>Date</th><th>Receipt</th><th>Method</th>
                                   <th class="num">Amount</th><th>Recorded by</th>
                                   <?php if ($canVoid): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($payments) as $p):
                            $void = !empty($p['voided_at']); ?>
                            <tr class="<?= $void ? 'void' : '' ?>">
                                <td><?= e(fmtDate((string)$p['paid_on'], 'j M Y')) ?></td>
                                <td>
                                    <?php if ($void): ?>
                                    <span class="rcpt"><?= e((string)$p['receipt_number']) ?></span>
                                    <?php else: ?>
                                    <a href="<?= BASE_URL ?>/modules/crm/credit_receipt.php?lead_id=<?= (int)$a['lead_id'] ?>&amp;payment_id=<?= (int)$p['id'] ?>"
                                       target="_blank"><?= e((string)$p['receipt_number']) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string)($p['method'] ?: '—')) ?>
                                    <?php if (!empty($p['reference'])): ?>
                                    <div class="rb-sub"><?= e((string)$p['reference']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($void): ?>
                                    <div class="ca-void-why">
                                        Reversed <?= e(fmtDate((string)$p['voided_at'], 'j M Y')) ?><?php
                                            if (!empty($p['voided_by_name'])): ?>
                                            by <?= e((string)$p['voided_by_name']) ?><?php endif; ?>
                                        <?php if (!empty($p['void_reason'])): ?>
                                        — <?= e((string)$p['void_reason']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><span class="amt"><?= e(number_format((float)$p['amount'])) ?></span></td>
                                <td class="rb-sub"><?= e((string)($p['by_name'] ?: '—')) ?></td>
                                <?php if ($canVoid): ?>
                                <td class="num">
                                    <?php if (!$void): ?>
                                    <?php /* Correct, or reverse. Reversing is right when the payment
                                             should not have been taken at all; correcting is for the
                                             imported opening balance keyed a digit out, where a
                                             cancelled line beside it would explain nothing to an
                                             auditor. */ ?>
                                    <?php if ($canAmend): ?>
                                    <button type="button" class="ca-rev" title="Correct this entry"
                                            data-bs-toggle="modal" data-bs-target="#payEditModal"
                                            data-pay="<?= (int)$p['id'] ?>"
                                            data-rcpt="<?= e((string)$p['receipt_number']) ?>"
                                            data-amt="<?= e((string)$p['amount']) ?>"
                                            data-on="<?= e((string)$p['paid_on']) ?>"
                                            data-method="<?= e((string)$p['method']) ?>"
                                            data-ref="<?= e((string)($p['reference'] ?? '')) ?>">
                                        <i class="fa fa-pen"></i>
                                    </button>
                                    <?php endif; ?>
                                    <button type="button" class="ca-rev" title="Reverse this entry"
                                            data-bs-toggle="modal" data-bs-target="#revModal"
                                            data-pay="<?= (int)$p['id'] ?>"
                                            data-rcpt="<?= e((string)$p['receipt_number']) ?>"
                                            data-amt="<?= e(money((float)$p['amount'])) ?>"
                                            data-on="<?= e(fmtDate((string)$p['paid_on'], 'j M Y')) ?>">
                                        <i class="fa fa-rotate-left"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Paperwork.
                 Two lists, because they are two different things: what the
                 system prints from live data on demand, and what has been
                 signed and scanned back in. Both belong to the lead; this is
                 another way in, so that chasing an account does not mean
                 opening the lead in a second tab. -->
            <div class="fin-card" id="paperwork">
                <header>
                    <h2>Signed documents</h2>
                    <span class="hint">
                        <?= empty($a['lead_id']) ? count($acctDocs) . ' on file'
                                                 : count($filed) . ' on file' ?>
                    </span>
                </header>
                <div class="fin-body">
                    <?php /* What the system prints is a row of buttons at the top of
                             the page now. What is left here is the other kind: the
                             paper that comes back signed, which only exists if
                             somebody put it there. */ ?>
                    <?php if (empty($a['lead_id'])): ?>
                    <!-- An imported account has no lead, and a document used to be
                         filed against a lead only, so this card explained there was
                         nowhere to put the agreement. A document now belongs to a
                         lead or to an account, so the scan goes here and stays with
                         the account it belongs to. -->
                    <?php if ($acctDocs): ?>
                    <div class="ca-docs mb-3">
                        <?php foreach ($acctDocs as $d): ?>
                        <div class="ca-doc">
                            <span class="ca-doc-ico"><i class="fa fa-file-lines"></i></span>
                            <span class="ca-doc-main">
                                <span class="ca-doc-t"><?= e((string)$d['title']) ?></span>
                                <span class="ca-doc-n">
                                    <?= e(leadDocTypes()[$d['doc_type']] ?? (string)$d['doc_type']) ?>
                                    · <?= e(leadDocSize($d['file_size'] !== null ? (int)$d['file_size'] : null)) ?>
                                    · <?= e(fmtDate((string)$d['created_at'], 'j M Y')) ?><?php
                                        if (!empty($d['by_name'])): ?>, <?= e((string)$d['by_name']) ?><?php endif; ?>
                                </span>
                            </span>
                            <a href="<?= BASE_URL ?>/modules/crm/document_file.php?id=<?= (int)$d['id'] ?>"
                               class="ca-rev" title="Download"><i class="fa fa-download"></i></a>
                            <?php if ($canAmend): ?>
                            <form method="post" class="d-inline"
                                  onsubmit="return confirm('Remove this document from the account?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_account_doc">
                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                                <button class="ca-rev" title="Remove"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="fin-note mb-3">
                        <i class="fa fa-paperclip"></i>
                        <div><strong>Nothing filed yet.</strong>
                            This account came in from the spreadsheet. Attach the signed
                            sales agreement, the credit agreement and whatever else came
                            back signed, and it stays with the account.</div>
                    </div>
                    <?php endif; ?>

                    <?php if (creditCanRecord()): ?>
                    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="upload_account_doc">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <div class="col-sm-5">
                            <label class="form-label small fw-semibold mb-1">What is it?</label>
                            <select name="doc_type" class="form-select form-select-sm">
                                <?php foreach (['sales_agreement', 'credit_agreement', 'payment_schedule',
                                                'id_copy', 'logbook_transfer', 'deposit_receipt',
                                                'other'] as $__t): ?>
                                <option value="<?= e($__t) ?>"><?= e(leadDocTypes()[$__t] ?? $__t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label small fw-semibold mb-1">
                                Title <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <input type="text" name="title" class="form-control form-control-sm"
                                   placeholder="e.g. Signed credit agreement, Mar 2024">
                        </div>
                        <div class="col-sm-8">
                            <input type="file" name="document" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-sm-4">
                            <button class="btn btn-sm btn-primary w-100">
                                <i class="fa fa-upload me-1"></i>File it
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                    <?php elseif (!$filed): ?>
                    <div class="fin-note mb-3">
                        <i class="fa fa-paperclip"></i>
                        <div>Nothing has been scanned in for this deal yet. Once the client has
                            signed the agreement, or sent a bank slip, attach it here &mdash; it
                            stays with the deal and follows them to their client profile.</div>
                    </div>
                    <?php else: ?>
                    <div class="ca-docs mb-3">
                        <?php
                        $dctx = leadDocContexts();
                        $dtyp = leadDocTypes();
                        foreach ($filed as $d):
                            $ext = strtolower(pathinfo((string)$d['file_name'], PATHINFO_EXTENSION));
                            $ico = in_array($ext, ['jpg','jpeg','png','gif','webp'], true) ? 'fa-file-image'
                                 : ($ext === 'pdf' ? 'fa-file-pdf'
                                 : (in_array($ext, ['xls','xlsx','csv'], true) ? 'fa-file-excel' : 'fa-file-lines'));
                        ?>
                        <div class="ca-doc">
                            <i class="fa <?= $ico ?> ca-doc-ico"></i>
                            <span class="ca-doc-main">
                                <a class="ca-doc-t" style="color:inherit;text-decoration:none"
                                   href="<?= BASE_URL ?>/modules/crm/document_file.php?id=<?= (int)$d['id'] ?>&amp;view=1"
                                   target="_blank" rel="noopener"><?= e((string)$d['title']) ?></a>
                                <span class="ca-doc-n">
                                    <?= e($dtyp[$d['doc_type']] ?? 'Document') ?>
                                    · <?= e($dctx[$d['context']][0] ?? 'Other') ?>
                                    <?php if ($sz = leadDocSize((int)$d['file_size'])): ?> · <?= e($sz) ?><?php endif; ?>
                                    · <?= e(fmtDate((string)$d['created_at'], 'j M Y')) ?>
                                    <?php if (!empty($d['client_id'])): ?> · on the client's profile<?php endif; ?>
                                </span>
                            </span>
                            <span class="ca-doc-go d-flex gap-1">
                                <a href="<?= BASE_URL ?>/modules/crm/document_file.php?id=<?= (int)$d['id'] ?>"
                                   class="ca-rev" title="Download"><i class="fa fa-download"></i></a>
                                <?php if (creditCanRecord()): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Remove this document? The file is deleted.')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action"  value="delete_lead_doc">
                                    <input type="hidden" name="doc_id"  value="<?= (int)$d['id'] ?>">
                                    <input type="hidden" name="lead_id" value="<?= (int)$a['lead_id'] ?>">
                                    <input type="hidden" name="id"      value="<?= (int)$id ?>">
                                    <button class="ca-rev" title="Remove"><i class="fa fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if (creditCanRecord() && !empty($a['lead_id'])): ?>
                    <!-- The same form the lead page uses, so the accepted types
                         and the size cap cannot come to differ between them.
                         The agreement id rides along so the redirect comes back
                         here rather than relying on the query string. -->
                    <?php leadDocsAddForm((int)$a['lead_id'], 'credit', ['id' => (int)$id]); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- The trail -->
            <div class="fin-card">
                <header><h2>Follow-up</h2><span class="hint"><?= count($notes) ?> note<?= count($notes) === 1 ? '' : 's' ?></span></header>
                <div class="fin-body">
                    <?php if (creditCanRecord()): ?>
                    <form method="post" class="mb-3">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="note">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <div class="d-flex gap-2 mb-2 flex-wrap">
                            <select name="kind" class="form-select form-select-sm" style="width:auto">
                                <?php foreach (creditNoteKinds() as $k => $lbl): ?>
                                <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <textarea name="body" class="form-control form-control-sm" rows="2"
                                  placeholder="What was agreed, and when they said they would pay."></textarea>
                        <button class="btn btn-outline-secondary btn-sm mt-2">Add note</button>
                    </form>
                    <?php endif; ?>

                    <?php if (!$notes): ?>
                        <p class="fin-empty mb-0">Nothing written down yet.</p>
                    <?php else: foreach ($notes as $n): ?>
                    <div class="ca-note">
                        <div class="who">
                            <span class="ca-kind"><?= e(creditNoteKinds()[$n['kind']] ?? $n['kind']) ?></span>
                            <?= e((string)($n['by_name'] ?: 'Someone')) ?> ·
                            <?= e(fmtDate((string)$n['created_at'], 'j M Y, H:i')) ?>
                        </div>
                        <div style="white-space:pre-line"><?= e((string)$n['body']) ?></div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="fin-stack">

            <!-- Chase it -->
            <?php if (creditCanRecord()): ?>
            <div class="fin-card">
                <header><h2>Remind them</h2></header>
                <div class="fin-body">
                    <?php if ((float)$sum['balance'] <= 0.009): ?>
                        <p class="fin-empty mb-0">Nothing outstanding — there is nothing to chase.</p>
                    <?php else: ?>
                    <p style="font-size:13px;color:var(--fin-ink-2);margin:0 0 12px">
                        Sends the reminder now, about the earliest unpaid instalment. It does not use
                        up one of the automatic reminders, and can be sent again.
                    </p>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="remind">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <button class="btn btn-outline-secondary btn-sm w-100" <?= $to['email'] === '' ? 'disabled' : '' ?>>
                            <i class="fa fa-paper-plane me-1"></i>Send a reminder now
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($sent): ?>
                    <div class="mt-3">
                        <div class="rb-sub mb-1">Last sent</div>
                        <?php foreach ($sent as $l): ?>
                        <div class="ca-log">
                            <i class="fa fa-<?= $l['status'] === 'sent' ? 'check' : 'xmark' ?> me-1"
                               style="color:var(--fin-<?= $l['status'] === 'sent' ? 'up-good' : 'critical' ?>)"></i>
                            <?= e(match (true) {
                                $l['stage'] === 'receipt' => 'Payment confirmation',
                                $l['stage'] === 'before'  => 'Upcoming reminder',
                                $l['stage'] === 'due'     => 'Due-today reminder',
                                $l['stage'] === 'manual'  => 'Reminder sent by hand',
                                str_starts_with((string)$l['stage'], 'overdue') => 'Overdue reminder',
                                default => (string)$l['stage'],
                            }) ?>
                            · <?= e(fmtDate((string)$l['sent_at'], 'j M, H:i')) ?>
                            <?php if ($l['status'] !== 'sent'): ?>
                            <div style="color:var(--fin-critical)"><?= e((string)($l['error'] ?: $l['status'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- How the account is handled -->
            <?php if (creditCanRecord()): ?>
            <div class="fin-card">
                <header><h2>How it is handled</h2></header>
                <div class="fin-body">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="settings">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">

                        <div class="mb-3">
                            <label class="form-label small">Account manager</label>
                            <select name="account_manager_id" class="form-select form-select-sm">
                                <option value="">Nobody assigned</option>
                                <?php foreach ($staff as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"
                                    <?= (int)($a['account_manager_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= e((string)$s['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small">Standing</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (creditStatuses() as $k => [$lbl, ]): ?>
                                <option value="<?= e($k) ?>" <?= ($a['status'] ?? '') === $k ? 'selected' : '' ?>>
                                    <?= e($lbl) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">An account with the lawyers is never emailed automatically.</div>
                        </div>

                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="logbook_held" id="lb"
                                   <?= (int)($a['logbook_held'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="lb">Logbook held as security</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="reminders_enabled" id="re"
                                   <?= (int)($a['reminders_enabled'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="re">Send automatic reminders</label>
                        </div>

                        <button class="btn btn-outline-secondary btn-sm w-100">Save</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <div class="fin-card">
                <header><h2>The buyer</h2></header>
                <div class="fin-body" style="font-size:13px">
                    <div class="mb-1"><strong><?= e($to['name'] ?: '—') ?></strong></div>
                    <div class="rb-sub"><?= e($to['phone'] ?: 'no phone on file') ?></div>
                    <div class="rb-sub"><?= e($to['email'] ?: 'no email on file') ?></div>
                    <?php if (!empty($a['last_reviewed_at'])): ?>
                    <div class="rb-sub mt-2">Last reviewed <?= e(fmtDate((string)$a['last_reviewed_at'], 'j M Y')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (creditCanRecord() && (float)$sum['balance'] > 0.009): ?>
<!-- Taking a payment. The same form as before, now opened from the toolbar
     rather than taking up a card on a page about what is owed. It still posts
     action=payment to creditRecordPayment(), so the lead page and this one
     cannot drift into taking money two different ways. -->
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" style="color:var(--fin-ink)">
                        <i class="fa fa-coins me-2" style="color:var(--fin-in)"></i>Record a payment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Amount received</label>
                        <input type="text" inputmode="decimal" name="amount" class="form-control" required
                               autocomplete="off"
                               placeholder="<?= e(number_format((float)$sum['next_amount'], 0, '.', '')) ?>">
                        <div class="form-text">
                            Goes against the oldest unpaid instalment first.
                            <?= e(money((float)$sum['balance'])) ?> outstanding.
                        </div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label class="form-label small">Date received</label>
                            <input type="date" name="paid_on" class="form-control" value="<?= e($today) ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label small">Method</label>
                            <select name="method" class="form-select">
                                <?php foreach (creditPaymentMethods() as $k => $lbl): ?>
                                <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reference</label>
                        <input type="text" name="reference" class="form-control"
                               placeholder="M-Pesa code, slip number">
                    </div>
                    <?php $accSel = acctSelect($db, 'account_id', 0, 'form-select'); ?>
                    <?php if ($accSel !== ''): ?>
                    <div class="mb-3">
                        <label class="form-label small">Into which account</label>
                        <?= $accSel ?>
                        <div class="form-text">Where the money actually landed, for the statement.</div>
                    </div>
                    <?php endif; ?>
                    <div class="form-text">
                        <?= $to['email'] !== '' && $cfg['receipts']
                            ? 'A confirmation will be emailed to ' . e($to['email']) . '.'
                            : 'No confirmation email will be sent.' ?>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fa fa-check me-1"></i>Record payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canVoid): ?>
<?php /* Amending the figures. The schedule and every payment's allocation are
         derived from these, so saving rebuilds both -- which is why this is one
         dialog rather than seven editable cells. */ ?>
<?php if ($canAmend): ?>
<div class="modal fade" id="amendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="amend_terms">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title"><i class="fa fa-pen me-2"></i>Amend this account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="fin-note mb-3">
                        <i class="fa fa-triangle-exclamation"></i>
                        <div>Saving rebuilds the instalment schedule and re-applies every
                            payment on the account from the first one. Figures already
                            reported, and any statement already sent, will change.</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Amount on credit</label>
                            <input type="number" step="any" min="1" name="principal" class="form-control"
                                   value="<?= e((string)$a['principal']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Monthly instalment</label>
                            <input type="number" step="any" min="1" name="monthly_payment" class="form-control"
                                   value="<?= e((string)$a['monthly_payment']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Instalments</label>
                            <input type="number" min="1" max="600" name="installments" class="form-control"
                                   value="<?= e((string)$a['installments']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">First due date</label>
                            <input type="date" name="first_due_date" class="form-control"
                                   value="<?= e((string)$a['first_due_date']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Agreement date</label>
                            <input type="date" name="agreement_date" class="form-control"
                                   value="<?= e((string)$a['agreement_date']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Interest % a year</label>
                            <input type="number" step="any" min="0" max="200" name="interest_rate"
                                   class="form-control" value="<?= e((string)$a['interest_rate']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">
                                Total repayable
                                <span class="text-muted fw-normal">— leave blank to recalculate</span>
                            </label>
                            <input type="number" step="any" min="0" name="total_repayable" class="form-control"
                                   value="<?= e((string)$a['total_repayable']) ?>">
                            <div class="form-text">
                                Blank works it out from the instalment and the term, or from the
                                interest rate if one is set. It can never be less than what has
                                already been paid in.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="fa fa-check me-1"></i>Save and rebuild</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php /* Correcting one payment. Reversing it and entering it again reaches the
         same figures and leaves a clearer trail, so that stays the right move
         for a payment that should not have been taken at all. This is for the
         imported opening balance that was keyed wrongly, where a cancelled line
         beside it would explain nothing to an auditor. */ ?>
<div class="modal fade" id="payEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit_payment">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="payment_id" id="peId" value="">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title"><i class="fa fa-pen me-2"></i>Correct payment
                        <span id="peRcpt" class="rcpt"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Amount</label>
                            <input type="number" step="any" min="1" name="amount" id="peAmt"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Paid on</label>
                            <input type="date" name="paid_on" id="peOn" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Method</label>
                            <select name="method" id="peMethod" class="form-select">
                                <?php foreach (creditPaymentMethods() as $__k => $__l): ?>
                                <option value="<?= e($__k) ?>"><?= e($__l) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Reference</label>
                            <input type="text" name="reference" id="peRef" class="form-control"
                                   placeholder="M-Pesa code, slip number—">
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Every payment is re-applied from the first one afterwards, so the
                        instalments and the balance follow the corrected figure.
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-primary"><i class="fa fa-check me-1"></i>Save correction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Filled from the row that opened it. Wired on show rather than constructed up
// front, because the bootstrap bundle loads at the end of the page.
(function () {
    var m = document.getElementById('payEditModal');
    if (!m) return;
    m.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget; if (!b) return;
        document.getElementById('peId').value  = b.dataset.pay  || '';
        document.getElementById('peAmt').value = b.dataset.amt  || '';
        document.getElementById('peOn').value  = b.dataset.on   || '';
        document.getElementById('peRef').value = b.dataset.ref  || '';
        document.getElementById('peRcpt').textContent = b.dataset.rcpt || '';
        var sel = document.getElementById('peMethod');
        if (sel && b.dataset.method) sel.value = b.dataset.method;
    });
}());
</script>
<?php endif; ?>

<div class="modal fade" id="revModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reverse_payment">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="payment_id" id="revPay" value="">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title" style="color:var(--fin-critical)">
                        <i class="fa fa-rotate-left me-2"></i>Reverse this payment
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">You are about to take back:</p>
                    <p class="fw-bold fs-5 mb-3" id="revWhat"></p>
                    <div class="fin-note warning mb-3">
                        <i class="fa fa-triangle-exclamation"></i>
                        <div>The entry is kept and marked reversed, not deleted, so the trail
                            still shows it was made. The schedule and every total are worked
                            out again without it. If a receipt was emailed, it no longer
                            matches the account.</div>
                    </div>
                    <label class="form-label small fw-semibold">
                        Why is it being reversed? <span style="color:var(--fin-critical)">*</span>
                    </label>
                    <textarea name="reason" class="form-control form-control-sm" rows="2" required
                              placeholder="e.g. entered twice, or the amount was 50,000 not 500,000"></textarea>
                    <div class="form-text" style="font-size:11px">
                        This is written onto the account's trail and the audit log.
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fa fa-rotate-left me-1"></i>Reverse it
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// One modal, filled from whichever row opened it. An account with thirty
// instalments would otherwise carry thirty copies of this markup.
//
// The button carries data-bs-toggle, so Bootstrap opens the modal itself and
// nothing here touches the `bootstrap` global. That matters: this script sits
// in the page body and footer.php loads the bundle after it, so the previous
// version's `new bootstrap.Modal(...)` ran before Bootstrap existed, threw,
// and left the click handler unbound — the button did nothing at all.
//
// show.bs.modal is a plain DOM event, and relatedTarget is the button that
// triggered it, so this listener is safe to register at any point.
(function () {
    var modalEl = document.getElementById('revModal');
    if (!modalEl) return;

    modalEl.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget;
        if (!b || !b.dataset.pay) return;
        modalEl.querySelector('#revPay').value = b.dataset.pay;
        modalEl.querySelector('#revWhat').textContent =
            b.dataset.rcpt + ' \u2014 ' + b.dataset.amt + ' on ' + b.dataset.on;
        // A reason from a previous open must not be re-submitted against a
        // different payment.
        var why = modalEl.querySelector('[name="reason"]');
        if (why) why.value = '';
    });

    // Belt and braces: if the payment id did not make it in, the form must not
    // post. Reversing payment 0 would be refused by the handler anyway, but a
    // silent no-op is worse than not submitting.
    modalEl.querySelector('form').addEventListener('submit', function (ev) {
        if (!modalEl.querySelector('#revPay').value) {
            ev.preventDefault();
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
