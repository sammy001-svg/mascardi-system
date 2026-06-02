<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');
canWrite('quick_assessments') || die('Permission denied.');
$pageTitle = 'New Quick Assessment';
$db = getDB();
$errors = [];

// ── Inline migration: run once, safe to repeat ────────────────────────────
// Adds new columns to quick_assessments if they don't exist yet.
foreach ([
    "ALTER TABLE quick_assessments MODIFY COLUMN check_tyres      VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_lights     VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_exterior   VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_engine     VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_interior   VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_brakes     VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_fluids     VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments MODIFY COLUMN check_electrical VARCHAR(255) NULL DEFAULT NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_jack          VARCHAR(255) NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_dents         TEXT         NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_items_left    TEXT         NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_mileage       VARCHAR(50)  NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_fuel_level    VARCHAR(50)  NULL",
    "ALTER TABLE quick_assessments ADD COLUMN check_radio         VARCHAR(255) NULL",
    "ALTER TABLE quick_assessments ADD COLUMN client_email        VARCHAR(150) NULL",
] as $_mig) {
    try { $db->exec($_mig); } catch (\Throwable $_me) { /* already applied or no change needed */ }
}

$preBookingId = (int)($_GET['booking_id'] ?? 0);
$preBooking   = null;
if ($preBookingId) {
    try {
        $s = $db->prepare("SELECT * FROM service_bookings WHERE id=?");
        $s->execute([$preBookingId]);
        $preBooking = $s->fetch() ?: null;
    } catch (\Throwable $e) {}
}

// Service bookings for the dropdown — fetch client + vehicle fields
$bookings = [];
try {
    $bookings = $db->query("
        SELECT sb.id, sb.booking_number,
               COALESCE(cl.name,  sb.client_name)  AS client_name,
               COALESCE(cl.phone, sb.client_phone) AS client_phone,
               COALESCE(cl.email, sb.client_email, '') AS client_email,
               sb.car_make, sb.car_model, sb.car_registration
        FROM service_bookings sb
        LEFT JOIN clients cl ON cl.id = sb.client_id
        ORDER BY sb.id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback: query without email if column doesn't exist in this DB version
    try {
        $bookings = $db->query("
            SELECT id, booking_number, client_name,
                   client_phone, '' AS client_email,
                   car_make, car_model, car_registration
            FROM service_bookings
            ORDER BY id DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e2) { $bookings = []; }
}

// Check items definition
$checkItems = [
    'tyres'      => ['Tyres',           'fa-circle-dot',     'text',   'Condition of all four tyres…'],
    'lights'     => ['Lights',          'fa-lightbulb',      'text',   'Head, tail, indicators…'],
    'exterior'   => ['Exterior Body',   'fa-car-side',       'text',   'Overall body condition…'],
    'engine'     => ['Engine Bay',      'fa-gears',          'text',   'Leaks, condition, noises…'],
    'interior'   => ['Interior',        'fa-couch',          'text',   'Dashboard, seats, trim…'],
    'brakes'     => ['Brakes',          'fa-circle-stop',    'text',   'Pads, discs, feel…'],
    'fluids'     => ['Fluid Levels',    'fa-droplet',        'text',   'Oil, coolant, brake fluid…'],
    'electrical' => ['Electrical',      'fa-microchip',      'text',   'Battery, fuses, wiring…'],
    'jack'       => ['Jack & Tools',    'fa-wrench',         'text',   'Jack present, wheel spanner…'],
    'radio'      => ['Radio / Audio',   'fa-radio',          'text',   'Working, channels, Bluetooth…'],
];
// Separate fields with special input types
$specialItems = [
    'dents'       => ['Dents / Scratches',    'fa-car-burst',   'textarea', 'Describe any dents, scratches, or paint damage…'],
    'items_left'  => ['Items Left in Car',    'fa-box-open',    'textarea', 'List any personal items found in the vehicle…'],
    'mileage'     => ['Mileage (km)',          'fa-gauge-high',  'number',   ''],
    'fuel_level'  => ['Fuel Level',            'fa-gas-pump',    'select',   ''],
];
$fuelLevels = ['Full', '3/4', 'Half (1/2)', '1/4', 'Empty', 'Not Checked'];

$overallOptions = [
    'good'            => ['success', 'fa-circle-check',         'Good',            'Vehicle in good overall condition'],
    'fair'            => ['warning', 'fa-circle-minus',         'Fair',            'Minor issues — serviceable'],
    'needs_attention' => ['primary', 'fa-triangle-exclamation', 'Needs Attention', 'Requires service soon'],
    'critical'        => ['danger',  'fa-circle-xmark',         'Critical',        'Urgent attention required'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingId   = (int)($_POST['service_booking_id'] ?? 0) ?: null;
    $carMake     = trim($_POST['car_make']  ?? '');
    $carModel    = trim($_POST['car_model'] ?? '');
    $carReg      = strtoupper(trim($_POST['car_registration'] ?? ''));
    $carYear     = (int)($_POST['car_year'] ?? 0) ?: null;
    $clientName  = trim($_POST['client_name']  ?? '');
    $clientPhone = trim($_POST['client_phone'] ?? '');
    $clientEmail = trim($_POST['client_email'] ?? '');
    $aDate       = $_POST['assessment_date'] ?? date('Y-m-d');
    $overall     = $_POST['overall_condition'] ?? '';
    $observations   = trim($_POST['observations']        ?? '');
    $recommended    = trim($_POST['recommended_services'] ?? '');
    $assessedBy     = trim($_POST['assessed_by'] ?? (authUser()['name'] ?? ''));

    // Gather all check fields
    $allChecks = array_merge(array_keys($checkItems), array_keys($specialItems));
    $checkVals = [];
    foreach ($allChecks as $c) {
        $v = trim($_POST["check_{$c}"] ?? '');
        $checkVals[$c] = $v !== '' ? $v : null;
    }

    if (!$carMake)                                          $errors[] = 'Vehicle make is required.';
    if (!array_key_exists($overall, $overallOptions))      $errors[] = 'Please select an overall condition.';
    if ($clientEmail && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        try {
            $num = nextNumber('quick_assessments', 'assessment_number', 'QA');
            $db->prepare("
                INSERT INTO quick_assessments
                    (assessment_number, assessment_date,
                     car_make, car_model, car_registration, car_year,
                     client_name, client_phone, client_email, service_booking_id,
                     check_tyres, check_lights, check_exterior, check_engine,
                     check_interior, check_brakes, check_fluids, check_electrical,
                     check_jack, check_dents, check_items_left, check_mileage, check_fuel_level, check_radio,
                     overall_condition, observations, recommended_services, assessed_by, created_by)
                VALUES (?,?,  ?,?,?,?,  ?,?,?,?,  ?,?,?,?,  ?,?,?,?,  ?,?,?,?,?,?,  ?,?,?,?,?)
            ")->execute([
                $num, $aDate,
                $carMake, $carModel, $carReg, $carYear,
                $clientName, $clientPhone, $clientEmail, $bookingId,
                $checkVals['tyres'],     $checkVals['lights'],  $checkVals['exterior'], $checkVals['engine'],
                $checkVals['interior'],  $checkVals['brakes'],  $checkVals['fluids'],   $checkVals['electrical'],
                $checkVals['jack'],      $checkVals['dents'],   $checkVals['items_left'],
                $checkVals['mileage'],   $checkVals['fuel_level'], $checkVals['radio'],
                $overall, $observations, $recommended, $assessedBy, authUser()['id'] ?? null,
            ]);
            $newId = (int)$db->lastInsertId();
            setFlash('success', "Quick assessment {$num} saved.");
            redirect(BASE_URL . '/modules/quick_assessments/view.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$p = $_POST; // shorthand for post values
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

    <!-- ── Service Booking (top, full-width) ───────────────────────────────── -->
    <div class="card mb-4 border-primary border-opacity-50">
        <div class="card-header fw-semibold bg-primary bg-opacity-10">
            <i class="fa fa-calendar-check me-2 text-primary"></i>Service Booked
            <span class="text-muted fw-normal small ms-1">— select to auto-fill client &amp; vehicle details</span>
        </div>
        <div class="card-body">
            <select name="service_booking_id" class="form-select select2" id="bookingSelect">
                <option value="">— No linked booking / Walk-in —</option>
                <?php foreach ($bookings as $bk): ?>
                <option value="<?= $bk['id'] ?>"
                        data-make="<?= e($bk['car_make'] ?? '') ?>"
                        data-model="<?= e($bk['car_model'] ?? '') ?>"
                        data-reg="<?= e($bk['car_registration'] ?? '') ?>"
                        data-client-name="<?= e($bk['client_name'] ?? '') ?>"
                        data-client-phone="<?= e($bk['client_phone'] ?? '') ?>"
                        data-client-email="<?= e($bk['client_email'] ?? '') ?>"
                        <?= (($p['service_booking_id'] ?? $preBookingId) == $bk['id']) ? 'selected' : '' ?>>
                    <?= e($bk['booking_number']) ?> — <?= e($bk['client_name'] ?: 'No name') ?>
                    <?php if ($bk['car_make']): ?> (<?= e($bk['car_make'].' '.($bk['car_model']??'')) ?>)<?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row g-4">

        <!-- ═══ LEFT COLUMN ═══════════════════════════════════════════════════ -->
        <div class="col-lg-7">

            <!-- Vehicle Details -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Make <span class="text-danger">*</span></label>
                            <input type="text" name="car_make" id="carMake" class="form-control"
                                   placeholder="e.g. Toyota"
                                   value="<?= e($p['car_make'] ?? $preBooking['car_make'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Model</label>
                            <input type="text" name="car_model" id="carModel" class="form-control"
                                   placeholder="e.g. Hilux, X5"
                                   value="<?= e($p['car_model'] ?? $preBooking['car_model'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Reg. No.</label>
                            <input type="text" name="car_registration" id="carReg" class="form-control text-uppercase"
                                   placeholder="KDA 000Q"
                                   value="<?= e($p['car_registration'] ?? $preBooking['car_registration'] ?? '') ?>"
                                   oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Year</label>
                            <input type="number" name="car_year" id="carYear" class="form-control"
                                   placeholder="2022" min="1970" max="2099"
                                   value="<?= e($p['car_year'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Check Items -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Quick Check</div>
                <div class="card-body">

                    <!-- Standard text checks (2-column grid) -->
                    <div class="row g-3 mb-3">
                        <?php foreach ($checkItems as $key => [$label, $icon, $type, $placeholder]): ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex align-items-center gap-2 mb-1">
                                <i class="fa <?= $icon ?> text-muted" style="width:16px;text-align:center"></i>
                                <?= $label ?>
                            </label>
                            <input type="text" name="check_<?= $key ?>" class="form-control form-control-sm"
                                   placeholder="<?= e($placeholder) ?>"
                                   value="<?= e($p["check_{$key}"] ?? '') ?>">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="my-3">

                    <!-- Special items (mileage, fuel, dents, items left) -->
                    <div class="row g-3">

                        <!-- Mileage -->
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold d-flex align-items-center gap-2 mb-1">
                                <i class="fa fa-gauge-high text-muted" style="width:16px;text-align:center"></i>
                                Mileage (km)
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="check_mileage" class="form-control"
                                       placeholder="e.g. 45000" min="0"
                                       value="<?= e($p['check_mileage'] ?? '') ?>">
                                <span class="input-group-text">km</span>
                            </div>
                        </div>

                        <!-- Fuel Level -->
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold d-flex align-items-center gap-2 mb-1">
                                <i class="fa fa-gas-pump text-muted" style="width:16px;text-align:center"></i>
                                Fuel Level
                            </label>
                            <select name="check_fuel_level" class="form-select form-select-sm">
                                <option value="">— Select —</option>
                                <?php foreach ($fuelLevels as $fl): ?>
                                <option value="<?= $fl ?>" <?= ($p['check_fuel_level'] ?? '') === $fl ? 'selected' : '' ?>><?= $fl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Radio (3rd in same row) -->
                        <div class="col-md-4" style="display:none"></div><!-- spacer -->

                        <!-- Dents / Scratches -->
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex align-items-center gap-2 mb-1">
                                <i class="fa fa-car-burst text-muted" style="width:16px;text-align:center"></i>
                                Dents / Scratches
                            </label>
                            <textarea name="check_dents" class="form-control form-control-sm" rows="3"
                                      placeholder="Describe any dents, scratches, or paint damage with location…"><?= e($p['check_dents'] ?? '') ?></textarea>
                        </div>

                        <!-- Items Left in Car -->
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold d-flex align-items-center gap-2 mb-1">
                                <i class="fa fa-box-open text-muted" style="width:16px;text-align:center"></i>
                                Items Left in Car
                            </label>
                            <textarea name="check_items_left" class="form-control form-control-sm" rows="3"
                                      placeholder="List any personal belongings found in the vehicle…"><?= e($p['check_items_left'] ?? '') ?></textarea>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Observations -->
            <div class="card mb-3">
                <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2 text-warning"></i>Observations &amp; Recommendations</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Observations / Issues Noted</label>
                        <textarea name="observations" class="form-control" rows="4"
                                  placeholder="Describe what you noticed during the quick check…"><?= e($p['observations'] ?? $preBooking['description'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Recommended Services</label>
                        <textarea name="recommended_services" class="form-control" rows="3"
                                  placeholder="e.g. Engine service, tyre replacement, brake pads…"><?= e($p['recommended_services'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-7 -->

        <!-- ═══ RIGHT COLUMN ══════════════════════════════════════════════════ -->
        <div class="col-lg-5">

            <!-- Client Details -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Client Name</label>
                        <input type="text" name="client_name" id="clientName" class="form-control"
                               placeholder="Full name"
                               value="<?= e($p['client_name'] ?? $preBooking['client_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-brands fa-whatsapp text-success"></i></span>
                            <input type="text" name="client_phone" id="clientPhone" class="form-control"
                                   placeholder="e.g. 0712 345 678"
                                   value="<?= e($p['client_phone'] ?? $preBooking['client_phone'] ?? '') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Email Address</label>
                        <input type="email" name="client_email" id="clientEmail" class="form-control"
                               placeholder="client@email.com"
                               value="<?= e($p['client_email'] ?? $preBooking['client_email'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Assessment Info -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa fa-sliders me-2 text-primary"></i>Assessment Info</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="assessment_date" class="form-control" required
                               value="<?= e($p['assessment_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label class="form-label small fw-semibold">Assessed By</label>
                        <input type="text" name="assessed_by" class="form-control"
                               value="<?= e($p['assessed_by'] ?? (authUser()['name'] ?? '')) ?>">
                    </div>
                </div>
            </div>

            <!-- Overall Condition -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa fa-gauge me-2 text-primary"></i>Overall Condition <span class="text-danger">*</span></div>
                <div class="card-body">
                    <select name="overall_condition" id="overallCondition" class="form-select mb-3" required>
                        <option value="">— Select Condition —</option>
                        <?php
                        $selCond = $p['overall_condition'] ?? 'fair';
                        foreach ($overallOptions as $ckey => [$col, $ico, $lbl, $desc]):
                        ?>
                        <option value="<?= $ckey ?>" <?= $selCond === $ckey ? 'selected' : '' ?>>
                            <?= $lbl ?> — <?= $desc ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <!-- Visual condition badge (updates on select change) -->
                    <div id="condBadge" class="p-3 rounded-3 d-flex align-items-center gap-3" style="display:none!important">
                        <?php foreach ($overallOptions as $ckey => [$col, $ico, $lbl, $desc]): ?>
                        <div class="cond-display border-<?= $col ?> bg-<?= $col ?> bg-opacity-10 border rounded-3 p-3 d-flex align-items-center gap-3 w-100 <?= $selCond !== $ckey ? 'd-none' : '' ?>" data-cond="<?= $ckey ?>">
                            <i class="fa <?= $ico ?> fa-lg text-<?= $col ?>"></i>
                            <div>
                                <div class="fw-bold text-<?= $col ?>" style="font-size:13px"><?= $lbl ?></div>
                                <div class="text-muted" style="font-size:11px"><?= $desc ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fa fa-save me-2"></i>Save Assessment
                </button>
            </div>

        </div><!-- /col-lg-5 -->
    </div><!-- /row -->
</form>

<script>
$(function() {

    // ── Service Booking → auto-fill all fields ──────────────────────────────
    $('#bookingSelect').on('change', function() {
        const opt = this.options[this.selectedIndex];
        if (!opt || !opt.value) return;

        const fill = (id, val) => { const el = document.getElementById(id); if (el && val) el.value = val; };

        fill('carMake',     opt.dataset.make);
        fill('carModel',    opt.dataset.model);
        fill('carReg',      opt.dataset.reg  ? opt.dataset.reg.toUpperCase() : '');
        fill('clientName',  opt.dataset.clientName);
        fill('clientPhone', opt.dataset.clientPhone);
        fill('clientEmail', opt.dataset.clientEmail);
    });

    // ── Overall Condition select → show coloured badge below ────────────────
    function updateCondBadge() {
        const val = document.getElementById('overallCondition').value;
        document.querySelectorAll('.cond-display').forEach(el => {
            el.classList.toggle('d-none', el.dataset.cond !== val);
        });
        document.getElementById('condBadge').style.removeProperty('display');
        document.getElementById('condBadge').style.display = val ? '' : 'none';
    }
    document.getElementById('overallCondition').addEventListener('change', updateCondBadge);
    updateCondBadge(); // run on load for pre-selected value

    // ── Pre-select booking if coming from URL param ──────────────────────────
    <?php if ($preBookingId): ?>
    $('#bookingSelect').trigger('change');
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
