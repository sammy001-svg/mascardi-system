<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('parts_requests');
$pageTitle = 'New Quote Request';
$db   = getDB();
$user = authUser();

// ── Inline migration (runs once, safe to repeat) ──────────────────────────
foreach ([
    "ALTER TABLE parts_requests MODIFY COLUMN mechanic_id INT NULL",
    "ALTER TABLE parts_requests ADD COLUMN quick_assessment_id INT NULL AFTER request_number",
    "ALTER TABLE parts_requests ADD COLUMN client_name      VARCHAR(150) NULL",
    "ALTER TABLE parts_requests ADD COLUMN client_phone     VARCHAR(50)  NULL",
    "ALTER TABLE parts_requests ADD COLUMN client_email     VARCHAR(150) NULL",
    "ALTER TABLE parts_requests ADD COLUMN car_make         VARCHAR(100) NULL",
    "ALTER TABLE parts_requests ADD COLUMN car_model        VARCHAR(100) NULL",
    "ALTER TABLE parts_requests ADD COLUMN car_registration VARCHAR(50)  NULL",
    "ALTER TABLE parts_requests ADD COLUMN car_chassis      VARCHAR(100) NULL",
    "ALTER TABLE parts_request_items ADD COLUMN part_number VARCHAR(100) NULL AFTER id",
] as $_mig) {
    try { $db->exec($_mig); } catch (\Throwable $_e) { /* already applied */ }
}

$errors = [];

// Quick Assessments for the selector
$assessments = [];
try {
    $assessments = $db->query("
        SELECT qa.id, qa.assessment_number, qa.assessment_date,
               qa.client_name, qa.client_phone,
               COALESCE(NULLIF(qa.client_email,''), cl.email) AS client_email,
               qa.car_make, qa.car_model, qa.car_registration,
               COALESCE(c.chassis_number, c2.chassis_number) AS chassis_number
        FROM quick_assessments qa
        LEFT JOIN clients cl  ON cl.id  = qa.client_id
        LEFT JOIN cars c      ON c.id   = qa.car_id
        LEFT JOIN cars c2     ON c2.registration_number = qa.car_registration
                              AND qa.car_id IS NULL
        ORDER BY qa.id DESC
        LIMIT 150
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $assessments = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assessmentId = (int)($_POST['assessment_id'] ?? 0) ?: null;
    $clientName   = trim($_POST['client_name']    ?? '');
    $clientPhone  = trim($_POST['client_phone']   ?? '');
    $clientEmail  = trim($_POST['client_email']   ?? '');
    $carMake      = trim($_POST['car_make']        ?? '');
    $carModel     = trim($_POST['car_model']       ?? '');
    $carReg       = strtoupper(trim($_POST['car_registration'] ?? ''));
    $carChassis   = trim($_POST['car_chassis']     ?? '');
    $notes        = trim($_POST['notes']           ?? '');

    $partNos   = $_POST['part_number'] ?? [];
    $partNames = $_POST['part_name']   ?? [];
    $qtys      = $_POST['qty']         ?? [];
    $itemNotes = $_POST['item_notes']  ?? [];

    // Build validated items list
    $items = [];
    foreach ($partNames as $i => $pname) {
        $pname = trim($pname);
        $qty   = (float)($qtys[$i] ?? 0);
        if (!$pname || $qty <= 0) continue;
        $items[] = [
            'part_number' => trim($partNos[$i] ?? '') ?: null,
            'part_name'   => $pname,
            'qty'         => $qty,
            'notes'       => trim($itemNotes[$i] ?? ''),
        ];
    }

    if (empty($items)) $errors[] = 'Add at least one part to the quote request.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $reqNum = nextNumber('parts_requests', 'request_number', 'QR');
            $db->prepare("
                INSERT INTO parts_requests
                    (request_number, quick_assessment_id, mechanic_id, requested_by,
                     client_name, client_phone, client_email,
                     car_make, car_model, car_registration, car_chassis, notes)
                VALUES (?,?,?,?, ?,?,?, ?,?,?,?,?)
            ")->execute([
                $reqNum, $assessmentId, null, $user['id'],
                $clientName ?: null, $clientPhone ?: null, $clientEmail ?: null,
                $carMake ?: null, $carModel ?: null, $carReg ?: null, $carChassis ?: null,
                $notes ?: null,
            ]);
            $reqId = (int)$db->lastInsertId();

            $ins = $db->prepare("
                INSERT INTO parts_request_items
                    (request_id, part_number, part_name, quantity_requested, unit, notes)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($items as $it) {
                $ins->execute([$reqId, $it['part_number'], $it['part_name'], $it['qty'], 'piece', $it['notes']]);
            }

            $db->commit();
            logActivity('create', 'parts_requests', $reqId, "Created quote request {$reqNum} with " . count($items) . " item(s)");
            setFlash('success', "Quote request {$reqNum} submitted successfully.");
            redirect(BASE_URL . '/modules/parts_requests/view.php?id=' . $reqId);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-file-invoice me-2 text-primary"></i>New Quote Request</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-2"></i>' . e($err) . '</div>'; ?>
</div>
<?php endif; ?>

<form method="POST" id="qrForm">

    <!-- ── Quick Assessment (top, full width) ─────────────────────────────── -->
    <div class="card mb-4 border-primary border-opacity-50">
        <div class="card-header fw-semibold bg-primary bg-opacity-10">
            <i class="fa fa-magnifying-glass-chart me-2 text-primary"></i>Quick Assessment
            <span class="text-muted fw-normal small ms-1">— select to auto-fill client &amp; vehicle details</span>
        </div>
        <div class="card-body">
            <select name="assessment_id" id="assessmentSelect" class="form-select select2">
                <option value="">— No linked assessment / Walk-in —</option>
                <?php foreach ($assessments as $qa): ?>
                <option value="<?= $qa['id'] ?>"
                        data-client-name="<?= e($qa['client_name']    ?? '') ?>"
                        data-client-phone="<?= e($qa['client_phone']   ?? '') ?>"
                        data-client-email="<?= e($qa['client_email']   ?? '') ?>"
                        data-car-make="<?= e($qa['car_make']       ?? '') ?>"
                        data-car-model="<?= e($qa['car_model']      ?? '') ?>"
                        data-car-reg="<?= e($qa['car_registration'] ?? '') ?>"
                        data-chassis="<?= e($qa['chassis_number']  ?? '') ?>"
                        <?= (($_POST['assessment_id'] ?? 0) == $qa['id']) ? 'selected' : '' ?>>
                    <?= e($qa['assessment_number']) ?>
                    — <?= e($qa['client_name'] ?: 'No name') ?>
                    <?php if ($qa['car_make']): ?>
                     (<?= e(trim($qa['car_make'] . ' ' . $qa['car_model'])) ?><?= $qa['car_registration'] ? ' · ' . e($qa['car_registration']) : '' ?>)
                    <?php endif; ?>
                    — <?= fmtDate($qa['assessment_date']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row g-4 mb-4">

        <!-- ── Client Details ─────────────────────────────────────────────── -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">
                    <i class="fa fa-user me-2 text-primary"></i>Client Details
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Client Name</label>
                        <input type="text" name="client_name" id="clientName" class="form-control"
                               placeholder="Auto-filled from assessment"
                               value="<?= e($_POST['client_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Phone</label>
                        <input type="text" name="client_phone" id="clientPhone" class="form-control"
                               placeholder="Auto-filled from assessment"
                               value="<?= e($_POST['client_phone'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" name="client_email" id="clientEmail" class="form-control"
                               placeholder="Auto-filled from assessment"
                               value="<?= e($_POST['client_email'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Vehicle Details ────────────────────────────────────────────── -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">
                    <i class="fa fa-car me-2 text-primary"></i>Vehicle Details
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Make</label>
                            <input type="text" name="car_make" id="carMake" class="form-control"
                                   placeholder="e.g. Toyota"
                                   value="<?= e($_POST['car_make'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Model</label>
                            <input type="text" name="car_model" id="carModel" class="form-control"
                                   placeholder="e.g. Hilux"
                                   value="<?= e($_POST['car_model'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Registration No.</label>
                            <input type="text" name="car_registration" id="carReg" class="form-control text-uppercase"
                                   placeholder="KDA 000Q"
                                   value="<?= e($_POST['car_registration'] ?? '') ?>"
                                   oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Chassis Number</label>
                            <input type="text" name="car_chassis" id="carChassis" class="form-control"
                                   placeholder="Auto-filled if linked"
                                   value="<?= e($_POST['car_chassis'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->

    <!-- ── Parts Needed ──────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa fa-list-ul me-2 text-primary"></i>Parts Needed for Quotation</span>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addPartBtn">
                <i class="fa fa-plus me-1"></i>Add Part
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover mb-0" id="partsTable">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:3%">#</th>
                        <th style="width:15%">Part No.</th>
                        <th>Part Name <span class="text-danger">*</span></th>
                        <th style="width:10%">QTY <span class="text-danger">*</span></th>
                        <th>Note</th>
                        <th style="width:48px"></th>
                    </tr>
                </thead>
                <tbody id="partsBody">
                    <tr class="part-row">
                        <td class="ps-3 text-muted row-num">1</td>
                        <td><input type="text" name="part_number[]" class="form-control form-control-sm" placeholder="e.g. OIL-001"></td>
                        <td><input type="text" name="part_name[]" class="form-control form-control-sm" placeholder="Part name / description" required></td>
                        <td><input type="number" name="qty[]" class="form-control form-control-sm" min="0.01" step="0.01" value="1" required></td>
                        <td><input type="text" name="item_notes[]" class="form-control form-control-sm" placeholder="Any additional note…"></td>
                        <td class="pe-2">
                            <button type="button" class="btn btn-xs btn-outline-danger remove-row" title="Remove row">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ── Notes + Submit ────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-body">
            <label class="form-label fw-semibold">Additional Notes</label>
            <textarea name="notes" class="form-control" rows="3"
                      placeholder="e.g. Parts needed urgently for brake service on KDA 000Q…"><?= e($_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fa fa-paper-plane me-2"></i>Submit Quote Request
        </button>
    </div>

</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
$(function () {

    // ── Assessment → auto-fill client + vehicle ──────────────────────────
    function applyAssessmentFill() {
        const opt = $('#assessmentSelect').find(':selected')[0];
        const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
        if (!opt || !opt.value) {
            ['clientName','clientPhone','clientEmail','carMake','carModel','carReg','carChassis']
                .forEach(id => set(id, ''));
            return;
        }
        set('clientName',  opt.dataset.clientName);
        set('clientPhone', opt.dataset.clientPhone);
        set('clientEmail', opt.dataset.clientEmail);
        set('carMake',     opt.dataset.carMake);
        set('carModel',    opt.dataset.carModel);
        set('carReg',      (opt.dataset.carReg || '').toUpperCase());
        set('carChassis',  opt.dataset.chassis);
    }
    $('#assessmentSelect').on('select2:select select2:clear', applyAssessmentFill);

    // ── Dynamic rows ─────────────────────────────────────────────────────
    function renumberRows() {
        document.querySelectorAll('#partsBody .part-row').forEach((r, i) => {
            const n = r.querySelector('.row-num');
            if (n) n.textContent = i + 1;
        });
    }

    function initRow(row) {
        row.querySelector('.remove-row').addEventListener('click', function () {
            if (document.querySelectorAll('.part-row').length > 1) {
                row.remove();
                renumberRows();
            }
        });
    }

    document.querySelectorAll('.part-row').forEach(initRow);

    document.getElementById('addPartBtn').addEventListener('click', function () {
        const template = document.querySelector('.part-row');
        const clone    = template.cloneNode(true);
        clone.querySelectorAll('input').forEach(inp => {
            inp.value = inp.type === 'number' ? '1' : '';
        });
        document.getElementById('partsBody').appendChild(clone);
        initRow(clone);
        renumberRows();
        clone.querySelector('input[name="part_name[]"]').focus();
    });
});
</script>
