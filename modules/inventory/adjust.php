<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'manager']);
$pageTitle = 'Adjust Stock';
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/inventory/index.php');

$item = $db->prepare("SELECT * FROM inventory WHERE id=?");
$item->execute([$id]);
$item = $item->fetch();
if (!$item) { setFlash('error', 'Part not found.'); redirect(BASE_URL . '/modules/inventory/index.php'); }

$transactions = $db->prepare("SELECT * FROM inventory_transactions WHERE inventory_id=? ORDER BY created_at DESC LIMIT 20");
$transactions->execute([$id]);
$transactions = $transactions->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type  = $_POST['transaction_type'] ?? '';
    $qty   = (float)($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if (!in_array($type, ['in','out','adjustment'])) {
        $error = 'Invalid transaction type.';
    } elseif ($qty <= 0) {
        $error = 'Quantity must be greater than zero.';
    } else {
        $current = (float)$item['quantity'];
        if ($type === 'in') {
            $balance = $current + $qty;
        } elseif ($type === 'out') {
            if ($qty > $current) { $error = 'Cannot remove more than current stock (' . number_format($current,2) . ').'; goto end; }
            $balance = $current - $qty;
        } else {
            $balance = $qty;
        }

        $db->prepare("UPDATE inventory SET quantity=? WHERE id=?")->execute([$balance, $id]);
        $db->prepare("INSERT INTO inventory_transactions (inventory_id,transaction_type,quantity,balance,notes,created_by) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $type, $qty, $balance, $notes, authUser()['name']]);
        setFlash('success', 'Stock adjusted successfully. New balance: ' . number_format($balance,2));
        redirect(BASE_URL . '/modules/inventory/adjust.php?id=' . $id);
    }
    end:
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1">Stock Adjustment — <?= e($item['part_name']) ?></h5>
        <div class="text-muted small">
            <?= e($item['part_number'] ?? 'No part number') ?> &bull;
            Current stock: <strong><?= number_format((float)$item['quantity'], 2) ?> <?= e($item['unit']) ?></strong>
        </div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header fw-semibold">Record Transaction</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-2">
                            <label class="d-flex align-items-center gap-2 p-3 border rounded flex-fill cursor-pointer <?= ($_POST['transaction_type'] ?? '') === 'in' ? 'border-success bg-success bg-opacity-10' : '' ?>"
                                   style="cursor:pointer" onclick="this.closest('form').querySelector('[value=in]').click()">
                                <input type="radio" name="transaction_type" value="in" class="d-none" <?= ($_POST['transaction_type'] ?? 'in') === 'in' ? 'checked' : '' ?>>
                                <i class="fa fa-arrow-down text-success"></i> <span class="fw-semibold">Stock In</span>
                            </label>
                            <label class="d-flex align-items-center gap-2 p-3 border rounded flex-fill cursor-pointer <?= ($_POST['transaction_type'] ?? '') === 'out' ? 'border-danger bg-danger bg-opacity-10' : '' ?>"
                                   style="cursor:pointer" onclick="this.closest('form').querySelector('[value=out]').click()">
                                <input type="radio" name="transaction_type" value="out" class="d-none" <?= ($_POST['transaction_type'] ?? '') === 'out' ? 'checked' : '' ?>>
                                <i class="fa fa-arrow-up text-danger"></i> <span class="fw-semibold">Stock Out</span>
                            </label>
                            <label class="d-flex align-items-center gap-2 p-3 border rounded flex-fill cursor-pointer <?= ($_POST['transaction_type'] ?? '') === 'adjustment' ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                                   style="cursor:pointer" onclick="this.closest('form').querySelector('[value=adjustment]').click()">
                                <input type="radio" name="transaction_type" value="adjustment" class="d-none" <?= ($_POST['transaction_type'] ?? '') === 'adjustment' ? 'checked' : '' ?>>
                                <i class="fa fa-sliders text-primary"></i> <span class="fw-semibold">Set Qty</span>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" min="0.01" step="0.01" required value="<?= e($_POST['quantity'] ?? '') ?>" placeholder="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes / Reference</label>
                        <input type="text" name="notes" class="form-control" value="<?= e($_POST['notes'] ?? '') ?>" placeholder="e.g. LPO-0003, Physical count">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="fa fa-check me-1"></i>Record Transaction</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold">Recent Transactions</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Date</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Balance</th>
                            <th>By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx):
                            $badge = $tx['transaction_type'] === 'in' ? 'success' : ($tx['transaction_type'] === 'out' ? 'danger' : 'primary');
                        ?>
                        <tr>
                            <td class="ps-3 text-muted small"><?= fmtDate($tx['created_at'], 'd M Y H:i') ?></td>
                            <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($tx['transaction_type']) ?></span></td>
                            <td class="fw-semibold"><?= number_format((float)$tx['quantity'], 2) ?></td>
                            <td><?= number_format((float)$tx['balance'], 2) ?></td>
                            <td class="text-muted small"><?= e($tx['created_by'] ?? '—') ?></td>
                            <td class="text-muted small"><?= e($tx['notes'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($transactions)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No transactions yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
