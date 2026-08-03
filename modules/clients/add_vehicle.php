<?php
/**
 * Clients — register another vehicle for a client.
 *
 * Deliberately separate from modules/cars/add.php. That form exists to put a
 * vehicle on the forecourt, so it asks for asking price, offer price, website
 * visibility, description, features and SEO metadata. A customer's own car is
 * none of those things — it is here to be serviced — so every one of those
 * fields would be noise the user has to scroll past and ignore.
 *
 * The field set matches the vehicle section of the client registration form
 * (modules/clients/add.php), plus the few extras a workshop actually needs.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('clients') || die('Access denied.');

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['client_id'] ?? 0);

// Registering a customer's vehicle is part of looking after that customer, so
// it follows client rights. Full 'cars' rights work too, for staff who have them.
if (!canWrite('clients') && !canWrite('cars')) {
    die('Permission denied.');
}

$client = $db->prepare("SELECT * FROM clients WHERE id = ?");
$client->execute([$id]);
$client = $client->fetch(PDO::FETCH_ASSOC);
if (!$client) { setFlash('error', 'Client not found.'); redirect(BASE_URL . '/modules/clients/index.php'); }

$errors = [];
$d = [
    'make' => '', 'model' => '', 'year' => date('Y'), 'chassis_number' => '',
    'registration_number' => '', 'color' => '', 'mileage' => '',
    'transmission' => 'automatic', 'fuel_type' => 'petrol',
    'engine_number' => '', 'body_type' => '', 'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($d as $k => $_) $d[$k] = trim($_POST[$k] ?? '');

    if ($d['make'] === '')           $errors[] = 'Make is required.';
    if ($d['model'] === '')          $errors[] = 'Model is required.';
    if ($d['chassis_number'] === '') $errors[] = 'Chassis number is required.';
    if (!(int)$d['year'])            $errors[] = 'Year is required.';

    // Check the chassis up front and name the clash, rather than letting the
    // insert fail with an integrity error that has to be guessed at.
    if ($d['chassis_number'] !== '' && !$errors) {
        $c = $db->prepare("SELECT id, make, model, car_type, status FROM cars WHERE chassis_number = ?");
        $c->execute([$d['chassis_number']]);
        if ($clash = $c->fetch(PDO::FETCH_ASSOC)) {
            $errors[] = 'That chassis number is already registered — '
                . trim($clash['make'] . ' ' . $clash['model']) . ' (' . $clash['car_type']
                . ', ' . ($clash['status'] ?: 'no status') . '). '
                . 'Open ' . BASE_URL . '/modules/cars/view.php?id=' . $clash['id'] . ' to check it.';
        }
    }

    if (!$errors) {
        try {
            // car_type and client are set here, not taken from the request —
            // this page only ever produces a vehicle belonging to this client.
            $db->prepare("INSERT INTO cars
                    (chassis_number, registration_number, make, model, year, color, mileage,
                     transmission, fuel_type, engine_number, body_type, notes,
                     car_type, owner_name, owner_phone, client_id, location_id, status, show_on_website)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'client', ?, ?, ?, NULL, 'completed', 0)")
               ->execute([
                   $d['chassis_number'], $d['registration_number'] ?: null,
                   $d['make'], $d['model'], (int)$d['year'], $d['color'] ?: null,
                   $d['mileage'] !== '' ? (int)$d['mileage'] : null,
                   $d['transmission'] ?: 'automatic', $d['fuel_type'] ?: 'petrol',
                   $d['engine_number'] ?: null, $d['body_type'] ?: null, $d['notes'] ?: null,
                   $client['name'], $client['phone'] ?: null, $id,
               ]);
            $carId = (int)$db->lastInsertId();
            logActivity('create', 'cars', $carId,
                "Added vehicle {$d['make']} {$d['model']} ({$d['chassis_number']}) for client {$client['name']}");
            setFlash('success', $d['make'] . ' ' . $d['model'] . ' added to ' . $client['name'] . '.');
            redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
        } catch (\PDOException $e) {
            error_log('clients/add_vehicle: ' . $e->getMessage());
            $errors[] = 'Could not save the vehicle: ' . $e->getMessage();
        }
    }
}

$existing = $db->prepare("SELECT make, model, year, chassis_number FROM cars WHERE client_id = ? ORDER BY id");
$existing->execute([$id]);
$existing = $existing->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Add Vehicle — ' . $client['name'];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.av-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); margin-bottom:16px; overflow:hidden; }
.av-head{ padding:13px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.av-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.av-title i{ color:var(--brand); }
.av-body{ padding:18px 16px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
.av-owner{ display:flex; align-items:center; gap:11px; padding:12px 16px;
    background:var(--brand-soft); border-bottom:1px solid var(--border); }
.av-av{ width:38px; height:38px; border-radius:50%; flex:0 0 38px; background:var(--brand); color:#fff;
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; }
.av-have{ font-size:12px; color:var(--text-2,#64748b); padding:9px 16px; border-top:1px solid var(--border); }
.av-have code{ font-size:11px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 style="font-size:20px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
        <i class="fa fa-car-side me-2" style="color:var(--brand)"></i>Add Vehicle
    </h1>
    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to client
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="client_id" value="<?= $id ?>">

            <div class="av-card">
                <div class="av-owner">
                    <span class="av-av">
                        <?php
                        $parts = array_values(array_filter(preg_split('/\s+/', trim($client['name'])) ?: []));
                        echo e(mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1)
                             . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')));
                        ?>
                    </span>
                    <div>
                        <div style="font-size:13.5px;font-weight:700;color:var(--text)"><?= e($client['name']) ?></div>
                        <div style="font-size:12px;color:var(--text-2,#64748b)">
                            This vehicle will be registered to this client
                            <?= $client['phone'] ? ' · ' . e($client['phone']) : '' ?>
                        </div>
                    </div>
                </div>

                <div class="av-head"><h2 class="av-title"><i class="fa fa-circle-info"></i>Vehicle Details</h2></div>
                <div class="av-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Make <span class="text-danger">*</span></label>
                            <input type="text" name="make" class="form-control" required
                                   value="<?= e($d['make']) ?>" placeholder="e.g. Toyota" autofocus>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model <span class="text-danger">*</span></label>
                            <input type="text" name="model" class="form-control" required
                                   value="<?= e($d['model']) ?>" placeholder="e.g. Prado">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" name="year" class="form-control" required
                                   min="1950" max="<?= date('Y') + 1 ?>" value="<?= e($d['year']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                            <input type="text" name="chassis_number" class="form-control" required
                                   value="<?= e($d['chassis_number']) ?>" placeholder="e.g. JTEBH9FJ0EK123456">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Registration Number</label>
                            <input type="text" name="registration_number" class="form-control"
                                   value="<?= e($d['registration_number']) ?>" placeholder="e.g. KDA 123X">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Colour</label>
                            <input type="text" name="color" class="form-control" value="<?= e($d['color']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Mileage (km)</label>
                            <input type="number" name="mileage" class="form-control" min="0"
                                   value="<?= e($d['mileage']) ?>" placeholder="e.g. 84000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Body Type</label>
                            <input type="text" name="body_type" class="form-control"
                                   value="<?= e($d['body_type']) ?>" placeholder="e.g. SUV">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Transmission</label>
                            <select name="transmission" class="form-select">
                                <?php foreach (['automatic' => 'Automatic', 'manual' => 'Manual'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= $d['transmission'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fuel</label>
                            <select name="fuel_type" class="form-select">
                                <?php foreach (['petrol','diesel','hybrid','electric'] as $f): ?>
                                <option value="<?= $f ?>" <?= $d['fuel_type'] === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Engine Number</label>
                            <input type="text" name="engine_number" class="form-control" value="<?= e($d['engine_number']) ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" rows="2" class="form-control"
                                      placeholder="Anything worth recording about this vehicle"><?= e($d['notes']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save Vehicle</button>
                        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="av-card">
            <div class="av-head">
                <h2 class="av-title"><i class="fa fa-car"></i>Already Registered</h2>
                <span class="small text-muted"><?= count($existing) ?></span>
            </div>
            <?php if (!$existing): ?>
            <div class="av-body text-muted" style="font-size:12.5px">
                This will be their first vehicle.
            </div>
            <?php else: foreach ($existing as $c): ?>
            <div class="av-have">
                <strong style="color:var(--text)"><?= e(trim($c['make'] . ' ' . $c['model'])) ?></strong>
                <?= $c['year'] ? ' ' . (int)$c['year'] : '' ?><br>
                <code><?= e($c['chassis_number']) ?></code>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="av-card">
            <div class="av-body text-muted" style="font-size:12px;line-height:1.65">
                <i class="fa fa-circle-info me-1"></i>
                Customer vehicles are for service and repair — they are not put on the showroom
                and need no pricing. Use <strong>All Cars → Add Car</strong> if you are adding
                stock for sale instead.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
