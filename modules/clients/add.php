<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('clients') || die('Permission denied.');
$pageTitle = 'New Client';
$db   = getDB();
$user = authUser();

// Auto-add kra_pin column if this is a fresh install
try { $db->exec("ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL AFTER id_number"); } catch (\Throwable $_) {}

$errors = [];
$d = [
    'name' => '', 'email' => '', 'phone' => '', 'id_number' => '', 'kra_pin' => '',
    'portal_enabled' => 0, 'notes' => '', 'status' => 'active',
    'car_make' => '', 'car_model' => '', 'car_year' => date('Y'), 
    'car_registration' => '', 'car_chassis' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['name']           = trim($_POST['name'] ?? '');
    $d['email']          = trim($_POST['email'] ?? '');
    $d['phone']          = trim($_POST['phone'] ?? '');
    $d['id_number']      = trim($_POST['id_number'] ?? '');
    $d['kra_pin']        = strtoupper(trim($_POST['kra_pin'] ?? ''));
    $d['portal_enabled'] = isset($_POST['portal_enabled']) ? 1 : 0;
    $d['notes']          = trim($_POST['notes'] ?? '');
    $d['status']         = $_POST['status'] ?? 'active';
    $portalPass          = trim($_POST['portal_password'] ?? '');

    $d['car_make']         = trim($_POST['car_make'] ?? '');
    $d['car_model']        = trim($_POST['car_model'] ?? '');
    $d['car_year']         = trim($_POST['car_year'] ?? '');
    $d['car_registration']  = trim($_POST['car_registration'] ?? '');
    $d['car_chassis']      = trim($_POST['car_chassis'] ?? '');

    if (!$d['name'])  $errors[] = 'Name is required.';
    if (!$d['email']) $errors[] = 'Email is required.';
    elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($d['portal_enabled'] && !$portalPass) $errors[] = 'A portal password is required when enabling portal access.';

    $hasVehicle = ($d['car_make'] !== '' || $d['car_model'] !== '' || $d['car_registration'] !== '' || $d['car_chassis'] !== '');
    if ($hasVehicle) {
        if (!$d['car_make']) $errors[] = 'Car Make is required if adding vehicle details.';
        if (!$d['car_model']) $errors[] = 'Car Model is required if adding vehicle details.';
        if (!$d['car_chassis']) $errors[] = 'Chassis Number is required if adding vehicle details.';
        if (!$d['car_year']) $errors[] = 'Car Year is required if adding vehicle details.';
        
        if ($d['car_chassis']) {
            $checkChassis = $db->prepare("SELECT COUNT(*) FROM cars WHERE chassis_number = ?");
            $checkChassis->execute([$d['car_chassis']]);
            if ($checkChassis->fetchColumn() > 0) {
                $errors[] = 'Chassis number already exists in the system.';
            }
        }
    }

    if (empty($errors)) {
        $hashedPass = $portalPass ? password_hash($portalPass, PASSWORD_DEFAULT) : null;
        try {
            $db->beginTransaction();
            $db->prepare("INSERT INTO clients (name,email,phone,id_number,kra_pin,portal_password,portal_enabled,status,notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$d['name'],$d['email'],$d['phone'],$d['id_number'],$d['kra_pin'],$hashedPass,$d['portal_enabled'],$d['status'],$d['notes']]);
            $newId = $db->lastInsertId();

            if ($hasVehicle) {
                $db->prepare("INSERT INTO cars (chassis_number,registration_number,make,model,year,car_type,owner_name,client_id,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$d['car_chassis'],$d['car_registration'],$d['car_make'],$d['car_model'],(int)$d['car_year'],'client',$d['name'],$newId,null,'completed']);
            }
            
            $db->commit();
            logActivity('create', 'clients', $newId, "Added client: {$d['name']}");
            setFlash('success', 'Client ' . $d['name'] . ' added' . ($hasVehicle ? ' with vehicle details.' : '.'));
            redirect(BASE_URL . '/modules/clients/view.php?id=' . $newId);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-user-plus me-2 text-primary"></i>New Client</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-1"></i>' . e($err) . '</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-user me-2"></i>Client Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= e($d['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= e($d['email']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= e($d['phone']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID / Passport</label>
                        <input type="text" name="id_number" class="form-control" value="<?= e($d['id_number']) ?>" placeholder="e.g. 12345678">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">KRA PIN</label>
                        <input type="text" name="kra_pin" class="form-control text-uppercase" value="<?= e($d['kra_pin']) ?>"
                               placeholder="e.g. A001234567B" maxlength="20" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= $d['status']==='active'?'selected':'' ?>>Active</option>
                            <option value="inactive" <?= $d['status']==='inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= e($d['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4" style="border-top:3px solid #10b981">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2"></i>Vehicle Details (Optional)</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Car Make</label>
                        <input type="text" name="car_make" class="form-control" value="<?= e($d['car_make'] ?? '') ?>" placeholder="e.g. Toyota">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Car Model</label>
                        <input type="text" name="car_model" class="form-control" value="<?= e($d['car_model'] ?? '') ?>" placeholder="e.g. Prado">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Year</label>
                        <input type="number" name="car_year" class="form-control" value="<?= e($d['car_year'] ?? date('Y')) ?>" min="1980" max="<?= date('Y')+1 ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="car_registration" class="form-control" value="<?= e($d['car_registration'] ?? '') ?>" placeholder="e.g. KDA 123A" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Chassis Number</label>
                        <input type="text" name="car_chassis" class="form-control" value="<?= e($d['car_chassis'] ?? '') ?>" placeholder="e.g. JTEBT9FJ60K...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card" style="border-top:3px solid #2563eb">
            <div class="card-header"><i class="fa fa-lock me-2"></i>Portal Access</div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="portal_enabled" id="portalToggle" value="1" <?= $d['portal_enabled']?'checked':'' ?>>
                    <label class="form-check-label" for="portalToggle">Enable Client Portal</label>
                </div>
                <div id="passBox">
                    <label class="form-label">Portal Password</label>
                    <input type="password" name="portal_password" class="form-control" placeholder="Set a password for this client" autocomplete="new-password">
                    <div class="text-muted small mt-1">Client uses their email + this password to log in.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-2">
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-1"></i>Save Client</button>
</div>
</form>

<?php
$extraJs = <<<'JS'
<script>
document.getElementById('portalToggle').addEventListener('change', function(){
    document.getElementById('passBox').style.display = this.checked ? '' : 'none';
});
document.getElementById('passBox').style.display = document.getElementById('portalToggle').checked ? '' : 'none';
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>
