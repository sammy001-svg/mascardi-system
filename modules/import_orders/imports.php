<?php
/**
 * Imports — the placements made against customer import orders.
 *
 * One card per vehicle actually placed with a supplier: who asked for it, what
 * is coming, from whom, when it is expected, and where it has got to.
 *
 * Nothing moves until an admin approves it. Placing an import commits money to a
 * supplier abroad, so the person who places it and the person who agrees to it
 * are deliberately not the same — the status control does not even appear until
 * the placement has been approved.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canApprove = canApproveImportPlacement();
$canPlace   = canWrite('crm');

importPlacementsEnsure($db);

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $back   = BASE_URL . '/modules/import_orders/imports.php';

    if ($action === 'place') {
        if (!$canPlace) { setFlash('error', 'You cannot place an import.'); redirect($back); }

        $leadId  = (int)($_POST['lead_id'] ?? 0);
        $vehicle = trim($_POST['vehicle_details'] ?? '');
        $errors  = [];
        if (!$leadId)         $errors[] = 'Choose the customer order this import is for.';
        if ($vehicle === '')  $errors[] = 'Describe the vehicle being imported.';

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect($back);
        }

        try {
            $db->prepare("
                INSERT INTO import_placements
                    (lead_id, vehicle_details, supplier, supplier_contact, purchase_price,
                     expected_arrival, notes, placed_by, status, approval_status)
                VALUES (?,?,?,?,?,?,?,?, 'not_dispatched', 'pending')
            ")->execute([
                $leadId, $vehicle,
                trim($_POST['supplier'] ?? '') ?: null,
                trim($_POST['supplier_contact'] ?? '') ?: null,
                ($_POST['purchase_price'] ?? '') !== '' ? (float)$_POST['purchase_price'] : null,
                trim($_POST['expected_arrival'] ?? '') ?: null,
                trim($_POST['notes'] ?? '') ?: null,
                $me['name'],
            ]);
            $newId = (int)$db->lastInsertId();

            require_once __DIR__ . '/../../includes/notifications.php';
            notifyRoles(['super_admin', 'admin', 'general_manager'], 'alert',
                'Import placement needs approval',
                $me['name'] . ' has placed an import: ' . mb_substr($vehicle, 0, 90)
                . '. Nothing is ordered until it is approved.',
                $back);

            logActivity('create', 'import_placements', $newId,
                'Import placed by ' . $me['name'] . ' — awaiting approval.');
            setFlash('success', 'Import placed. It is waiting for an admin or the general manager '
                              . 'to approve it before anything moves.');
        } catch (\Throwable $e) {
            error_log('import place: ' . $e->getMessage());
            setFlash('error', 'The import could not be placed.');
        }
        redirect($back);
    }

    if ($action === 'approve' || $action === 'reject') {
        if (!$canApprove) {
            setFlash('error', 'Approving an import is limited to a super admin, an admin or the general manager.');
            redirect($back);
        }
        $pid = (int)($_POST['placement_id'] ?? 0);
        try {
            if ($action === 'approve') {
                $db->prepare("UPDATE import_placements
                                 SET approval_status='approved', approved_by=?, approved_at=NOW()
                               WHERE id=?")->execute([$me['name'], $pid]);
                logActivity('update', 'import_placements', $pid, 'Import approved by ' . $me['name'] . '.');
                setFlash('success', 'Import approved. It can now be tracked to arrival.');
            } else {
                $why = trim($_POST['rejection_reason'] ?? '');
                $db->prepare("UPDATE import_placements
                                 SET approval_status='rejected', approved_by=?, approved_at=NOW(),
                                     rejection_reason=?
                               WHERE id=?")->execute([$me['name'], $why ?: null, $pid]);
                logActivity('update', 'import_placements', $pid,
                    'Import rejected by ' . $me['name'] . ($why ? ' — ' . $why : '') . '.');
                setFlash('success', 'Import rejected. The order can be placed again.');
            }
        } catch (\Throwable $e) {
            error_log('import approve: ' . $e->getMessage());
            setFlash('error', 'That could not be saved.');
        }
        redirect($back);
    }

    if ($action === 'status') {
        $pid    = (int)($_POST['placement_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!array_key_exists($status, importPlacementStages())) {
            setFlash('error', 'Unknown status.');
            redirect($back);
        }
        try {
            // Only an approved placement can move. An unapproved one has not been
            // ordered, so it cannot be in transit however it is described.
            $st = $db->prepare("SELECT approval_status FROM import_placements WHERE id=?");
            $st->execute([$pid]);
            if ($st->fetchColumn() !== 'approved') {
                setFlash('error', 'That import has not been approved yet, so it cannot be moved on.');
                redirect($back);
            }
            $db->prepare("UPDATE import_placements SET status=?, updated_at=NOW() WHERE id=?")
               ->execute([$status, $pid]);
            logActivity('update', 'import_placements', $pid,
                'Import status set to ' . importPlacementStages()[$status][0] . ' by ' . $me['name'] . '.');
            setFlash('success', 'Status updated to ' . importPlacementStages()[$status][0] . '.');
        } catch (\Throwable $e) {
            error_log('import status: ' . $e->getMessage());
            setFlash('error', 'The status could not be updated.');
        }
        redirect($back);
    }
}

// ── What to show ─────────────────────────────────────────────────────────────
$filters = [
    'status'   => trim($_GET['status'] ?? ''),
    'approval' => trim($_GET['approval'] ?? ''),
    'q'        => trim($_GET['q'] ?? ''),
];
if ($isCrmAgent) $filters['agent_id'] = $uid;

$rows      = importPlacementRows($db, $filters);
$available = importOrdersAwaitingPlacement($db, $isCrmAgent ? $uid : null);
$stages    = importPlacementStages();
$approvals = importPlacementApprovals();

$counts = ['pending' => 0, 'moving' => 0, 'landed' => 0];
foreach (importPlacementRows($db, $isCrmAgent ? ['agent_id' => $uid] : []) as $r) {
    if ($r['approval_status'] === 'pending')            $counts['pending']++;
    elseif ($r['approval_status'] === 'approved') {
        if ($r['status'] === 'arrived_nairobi')         $counts['landed']++;
        else                                            $counts['moving']++;
    }
}

$pageTitle = 'Imports';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.imp-card{ background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:16px; overflow:hidden; box-shadow:0 1px 8px rgba(0,0,0,.06);
    display:flex; flex-direction:column; height:100%;
    transition:box-shadow .2s, transform .2s; }
.imp-card:hover{ box-shadow:0 6px 24px rgba(0,0,0,.10); transform:translateY(-2px); }
.imp-card.s-pending { border-left:4px solid #f59e0b; }
.imp-card.s-rejected{ border-left:4px solid #dc2626; opacity:.72; }
.imp-card.s-late    { border-left:4px solid #dc2626; }
.imp-card.s-ok      { border-left:4px solid #2563eb; }
.imp-card.s-landed  { border-left:4px solid #16a34a; }
.imp-head{ padding:14px 16px 10px; border-bottom:1px solid var(--border,#e2e8f0); }
.imp-who{ font-size:14.5px; font-weight:800; letter-spacing:-.2px; }
.imp-sub{ font-size:12px; color:var(--text-2,#64748b); }
.imp-body{ padding:14px 16px; flex:1; }
.imp-veh{ font-size:13px; font-weight:600; line-height:1.5; margin-bottom:10px; }
.imp-row{ display:flex; justify-content:space-between; gap:12px; font-size:12.5px; padding:3px 0; }
.imp-row span{ color:var(--text-2,#64748b); }
.imp-row b{ font-weight:600; text-align:right; }
.imp-foot{ padding:12px 16px; border-top:1px solid var(--border,#e2e8f0);
    background:var(--surface-alt,#f8fafc); }
.imp-late{ color:#b91c1c; font-weight:700; }
.imp-stat-row{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:22px; }
@media(max-width:640px){ .imp-stat-row{ grid-template-columns:1fr; } }
.imp-stat{ background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:12px; padding:14px 16px; }
.imp-stat b{ display:block; font-size:22px; font-weight:900; letter-spacing:-.5px; }
.imp-stat span{ font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); }
</style>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-ship me-2 text-primary"></i>Imports</h5>
        <div class="text-muted small">Vehicles placed with a supplier against a customer's order.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-list me-1"></i>Import Orders</a>
        <?php if ($canPlace): ?>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#placeImportModal">
                <i class="fa fa-plus me-1"></i>Place Import</button>
        <?php endif; ?>
    </div>
</div>

<div class="imp-stat-row">
    <div class="imp-stat"><b class="text-warning"><?= $counts['pending'] ?></b><span>Awaiting approval</span></div>
    <div class="imp-stat"><b class="text-primary"><?= $counts['moving'] ?></b><span>On the way</span></div>
    <div class="imp-stat"><b class="text-success"><?= $counts['landed'] ?></b><span>Arrived in Nairobi</span></div>
</div>

<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-4">
    <div><label class="form-label small text-muted mb-1">Search</label>
        <input type="text" name="q" value="<?= e($filters['q']) ?>" class="form-control form-control-sm"
               placeholder="Customer, vehicle or supplier" style="min-width:200px"></div>
    <div><label class="form-label small text-muted mb-1">Stage</label>
        <select name="status" class="form-select form-select-sm">
            <option value="">Any</option>
            <?php foreach ($stages as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div><label class="form-label small text-muted mb-1">Approval</label>
        <select name="approval" class="form-select form-select-sm">
            <option value="">Any</option>
            <?php foreach ($approvals as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filters['approval'] === $k ? 'selected' : '' ?>><?= e($v[0]) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-primary">Apply</button>
        <a href="imports.php" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
        <i class="fa fa-ship fa-2x mb-3 d-block opacity-25"></i>
        <?php if ($filters['q'] || $filters['status'] || $filters['approval']): ?>
            Nothing matches that. <a href="imports.php">Clear the filters</a>.
        <?php else: ?>
            No imports placed yet.
            <?= $canPlace ? 'Use <strong>Place Import</strong> to record one against a customer order.' : '' ?>
        <?php endif; ?>
    </div></div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($rows as $r):
    $days = $r['expected_arrival'] !== null ? (int)$r['days_away'] : null;
    if     ($r['approval_status'] === 'pending')       $cls = 's-pending';
    elseif ($r['approval_status'] === 'rejected')      $cls = 's-rejected';
    elseif ($r['status'] === 'arrived_nairobi')        $cls = 's-landed';
    elseif ($days !== null && $days < 0)               $cls = 's-late';
    else                                               $cls = 's-ok';
    [$stLabel, $stTone, $stIcon] = $stages[$r['status']] ?? ['Unknown', 'secondary', 'fa-question'];
    [$apLabel, $apTone]          = $approvals[$r['approval_status']] ?? ['Unknown', 'secondary'];
?>
    <div class="col-md-6 col-xl-4">
        <div class="imp-card <?= $cls ?>">
            <div class="imp-head d-flex justify-content-between align-items-start gap-2">
                <div>
                    <div class="imp-who"><?= e($r['client_name'] ?: $r['lead_name']) ?></div>
                    <div class="imp-sub"><?= e($r['client_phone'] ?: $r['lead_phone'] ?: '—') ?></div>
                </div>
                <span class="badge bg-<?= $apTone ?>"><?= e($apLabel) ?></span>
            </div>

            <div class="imp-body">
                <div class="imp-veh"><?= e($r['vehicle_details']) ?></div>
                <div class="imp-row"><span>Supplier</span><b><?= e($r['supplier'] ?: '—') ?></b></div>
                <?php if ($r['purchase_price']): ?>
                    <div class="imp-row"><span>Cost</span><b><?= money((float)$r['purchase_price']) ?></b></div>
                <?php endif; ?>
                <div class="imp-row"><span>Expected</span>
                    <b><?= $r['expected_arrival'] ? fmtDate($r['expected_arrival']) : 'Not set' ?></b></div>
                <?php if ($days !== null && $days < 0 && $r['status'] !== 'arrived_nairobi'): ?>
                    <div class="imp-row"><span>Overdue by</span>
                        <b class="imp-late"><?= abs($days) ?> days</b></div>
                <?php endif; ?>
                <?php if ($r['promised_to_client']): ?>
                    <div class="imp-row"><span>Promised to client</span>
                        <b><?= fmtDate($r['promised_to_client']) ?></b></div>
                <?php endif; ?>
                <?php if ($r['deposit_amount']): ?>
                    <div class="imp-row"><span>Deposit held</span>
                        <b><?= money((float)$r['deposit_amount']) ?></b></div>
                <?php endif; ?>
                <div class="imp-row"><span>Handled by</span><b><?= e($r['agent_name'] ?: 'Unassigned') ?></b></div>

                <div class="mt-2">
                    <span class="badge bg-<?= $stTone ?>"><i class="fa <?= $stIcon ?> me-1"></i><?= e($stLabel) ?></span>
                </div>

                <?php if ($r['approval_status'] === 'rejected' && $r['rejection_reason']): ?>
                    <div class="alert alert-danger py-2 px-3 mt-3 mb-0" style="font-size:12px">
                        <strong>Rejected:</strong> <?= e($r['rejection_reason']) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="imp-foot">
                <?php if ($r['approval_status'] === 'pending'): ?>
                    <?php if ($canApprove): ?>
                        <div class="d-flex gap-2">
                            <form method="post" class="flex-grow-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="placement_id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-success w-100">
                                    <i class="fa fa-check me-1"></i>Approve</button>
                            </form>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                                    data-bs-target="#rejectModal<?= (int)$r['id'] ?>">Reject</button>
                        </div>
                    <?php else: ?>
                        <div class="small text-muted">
                            <i class="fa fa-hourglass-half me-1"></i>
                            Waiting for an admin or the general manager to approve it.
                        </div>
                    <?php endif; ?>

                <?php elseif ($r['approval_status'] === 'approved'): ?>
                    <form method="post" class="d-flex gap-2 align-items-center">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="status">
                        <input type="hidden" name="placement_id" value="<?= (int)$r['id'] ?>">
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach ($stages as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>>
                                    <?= e($v[0]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary">Update</button>
                    </form>
                    <div class="small text-muted mt-2">
                        Approved by <?= e($r['approved_by']) ?>
                        <?= $r['approved_at'] ? ' on ' . fmtDate($r['approved_at']) : '' ?>
                    </div>

                <?php else: ?>
                    <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$r['lead_id'] ?>"
                       class="btn btn-sm btn-outline-secondary w-100">Open the order</a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$r['lead_id'] ?>"
                   class="d-block text-center small mt-2 text-decoration-none">
                    Open <?= e($r['client_name'] ?: $r['lead_name']) ?>'s order</a>
            </div>
        </div>
    </div>

    <?php if ($r['approval_status'] === 'pending' && $canApprove): ?>
    <div class="modal fade" id="rejectModal<?= (int)$r['id'] ?>" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="placement_id" value="<?= (int)$r['id'] ?>">
          <div class="modal-header"><h6 class="modal-title">Reject this import</h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <p class="small text-muted">
                <?= e($r['vehicle_details']) ?> for <?= e($r['client_name'] ?: $r['lead_name']) ?>.
                The order stays open and can be placed again.
            </p>
            <label class="form-label small">Why, so whoever placed it knows</label>
            <textarea name="rejection_reason" class="form-control" rows="3"
                      placeholder="e.g. supplier price too high, wrong specification"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-danger">Reject</button>
          </div>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($canPlace): ?>
<div class="modal fade" id="placeImportModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="post">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="place">
      <div class="modal-header">
        <h6 class="modal-title"><i class="fa fa-ship me-2"></i>Place an import</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2 small">
          <i class="fa fa-circle-info me-1"></i>
          Placing an import commits money to a supplier, so it is held until an admin or the
          general manager approves it. Nothing can be marked in transit before then.
        </div>

        <?php if (!$available): ?>
          <div class="alert alert-warning py-2 small mb-0">
            Every import order already has an import placed against it. Raise the order on the
            customer's lead first — it then appears here.
          </div>
        <?php else: ?>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label small fw-semibold">Customer order <span class="text-danger">*</span></label>
            <select name="lead_id" class="form-select" required id="placeLead">
              <option value="">— choose the order —</option>
              <?php foreach ($available as $a): ?>
                <option value="<?= (int)$a['id'] ?>"
                        data-vehicle="<?= e($a['import_vehicle_details'] ?? '') ?>"
                        data-arrival="<?= e($a['expected_arrival_date'] ?? '') ?>">
                  <?= e($a['client_name'] ?: $a['name']) ?>
                  <?= $a['phone'] ? ' — ' . e($a['phone']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Only orders without an import already placed are listed.</div>
          </div>

          <div class="col-12">
            <label class="form-label small fw-semibold">Vehicle being imported <span class="text-danger">*</span></label>
            <textarea name="vehicle_details" id="placeVehicle" class="form-control" rows="2" required
                      placeholder="e.g. 2024 Toyota Land Cruiser 300 GR Sport, White, 3.5L Twin Turbo"></textarea>
            <div class="form-text">Filled in from the order when you choose one — correct it if the actual buy differs.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-semibold">Supplier</label>
            <input type="text" name="supplier" class="form-control" placeholder="e.g. Kaneko Auto, Japan">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Supplier contact</label>
            <input type="text" name="supplier_contact" class="form-control" placeholder="Email or phone">
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-semibold">Purchase price</label>
            <input type="number" name="purchase_price" class="form-control" min="0" step="0.01" placeholder="0.00">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Expected arrival</label>
            <input type="date" name="expected_arrival" id="placeArrival" class="form-control">
          </div>

          <div class="col-12">
            <label class="form-label small fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Anything worth recording"></textarea>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if ($available): ?>
          <button class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Place import</button>
        <?php endif; ?>
      </div>
    </form>
  </div></div>
</div>

<script>
// Choosing the order fills in what the customer asked for, so the common case is
// one click rather than retyping a specification that is already on file.
(function () {
    var lead = document.getElementById('placeLead');
    if (!lead) return;
    lead.addEventListener('change', function () {
        var o = this.options[this.selectedIndex];
        var v = document.getElementById('placeVehicle');
        var a = document.getElementById('placeArrival');
        if (o && o.dataset.vehicle && v && !v.value.trim()) v.value = o.dataset.vehicle;
        if (o && o.dataset.arrival && a && !a.value) a.value = o.dataset.arrival;
    });
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
