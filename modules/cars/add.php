<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('cars') || die('Permission denied.');
$pageTitle = 'Add Car';
$db = getDB();
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
    $chassis   = trim($_POST['chassis_number'] ?? '');
    $reg       = trim($_POST['registration_number'] ?? '');
    $make      = trim($_POST['make'] ?? '');
    $model     = trim($_POST['model'] ?? '');
    $year      = (int)($_POST['year'] ?? 0);
    $color     = trim($_POST['color'] ?? '');
    $engine    = trim($_POST['engine_number'] ?? '');
    $trans     = $_POST['transmission'] ?? 'manual';
    $fuel      = $_POST['fuel_type'] ?? 'petrol';
    $carType    = $_POST['car_type'] ?? 'inventory';
    $ownerName  = trim($_POST['owner_name'] ?? '');
    $ownerPhone = trim($_POST['owner_phone'] ?? '');
    $body       = trim($_POST['body_type'] ?? '');
    $notes      = trim($_POST['notes'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $features    = trim($_POST['features'] ?? '');
    $metaTitle   = trim($_POST['meta_title'] ?? '');
    $metaDesc    = trim($_POST['meta_description'] ?? '');
    $metaImage   = trim($_POST['meta_image'] ?? '');
    $askingPrice  = ($_POST['asking_price'] ?? '') !== '' ? (float)$_POST['asking_price'] : null;
    $mileage      = ($_POST['mileage']      ?? '') !== '' ? (int)$_POST['mileage']        : null;
    $engineCc     = ($_POST['engine_cc']    ?? '') !== '' ? (int)$_POST['engine_cc']      : null;
    $featured     = isset($_POST['featured']) ? 1 : 0;
    $offerPrice   = ($_POST['offer_price']  ?? '') !== '' ? (float)$_POST['offer_price']  : null;
    $showOnWeb    = isset($_POST['show_on_website']) ? 1 : 0;

    if (!$chassis) $errors[] = 'Chassis number is required.';
    if (!$make)    $errors[] = 'Make is required.';
    if (!$model)   $errors[] = 'Model is required.';
    if (!$year)    $errors[] = 'Year is required.';
    if ($carType === 'client' && !$ownerName) $errors[] = 'Owner name is required for client vehicles.';

    if (empty($errors)) {
        try {
            $locId    = (int)($_POST['location_id'] ?? 1);
            $clientId = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
            $stmt = $db->prepare("INSERT INTO cars (chassis_number,registration_number,make,model,year,color,engine_number,transmission,fuel_type,car_type,owner_name,owner_phone,client_id,location_id,body_type,notes,description,features,meta_title,meta_description,meta_image,asking_price,mileage,engine_cc,featured,offer_price,show_on_website) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$chassis,$reg,$make,$model,$year,$color,$engine,$trans,$fuel,$carType,$ownerName,$ownerPhone,$clientId,$locId,$body,$notes,$description,$features,$metaTitle,$metaDesc,$metaImage,$askingPrice,$mileage,$engineCc,$featured,$offerPrice,$showOnWeb]);
            $carId = $db->lastInsertId();
            
            logActivity('create', 'cars', $carId, "Added car: $make $model ($chassis)");
            setFlash('success', "Car {$make} {$model} ({$chassis}) added successfully.");
            redirect(BASE_URL . '/modules/cars/view.php?id=' . $carId);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // Point straight at the conflicting record — a "deleted" car that
                // still has invoices/quotations/jobs attached won't actually be
                // removed (delete is blocked to protect those records), so the
                // chassis number is still genuinely in use.
                $existing = $db->prepare("SELECT id, make, model, status FROM cars WHERE chassis_number=?");
                $existing->execute([$chassis]);
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
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add New Car</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                    <input type="text" name="chassis_number" class="form-control" value="<?= e($_POST['chassis_number'] ?? '') ?>" placeholder="e.g. JTEBT9FJ60K056783" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_number" class="form-control" value="<?= e($_POST['registration_number'] ?? '') ?>" placeholder="e.g. KCA 123A">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Engine Number</label>
                    <input type="text" name="engine_number" class="form-control" value="<?= e($_POST['engine_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Make <span class="text-danger">*</span></label>
                    <input type="text" name="make" class="form-control" value="<?= e($_POST['make'] ?? '') ?>" placeholder="e.g. Toyota" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model <span class="text-danger">*</span></label>
                    <input type="text" name="model" class="form-control" value="<?= e($_POST['model'] ?? '') ?>" placeholder="e.g. Land Cruiser" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Year <span class="text-danger">*</span></label>
                    <input type="number" name="year" class="form-control" value="<?= e($_POST['year'] ?? date('Y')) ?>" min="1980" max="<?= date('Y')+1 ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control" value="<?= e($_POST['color'] ?? '') ?>" placeholder="e.g. White">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Body Type</label>
                    <select name="body_type" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach (['Saloon','SUV','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($_POST['body_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transmission</label>
                    <select name="transmission" class="form-select">
                        <option value="manual" <?= ($_POST['transmission'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        <option value="automatic" <?= ($_POST['transmission'] ?? '') === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                        <option value="cvt" <?= ($_POST['transmission'] ?? '') === 'cvt' ? 'selected' : '' ?>>CVT</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fuel Type</label>
                    <select name="fuel_type" class="form-select">
                        <?php foreach (['petrol','diesel','hybrid','electric'] as $ft): ?>
                        <option value="<?= $ft ?>" <?= ($_POST['fuel_type'] ?? '') === $ft ? 'selected' : '' ?>><?= ucfirst($ft) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                    <select name="car_type" id="car_type" class="form-select" required>
                        <option value="inventory" <?= ($_POST['car_type'] ?? 'inventory') === 'inventory' ? 'selected' : '' ?>>Inventory (Imported)</option>
                        <option value="client" <?= ($_POST['car_type'] ?? '') === 'client' ? 'selected' : '' ?>>Client (Repair/Service)</option>
                    </select>
                </div>
                <div class="col-md-4 owner-fields" style="<?= ($_POST['car_type'] ?? '') === 'client' ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Name <span class="text-danger">*</span></label>
                    <input type="text" name="owner_name" class="form-control" value="<?= e($_POST['owner_name'] ?? '') ?>" placeholder="Customer Name">
                </div>
                <div class="col-md-4 owner-fields" style="<?= ($_POST['car_type'] ?? '') === 'client' ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Phone</label>
                    <input type="text" name="owner_phone" class="form-control" value="<?= e($_POST['owner_phone'] ?? '') ?>" placeholder="Customer Phone">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client Account <small class="text-muted">(for portal access)</small></label>
                    <select name="client_id" id="client_id" class="form-select select2">
                        <option value="">— No account —</option>
                        <?php 
                        $clients = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name ASC")->fetchAll();
                        foreach ($clients as $cl): ?>
                        <option value="<?= $cl['id'] ?>" data-name="<?= e($cl['name']) ?>" data-phone="<?= e($cl['phone']) ?>" <?= (int)($_POST['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                            <?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Current Location <span class="text-danger">*</span></label>
                    <select name="location_id" class="form-select" required>
                        <?php 
                        $locs = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name ASC")->fetchAll();
                        foreach ($locs as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= (int)($_POST['location_id'] ?? 1) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Internal Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Internal notes — not shown to customers"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

                <!-- ── Showroom / Sales ───────────────────────────── -->
                <div class="col-12 mt-2">
                    <div class="form-section-title">
                        <i class="fa fa-store me-1 text-primary"></i>Showroom &amp; Pricing
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description <small class="text-muted">(shown on the public showroom listing)</small></label>
                    <textarea name="description" class="form-control" rows="3" placeholder="e.g. Well maintained, single owner, full service history..."><?= e($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Features <small class="text-muted">(one per line — shown as a feature list on the website)</small></label>
                    <textarea name="features" class="form-control" rows="4" placeholder="Sunroof&#10;Leather Seats&#10;Reverse Camera&#10;Alloy Wheels"><?= e($_POST['features'] ?? '') ?></textarea>
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
                           value="<?= e($_POST['meta_title'] ?? '') ?>"
                           placeholder="Auto-generated from Year, Make &amp; Model if left blank">
                    <div class="form-text"><span id="metaTitleCount">0</span> characters</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Meta Image <small class="text-muted">(optional — defaults to the vehicle's primary photo)</small></label>
                    <input type="text" name="meta_image" id="metaImageInput" class="form-control"
                           value="<?= e($_POST['meta_image'] ?? '') ?>" placeholder="https://... (leave blank to auto-use primary photo)">
                </div>
                <div class="col-12">
                    <label class="form-label">Meta Description <small class="text-muted">(~160 characters recommended)</small></label>
                    <textarea name="meta_description" id="metaDescInput" class="form-control" rows="2" maxlength="500"
                              placeholder="Auto-generated from vehicle details if left blank"><?= e($_POST['meta_description'] ?? '') ?></textarea>
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
                               value="<?= $_POST['asking_price'] ?? '' ?>" placeholder="e.g. 2500000">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Offer / Sale Price <small class="text-muted">(KES — optional, shown as sale price)</small></label>
                    <div class="input-group">
                        <span class="input-group-text text-danger"><i class="fa fa-tag"></i></span>
                        <input type="number" name="offer_price" class="form-control" step="1" min="0"
                               value="<?= $_POST['offer_price'] ?? '' ?>" placeholder="e.g. 2200000">
                    </div>
                    <div class="form-text">Displays with strikethrough on asking price</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mileage <small class="text-muted">(km)</small></label>
                    <input type="number" name="mileage" class="form-control" min="0"
                           value="<?= $_POST['mileage'] ?? '' ?>" placeholder="e.g. 45000">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Engine Size <small class="text-muted">(cc)</small></label>
                    <input type="number" name="engine_cc" class="form-control" min="0"
                           value="<?= $_POST['engine_cc'] ?? '' ?>" placeholder="e.g. 1800">
                </div>
                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="featured" id="featuredChk" class="form-check-input" value="1"
                               <?= isset($_POST['featured']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="featuredChk">
                            <i class="fa fa-star text-warning me-1"></i>Featured
                            <div class="text-muted fw-normal" style="font-size:11.5px">Highlighted on homepage</div>
                        </label>
                    </div>
                </div>
                <div class="col-md-2 d-flex align-items-end pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="show_on_website" id="showOnWebChk" class="form-check-input" value="1"
                               <?= $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['show_on_website']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="showOnWebChk">
                            <i class="fa fa-globe text-success me-1"></i>Show on website
                            <div class="text-muted fw-normal" style="font-size:11.5px">Visible in public showroom</div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Car</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('car_type').addEventListener('change', function() {
    const isClient = this.value === 'client';
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
    var baseUrl      = <?= json_encode(rtrim(BASE_URL, '/') . '/showroom/view.php?id=') ?>;

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
        document.getElementById('serpUrl').textContent  = baseUrl + 'NEW' + (f.make.value || f.model.value ? ' › ' + slugify(f.make.value + '-' + f.model.value) : '');
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
