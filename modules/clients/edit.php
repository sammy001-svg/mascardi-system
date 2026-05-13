<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

$client = $db->prepare("SELECT * FROM clients WHERE id=?");
$client->execute([$id]); $client = $client->fetch();
if (!$client) { setFlash('error','Client not found.'); redirect(BASE_URL.'/modules/clients/index.php'); }

$errors = [];
$d = $client;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['name']           = trim($_POST['name'] ?? '');
    $d['email']          = trim($_POST['email'] ?? '');
    $d['phone']          = trim($_POST['phone'] ?? '');
    $d['id_number']      = trim($_POST['id_number'] ?? '');
    $d['portal_enabled'] = isset($_POST['portal_enabled']) ? 1 : 0;
    $d['status']         = $_POST['status'] ?? 'active';
    $d['notes']          = trim($_POST['notes'] ?? '');
    $portalPass          = trim($_POST['portal_password'] ?? '');

    if (!$d['name'])  $errors[] = 'Name is required.';
    if (!$d['email']) $errors[] = 'Email is required.';
    elseif (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if (empty($errors)) {
        $hashedPass = $portalPass
            ? password_hash($portalPass, PASSWORD_DEFAULT)
            : $client['portal_password'];
        try {
            $db->prepare("UPDATE clients SET name=?,email=?,phone=?,id_number=?,portal_password=?,portal_enabled=?,status=?,notes=? WHERE id=?")
               ->execute([$d['name'],$d['email'],$d['phone'],$d['id_number'],$hashedPass,$d['portal_enabled'],$d['status'],$d['notes'],$id]);
            setFlash('success', 'Client updated.');
            redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Client — ' . $client['name'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-pen me-2 text-primary"></i>Edit Client: <?= e($client['name']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $err) echo '<div>' . e($err) . '</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Client Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= e($d['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= e($d['email']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= e($d['phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ID / KRA PIN</label>
                        <input type="text" name="id_number" class="form-control" value="<?= e($d['id_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= $d['status']==='active'?'selected':'' ?>>Active</option>
                            <option value="inactive" <?= $d['status']==='inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= e($d['notes'] ?? '') ?></textarea>
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
                <?php if ($client['portal_password']): ?>
                <div class="alert alert-success py-2 small mb-3"><i class="fa fa-check me-1"></i>Password is set. Leave blank to keep current.</div>
                <?php endif; ?>
                <div id="passBox">
                    <label class="form-label">New Portal Password</label>
                    <input type="password" name="portal_password" class="form-control" placeholder="Leave blank to keep current" autocomplete="new-password">
                </div>
            </div>
        </div>
    </div>
</div>
<div class="d-flex justify-content-end gap-2 mt-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-1"></i>Save Changes</button>
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
