<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canWrite('trade_in') || die('Permission denied.');

$db = getDB();
tradeInMigrate($db);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c  = $id ? consignmentFind($db, $id) : null;
if (!$c) { setFlash('error', 'Record not found.'); redirect(BASE_URL . '/modules/trade_in/index.php'); }

$types    = consignmentTypes();
$dealType = $c['deal_type'];
$isTrade  = $dealType === 'trade_in';
$errors   = [];

// Seed the form from the stored record, then overlay any POSTed values.
$d = [
    'registration_number' => $c['registration_number'], 'make' => $c['make'], 'model' => $c['model'],
    'year' => $c['year'], 'color' => $c['color'], 'body_type' => $c['body_type'],
    'transmission' => $c['transmission'], 'fuel_type' => $c['fuel_type'],
    'mileage' => $c['mileage'], 'engine_cc' => $c['engine_cc'], 'location_id' => $c['location_id'],
    'owner_name' => $c['owner_name'], 'owner_phone' => $c['owner_phone'], 'owner_email' => $c['owner_email'],
    'owner_id_number' => $c['owner_id_number'], 'owner_address' => $c['owner_address'],
    'client_id' => $c['client_id'],
    'owner_expected_price' => $c['owner_expected_price'], 'listing_price' => $c['listing_price'],
    'commission_type' => $c['commission_type'], 'commission_value' => $c['commission_value'],
    'valuation_amount' => $c['valuation_amount'], 'trade_in_value' => $c['trade_in_value'],
    'against_car_id' => $c['against_car_id'],
    'agreement_date' => $c['agreement_date'], 'expiry_date' => $c['expiry_date'],
    'notes' => $c['car_notes'], 'show_on_website' => (int)$c['show_on_website'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach (array_keys($d) as $k) {
        if ($k === 'show_on_website') { $d[$k] = isset($_POST[$k]) ? 1 : 0; continue; }
        $d[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if (!$d['make'])        $errors[] = 'Make is required.';
    if (!$d['model'])       $errors[] = 'Model is required.';
    if (!$d['year'])        $errors[] = 'Year is required.';
    if (!$d['owner_name'])  $errors[] = 'Owner name is required.';
    if (!$d['owner_phone']) $errors[] = 'Owner phone is required.';
    if ($d['owner_email'] && !filter_var($d['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Owner email is not a valid address.';
    }
    if (!$isTrade) {
        if ($d['listing_price'] === '' || (float)$d['listing_price'] <= 0) $errors[] = 'Listing price is required.';
        if ($d['commission_type'] === 'percent' && (float)$d['commission_value'] > 100) {
            $errors[] = 'Percentage commission cannot exceed 100%.';
        }
        if ($d['commission_type'] === 'fixed' && (float)$d['commission_value'] > (float)$d['listing_price']) {
            $errors[] = 'Fixed commission cannot exceed the listing price.';
        }
        if ($d['expiry_date'] && $d['agreement_date'] && $d['expiry_date'] < $d['agreement_date']) {
            $errors[] = 'Agreement expiry cannot be before the agreement date.';
        }
    } elseif ($d['trade_in_value'] === '' || (float)$d['trade_in_value'] <= 0) {
        $errors[] = 'Trade-in allowance is required.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $num = fn($v) => ($v === '' || $v === null) ? null : (float)$v;
            $int = fn($v) => ($v === '' || $v === null) ? null : (int)$v;

            $db->prepare("UPDATE cars SET
                    registration_number=?, make=?, model=?, year=?, color=?, body_type=?,
                    transmission=?, fuel_type=?, owner_name=?, owner_phone=?, client_id=?,
                    location_id=?, mileage=?, engine_cc=?, asking_price=?, notes=?, show_on_website=?
                 WHERE id=?")
               ->execute([
                   $d['registration_number'], $d['make'], $d['model'], (int)$d['year'], $d['color'],
                   $d['body_type'], $d['transmission'], $d['fuel_type'], $d['owner_name'], $d['owner_phone'],
                   $int($d['client_id']), (int)($d['location_id'] ?: 1), $int($d['mileage']), $int($d['engine_cc']),
                   $isTrade ? $num($d['valuation_amount']) : $num($d['listing_price']),
                   $d['notes'], $d['show_on_website'], (int)$c['car_id'],
               ]);

            $db->prepare("UPDATE consignments SET
                    owner_name=?, owner_phone=?, owner_email=?, owner_id_number=?, owner_address=?,
                    client_id=?, owner_expected_price=?, listing_price=?, commission_type=?, commission_value=?,
                    valuation_amount=?, trade_in_value=?, against_car_id=?, agreement_date=?, expiry_date=?, notes=?
                 WHERE id=?")
               ->execute([
                   $d['owner_name'], $d['owner_phone'], $d['owner_email'], $d['owner_id_number'],
                   $d['owner_address'], $int($d['client_id']), $num($d['owner_expected_price']),
                   $num($d['listing_price']), $d['commission_type'], (float)($d['commission_value'] ?: 0),
                   $num($d['valuation_amount']), $num($d['trade_in_value']), $int($d['against_car_id']),
                   $d['agreement_date'] ?: null, $isTrade ? null : ($d['expiry_date'] ?: null),
                   $d['notes'], $id,
               ]);

            $db->commit();
            logActivity('update', 'trade_in', $id, "Updated {$c['reference']} — {$d['make']} {$d['model']}");
            setFlash('success', 'Record updated.');
            redirect(BASE_URL . '/modules/trade_in/view.php?id=' . $id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Could not save: ' . $e->getMessage();
        }
    }
}

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$clients   = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$stockCars = $isTrade
    ? $db->query("SELECT id, make, model, year, registration_number, chassis_number
                  FROM cars WHERE car_type='inventory' ORDER BY make, model LIMIT 500")->fetchAll()
    : [];

$pageTitle = 'Edit ' . $c['reference'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fa <?= $types[$dealType]['icon'] ?> me-2" style="color:<?= $types[$dealType]['color'] ?>"></i>
        Edit <?= e($c['reference'] ?: $types[$dealType]['label']) ?>
    </h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $er) echo '<li>' . e($er) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php if ($c['status'] === 'sold'): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa fa-lock me-1"></i>
    This deal is already settled. Editing the commercial terms will not change the recorded
    sale figures — use the settlement panel on the detail page for those.
</div>
<?php endif; ?>

<form method="POST">
<?= csrfField() ?>
<input type="hidden" name="id" value="<?= $id ?>">

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-car-side me-2"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Chassis Number</label>
                        <input type="text" class="form-control" value="<?= e($c['chassis_number']) ?>" disabled>
                        <div class="form-text">Change the chassis from the vehicle record.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="registration_number" class="form-control" value="<?= e($d['registration_number']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Make <span class="text-danger">*</span></label>
                        <input type="text" name="make" class="form-control" required value="<?= e($d['make']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model <span class="text-danger">*</span></label>
                        <input type="text" name="model" class="form-control" required value="<?= e($d['model']) ?>">
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
                        <textarea name="notes" class="form-control" rows="2"><?= e((string)$d['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-user me-2"></i>Vehicle Owner</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Link to Client Account</label>
                        <select name="client_id" class="form-select select2">
                            <option value="">— Not linked —</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>" <?= (int)$d['client_id'] === (int)$cl['id'] ? 'selected' : '' ?>>
                                <?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="owner_name" class="form-control" required value="<?= e($d['owner_name']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="owner_phone" class="form-control" required value="<?= e($d['owner_phone']) ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Email</label>
                        <input type="email" name="owner_email" class="form-control" value="<?= e((string)$d['owner_email']) ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">ID / Passport</label>
                        <input type="text" name="owner_id_number" class="form-control" value="<?= e((string)$d['owner_id_number']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <input type="text" name="owner_address" class="form-control" value="<?= e((string)$d['owner_address']) ?>">
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
                               value="<?= e((string)$d['valuation_amount']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Trade-In Allowance <span class="text-danger">*</span></label>
                        <input type="number" name="trade_in_value" class="form-control" step="1" min="0" required
                               value="<?= e((string)$d['trade_in_value']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Traded Against</label>
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
                        <input type="date" name="agreement_date" class="form-control" value="<?= e((string)$d['agreement_date']) ?>">
                    </div>
                <?php else: ?>
                    <div class="col-md-6">
                        <label class="form-label">Listing Price <span class="text-danger">*</span></label>
                        <input type="number" name="listing_price" id="listingPrice" class="form-control" step="1" min="0" required
                               value="<?= e((string)$d['listing_price']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Owner Expects <small class="text-muted">(optional)</small></label>
                        <input type="number" name="owner_expected_price" class="form-control" step="1" min="0"
                               value="<?= e((string)$d['owner_expected_price']) ?>">
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
                        <input type="date" name="agreement_date" class="form-control" value="<?= e((string)$d['agreement_date']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Agreement Expires</label>
                        <input type="date" name="expiry_date" class="form-control" value="<?= e((string)$d['expiry_date']) ?>">
                    </div>
                <?php endif; ?>
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" name="show_on_website" id="showWeb" class="form-check-input" value="1"
                                   <?= $d['show_on_website'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="showWeb">
                                <i class="fa fa-globe text-success me-1"></i>Advertise on public website
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-1"></i>Save Changes</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
</div>
</form>

<script>
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
