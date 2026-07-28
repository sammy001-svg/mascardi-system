<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('cars');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM cars WHERE id=?");
$stmt->execute([$id]); $car = $stmt->fetch();
if (!$car) { setFlash('error','Car not found.'); redirect(BASE_URL.'/modules/cars/index.php'); }
$errors = [];

// Inline migrations — silent no-op if columns already exist
// The vehicle-type dropdown offers Sale on Behalf / Trade-In, but those values
// only exist in the car_type ENUM once the Trade-In module has been opened
// (see modules/trade_in/_bootstrap.php). With MySQL strict mode off, saving a
// value the ENUM does not know is silently coerced to '' — the car then matches
// no tab at all and disappears from the system. Widen it here, where the values
// are actually offered, so the save cannot lose data.
try {
    $__ct = $db->query("SHOW COLUMNS FROM cars LIKE 'car_type'")->fetch(PDO::FETCH_ASSOC);
    if ($__ct && !str_contains(strtolower($__ct['Type']), 'sale_on_behalf')) {
        $db->exec("ALTER TABLE cars MODIFY COLUMN car_type
                   ENUM('inventory','client','trade_in','sale_on_behalf') DEFAULT 'inventory'");
    }
} catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN description TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN features TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN meta_title VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN meta_description VARCHAR(500) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN meta_image VARCHAR(500) NULL DEFAULT NULL"); } catch (\Throwable $_) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'chassis_number'      => trim($_POST['chassis_number'] ?? ''),
        'registration_number' => trim($_POST['registration_number'] ?? ''),
        'make'                => trim($_POST['make'] ?? ''),
        'model'               => trim($_POST['model'] ?? ''),
        'year'                => (int)($_POST['year'] ?? 0),
        'color'               => trim($_POST['color'] ?? ''),
        'engine_number'       => trim($_POST['engine_number'] ?? ''),
        'transmission'        => $_POST['transmission'] ?? 'manual',
        'fuel_type'           => $_POST['fuel_type'] ?? 'petrol',
        'car_type'            => $_POST['car_type'] ?? 'inventory',
        'owner_name'          => trim($_POST['owner_name'] ?? ''),
        'owner_phone'         => trim($_POST['owner_phone'] ?? ''),
        'location_id'         => (int)($_POST['location_id'] ?? 1),
        'client_id'           => $_POST['client_id'] ? (int)$_POST['client_id'] : null,
        'body_type'           => trim($_POST['body_type'] ?? ''),
        'status'              => $_POST['status'] ?? 'in_transit',
        'notes'               => trim($_POST['notes'] ?? ''),
        'description'         => trim($_POST['description'] ?? ''),
        'features'            => trim($_POST['features'] ?? ''),
        'meta_title'          => trim($_POST['meta_title'] ?? ''),
        'meta_description'    => trim($_POST['meta_description'] ?? ''),
        'meta_image'          => trim($_POST['meta_image'] ?? ''),
        'asking_price'        => ($_POST['asking_price'] ?? '') !== '' ? (float)$_POST['asking_price'] : null,
        'mileage'             => ($_POST['mileage']      ?? '') !== '' ? (int)$_POST['mileage']        : null,
        'engine_cc'           => ($_POST['engine_cc']    ?? '') !== '' ? (int)$_POST['engine_cc']      : null,
        'featured'            => isset($_POST['featured']) ? 1 : 0,
        'offer_price'         => ($_POST['offer_price']  ?? '') !== '' ? (float)$_POST['offer_price']  : null,
        'show_on_website'     => isset($_POST['show_on_website']) ? 1 : 0,
    ];
    if (!$data['chassis_number']) $errors[] = 'Chassis number is required.';
    if (!$data['make'])           $errors[] = 'Make is required.';
    if (!$data['model'])          $errors[] = 'Model is required.';

    if (empty($errors)) {
        try {
            $db->prepare("UPDATE cars SET chassis_number=?,registration_number=?,make=?,model=?,year=?,color=?,engine_number=?,transmission=?,fuel_type=?,car_type=?,owner_name=?,owner_phone=?,location_id=?,client_id=?,body_type=?,status=?,notes=?,description=?,features=?,meta_title=?,meta_description=?,meta_image=?,asking_price=?,mileage=?,engine_cc=?,featured=?,offer_price=?,show_on_website=? WHERE id=?")
               ->execute([...array_values($data), $id]);
            logActivity('update', 'cars', $id, "Updated car: {$data['make']} {$data['model']} ({$data['chassis_number']})");

            // Switching a vehicle to Trade-In / Sale on Behalf only changed its
            // car_type, so it showed on the Cars tab but never in the Trade-In &
            // Sale on Behalf module — that module lists from `consignments`, and
            // no such record existed. Open a stub deal here so the vehicle turns
            // up there and the owner/commission details can be filled in.
            $__flash = 'Car updated successfully.';
            if (in_array($data['car_type'], ['trade_in','sale_on_behalf'], true)) {
                try {
                    require_once __DIR__ . '/../trade_in/_bootstrap.php';
                    tradeInMigrate($db);

                    $has = $db->prepare("SELECT COUNT(*) FROM consignments WHERE car_id = ?");
                    $has->execute([$id]);
                    if (!(int)$has->fetchColumn()) {
                        $ref = consignmentNextRef($db, $data['car_type']);
                        $db->prepare("INSERT INTO consignments
                                (car_id, deal_type, reference, owner_name, owner_phone,
                                 client_id, listing_price, commission_type, commission_value,
                                 agreement_date, status, notes, created_by)
                             VALUES (?,?,?,?,?,?,?, 'percent', 0, CURDATE(), 'active', ?, ?)")
                           ->execute([
                               $id, $data['car_type'], $ref,
                               // owner_name is NOT NULL — fall back to a clear placeholder
                               // rather than blocking the save on a field this form
                               // does not always collect.
                               $data['owner_name'] !== '' ? $data['owner_name'] : 'To be completed',
                               $data['owner_phone'] ?: null,
                               $data['client_id'] ?: null,
                               $data['asking_price'],
                               'Opened automatically when the vehicle type was changed in Cars. '
                                 . 'Owner and commission details still need completing.',
                               (int)(authUser()['id'] ?? 0),
                           ]);
                        $consId = (int)$db->lastInsertId();
                        logActivity('create', 'trade_in', $consId,
                            "Consignment {$ref} opened automatically for car #{$id}");
                        $__flash = 'Car updated. A ' . ($data['car_type'] === 'trade_in' ? 'Trade-In' : 'Sale on Behalf')
                                 . ' record (' . $ref . ') was opened — complete the owner and commission details in '
                                 . 'Trade-In &amp; Sale on Behalf.';
                    }
                } catch (\Throwable $e) {
                    error_log('cars/edit consignment autocreate: ' . $e->getMessage());
                    $__flash = 'Car updated, but the Trade-In / Sale on Behalf record could not be opened automatically ('
                             . $e->getMessage() . '). Add it from the Trade-In module.';
                }
            }

            setFlash('success', $__flash);
            redirect(BASE_URL.'/modules/cars/view.php?id='.$id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $existing = $db->prepare("SELECT id, make, model, status FROM cars WHERE chassis_number=? AND id!=?");
                $existing->execute([$data['chassis_number'], $id]);
                $existing = $existing->fetch();
                if ($existing) {
                    $errors[] = 'Chassis number already exists — car #' . $existing['id'] . ' ('
                        . trim($existing['make'] . ' ' . $existing['model']) . ', status: ' . $existing['status']
                        . '). Open ' . BASE_URL . '/modules/cars/view.php?id=' . $existing['id']
                        . ' — if you tried to delete it, it likely still has invoices, quotations, or jobs linked to it.';
                } else {
                    $errors[] = 'Chassis number already exists.';
                }
            } else {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
    $car = array_merge($car, $data);
}
$pageTitle = 'Edit Car';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Edit Car: <?= e($car['make'].' '.$car['model']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                    <input type="text" name="chassis_number" class="form-control" value="<?= e($car['chassis_number']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_number" class="form-control" value="<?= e($car['registration_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Engine Number</label>
                    <input type="text" name="engine_number" class="form-control" value="<?= e($car['engine_number'] ?? '') ?>">
                </div>
                <div class="col-md-4"><label class="form-label">Make <span class="text-danger">*</span></label><input type="text" name="make" class="form-control" value="<?= e($car['make']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Model <span class="text-danger">*</span></label><input type="text" name="model" class="form-control" value="<?= e($car['model']) ?>" required></div>
                <div class="col-md-2"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="<?= e($car['year']) ?>" min="1980" max="<?= date('Y')+1 ?>"></div>
                <div class="col-md-2"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= e($car['color'] ?? '') ?>"></div>
                <div class="col-md-3">
                    <label class="form-label">Body Type</label>
                    <select name="body_type" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach (['Saloon','SUV','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= $car['body_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transmission</label>
                    <select name="transmission" class="form-select">
                        <?php foreach (['manual','automatic','cvt'] as $t): ?>
                        <option value="<?= $t ?>" <?= $car['transmission']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fuel Type</label>
                    <select name="fuel_type" class="form-select">
                        <?php foreach (['petrol','diesel','hybrid','electric'] as $f): ?>
                        <option value="<?= $f ?>" <?= $car['fuel_type']===$f?'selected':'' ?>><?= ucfirst($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['in_transit','arrived','in_assessment','in_workshop','completed','delivered'] as $s): ?>
                        <option value="<?= $s ?>" <?= $car['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                    <select name="car_type" id="car_type" class="form-select" required>
                        <option value="inventory" <?= ($car['car_type'] ?? 'inventory') === 'inventory' ? 'selected' : '' ?>>Inventory (Imported)</option>
                        <option value="client" <?= ($car['car_type'] ?? '') === 'client' ? 'selected' : '' ?>>Client (Repair/Service)</option>
                        <!-- Consignment types are listed so the value round-trips on save;
                             the deal itself is managed in the Trade-In module. -->
                        <option value="sale_on_behalf" <?= ($car['car_type'] ?? '') === 'sale_on_behalf' ? 'selected' : '' ?>>Sale on Behalf (Customer-owned)</option>
                        <option value="trade_in" <?= ($car['car_type'] ?? '') === 'trade_in' ? 'selected' : '' ?>>Trade-In (Part-exchange)</option>
                    </select>
                    <?php if (in_array($car['car_type'] ?? '', ['sale_on_behalf','trade_in'], true)): ?>
                    <div class="form-text">
                        <i class="fa fa-handshake me-1"></i>Owner &amp; commission are managed in
                        <a href="<?= BASE_URL ?>/modules/trade_in/index.php">Trade-In &amp; Sale on Behalf</a>.
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 owner-fields" style="<?= in_array($car['car_type'] ?? '', ['client','sale_on_behalf','trade_in'], true) ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Name <span class="text-danger">*</span></label>
                    <input type="text" name="owner_name" class="form-control" value="<?= e($car['owner_name'] ?? '') ?>" placeholder="Customer Name">
                </div>
                <div class="col-md-4 owner-fields" style="<?= in_array($car['car_type'] ?? '', ['client','sale_on_behalf','trade_in'], true) ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Phone</label>
                    <input type="text" name="owner_phone" class="form-control" value="<?= e($car['owner_phone'] ?? '') ?>" placeholder="Customer Phone">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client Account <small class="text-muted">(for portal access)</small></label>
                    <select name="client_id" id="client_id" class="form-select select2">
                        <option value="">— No account —</option>
                        <?php 
                        $clients = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name ASC")->fetchAll();
                        foreach ($clients as $cl): ?>
                        <option value="<?= $cl['id'] ?>" data-name="<?= e($cl['name']) ?>" data-phone="<?= e($cl['phone']) ?>" <?= (int)($car['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                            <?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Current Location <span class="text-danger">*</span></label>
                    <select name="location_id" class="form-select" required>
                        <?php 
                        $locs = $db->query("SELECT id, name FROM locations WHERE status='active' OR id = " . (int)($car['location_id'] ?? 0) . " ORDER BY name ASC")->fetchAll();
                        foreach ($locs as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= (int)($car['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Internal Notes</label><textarea name="notes" class="form-control" rows="2" placeholder="Internal notes — not shown to customers"><?= e($car['notes'] ?? '') ?></textarea></div>

                <!-- ── Showroom / Sales ───────────────────────────── -->
                <div class="col-12 mt-2">
                    <div class="form-section-title">
                        <i class="fa fa-store me-1 text-primary"></i>Showroom &amp; Pricing
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description <small class="text-muted">(shown on the public showroom listing)</small></label>
                    <textarea name="description" class="form-control" rows="3" placeholder="e.g. Well maintained, single owner, full service history..."><?= e($car['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Features <small class="text-muted">(one per line — shown as a feature list on the website)</small></label>
                    <textarea name="features" class="form-control" rows="4" placeholder="Sunroof&#10;Leather Seats&#10;Reverse Camera&#10;Alloy Wheels"><?= e($car['features'] ?? '') ?></textarea>
                </div>

                <!-- ── SEO ───────────────────────────────────────── -->
                <div class="col-12 mt-2">
                    <div class="form-section-title">
                        <i class="fa fa-magnifying-glass me-1 text-primary"></i>Search Engine Optimization (SEO)
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Meta Title <small class="text-muted">(~60 characters recommended)</small></label>
                    <input type="text" name="meta_title" id="metaTitleInput" class="form-control" maxlength="255"
                           value="<?= e($car['meta_title'] ?? '') ?>"
                           placeholder="Auto-generated from Year, Make &amp; Model if left blank">
                    <div class="form-text"><span id="metaTitleCount">0</span> characters</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Meta Image <small class="text-muted">(optional — defaults to the vehicle's primary photo)</small></label>
                    <input type="text" name="meta_image" id="metaImageInput" class="form-control"
                           value="<?= e($car['meta_image'] ?? '') ?>" placeholder="https://... (leave blank to auto-use primary photo)">
                </div>
                <div class="col-12">
                    <label class="form-label">Meta Description <small class="text-muted">(~160 characters recommended)</small></label>
                    <textarea name="meta_description" id="metaDescInput" class="form-control" rows="2" maxlength="500"
                              placeholder="Auto-generated from vehicle details if left blank"><?= e($car['meta_description'] ?? '') ?></textarea>
                    <div class="form-text"><span id="metaDescCount">0</span> characters</div>
                </div>
                <div class="col-12">
                    <label class="form-label small text-muted mb-2"><i class="fa fa-eye me-1"></i>Search Engine Preview</label>
                    <div id="serpPreview" style="max-width:600px;padding:16px 20px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;font-family:arial,sans-serif">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <div style="width:22px;height:22px;border-radius:50%;background:#e2e8f0;flex-shrink:0"></div>
                            <div>
                                <div id="serpSite" style="font-size:13px;color:#202124;line-height:1.3"></div>
                                <div id="serpUrl" style="font-size:12px;color:#4d5156;line-height:1.3"></div>
                            </div>
                        </div>
                        <div id="serpTitle" style="font-size:19px;line-height:1.3;color:#1a0dab;margin:2px 0 3px"></div>
                        <div id="serpDesc" style="font-size:13.5px;line-height:1.5;color:#4d5156"></div>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Asking Price <small class="text-muted">(KES — leave blank to hide price)</small></label>
                    <div class="input-group">
                        <span class="input-group-text">KES</span>
                        <input type="number" name="asking_price" class="form-control" step="1" min="0"
                               value="<?= $car['asking_price'] !== null ? (int)$car['asking_price'] : '' ?>"
                               placeholder="e.g. 2500000">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Offer / Sale Price <small class="text-muted">(KES — optional, shown as sale price)</small></label>
                    <div class="input-group">
                        <span class="input-group-text text-danger"><i class="fa fa-tag"></i></span>
                        <input type="number" name="offer_price" class="form-control" step="1" min="0"
                               value="<?= isset($car['offer_price']) && $car['offer_price'] !== null ? (int)$car['offer_price'] : '' ?>"
                               placeholder="e.g. 2200000">
                    </div>
                    <div class="form-text">Displays with strikethrough on asking price</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mileage <small class="text-muted">(km)</small></label>
                    <input type="number" name="mileage" class="form-control" min="0"
                           value="<?= $car['mileage'] ?? '' ?>" placeholder="e.g. 45000">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Engine Size <small class="text-muted">(cc)</small></label>
                    <input type="number" name="engine_cc" class="form-control" min="0"
                           value="<?= $car['engine_cc'] ?? '' ?>" placeholder="e.g. 1800">
                </div>
                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="featured" id="featuredChk" class="form-check-input"
                               value="1" <?= !empty($car['featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="featuredChk">
                            <i class="fa fa-star text-warning me-1"></i>Featured
                            <div class="text-muted fw-normal" style="font-size:11.5px">Highlighted on homepage</div>
                        </label>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="show_on_website" id="showOnWebChk" class="form-check-input"
                               value="1" <?= ($car['show_on_website'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="showOnWebChk">
                            <i class="fa fa-globe text-success me-1"></i>Show on website
                            <div class="text-muted fw-normal" style="font-size:11.5px">Visible in public showroom</div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Update Car</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('car_type').addEventListener('change', function() {
    const isClient = ['client','sale_on_behalf','trade_in'].includes(this.value);
    document.querySelectorAll('.owner-fields').forEach(el => {
        el.style.display = isClient ? 'block' : 'none';
        const input = el.querySelector('input');
        if (input) input.required = isClient && input.name === 'owner_name';
    });
});
document.getElementById('client_id').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt.value && document.getElementById('car_type').value === 'client') {
        document.getElementsByName('owner_name')[0].value = opt.getAttribute('data-name');
        document.getElementsByName('owner_phone')[0].value = opt.getAttribute('data-phone');
    }
});

// ── Live Google search-result preview ──────────────────────────────
(function () {
    var f = {
        make:      document.querySelector('[name="make"]'),
        model:     document.querySelector('[name="model"]'),
        year:      document.querySelector('[name="year"]'),
        price:     document.querySelector('[name="asking_price"]'),
        desc:      document.querySelector('[name="description"]'),
        metaTitle: document.getElementById('metaTitleInput'),
        metaDesc:  document.getElementById('metaDescInput'),
    };
    var companyName = <?= json_encode(getSetting('company_name', 'Mascardi Car Yard')) ?>;
    var pageUrl      = <?= json_encode(rtrim(BASE_URL, '/') . '/showroom/view.php?id=' . (int)$id) ?>;

    function slugify(s) {
        return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    }
    function autoTitle() {
        var parts = [f.year.value.trim(), f.make.value.trim(), f.model.value.trim()].filter(Boolean).join(' ');
        return parts ? parts + ' for Sale in Kenya | ' + companyName : companyName;
    }
    function autoDesc() {
        var vehicle = [f.year.value.trim(), f.make.value.trim(), f.model.value.trim()].filter(Boolean).join(' ') || 'This vehicle';
        var out = vehicle + ' is available at ' + companyName + '.';
        if (f.price.value) out += ' Price: KES ' + Number(f.price.value).toLocaleString() + '.';
        if (f.desc.value.trim()) out += ' ' + f.desc.value.trim();
        return out.slice(0, 160);
    }
    function render() {
        var title = f.metaTitle.value.trim() || autoTitle();
        var desc  = f.metaDesc.value.trim()  || autoDesc();
        document.getElementById('metaTitleCount').textContent = f.metaTitle.value.length;
        document.getElementById('metaDescCount').textContent  = f.metaDesc.value.length;
        document.getElementById('serpSite').textContent = companyName;
        document.getElementById('serpUrl').textContent  = pageUrl + (f.make.value || f.model.value ? ' › ' + slugify(f.make.value + '-' + f.model.value) : '');
        document.getElementById('serpTitle').textContent = title.length > 60 ? title.slice(0, 57) + '…' : title;
        document.getElementById('serpDesc').textContent  = desc.length > 160 ? desc.slice(0, 157) + '…' : desc;
    }
    [f.make, f.model, f.year, f.price, f.desc, f.metaTitle, f.metaDesc].forEach(function (el) {
        if (el) el.addEventListener('input', render);
    });
    render();
}());
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
