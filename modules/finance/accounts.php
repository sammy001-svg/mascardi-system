<?php
/**
 * The company's accounts, and what is in each.
 *
 * Opening and closing them is finance-manager work; everybody in finance can
 * see the balances. An account is never deleted once money has moved through
 * it — it is closed, which keeps it off the forms while leaving its history
 * readable. Deleting it would orphan every movement that named it.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (!acctCanManage()) {
        setFlash('danger', 'Only the finance manager can open or change an account.');
        redirect(BASE_URL . '/modules/finance/accounts.php');
    }

    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = array_key_exists($_POST['type'] ?? '', acctTypes()) ? $_POST['type'] : 'bank';

        if ($name === '') {
            setFlash('danger', 'Give the account a name.');
            redirect(BASE_URL . '/modules/finance/accounts.php');
        }

        $data = [
            'name'            => mb_substr($name, 0, 120),
            'type'            => $type,
            'bank_name'       => mb_substr(trim((string)($_POST['bank_name'] ?? '')), 0, 120) ?: null,
            'account_number'  => mb_substr(trim((string)($_POST['account_number'] ?? '')), 0, 60) ?: null,
            'paybill'         => mb_substr(trim((string)($_POST['paybill'] ?? '')), 0, 40) ?: null,
            'opening_balance' => (float)str_replace(',', '', (string)($_POST['opening_balance'] ?? 0)),
            'opening_date'    => trim((string)($_POST['opening_date'] ?? '')) ?: null,
            'notes'           => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000) ?: null,
            'status'          => ($_POST['status'] ?? 'active') === 'closed' ? 'closed' : 'active',
            'sort_order'      => (int)($_POST['sort_order'] ?? 0),
        ];

        try {
            if ($id > 0) {
                $set = implode(', ', array_map(static fn ($k) => "$k = ?", array_keys($data)));
                $db->prepare("UPDATE cash_accounts SET $set WHERE id = ?")
                   ->execute([...array_values($data), $id]);
                $what = 'updated';
            } else {
                $data['created_by'] = (int)authUser()['id'];
                $cols = implode(', ', array_keys($data));
                $qs   = implode(', ', array_fill(0, count($data), '?'));
                $db->prepare("INSERT INTO cash_accounts ($cols) VALUES ($qs)")->execute(array_values($data));
                $id   = (int)$db->lastInsertId();
                $what = 'opened';
            }

            // Exactly one default, enforced here rather than hoped for.
            if (!empty($_POST['is_default'])) {
                $db->exec('UPDATE cash_accounts SET is_default = 0');
                $db->prepare('UPDATE cash_accounts SET is_default = 1 WHERE id = ?')->execute([$id]);
            }

            logActivity($what === 'opened' ? 'create' : 'update', 'cash_accounts', $id,
                        'Account ' . $what . ': ' . $data['name']);
            setFlash('success', 'Account ' . $what . '.');
        } catch (\Throwable $e) {
            setFlash('danger', 'That could not be saved: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/finance/accounts.php');
    }
}

$showClosed = !empty($_GET['closed']);
$accounts   = acctAll($db, $showClosed);
$totals     = acctTotals($db);
$edit       = ($eid = (int)($_GET['edit'] ?? 0)) ? acctOne($db, $eid) : null;

$monthFrom  = (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn();
$today      = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$unassigned = acctUnassigned($db, $monthFrom, $today);

$pageTitle = 'Company accounts';
include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<style>
.ac-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
.ac-card{background:var(--fin-surface);border:1px solid var(--fin-ring);border-radius:14px;padding:16px 18px}
.ac-card.closed{opacity:.6}
.ac-top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}
.ac-name{font-weight:600;color:var(--fin-ink);font-size:14.5px}
.ac-meta{font-size:11.5px;color:var(--fin-muted);margin-top:2px}
.ac-bal{font-size:24px;font-weight:600;color:var(--fin-ink);margin-top:11px;letter-spacing:-.01em}
.ac-flow{display:flex;gap:14px;font-size:12px;color:var(--fin-ink-2);margin-top:6px}
.ac-ico{width:34px;height:34px;border-radius:9px;background:var(--fin-plane);display:flex;
    align-items:center;justify-content:center;color:var(--fin-in);flex:none}
.ac-tag{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    padding:1px 6px;border-radius:4px;border:1px solid var(--fin-ring);color:var(--fin-muted)}
</style>

<div class="fin">

    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-vault me-2" style="color:var(--fin-in)"></i>Company accounts
            </h5>
            <div class="fin-asat">Where the money sits</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm"
               href="?<?= $showClosed ? '' : 'closed=1' ?>">
                <?= $showClosed ? 'Hide closed' : 'Show closed' ?>
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/index.php">
                <i class="fa fa-scale-balanced me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php if ($unassigned['any']): ?>
    <div class="fin-note warning mb-3">
        <i class="fa fa-circle-question"></i>
        <div>
            <strong>Some money this month has no account against it.</strong>
            <?= e(money($unassigned['in'])) ?> in and <?= e(money($unassigned['out'])) ?> out were
            recorded without naming an account, so they are not in any balance below. Anything
            entered before accounts existed will look like this; from now on the account is asked
            for whenever money is recorded.
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-tiles mb-4">
        <div class="fin-tile">
            <div class="lbl">Total held</div>
            <div class="val" title="<?= e(money($totals['balance'])) ?>">KES <?= e(finShort($totals['balance'])) ?></div>
            <div class="sub">across <?= (int)$totals['accounts'] ?> open account<?= $totals['accounts'] === 1 ? '' : 's' ?></div>
        </div>
        <?php foreach (['bank' => 'In the bank', 'mpesa' => 'Mobile money', 'cash' => 'Cash on hand'] as $k => $lbl):
            if (!isset($totals['by_type'][$k])) continue; ?>
        <div class="fin-tile">
            <div class="lbl"><?= e($lbl) ?></div>
            <div class="val" title="<?= e(money($totals['by_type'][$k])) ?>">KES <?= e(finShort($totals['by_type'][$k])) ?></div>
            <div class="sub"><?= e(acctTypes()[$k][0]) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if ($totals['pending'] > 0): ?>
        <div class="fin-tile">
            <div class="lbl">Not yet confirmed</div>
            <div class="val" style="color:var(--fin-warning)" title="<?= e(money($totals['pending'])) ?>">
                KES <?= e(finShort($totals['pending'])) ?>
            </div>
            <div class="sub">left out of the balances</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$accounts): ?>
    <div class="fin-card mb-4">
        <div class="fin-body">
            <p class="fin-empty mb-0">
                No account has been opened yet. Until one is, money is recorded without saying where
                it went<?= acctCanManage() ? ' — open the first one below.' : '.' ?>
            </p>
        </div>
    </div>
    <?php else: ?>
    <div class="ac-grid mb-4">
        <?php foreach ($accounts as $a):
            [$typeLabel, $icon] = acctTypes()[$a['type']] ?? acctTypes()['other']; ?>
        <div class="ac-card <?= $a['status'] === 'closed' ? 'closed' : '' ?>">
            <div class="ac-top">
                <div style="min-width:0">
                    <div class="ac-name"><?= e((string)$a['name']) ?></div>
                    <div class="ac-meta">
                        <?= e($typeLabel) ?>
                        <?php if (!empty($a['account_number'])): ?> · <?= e((string)$a['account_number']) ?>
                        <?php elseif (!empty($a['paybill'])): ?> · Paybill <?= e((string)$a['paybill']) ?><?php endif; ?>
                    </div>
                    <div class="mt-1">
                        <?php if ((int)$a['is_default']): ?><span class="ac-tag">default</span><?php endif; ?>
                        <?php if ($a['status'] === 'closed'): ?><span class="ac-tag">closed</span><?php endif; ?>
                    </div>
                </div>
                <div class="ac-ico"><i class="fa <?= $icon ?>"></i></div>
            </div>

            <div class="ac-bal" title="<?= e(money((float)$a['balance'])) ?>">
                KES <?= e(finShort((float)$a['balance'])) ?>
            </div>
            <div class="ac-flow">
                <span style="color:var(--fin-up-good)">+<?= e(finShort((float)$a['in'])) ?> in</span>
                <span style="color:var(--fin-critical)">−<?= e(finShort((float)$a['out'])) ?> out</span>
                <?php if ((float)$a['opening_balance'] != 0.0): ?>
                <span>opened at <?= e(finShort((float)$a['opening_balance'])) ?></span>
                <?php endif; ?>
            </div>

            <div class="d-flex gap-2 mt-3">
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?= BASE_URL ?>/modules/finance/statement.php?id=<?= (int)$a['id'] ?>">
                    <i class="fa fa-file-lines me-1"></i>Statement
                </a>
                <?php if (acctCanManage()): ?>
                <a class="btn btn-outline-secondary btn-sm" href="?edit=<?= (int)$a['id'] ?><?= $showClosed ? '&closed=1' : '' ?>#form">
                    <i class="fa fa-pen"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (acctCanManage()): ?>
    <div class="fin-card" id="form">
        <header>
            <h2><?= $edit ? 'Edit ' . e((string)$edit['name']) : 'Open an account' ?></h2>
            <?php if ($edit): ?><a class="hint" href="<?= BASE_URL ?>/modules/finance/accounts.php">Cancel</a><?php endif; ?>
        </header>
        <div class="fin-body">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">

                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control" required maxlength="120"
                               value="<?= e((string)($edit['name'] ?? '')) ?>"
                               placeholder="Equity — main current account">
                        <div class="form-text">What the team calls it.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select">
                            <?php foreach (acctTypes() as $k => [$lbl, ]): ?>
                            <option value="<?= e($k) ?>" <?= ($edit['type'] ?? 'bank') === $k ? 'selected' : '' ?>>
                                <?= e($lbl) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Bank</label>
                        <input type="text" name="bank_name" class="form-control" maxlength="120"
                               value="<?= e((string)($edit['bank_name'] ?? '')) ?>" placeholder="Equity Bank">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small">Account number</label>
                        <input type="text" name="account_number" class="form-control" maxlength="60"
                               value="<?= e((string)($edit['account_number'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Paybill or till</label>
                        <input type="text" name="paybill" class="form-control" maxlength="40"
                               value="<?= e((string)($edit['paybill'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Order shown</label>
                        <input type="number" name="sort_order" class="form-control"
                               value="<?= (int)($edit['sort_order'] ?? 0) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small">Opening balance</label>
                        <input type="text" inputmode="decimal" name="opening_balance" class="form-control"
                               value="<?= e(number_format((float)($edit['opening_balance'] ?? 0), 2, '.', '')) ?>">
                        <div class="form-text">
                            What was in it when the showroom started recording here. Without it every
                            balance is short by whatever was already in the account.
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">As at</label>
                        <input type="date" name="opening_date" class="form-control"
                               value="<?= e((string)($edit['opening_date'] ?? '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Open</option>
                            <option value="closed" <?= ($edit['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                        <div class="form-text">
                            A closed account stays readable but is no longer offered on forms. Accounts
                            are never deleted — that would orphan every payment that named one.
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label small">Notes</label>
                        <input type="text" name="notes" class="form-control" maxlength="2000"
                               value="<?= e((string)($edit['notes'] ?? '')) ?>">
                    </div>

                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_default" id="def"
                                   <?= (int)($edit['is_default'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="def">
                                Pre-select this account when money is recorded
                            </label>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary mt-3">
                    <i class="fa fa-check me-1"></i><?= $edit ? 'Save' : 'Open the account' ?>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
