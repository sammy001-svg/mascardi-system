<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canWrite('trade_in') || die('Permission denied.');

$db = getDB();
tradeInMigrate($db);

$types = consignmentTypes();
$user  = authUser();

$dealType = $_POST['deal_type'] ?? $_GET['type'] ?? 'sale_on_behalf';
if (!isset($types[$dealType])) $dealType = 'sale_on_behalf';
$isTrade = $dealType === 'trade_in';

$errors = [];
$d = [
    // Vehicle
    'chassis_number'      => '', 'registration_number' => '', 'make' => '', 'model' => '',
    'year'                => date('Y'), 'color' => '', 'body_type' => '', 'transmission' => 'manual',
    'fuel_type'           => 'petrol', 'mileage' => '', 'engine_cc' => '', 'location_id' => 1,
    // Owner
    'owner_name'          => '', 'owner_phone' => '', 'owner_email' => '',
    'owner_id_number'     => '', 'owner_address' => '', 'client_id' => '',
    // Commercials
    'owner_expected_price'=> '', 'listing_price' => '', 'commission_type' => 'percent',
    'commission_value'    => '10',
    'valuation_amount'    => '', 'trade_in_value' => '', 'against_car_id' => '',
    'agreement_date'      => date('Y-m-d'), 'expiry_date' => date('Y-m-d', strtotime('+90 days')),
    'notes'               => '', 'show_on_website' => $isTrade ? 0 : 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach (array_keys($d) as $k) {
        if ($k === 'show_on_website') { $d[$k] = isset($_POST[$k]) ? 1 : 0; continue; }
        $d[$k] = trim((string)($_POST[$k] ?? ''));
    }

    // ── Validation ────────────────────────────────────────────────────────────
    if (!$d['chassis_number']) $errors[] = 'Chassis number is required.';
    if (!$d['make'])           $errors[] = 'Make is required.';
    if (!$d['model'])          $errors[] = 'Model is required.';
    if (!$d['year'])           $errors[] = 'Year is required.';
    if (!$d['owner_name'])     $errors[] = 'Owner name is required.';
    if (!$d['owner_phone'])    $errors[] = 'Owner phone is required.';
    if ($d['owner_email'] && !filter_var($d['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Owner email is not a valid address.';
    }

    if ($isTrade) {
        if ($d['trade_in_value'] === '' || (float)$d['trade_in_value'] <= 0) {
            $errors[] = 'Trade-in allowance is required.';
        }
    } else {
        if ($d['listing_price'] === '' || (float)$d['listing_price'] <= 0) {
            $errors[] = 'Listing price is required.';
        }
        if ($d['commission_value'] === '' || (float)$d['commission_value'] < 0) {
            $errors[] = 'Commission is required.';
        }
        if ($d['commission_type'] === 'percent' && (float)$d['commission_value'] > 100) {
            $errors[] = 'Percentage commission cannot exceed 100%.';
        }
        if ($d['commission_type'] === 'fixed' && (float)$d['commission_value'] > (float)$d['listing_price']) {
            $errors[] = 'Fixed commission cannot exceed the listing price.';
        }
        if ($d['expiry_date'] && $d['agreement_date'] && $d['expiry_date'] < $d['agreement_date']) {
            $errors[] = 'Agreement expiry cannot be before the agreement date.';
        }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $num = fn($v) => ($v === '' || $v === null) ? null : (float)$v;
            $int = fn($v) => ($v === '' || $v === null) ? null : (int)$v;

            // The vehicle itself. Consignment cars are only advertised while the
            // deal is active, so a trade-in defaults to hidden.
            $db->prepare("INSERT INTO cars
                (chassis_number, registration_number, make, model, year, color, body_type,
                 transmission, fuel_type, car_type, owner_name, owner_phone, client_id,
                 location_id, mileage, engine_cc, asking_price, notes, status, show_on_website)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'arrived',?)")
               ->execute([
                   $d['chassis_number'], $d['registration_number'], $d['make'], $d['model'],
                   (int)$d['year'], $d['color'], $d['body_type'], $d['transmission'], $d['fuel_type'],
                   $dealType, $d['owner_name'], $d['owner_phone'], $int($d['client_id']),
                   (int)($d['location_id'] ?: 1), $int($d['mileage']), $int($d['engine_cc']),
                   $isTrade ? $num($d['valuation_amount']) : $num($d['listing_price']),
                   $d['notes'], $d['show_on_website'],
               ]);
            $carId = (int)$db->lastInsertId();

            $ref = consignmentNextRef($db, $dealType);
            $db->prepare("INSERT INTO consignments
                (car_id, deal_type, reference, owner_name, owner_phone, owner_email,
                 owner_id_number, owner_address, client_id, owner_expected_price, listing_price,
                 commission_type, commission_value, valuation_amount, trade_in_value, against_car_id,
                 agreement_date, expiry_date, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'active',?,?)")
               ->execute([
                   $carId, $dealType, $ref, $d['owner_name'], $d['owner_phone'], $d['owner_email'],
                   $d['owner_id_number'], $d['owner_address'], $int($d['client_id']),
                   $num($d['owner_expected_price']), $num($d['listing_price']),
                   $d['commission_type'], (float)($d['commission_value'] ?: 0),
                   $num($d['valuation_amount']), $num($d['trade_in_value']), $int($d['against_car_id']),
                   $d['agreement_date'] ?: null, $isTrade ? null : ($d['expiry_date'] ?: null),
                   $d['notes'], (int)$user['id'],
               ]);
            $consId = (int)$db->lastInsertId();

            $db->commit();

            logActivity('create', 'trade_in', $consId,
                "{$types[$dealType]['label']} {$ref} — {$d['make']} {$d['model']} for {$d['owner_name']}");

            setFlash('success', "{$types[$dealType]['label']} {$ref} recorded successfully.");
            redirect(BASE_URL . '/modules/trade_in/view.php?id=' . $consId);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = ($e instanceof PDOException && $e->getCode() === '23000')
                ? 'That chassis number already exists in the system.'
                : 'Could not save: ' . $e->getMessage();
        }
    }
}

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$clients   = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$stockCars = $isTrade
    ? $db->query("SELECT id, make, model, year, registration_number, chassis_number
                  FROM cars WHERE car_type='inventory' ORDER BY make, model LIMIT 500")->fetchAll()
    : [];

$pageTitle = 'New ' . $types[$dealType]['label'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fa <?= $types[$dealType]['icon'] ?> me-2" style="color:<?= $types[$dealType]['color'] ?>"></i>
        New <?= $types[$dealType]['label'] ?>
    </h5>
    <a href="index.php?tab=<?= $dealType ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<!-- Deal type switcher -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php foreach ($types as $key => $t): ?>
    <a href="?type=<?= $key ?>" class="btn btn-sm <?= $dealType === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <i class="fa <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="alert alert-info py-2 small">
    <i class="fa fa-circle-info me-1"></i>
    <?= $isTrade
        ? 'A trade-in becomes company stock. Record what you valued it at and the allowance credited to the customer — you can convert it to normal inventory once it is ready to resell.'
        : 'The vehicle stays owned by the customer. It can be advertised on the public website, and when it sells you keep the commission and pay the balance to the owner.' ?>
</div>

<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="deal_type" value="<?= e($dealType) ?>">

<div class="row g-3">
    <!-- ── Vehicle ─────────────────────────────────────────────────────────── -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-car-side me-2"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                        <input type="text" name="chassis_number" class="form-control" required
                               value="<?= e($d['chassis_number']) ?>" placeholder="e.g. JTEBT9FJ60K056783">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="registration_number" class="form-control"
                               value="<?= e($d['registration_number']) ?>" placeholder="e.g. KCA 123A">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Make <span class="text-danger">*</span></label>
                        <input type="text" name="make" class="form-control" required
                               value="<?= e($d['make']) ?>" placeholder="Toyota">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model <span class="text-danger">*</span></label>
                        <input type="text" name="model" class="form-control" required
                               value="<?= e($d['model']) ?>" placeholder="Land Cruiser">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Year <span class="text-danger">*</span></label>
                        <input type="number" name="year" class="form-control" required min="1980" max="<?= date('Y') + 1 ?>"
                               value="<?= e((string)$d['year']) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Color</label>
                        <input type="text" name="color" class="form-control" value="<?= e($d['color']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Body Type</label>
                        <select name="body_type" class="form-select">
                            <option value="">Select…</option>
                            <?php foreach (['Saloon','SUV','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                            <option value="<?= $bt ?>" <?= $d['body_type'] === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Transmission</label>
                        <select name="transmission" class="form-select">
                            <?php foreach (['manual' => 'Manual','automatic' => 'Automatic','cvt' => 'CVT'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $d['transmission'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Fuel</label>
                        <select name="fuel_type" class="form-select">
                            <?php foreach (['petrol','diesel','hybrid','electric'] as $ft): ?>
                            <option value="<?= $ft ?>" <?= $d['fuel_type'] === $ft ? 'selected' : '' ?>><?= ucfirst($ft) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Mileage <small class="text-muted">(km)</small></label>
                        <input type="number" name="mileage" class="form-control" min="0" value="<?= e((string)$d['mileage']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Engine <small class="text-muted">(cc)</small></label>
                        <input type="number" name="engine_cc" class="form-control" min="0" value="<?= e((string)$d['engine_cc']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Location</label>
                        <select name="location_id" class="form-select">
                            <?php foreach ($locations as $l): ?>
                            <option value="<?= $l['id'] ?>" <?= (int)$d['location_id'] === (int)$l['id'] ? 'selected' : '' ?>>
                                <?= e($l['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description / Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Condition, service history, features — also used as the public description"><?= e($d['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Owner + commercials ─────────────────────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-user me-2"></i>Vehicle Owner</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Link to Client Account <small class="text-muted">(optional)</small></label>
                        <select name="client_id" id="clientSel" class="form-select select2">
                            <option value="">— Not linked —</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                    data-name="<?= e($cl['name']) ?>"
                                    data-phone="<?= e($cl['phone'] ?? '') ?>"
                                    data-email="<?= e($cl['email'] ?? '') ?>"
                                    <?= (int)$d['client_id'] === (int)$cl['id'] ? 'selected' : '' ?>>
                                <?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="owner_name" id="ownerName" class="form-control" required value="<?= e($d['owner_name']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="owner_phone" id="ownerPhone" class="form-control" required value="<?= e($d['owner_phone']) ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Email</label>
                        <input type="email" name="owner_email" id="ownerEmail" class="form-control" value="<?= e($d['owner_email']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">ID / Passport</label>
                        <input type="text" name="owner_id_number" class="form-control" value="<?= e($d['owner_id_number']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="owner_address" class="form-control" value="<?= e($d['owner_address']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="fa fa-file-signature me-2"></i><?= $isTrade ? 'Valuation & Allowance' : 'Commercial Terms' ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                <?php if ($isTrade): ?>
                    <div class="col-md-6">
                        <label class="form-label">Our Valuation <small class="text-muted">(KES)</small></label>
                        <input type="number" name="valuation_amount" class="form-control" step="1" min="0"
                               value="<?= e((string)$d['valuation_amount']) ?>" placeholder="Appraised market value">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trade-In Allowance <span class="text-danger">*</span> <small class="text-muted">(KES)</small></label>
                        <input type="number" name="trade_in_value" class="form-control" step="1" min="0" required
                               value="<?= e((string)$d['trade_in_value']) ?>" placeholder="Credited to customer">
                        <div class="form-text">Amount deducted from the price of the vehicle they are buying.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Traded Against <small class="text-muted">(vehicle purchased)</small></label>
                        <select name="against_car_id" class="form-select select2">
                            <option value="">— Not specified —</option>
                            <?php foreach ($stockCars as $sc): ?>
                            <option value="<?= $sc['id'] ?>" <?= (int)$d['against_car_id'] === (int)$sc['id'] ? 'selected' : '' ?>>
                                <?= e($sc['year'] . ' ' . $sc['make'] . ' ' . $sc['model']) ?>
                                — <?= e($sc['registration_number'] ?: $sc['chassis_number']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date Received</label>
                        <input type="date" name="agreement_date" class="form-control" value="<?= e($d['agreement_date']) ?>">
                    </div>
                <?php else: ?>
                    <div class="col-md-6">
                        <label class="form-label">Listing Price <span class="text-danger">*</span> <small class="text-muted">(KES)</small></label>
                        <input type="number" name="listing_price" id="listingPrice" class="form-control" step="1" min="0" required
                               value="<?= e((string)$d['listing_price']) ?>" placeholder="Advertised price">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Owner Expects <small class="text-muted">(KES, optional)</small></label>
                        <input type="number" name="owner_expected_price" class="form-control" step="1" min="0"
                               value="<?= e((string)$d['owner_expected_price']) ?>" placeholder="Net to owner">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Commission Type</label>
                        <select name="commission_type" id="commType" class="form-select">
                            <option value="percent" <?= $d['commission_type'] === 'percent' ? 'selected' : '' ?>>Percentage</option>
                            <option value="fixed"   <?= $d['commission_type'] === 'fixed'   ? 'selected' : '' ?>>Fixed amount</option>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Commission <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="commission_value" id="commValue" class="form-control" step="0.01" min="0" required
                                   value="<?= e((string)$d['commission_value']) ?>">
                            <span class="input-group-text" id="commUnit"><?= $d['commission_type'] === 'fixed' ? 'KES' : '%' ?></span>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="d-flex justify-content-between" style="font-size:12.5px">
                                <span class="text-muted">Your commission</span>
                                <span class="fw-bold" id="calcComm" style="color:#15803d">KES 0</span>
                            </div>
                            <div class="d-flex justify-content-between mt-1" style="font-size:12.5px">
                                <span class="text-muted">Owner receives</span>
                                <span class="fw-bold" id="calcPayout" style="color:#c2410c">KES 0</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Agreement Date</label>
                        <input type="date" name="agreement_date" class="form-control" value="<?= e($d['agreement_date']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Agreement Expires</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= e($d['expiry_date']) ?>">
                    </div>
                <?php endif; ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="show_on_website" id="showWeb" class="form-check-input" value="1"
                                   <?= $d['show_on_website'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="showWeb">
                                <i class="fa fa-globe text-success me-1"></i>Advertise on public website
                                <div class="text-muted fw-normal" style="font-size:11.5px">
                                    <?= $isTrade
                                        ? 'Usually off until the vehicle is reconditioned and moved to inventory.'
                                        : 'Shows in the public showroom while the agreement is active.' ?>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary px-4">
        <i class="fa fa-save me-1"></i>Save <?= $types[$dealType]['label'] ?>
    </button>
    <a href="index.php?tab=<?= $dealType ?>" class="btn btn-outline-secondary">Cancel</a>
</div>
</form>

<script>
// Autofill owner details from a linked client account
document.getElementById('clientSel')?.addEventListener('change', function () {
    var o = this.options[this.selectedIndex];
    if (!o || !o.value) return;
    var set = function (id, val) {
        var el = document.getElementById(id);
        if (el && !el.value) el.value = val || '';
    };
    set('ownerName',  o.getAttribute('data-name'));
    set('ownerPhone', o.getAttribute('data-phone'));
    set('ownerEmail', o.getAttribute('data-email'));
});

// Live commission / payout preview
(function () {
    var price = document.getElementById('listingPrice');
    var type  = document.getElementById('commType');
    var value = document.getElementById('commValue');
    var unit  = document.getElementById('commUnit');
    var oComm = document.getElementById('calcComm');
    var oPay  = document.getElementById('calcPayout');
    if (!price || !type || !value) return;

    function fmt(n) { return 'KES ' + Math.round(n).toLocaleString(); }
    function recalc() {
        var p = parseFloat(price.value) || 0;
        var v = parseFloat(value.value) || 0;
        var comm = type.value === 'fixed' ? Math.min(v, p) : (p * v / 100);
        if (comm < 0) comm = 0;
        unit.textContent = type.value === 'fixed' ? 'KES' : '%';
        oComm.textContent = fmt(comm);
        oPay.textContent  = fmt(Math.max(0, p - comm));
    }
    [price, type, value].forEach(function (el) {
        el.addEventListener('input',  recalc);
        el.addEventListener('change', recalc);
    });
    recalc();
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
