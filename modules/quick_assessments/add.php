<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');
canWrite('quick_assessments') || die('Permission denied.');
$pageTitle = 'New Quick Assessment';
$db = getDB();
$errors = [];

$preBookingId = (int)($_GET['booking_id'] ?? 0);
$preBooking   = null;
if ($preBookingId) {
    $s = $db->prepare("SELECT * FROM service_bookings WHERE id=?");
    $s->execute([$preBookingId]); $preBooking = $s->fetch();
}

$clients  = $db->query("SELECT id, name, phone FROM clients WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$bookings = $db->query("SELECT id, booking_number, client_name, car_make, car_model, car_registration FROM service_bookings ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$cars     = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.registration_number, 
           cl.id as client_id, cl.name as client_name, cl.phone as client_phone
    FROM cars c
    LEFT JOIN clients cl ON cl.id = c.client_id
    ORDER BY c.make, c.model LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$checks = ['tyres','lights','exterior','engine','interior','brakes','fluids','electrical'];
$checkLabels = [
    'tyres'     => ['Tyres',            'fa-circle-dot'],
    'lights'    => ['Lights',           'fa-lightbulb'],
    'exterior'  => ['Exterior Body',    'fa-car-side'],
    'engine'    => ['Engine Bay',       'fa-gears'],
    'interior'  => ['Interior',         'fa-couch'],
    'brakes'    => ['Brakes',           'fa-circle-stop'],
    'fluids'    => ['Fluid Levels',     'fa-droplet'],
    'electrical'=> ['Electrical',       'fa-microchip'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
    $clientName    = trim($_POST['client_name'] ?? '');
    $clientPhone   = trim($_POST['client_phone'] ?? '');
    $bookingId     = (int)($_POST['service_booking_id'] ?? 0) ?: null;
    $carId         = (int)($_POST['car_id'] ?? 0) ?: null;
    $carMake       = trim($_POST['car_make'] ?? '');
    $carModel      = trim($_POST['car_model'] ?? '');
    $carReg        = strtoupper(trim($_POST['car_registration'] ?? ''));
    $carYear       = (int)($_POST['car_year'] ?? 0) ?: null;
    $aDate         = $_POST['assessment_date'] ?? date('Y-m-d');
    $overall       = $_POST['overall_condition'] ?? 'fair';
    $observations  = trim($_POST['observations'] ?? '');
    $recommended   = trim($_POST['recommended_services'] ?? '');
    $assessedBy    = trim($_POST['assessed_by'] ?? (authUser()['name'] ?? ''));

    $checkVals = [];
    foreach ($checks as $c) {
        $checkVals["check_{$c}"] = in_array($_POST["check_{$c}"] ?? '', ['ok','issue','na']) ? $_POST["check_{$c}"] : 'na';
    }

    if (!$carMake && !$carId) $errors[] = 'Vehicle make or selection is required.';
    if (!in_array($overall, ['good','fair','needs_attention','critical'])) $errors[] = 'Select an overall condition.';

    if (empty($errors)) {
        try {
            $num = nextNumber('quick_assessments', 'assessment_number', 'QA');
            $cols = implode(',', array_keys($checkVals));
            $placeholders = implode(',', array_fill(0, count($checkVals), '?'));
            $db->prepare("
                INSERT INTO quick_assessments
                (assessment_number, assessment_date, car_id, car_make, car_model, car_registration, car_year,
                 client_id, client_name, client_phone, service_booking_id,
                 check_tyres, check_lights, check_exterior, check_engine,
                 check_interior, check_brakes, check_fluids, check_electrical,
                 overall_condition, observations, recommended_services, assessed_by, created_by)
                VALUES (?,?,?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?)
            ")->execute([
                $num, $aDate, $carId, $carMake, $carModel, $carReg, $carYear,
                $clientId, $clientName, $clientPhone, $bookingId,
                $checkVals['check_tyres'], $checkVals['check_lights'], $checkVals['check_exterior'], $checkVals['check_engine'],
                $checkVals['check_interior'], $checkVals['check_brakes'], $checkVals['check_fluids'], $checkVals['check_electrical'],
                $overall, $observations, $recommended, $assessedBy, authUser()['id'] ?? null,
            ]);
            setFlash('success', "Quick assessment {$num} saved.");
            redirect(BASE_URL . '/modules/quick_assessments/view.php?id=' . $db->lastInsertId());
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-magnifying-glass-chart me-2 text-primary"></i>New Quick Assessment</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e2) echo '<li>'.e($e2).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Left column -->
    <div class="col-lg-7">

        <!-- Vehicle -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Search Existing Car <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="car_id" class="form-select select2" id="carSelect">
                        <option value="">— Walk-in / Manual Entry —</option>
                        <?php foreach ($cars as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                data-make="<?= e($c['make']) ?>"
                                data-model="<?= e($c['model']) ?>"
                                data-year="<?= e($c['year'] ?? '') ?>"
                                data-reg="<?= e($c['registration_number'] ?? '') ?>"
                                data-client-id="<?= e($c['client_id'] ?? '') ?>"
                                data-client-name="<?= e($c['client_name'] ?? '') ?>"
                                data-client-phone="<?= e($c['client_phone'] ?? '') ?>"
                                <?= (($_POST['car_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['make'].' '.$c['model'].($c['year']?" ({$c['year']})":"")) ?>
                            <?= $c['registration_number'] ? ' — '.e($c['registration_number']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Make <span class="text-danger">*</span></label>
                        <input type="text" name="car_make" id="carMake" class="form-control" placeholder="e.g. Toyota"
                               value="<?= e($_POST['car_make'] ?? $preBooking['car_make'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Model</label>
                        <input type="text" name="car_model" id="carModel" class="form-control" placeholder="e.g. GLE 300"
                               value="<?= e($_POST['car_model'] ?? $preBooking['car_model'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Year</label>
                        <input type="number" name="car_year" id="carYear" class="form-control" placeholder="2022" min="1990" max="2099"
                               value="<?= e($_POST['car_year'] ?? '') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Reg. No.</label>
                        <input type="text" name="car_registration" id="carReg" class="form-control text-uppercase" placeholder="KDA 000Q"
                               value="<?= e($_POST['car_registration'] ?? $preBooking['car_registration'] ?? '') ?>"
                               oninput="this.value=this.value.toUpperCase()">
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Check Items -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Quick Check</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Mark each item as <strong>OK</strong>, <strong>Issue Found</strong>, or <strong>N/A</strong>.</p>
                <div class="row g-2">
                    <?php foreach ($checks as $chk):
                        [$chkLabel, $chkIcon] = $checkLabels[$chk];
                        $val = $_POST["check_{$chk}"] ?? 'na';
                    ?>
                    <div class="col-md-6">
                        <div class="border rounded-3 p-3 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa <?= $chkIcon ?> text-muted" style="width:18px;text-align:center"></i>
                                <span style="font-size:13px;font-weight:500"><?= $chkLabel ?></span>
                            </div>
                            <div class="d-flex gap-1" role="group">
                                <label class="btn btn-xs <?= $val === 'ok' ? 'btn-success' : 'btn-outline-success' ?> check-opt" title="OK">
                                    <input type="radio" name="check_<?= $chk ?>" value="ok" <?= $val === 'ok' ? 'checked' : '' ?> hidden>
                                    <i class="fa fa-check"></i>
                                </label>
                                <label class="btn btn-xs <?= $val === 'issue' ? 'btn-danger' : 'btn-outline-danger' ?> check-opt" title="Issue">
                                    <input type="radio" name="check_<?= $chk ?>" value="issue" <?= $val === 'issue' ? 'checked' : '' ?> hidden>
                                    <i class="fa fa-triangle-exclamation"></i>
                                </label>
                                <label class="btn btn-xs <?= $val === 'na' ? 'btn-secondary' : 'btn-outline-secondary' ?> check-opt" title="N/A">
                                    <input type="radio" name="check_<?= $chk ?>" value="na" <?= $val === 'na' ? 'checked' : '' ?> hidden>
                                    <span style="font-size:10px">N/A</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Observations -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2 text-warning"></i>Observations & Recommendations</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Observations / Issues Noted</label>
                    <textarea name="observations" class="form-control" rows="4"
                              placeholder="Describe what you noticed during the quick check…"><?= e($_POST['observations'] ?? ($preBooking['description'] ?? '')) ?></textarea>
                </div>
                <div>
                    <label class="form-label small fw-semibold">Recommended Services</label>
                    <textarea name="recommended_services" class="form-control" rows="3"
                              placeholder="e.g. Engine service, tyre replacement, brake pads…"><?= e($_POST['recommended_services'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

    </div>

    <!-- Right column -->
    <div class="col-lg-5">

        <!-- Client -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Select Existing Client <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="client_id" class="form-select select2" id="clientSelect">
                        <option value="">— Walk-in / Manual Entry —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"
                                data-name="<?= e($c['name']) ?>"
                                data-phone="<?= e($c['phone'] ?? '') ?>"
                                <?= (($_POST['client_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['name']) ?><?= $c['phone'] ? ' — '.e($c['phone']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Client Name</label>
                    <input type="text" name="client_name" id="clientName" class="form-control"
                           value="<?= e($_POST['client_name'] ?? $preBooking['client_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="form-label small fw-semibold">Phone</label>
                    <input type="text" name="client_phone" id="clientPhone" class="form-control"
                           value="<?= e($_POST['client_phone'] ?? $preBooking['client_phone'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Meta -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-sliders me-2 text-primary"></i>Assessment Info</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                    <input type="date" name="assessment_date" class="form-control"
                           value="<?= e($_POST['assessment_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Assessed By</label>
                    <input type="text" name="assessed_by" class="form-control"
                           value="<?= e($_POST['assessed_by'] ?? (authUser()['name'] ?? '')) ?>">
                </div>
                <div>
                    <label class="form-label small fw-semibold">Linked Service Booking <span class="text-muted fw-normal">(optional)</span></label>
                    <select name="service_booking_id" class="form-select select2" id="bookingSelect">
                        <option value="">— None —</option>
                        <?php foreach ($bookings as $bk): ?>
                        <option value="<?= $bk['id'] ?>"
                                data-make="<?= e($bk['car_make'] ?? '') ?>"
                                data-model="<?= e($bk['car_model'] ?? '') ?>"
                                data-reg="<?= e($bk['car_registration'] ?? '') ?>"
                                data-client="<?= e($bk['client_name']) ?>"
                                <?= (($_POST['service_booking_id'] ?? $preBookingId) == $bk['id']) ? 'selected' : '' ?>>
                            <?= e($bk['booking_number']) ?> — <?= e($bk['client_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Overall Condition -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-gauge me-2 text-primary"></i>Overall Condition <span class="text-danger">*</span></div>
            <div class="card-body">
                <?php
                $conditions = [
                    'good'            => ['success', 'fa-circle-check', 'Good',            'Vehicle is in good condition'],
                    'fair'            => ['warning', 'fa-circle-minus', 'Fair',            'Minor issues, serviceable'],
                    'needs_attention' => ['primary', 'fa-triangle-exclamation', 'Needs Attention', 'Requires service soon'],
                    'critical'        => ['danger',  'fa-circle-xmark', 'Critical',        'Urgent attention required'],
                ];
                $selCond = $_POST['overall_condition'] ?? 'fair';
                foreach ($conditions as $ckey => [$col, $ico, $lbl, $desc]):
                ?>
                <label class="d-flex align-items-center gap-3 border rounded-3 p-3 mb-2 <?= $selCond === $ckey ? "border-{$col} bg-{$col} bg-opacity-10" : '' ?>"
                       style="cursor:pointer">
                    <input type="radio" name="overall_condition" value="<?= $ckey ?>"
                           class="form-check-input cond-radio" <?= $selCond === $ckey ? 'checked' : '' ?> required>
                    <i class="fa <?= $ico ?> fa-lg text-<?= $col ?>"></i>
                    <div>
                        <div class="fw-semibold text-<?= $col ?>" style="font-size:13px"><?= $lbl ?></div>
                        <div class="text-muted" style="font-size:11px"><?= $desc ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa fa-save me-2"></i>Save Assessment
            </button>
        </div>

    </div>
</div>
</form>

<script>
$(document).ready(function() {
    // Client select auto-fill
    $('#clientSelect').on('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt) return;
        document.getElementById('clientName').value  = opt.dataset.name  || '';
        document.getElementById('clientPhone').value = opt.dataset.phone || '';
    });

    // Car select auto-fill
    $('#carSelect').on('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt) return;
        document.getElementById('carMake').value  = opt.dataset.make  || '';
        document.getElementById('carModel').value = opt.dataset.model || '';
        document.getElementById('carYear').value  = opt.dataset.year  || '';
        document.getElementById('carReg').value   = opt.dataset.reg   || '';
        if (opt.dataset.clientId) {
            $('#clientSelect').val(opt.dataset.clientId).trigger('change');
        } else if (opt.dataset.clientName) {
            document.getElementById('clientName').value = opt.dataset.clientName;
            document.getElementById('clientPhone').value = opt.dataset.clientPhone || '';
        }
    });

    // Booking select auto-fill
    $('#bookingSelect').on('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt) return;
        if (opt.dataset.make)   document.getElementById('carMake').value   = opt.dataset.make;
        if (opt.dataset.model)  document.getElementById('carModel').value  = opt.dataset.model;
        if (opt.dataset.reg)    document.getElementById('carReg').value    = opt.dataset.reg;
        if (opt.dataset.client) document.getElementById('clientName').value = opt.dataset.client;
    });
});

// Check button toggle style
document.querySelectorAll('.check-opt').forEach(lbl => {
    lbl.addEventListener('click', () => {
        const radio = lbl.querySelector('input[type=radio]');
        const group = document.querySelectorAll(`input[name="${radio.name}"]`);
        group.forEach(r => {
            const l = r.closest('label');
            const v = r.value;
            l.classList.remove('btn-success','btn-danger','btn-secondary','btn-outline-success','btn-outline-danger','btn-outline-secondary');
            const [active, inactive] = v === 'ok' ? ['btn-success','btn-outline-success'] : v === 'issue' ? ['btn-danger','btn-outline-danger'] : ['btn-secondary','btn-outline-secondary'];
            l.classList.add(r === radio ? active : inactive);
        });
    });
});

// Overall condition card highlight
document.querySelectorAll('.cond-radio').forEach(r => {
    r.addEventListener('change', () => {
        document.querySelectorAll('.cond-radio').forEach(r2 => {
            const lbl = r2.closest('label');
            lbl.className = lbl.className.replace(/border-\w+|bg-\w+\s+bg-opacity-\d+/g,'').trim();
            lbl.classList.add('border','rounded-3','p-3','mb-2');
            if (r2.checked) {
                const col = {good:'success',fair:'warning',needs_attention:'primary',critical:'danger'}[r2.value] || 'secondary';
                lbl.classList.add('border-'+col, 'bg-'+col, 'bg-opacity-10');
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
