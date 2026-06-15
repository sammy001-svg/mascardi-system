<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('inventory') || die('Access denied.');
$pageTitle = 'Procurement & Reorder';
$db = getDB();

// ── Inline migration: add reorder_qty ────────────────────────────────────────
try { $db->exec("ALTER TABLE inventory ADD COLUMN reorder_qty DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER reorder_level"); } catch (\Throwable $_) {}

// ── POST: generate LPO for a supplier group ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_lpo') {
    canWrite('lpo') || die('Permission denied.');

    $suppId  = (int)($_POST['supplier_id'] ?? 0);
    $itemIds = array_map('intval', (array)($_POST['item_ids'] ?? []));
    $orderQtys = $_POST['order_qty'] ?? [];

    if (!$suppId)            { setFlash('error', 'Please select a supplier.'); redirect(BASE_URL.'/modules/inventory/reorder.php'); }
    if (empty($itemIds))     { setFlash('error', 'No items selected.');        redirect(BASE_URL.'/modules/inventory/reorder.php'); }

    try {
        // Fetch items
        $ph = implode(',', array_fill(0, count($itemIds), '?'));
        $rows = $db->prepare("SELECT id, part_name, unit, unit_price, reorder_qty, reorder_level, quantity FROM inventory WHERE id IN ($ph)");
        $rows->execute($itemIds);
        $rows = $rows->fetchAll();

        $db->beginTransaction();

        $subtotal = 0;
        $lineData = [];
        foreach ($rows as $r) {
            $qty   = max(0.01, (float)($orderQtys[$r['id']] ?? ($r['reorder_qty'] > 0 ? $r['reorder_qty'] : max(1, $r['reorder_level']))));
            $price = (float)$r['unit_price'];
            $lineData[] = ['id' => $r['id'], 'name' => $r['part_name'], 'qty' => $qty, 'unit' => $r['unit'], 'price' => $price, 'total' => $qty * $price];
            $subtotal += $qty * $price;
        }

        $vatRate = (float)getSetting('vat_rate', '16');
        $taxAmt  = $subtotal * ($vatRate / 100);
        $total   = $subtotal + $taxAmt;

        $lpoNum = nextNumber('lpo', 'lpo_number', getSetting('lpo_prefix', 'LPO'));
        $db->prepare("INSERT INTO lpo (lpo_number,supplier_id,date,tax_rate,subtotal,tax_amount,total,notes,approved_by)
                      VALUES (?,?,CURDATE(),?,?,?,?,?,?)")
           ->execute([$lpoNum, $suppId, $vatRate, $subtotal, $taxAmt, $total, 'Auto-generated from reorder list.', authUser()['name']]);
        $lpoId = (int)$db->lastInsertId();

        $iStmt = $db->prepare("INSERT INTO lpo_items (lpo_id,inventory_id,description,quantity,unit,unit_price,total) VALUES (?,?,?,?,?,?,?)");
        foreach ($lineData as $line) {
            $iStmt->execute([$lpoId, $line['id'], $line['name'], $line['qty'], $line['unit'], $line['price'], $line['total']]);
        }

        $db->commit();
        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(['admin','workshop_manager','procurement_officer'], 'lpo',
            "Reorder LPO Created: {$lpoNum}",
            count($lineData) . ' item(s) — ' . money($total),
            BASE_URL . '/modules/lpo/view.php?id=' . $lpoId
        );
        logActivity('create', 'lpo', $lpoId, "Auto-reorder LPO {$lpoNum} for supplier #{$suppId}");
        setFlash('success', "LPO {$lpoNum} created with " . count($lineData) . " item(s). Review and send to supplier.");
        redirect(BASE_URL . '/modules/lpo/view.php?id=' . $lpoId);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', 'LPO generation failed: ' . $e->getMessage());
        redirect(BASE_URL . '/modules/inventory/reorder.php');
    }
}

// ── Load low-stock items ──────────────────────────────────────────────────────
$lowStock = $db->query("
    SELECT i.*, s.id AS sup_id, s.name AS supplier_name, s.phone AS supplier_phone, s.email AS supplier_email
    FROM inventory i
    LEFT JOIN suppliers s ON s.id = i.supplier_id
    WHERE i.quantity <= i.reorder_level
    ORDER BY s.name ASC, i.part_name ASC
")->fetchAll();

// Group by supplier
$groups = [];
foreach ($lowStock as $item) {
    $key = $item['sup_id'] ? 'sup_' . $item['sup_id'] : 'none';
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'supplier_id'    => $item['sup_id'],
            'supplier_name'  => $item['supplier_name'] ?? null,
            'supplier_phone' => $item['supplier_phone'] ?? null,
            'supplier_email' => $item['supplier_email'] ?? null,
            'items'          => [],
        ];
    }
    $groups[$key]['items'][] = $item;
}

// KPIs
$kpi = [
    'low'    => count($lowStock),
    'out'    => count(array_filter($lowStock, fn($i) => (float)$i['quantity'] == 0)),
    'value'  => array_sum(array_map(fn($i) => (float)$i['unit_price'] * max(0, (float)($i['reorder_qty'] > 0 ? $i['reorder_qty'] : $i['reorder_level'])), $lowStock)),
    'groups' => count(array_filter(array_keys($groups), fn($k) => $k !== 'none')),
];

$allSuppliers = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-truck me-2 text-primary"></i>Procurement &amp; Reorder</h5>
        <div class="text-muted small">Items at or below reorder level — generate LPOs per supplier</div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-boxes-stacked me-1"></i>All Inventory
    </a>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #d97706 !important">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:44px;height:44px;background:#fffbeb">
                    <i class="fa fa-triangle-exclamation text-warning"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Low Stock</div>
                    <div class="fw-bold" style="font-size:22px"><?= $kpi['low'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #dc2626 !important">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:44px;height:44px;background:#fef2f2">
                    <i class="fa fa-ban text-danger"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Out of Stock</div>
                    <div class="fw-bold text-danger" style="font-size:22px"><?= $kpi['out'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:44px;height:44px;background:#eff6ff">
                    <i class="fa fa-truck text-primary"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Suppliers to Order</div>
                    <div class="fw-bold" style="font-size:22px"><?= $kpi['groups'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:44px;height:44px;background:#f0fdf4">
                    <i class="fa fa-coins text-success"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Est. Reorder Cost</div>
                    <div class="fw-bold" style="font-size:18px"><?= money($kpi['value']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($lowStock)): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px">
    <i class="fa fa-circle-check fa-3x mb-3 d-block text-success opacity-50"></i>
    <p class="fw-semibold mb-1">All parts are well stocked</p>
    <p class="text-muted small mb-0">No items are at or below their reorder level.</p>
</div>

<?php else: ?>

<?php foreach ($groups as $groupKey => $group): ?>
<?php
$suppId      = $group['supplier_id'];
$suppName    = $group['supplier_name'] ?? 'No Preferred Supplier';
$groupItems  = $group['items'];
$groupTotal  = array_sum(array_map(fn($i) => (float)$i['unit_price'] * max(1, (float)($i['reorder_qty'] > 0 ? $i['reorder_qty'] : $i['reorder_level'])), $groupItems));
$isNoSupp    = ($groupKey === 'none');
?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px">
    <div class="card-body p-0">

        <!-- Group header -->
        <div class="d-flex justify-content-between align-items-center p-4 pb-3 border-bottom <?= $isNoSupp ? '' : '' ?>">
            <div>
                <h6 class="fw-bold mb-0">
                    <?php if ($isNoSupp): ?>
                    <i class="fa fa-circle-exclamation me-2 text-warning"></i>No Preferred Supplier Assigned
                    <?php else: ?>
                    <i class="fa fa-truck me-2 text-primary"></i><?= e($suppName) ?>
                    <?php endif; ?>
                </h6>
                <?php if (!$isNoSupp && ($group['supplier_phone'] || $group['supplier_email'])): ?>
                <div class="text-muted small mt-1">
                    <?= $group['supplier_phone'] ? '<a href="tel:'.e($group['supplier_phone']).'" class="text-muted me-3"><i class="fa fa-phone me-1"></i>'.e($group['supplier_phone']).'</a>' : '' ?>
                    <?= $group['supplier_email'] ? '<a href="mailto:'.e($group['supplier_email']).'" class="text-muted"><i class="fa fa-envelope me-1"></i>'.e($group['supplier_email']).'</a>' : '' ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <div class="text-muted small">Est. order value</div>
                <div class="fw-bold"><?= money($groupTotal) ?></div>
            </div>
        </div>

        <!-- Items table -->
        <form method="POST" class="reorder-form">
            <input type="hidden" name="action" value="generate_lpo">
            <?php if ($isNoSupp): ?>
            <div class="px-4 pt-3">
                <div class="alert alert-warning d-flex gap-2 align-items-center py-2 mb-0" style="border-radius:8px;font-size:13px">
                    <i class="fa fa-triangle-exclamation flex-shrink-0"></i>
                    <div>These parts have no preferred supplier. Select one below, then generate an LPO.</div>
                </div>
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:13px">
                    <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.04em;background:#f8fafc">
                        <tr>
                            <th class="ps-4 py-3" style="width:36px">
                                <input type="checkbox" class="form-check-input check-all" title="Select all">
                            </th>
                            <th class="py-3">Part</th>
                            <th class="py-3">Category</th>
                            <th class="py-3 text-center">In Stock</th>
                            <th class="py-3 text-center">Reorder At</th>
                            <th class="py-3 text-center">Shortfall</th>
                            <th class="py-3 text-center" style="width:110px">Order Qty</th>
                            <th class="py-3 text-end">Unit Price</th>
                            <th class="py-3 text-end pe-4">Est. Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupItems as $item):
                        $qty         = (float)$item['quantity'];
                        $rl          = (float)$item['reorder_level'];
                        $rq          = (float)$item['reorder_qty'];
                        $shortfall   = max(0, $rl - $qty);
                        $suggestQty  = $rq > 0 ? $rq : max(1, $rl);
                        $estTotal    = $suggestQty * (float)$item['unit_price'];
                        $isOut       = $qty == 0;
                    ?>
                    <tr class="<?= $isOut ? 'table-danger' : 'table-warning' ?>" style="--bs-table-bg-type: transparent">
                        <td class="ps-4 py-3">
                            <input type="checkbox" class="form-check-input item-check" name="item_ids[]"
                                   value="<?= $item['id'] ?>" checked>
                        </td>
                        <td class="py-3">
                            <div class="fw-semibold"><?= e($item['part_name']) ?></div>
                            <?php if ($item['part_number']): ?>
                            <div class="text-muted" style="font-size:11.5px"><code><?= e($item['part_number']) ?></code></div>
                            <?php endif; ?>
                            <?php if ($item['make'] || $item['model']): ?>
                            <div class="text-muted" style="font-size:11px"><?= e(trim(($item['make'] ?? '').' '.($item['model'] ?? ''))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-muted small"><?= e($item['category'] ?: '—') ?></td>
                        <td class="py-3 text-center">
                            <span class="fw-bold <?= $isOut ? 'text-danger' : 'text-warning' ?>">
                                <?= number_format($qty, 2) ?>
                            </span>
                            <div class="text-muted" style="font-size:11px"><?= e($item['unit']) ?></div>
                        </td>
                        <td class="py-3 text-center text-muted small"><?= number_format($rl, 2) ?></td>
                        <td class="py-3 text-center">
                            <?php if ($shortfall > 0): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">-<?= number_format($shortfall, 2) ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-center">
                            <input type="number" name="order_qty[<?= $item['id'] ?>]"
                                   class="form-control form-control-sm text-center order-qty-input"
                                   value="<?= number_format($suggestQty, 2, '.', '') ?>"
                                   min="0.01" step="0.01" style="width:90px;display:inline-block">
                        </td>
                        <td class="py-3 text-end small"><?= money((float)$item['unit_price']) ?></td>
                        <td class="py-3 text-end pe-4 fw-semibold line-est"><?= money($estTotal) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- LPO Generation footer -->
            <?php if (canWrite('lpo')): ?>
            <div class="d-flex justify-content-between align-items-center p-4 pt-3 border-top bg-light rounded-bottom-3 flex-wrap gap-3">
                <?php if ($isNoSupp): ?>
                <div class="d-flex align-items-center gap-2">
                    <label class="fw-semibold small text-nowrap">Assign Supplier:</label>
                    <select name="supplier_id" class="form-select form-select-sm" style="min-width:220px" required>
                        <option value="">— Select Supplier —</option>
                        <?php foreach ($allSuppliers as $s): ?>
                        <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="supplier_id" value="<?= $suppId ?>">
                <div class="text-muted small">
                    <i class="fa fa-info-circle me-1"></i>
                    LPO will be raised to <strong><?= e($suppName) ?></strong> for selected items
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm"
                        onclick="return confirm('Generate LPO for selected items?')">
                    <i class="fa fa-file-contract me-1"></i>Generate LPO
                </button>
            </div>
            <?php endif; ?>
        </form>

    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
// Select all / deselect per group
document.querySelectorAll('.check-all').forEach(function (master) {
    master.addEventListener('change', function () {
        var form = master.closest('form');
        form.querySelectorAll('.item-check').forEach(function (cb) { cb.checked = master.checked; });
    });
});

// Live estimate recalculation
document.querySelectorAll('.order-qty-input').forEach(function (input) {
    input.addEventListener('input', function () {
        var row  = input.closest('tr');
        var priceEl = row.querySelector('td:nth-last-child(2)');
        var estEl   = row.querySelector('.line-est');
        if (!priceEl || !estEl) return;
        var price = parseFloat(priceEl.textContent.replace(/[^0-9.]/g,'')) || 0;
        var qty   = parseFloat(input.value) || 0;
        estEl.textContent = 'KES ' + (qty * price).toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
