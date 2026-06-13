<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'New Lead';
$db     = getDB();
$errors = [];

$stages  = ['new','contacted','interested','test_drive','negotiation'];
$sources = ['walk_in','referral','facebook','instagram','website','phone_call','whatsapp','other'];
$sourceLabels = [
    'walk_in'    => 'Walk-in',    'referral'   => 'Referral',
    'facebook'   => 'Facebook',   'instagram'  => 'Instagram',
    'website'    => 'Website',    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'      => 'Other',
];

$salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']          ?? '');
    $phone      = trim($_POST['phone']         ?? '') ?: null;
    $email      = trim($_POST['email']         ?? '') ?: null;
    $source     = $_POST['source']             ?? 'walk_in';
    $interestedIn = trim($_POST['interested_in'] ?? '') ?: null;
    $budget     = $_POST['budget']             ?? '';
    $budget     = $budget !== '' ? (float)$budget : null;
    $stage      = in_array($_POST['stage'] ?? '', $stages) ? $_POST['stage'] : 'new';
    $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $notes      = trim($_POST['notes']         ?? '') ?: null;
    $followUp   = trim($_POST['follow_up_date'] ?? '') ?: null;

    if (!$name) $errors[] = 'Lead name is required.';
    if (!in_array($source, $sources)) $errors[] = 'Invalid source.';

    if (empty($errors)) {
        try {
            $db->prepare("
                INSERT INTO crm_leads
                    (name, phone, email, source, interested_in, budget, stage, assigned_to, notes, follow_up_date)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$name,$phone,$email,$source,$interestedIn,$budget,$stage,$assignedTo,$notes,$followUp]);

            $newId = (int)$db->lastInsertId();
            logActivity('create','crm_leads',$newId,"New lead: $name");
            require_once __DIR__ . '/../../includes/notifications.php';
            if ($assignedTo) {
                createNotification((int)$assignedTo, 'info',
                    "Lead Assigned: {$name}",
                    "New lead from " . ($sourceLabels[$source] ?? $source) . ($interestedIn ? " — {$interestedIn}" : ''),
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $newId
                );
            }
            notifyRoles(['admin','sales_manager'], 'info',
                "New Lead: {$name}",
                ($sourceLabels[$source] ?? $source) . ($interestedIn ? " — {$interestedIn}" : ''),
                BASE_URL . '/modules/crm/view_lead.php?id=' . $newId
            );
            setFlash('success', "Lead '{$name}' added successfully.");
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-user-plus me-2 text-primary"></i>New Lead</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= e($_POST['name'] ?? '') ?>" placeholder="Prospective buyer name">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+254 7xx xxx xxx">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Lead Source <span class="text-danger">*</span></label>
                    <select name="source" class="form-select" required>
                        <?php foreach ($sourceLabels as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= (($_POST['source'] ?? 'walk_in') === $k) ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Initial Stage</label>
                    <select name="stage" class="form-select">
                        <option value="new"       <?= (($_POST['stage'] ?? 'new') === 'new')       ? 'selected' : '' ?>>New Lead</option>
                        <option value="contacted" <?= (($_POST['stage'] ?? '') === 'contacted')    ? 'selected' : '' ?>>Contacted</option>
                        <option value="interested"<?= (($_POST['stage'] ?? '') === 'interested')   ? 'selected' : '' ?>>Interested</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Assigned To</label>
                    <select name="assigned_to" class="form-select select2">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($salesUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)($_POST['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-8">
                    <label class="form-label fw-semibold">Interested In</label>
                    <input type="text" name="interested_in" class="form-control"
                           value="<?= e($_POST['interested_in'] ?? '') ?>"
                           placeholder="e.g. Toyota Land Cruiser V8, budget SUV, automatic…">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">Budget (KES)</label>
                    <input type="number" name="budget" class="form-control" min="0" step="1000"
                           value="<?= e($_POST['budget'] ?? '') ?>" placeholder="0">
                </div>

                <div class="col-md-2">
                    <label class="form-label fw-semibold">Follow-up Date</label>
                    <input type="date" name="follow_up_date" class="form-control"
                           value="<?= e($_POST['follow_up_date'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"
                              placeholder="Any additional context about this lead…"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Lead</button>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
