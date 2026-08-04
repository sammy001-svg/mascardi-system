<?php
/**
 * Call Centre — airtime.
 *
 * The provider holds the money; this page shows what is left, how long it will
 * last, and keeps a ledger of what has been paid in. Payment itself happens on
 * the Africa's Talking dashboard — they do not accept a top-up through the API,
 * so recording it here is what makes spend reconcilable without logging in
 * there to check.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('callcenter') || redirect(BASE_URL . '/index.php');

$db = getDB();
ccMigrate($db);

$me     = authUser();
$cfg    = ccConfig();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('callcenter')) {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'record') {
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            $errors[] = 'Enter the amount that was paid in.';
        } else {
            // Read the balance straight after so the ledger row carries what
            // the provider actually showed, not an assumed figure.
            $bal = ccBalance(true);
            try {
                $db->prepare("INSERT INTO call_topups (amount,currency,reference,method,balance_after,note,recorded_by)
                              VALUES (?,?,?,?,?,?,?)")
                   ->execute([
                       $amount,
                       !empty($bal['ok']) ? $bal['currency'] : 'KES',
                       trim($_POST['reference'] ?? '') ?: null,
                       trim($_POST['method'] ?? '') ?: null,
                       !empty($bal['ok']) ? $bal['amount'] : null,
                       trim($_POST['note'] ?? '') ?: null,
                       (int)$me['id'],
                   ]);
                logActivity('create', 'callcenter', 0, 'Recorded airtime top-up of ' . number_format($amount, 2));
                setFlash('success', 'Top-up recorded.');
                redirect(BASE_URL . '/modules/callcenter/topup.php');
            } catch (\Throwable $e) {
                error_log('cc/topup: ' . $e->getMessage());
                $errors[] = 'Could not record the top-up.';
            }
        }
    }
}

$balance = ccBalance(true);
$minutes = ccEstimatedMinutes($balance, $cfg);

$topups = [];
try {
    $topups = $db->query("SELECT t.*, u.name AS by_name FROM call_topups t
                          LEFT JOIN users u ON u.id = t.recorded_by
                          ORDER BY t.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// Spend measured from what the provider billed on each call.
$spend = ['today' => 0.0, 'month' => 0.0, 'calls_month' => 0];
try {
    $r = $db->query("SELECT
            COALESCE(SUM(CASE WHEN DATE(created_at)=CURDATE() THEN cost_amount END),0) today,
            COALESCE(SUM(CASE WHEN YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW()) THEN cost_amount END),0) month,
            COALESCE(SUM(YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())),0) calls_month
          FROM call_logs")->fetch(PDO::FETCH_ASSOC) ?: [];
    $spend = ['today' => (float)($r['today'] ?? 0), 'month' => (float)($r['month'] ?? 0),
              'calls_month' => (int)($r['calls_month'] ?? 0)];
} catch (\Throwable $_) {}

$pageTitle = 'Airtime';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.tu-hero{ background:linear-gradient(135deg,#0f766e 0%,#0891b2 55%,#0ea5e9 100%);
    border-radius:var(--r-lg); padding:22px 24px; color:#fff; margin-bottom:16px; }
.tu-amt{ font-size:34px; font-weight:900; letter-spacing:-1px; line-height:1.1; }
.tu-sub{ font-size:12.5px; opacity:.85; margin-top:4px; }
.tu-stats{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin-top:18px; }
.tu-stat{ background:rgba(255,255,255,.13); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:11px 13px; }
.tu-stat-v{ font-size:17px; font-weight:800; color:#fff; }
.tu-stat-l{ font-size:10px; opacity:.75; text-transform:uppercase; letter-spacing:.6px; margin-top:3px; }

.tu-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.tu-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.tu-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.tu-title i{ color:var(--brand); }
.tu-body{ padding:16px; }
.tu-row{ display:flex; align-items:center; gap:12px; padding:11px 16px; border-bottom:1px solid var(--border); }
.tu-row:last-child{ border-bottom:0; }
.tu-empty{ text-align:center; padding:34px 16px; color:var(--text-2,#64748b); font-size:13px; }
.tu-empty i{ font-size:28px; opacity:.3; display:block; margin-bottom:9px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 style="font-size:20px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
        <i class="fa fa-coins me-2" style="color:var(--brand)"></i>Airtime
    </h1>
    <a href="<?= BASE_URL ?>/modules/callcenter/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2"><ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="tu-hero">
    <?php if (!empty($balance['ok'])): ?>
        <div class="tu-amt"><?= e($balance['currency']) ?> <?= number_format($balance['amount'], 2) ?></div>
        <div class="tu-sub">
            <?php if ($minutes !== null): ?>
                roughly <strong><?= number_format($minutes) ?></strong> minutes of calling left
                at <?= e($balance['currency']) ?> <?= number_format($cfg['rate_per_min'], 2) ?>/min
            <?php else: ?>live account balance<?php endif; ?>
        </div>
        <?php if ($balance['amount'] <= $cfg['low_balance']): ?>
        <div class="mt-3 px-3 py-2" style="background:rgba(0,0,0,.22);border-radius:var(--r);font-size:12.5px">
            <i class="fa fa-triangle-exclamation me-1"></i>
            <?= $balance['amount'] <= 0
                ? 'Airtime is exhausted — calls will not connect until you top up.'
                : 'Balance is below your warning threshold of ' . number_format($cfg['low_balance'], 0) . '.' ?>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="tu-amt">—</div>
        <div class="tu-sub"><?= e($balance['error'] ?? 'Balance unavailable') ?></div>
    <?php endif; ?>

    <div class="tu-stats">
        <div class="tu-stat">
            <div class="tu-stat-v"><?= number_format($spend['today'], 2) ?></div>
            <div class="tu-stat-l">Spent Today</div>
        </div>
        <div class="tu-stat">
            <div class="tu-stat-v"><?= number_format($spend['month'], 2) ?></div>
            <div class="tu-stat-l">Spent This Month</div>
        </div>
        <div class="tu-stat">
            <div class="tu-stat-v"><?= number_format($spend['calls_month']) ?></div>
            <div class="tu-stat-l">Calls This Month</div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="tu-card">
            <div class="tu-head"><h2 class="tu-title"><i class="fa fa-plus"></i>How to Top Up</h2></div>
            <div class="tu-body">
                <p class="small text-muted mb-3">
                    Africa's Talking does not accept payment through the API, so a top-up is made on
                    their dashboard and recorded here afterwards. The balance above is read live from
                    the account, so it updates on its own once payment clears.
                </p>
                <ol class="small" style="padding-left:18px;line-height:1.9;color:var(--text)">
                    <li>Sign in at <strong>account.africastalking.com</strong></li>
                    <li>Open <strong>Billing → Top Up</strong></li>
                    <li>Pay by M-Pesa or card</li>
                    <li>Record the amount below so spend can be reconciled</li>
                </ol>
                <a href="https://account.africastalking.com" target="_blank" rel="noopener"
                   class="btn btn-sm btn-primary w-100 mt-2">
                    <i class="fa fa-arrow-up-right-from-square me-1"></i>Open the provider dashboard
                </a>
            </div>
        </div>

        <?php if (canWrite('callcenter')): ?>
        <div class="tu-card">
            <div class="tu-head"><h2 class="tu-title"><i class="fa fa-receipt"></i>Record a Top-Up</h2></div>
            <div class="tu-body">
                <form method="POST" class="row g-2">
                    <?= csrfField() ?><input type="hidden" name="action" value="record">
                    <div class="col-6">
                        <label class="form-label">Amount</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">KES</span>
                            <input type="number" name="amount" step="0.01" min="0" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Paid by</label>
                        <input type="text" name="method" class="form-control form-control-sm" placeholder="M-Pesa / Card">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reference</label>
                        <input type="text" name="reference" class="form-control form-control-sm" placeholder="M-Pesa code or receipt no.">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-sm btn-primary w-100"><i class="fa fa-check me-1"></i>Record it</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="tu-card">
            <div class="tu-head">
                <h2 class="tu-title"><i class="fa fa-clock-rotate-left"></i>Top-Up History</h2>
                <span class="small text-muted"><?= count($topups) ?></span>
            </div>
            <?php if (!$topups): ?>
                <div class="tu-empty"><i class="fa fa-receipt"></i>No top-ups recorded yet.</div>
            <?php else: foreach ($topups as $t): ?>
            <div class="tu-row">
                <span style="width:34px;height:34px;border-radius:50%;flex:0 0 34px;background:#16a34a1f;color:#16a34a;
                             display:flex;align-items:center;justify-content:center"><i class="fa fa-arrow-up"></i></span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13.5px;font-weight:700;color:var(--text)">
                        <?= e($t['currency']) ?> <?= number_format((float)$t['amount'], 2) ?>
                        <?php if ($t['method']): ?><span class="text-muted fw-normal" style="font-size:12px"> · <?= e($t['method']) ?></span><?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:11.5px">
                        <?= date('j M Y, H:i', strtotime($t['created_at'])) ?>
                        <?= $t['by_name'] ? ' · ' . e($t['by_name']) : '' ?>
                        <?= $t['reference'] ? ' · ref ' . e($t['reference']) : '' ?>
                    </div>
                    <?php if ($t['note']): ?>
                    <div class="text-muted" style="font-size:11.5px;font-style:italic">“<?= e($t['note']) ?>”</div>
                    <?php endif; ?>
                </div>
                <?php if ($t['balance_after'] !== null): ?>
                <div class="text-end">
                    <div class="text-muted" style="font-size:10.5px;text-transform:uppercase;letter-spacing:.5px">Balance then</div>
                    <div style="font-size:12.5px;font-weight:700;color:var(--text)"><?= number_format((float)$t['balance_after'], 2) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
