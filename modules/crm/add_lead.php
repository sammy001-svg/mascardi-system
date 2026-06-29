<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'New Lead';
$db     = getDB();
$errors = [];

$stages  = ['hot','lukewarm','cold','lost','reserved','delivered'];
$sources = ['walk_in','referral','facebook','instagram','website','phone_call','whatsapp','other'];
$sourceLabels = [
    'walk_in'    => 'Walk-in',    'referral'   => 'Referral',
    'facebook'   => 'Facebook',   'instagram'  => 'Instagram',
    'website'    => 'Website',    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'      => 'Other',
];

$salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// ── Helper: find duplicates of a given phone / email / name ──────────────────
function findDuplicateLeads(PDO $db, ?string $phone, ?string $email, ?string $name): array {
    $conds = []; $params = [];

    if ($phone !== null && $phone !== '') {
        $suffix = substr(preg_replace('/[^0-9]/', '', $phone), -9);
        if (strlen($suffix) >= 7) {
            $conds[]  = "phone LIKE ?";
            $params[] = '%' . $suffix;
        }
    }
    if ($email !== null && $email !== '') {
        $conds[]  = "LOWER(email) = LOWER(?)";
        $params[] = $email;
    }
    if ($name !== null && $name !== '') {
        $conds[]  = "LOWER(name) = LOWER(?)";
        $params[] = strtolower($name);
    }
    if (!$conds) return [];

    try {
        $stmt = $db->prepare("
            SELECT l.id, l.name, l.phone, l.email, l.stage, l.created_at,
                   u.name AS assigned_name
            FROM crm_leads l
            LEFT JOIN users u ON u.id = l.assigned_to
            WHERE (" . implode(' OR ', $conds) . ")
            ORDER BY l.created_at DESC
            LIMIT 10
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

$dupMatches  = [];   // leads that look like duplicates
$forceSave   = !empty($_POST['force_save']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']          ?? '');
    $phone      = trim($_POST['phone']         ?? '') ?: null;
    $email      = trim($_POST['email']         ?? '') ?: null;
    $source     = $_POST['source']             ?? 'walk_in';
    $interestedIn = trim($_POST['interested_in'] ?? '') ?: null;
    $budget     = $_POST['budget']             ?? '';
    $budget     = $budget !== '' ? (float)$budget : null;
    $stage      = in_array($_POST['stage'] ?? '', $stages) ? $_POST['stage'] : 'hot';
    $assignedTo = (int)($_POST['assigned_to'] ?? 0) ?: null;
    $notes      = trim($_POST['notes']         ?? '') ?: null;
    $followUp   = trim($_POST['follow_up_date'] ?? '') ?: null;

    if (!$name) $errors[] = 'Lead name is required.';
    if (!in_array($source, $sources)) $errors[] = 'Invalid source.';

    // ── Duplicate detection ───────────────────────────────────────────────
    if (empty($errors)) {
        $dupMatches = findDuplicateLeads($db, $phone, $email, $name);
    }

    // ── Save (only if no unconfirmed duplicates) ──────────────────────────
    if (empty($errors) && (empty($dupMatches) || $forceSave)) {
        try {
            $db->prepare("
                INSERT INTO crm_leads
                    (name, phone, email, source, interested_in, budget, stage, assigned_to, notes, follow_up_date)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$name,$phone,$email,$source,$interestedIn,$budget,$stage,$assignedTo,$notes,$followUp]);

            $newId = (int)$db->lastInsertId();
            logActivity('create','crm_leads',$newId,"New lead: $name");

            require_once __DIR__ . '/../../includes/notifications.php';

            // Notify assigned staff
            if ($assignedTo) {
                createNotification((int)$assignedTo, 'info',
                    "Lead Assigned: {$name}",
                    "New lead from " . ($sourceLabels[$source] ?? $source) . ($interestedIn ? " — {$interestedIn}" : ''),
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $newId
                );
            }

            // Notify managers about new lead
            notifyRoles(['admin','sales_manager'], 'info',
                "New Lead: {$name}",
                ($sourceLabels[$source] ?? $source) . ($interestedIn ? " — {$interestedIn}" : ''),
                BASE_URL . '/modules/crm/view_lead.php?id=' . $newId
            );

            // If saved despite duplicates — alert admin + super_admin
            if ($dupMatches) {
                $dupNames = implode(', ', array_map(fn($d) => '"' . $d['name'] . '" (#' . $d['id'] . ')', $dupMatches));
                notifyRoles(['admin','super_admin'], 'warning',
                    "Duplicate Lead Saved: {$name}",
                    "New lead \"{$name}\" (#{$newId}) was saved despite matching existing lead(s): {$dupNames}. Please review and delete one.",
                    BASE_URL . '/modules/crm/leads.php'
                );
            }

            setFlash('success', "Lead '{$name}' added successfully.");
            redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$stageLabels = [
    'hot' => 'Hot', 'lukewarm' => 'Lukewarm', 'cold' => 'Cold',
    'lost' => 'Lost', 'reserved' => 'Reserved', 'delivered' => 'Delivered',
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-user-plus me-2 text-primary"></i>New Lead</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
<?php endif; ?>

<?php if (!empty($dupMatches) && !$forceSave): ?>
<!-- ── Duplicate warning ──────────────────────────────────────────────── -->
<div class="alert alert-warning border-2 border-warning shadow-sm" id="dupWarning">
    <div class="d-flex align-items-start gap-3">
        <span style="font-size:26px;line-height:1">⚠️</span>
        <div class="flex-grow-1">
            <strong style="font-size:15px">Similar lead<?= count($dupMatches) > 1 ? 's' : '' ?> already exist in the system</strong>
            <p class="mb-2 mt-1" style="font-size:13px">
                The <?= count($dupMatches) > 1 ? 'following leads match' : 'following lead matches' ?> the
                <strong>phone number, email, or name</strong> you entered. Please review before saving.
            </p>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0" style="font-size:12.5px;background:#fff">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Stage</th>
                            <th>Assigned To</th>
                            <th>Added</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dupMatches as $dup): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($dup['name']) ?></td>
                        <td><?= e($dup['phone'] ?? '—') ?></td>
                        <td><?= e($dup['email'] ?? '—') ?></td>
                        <td><span class="badge bg-secondary"><?= ucfirst($dup['stage']) ?></span></td>
                        <td><?= e($dup['assigned_name'] ?? '—') ?></td>
                        <td><?= date('d M Y', strtotime($dup['created_at'])) ?></td>
                        <td>
                            <a href="view_lead.php?id=<?= $dup['id'] ?>" target="_blank"
                               class="btn btn-xs btn-outline-primary">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="confirmDup" name="force_save" value="1"
                           form="addLeadForm">
                    <label class="form-check-label fw-semibold" for="confirmDup" style="font-size:13px">
                        This is a different person — save anyway
                    </label>
                </div>
                <button type="submit" form="addLeadForm" id="forceSaveBtn"
                        class="btn btn-warning btn-sm" disabled>
                    <i class="fa fa-save me-1"></i>Save Anyway
                </button>
                <a href="leads.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('confirmDup').addEventListener('change', function () {
    document.getElementById('forceSaveBtn').disabled = !this.checked;
});
</script>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" id="addLeadForm">
            <?php if ($forceSave): ?>
            <input type="hidden" name="force_save" value="1">
            <?php endif; ?>
            <div class="row g-3">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="leadName" class="form-control" required
                           value="<?= e($_POST['name'] ?? '') ?>" placeholder="Prospective buyer name"
                           autocomplete="off">
                    <div id="nameDupHint" class="form-text text-warning fw-semibold" style="display:none">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        <span></span>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" id="leadPhone" class="form-control"
                           value="<?= e($_POST['phone'] ?? '') ?>" placeholder="+254 7xx xxx xxx"
                           autocomplete="off">
                    <div id="phoneDupHint" class="form-text text-warning fw-semibold" style="display:none">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        <span></span>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" id="leadEmail" class="form-control"
                           value="<?= e($_POST['email'] ?? '') ?>"
                           autocomplete="off">
                    <div id="emailDupHint" class="form-text text-warning fw-semibold" style="display:none">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        <span></span>
                    </div>
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
                        <?php foreach ($stageLabels as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= (($_POST['stage'] ?? 'hot') === $k) ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
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
                    <?php if (empty($dupMatches) || $forceSave): ?>
                    <button type="submit" class="btn btn-primary" id="mainSaveBtn">
                        <i class="fa fa-save me-1"></i>Save Lead
                    </button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-primary" id="mainSaveBtn" disabled
                            title="Review the duplicate warning above before saving">
                        <i class="fa fa-save me-1"></i>Save Lead
                    </button>
                    <span class="ms-2 text-warning small"><i class="fa fa-arrow-up me-1"></i>Review duplicate warning above</span>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Real-time duplicate checker -->
<script>
(function () {
    var apiUrl  = '<?= BASE_URL ?>/modules/crm/api/check_duplicate.php';
    var timer   = null;

    var phoneField = document.getElementById('leadPhone');
    var emailField = document.getElementById('leadEmail');
    var nameField  = document.getElementById('leadName');

    function setHint(hintId, matches, field) {
        var el   = document.getElementById(hintId);
        var span = el ? el.querySelector('span') : null;
        if (!el || !span) return;
        var filtered = matches.filter(function (m) {
            // Only show hint relevant to the field that was just checked
            if (field === 'phone') return m.phone && m.phone !== '';
            if (field === 'email') return m.email && m.email !== '';
            if (field === 'name')  return true;
            return true;
        });
        if (filtered.length > 0) {
            span.innerHTML = filtered.length === 1
                ? 'Similar lead already exists: <strong>' + esc(filtered[0].name) + '</strong> (' + esc(filtered[0].stage) + ')'
                : filtered.length + ' similar leads already exist in the system';
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function check(field, value, hintId) {
        if (!value || value.length < 3) {
            document.getElementById(hintId).style.display = 'none';
            return;
        }
        clearTimeout(timer);
        timer = setTimeout(function () {
            var params = {};
            if (field === 'phone') params.phone = value;
            if (field === 'email') params.email = value;
            if (field === 'name')  params.name  = value;
            var qs = Object.keys(params).map(function (k) {
                return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }).join('&');
            fetch(apiUrl + '?' + qs)
                .then(function (r) { return r.json(); })
                .then(function (d) { setHint(hintId, d.duplicates || [], field); })
                .catch(function () {});
        }, 500);
    }

    phoneField && phoneField.addEventListener('blur', function () {
        check('phone', this.value.trim(), 'phoneDupHint');
    });
    emailField && emailField.addEventListener('blur', function () {
        check('email', this.value.trim(), 'emailDupHint');
    });
    nameField && nameField.addEventListener('blur', function () {
        check('name', this.value.trim(), 'nameDupHint');
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
