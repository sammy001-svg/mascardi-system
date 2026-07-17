<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$pageTitle = 'System Settings';
$db = getDB();

$defaults = [
    'company_name'      => 'Mascardi Car Yard',
    'company_address'   => 'Nairobi, Kenya',
    'company_phone'     => '+254 700 000 000',
    'company_email'     => 'info@mascardi.co.ke',
    'company_pin'       => 'P051234567X',
    'company_logo'      => '',
    'vat_rate'          => '16',
    'currency'          => 'KES',
    'invoice_prefix'    => 'INV',
    'quotation_prefix'  => 'QT',
    'lpo_prefix'        => 'LPO',
    'job_prefix'        => 'JOB',
    'booking_prefix'    => 'BK',
    'payment_prefix'    => 'PAY',
    'sale_prefix'       => 'SALE',
    'issue_prefix'      => 'ISS',
    'assessment_prefix' => 'ASS',
    'smtp_host'         => '',
    'smtp_port'         => '587',
    'smtp_user'         => '',
    'smtp_pass'         => '',
    'smtp_from_email'   => '',
    'smtp_from_name'    => '',
    'smtp_encryption'   => 'tls',
    'mpesa_env'             => 'sandbox',
    'mpesa_consumer_key'    => '',
    'mpesa_consumer_secret' => '',
    'mpesa_shortcode'       => '',
    'mpesa_passkey'         => '',
    'mpesa_callback_url'    => '',
];

$rows     = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings = array_column($rows, 'setting_value', 'setting_key');
$stmt     = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $settings)) { $stmt->execute([$k, $v]); $settings[$k] = $v; }
}

$errors  = [];
$success = '';
$activeTab = $_GET['tab'] ?? 'branding';

// ── Logo upload ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_logo') {
    $file = $_FILES['company_logo'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!isset($allowed[$mime])) {
            $errors[] = 'Logo must be JPG, PNG, WebP, or SVG.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Logo must be under 2 MB.';
        } else {
            $imgDir = BASE_PATH . '/assets/images';
            if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
            $ext     = $allowed[$mime];
            $destRel = '/assets/images/company_logo.' . $ext;
            $dest    = BASE_PATH . $destRel;
            // Remove any old logos with different extension
            foreach (['jpg','png','webp','svg'] as $e) {
                $old = BASE_PATH . '/assets/images/company_logo.' . $e;
                if (file_exists($old) && $e !== $ext) @unlink($old);
            }
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('company_logo',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$destRel]);
                $settings['company_logo'] = $destRel;
                setFlash('success', 'Logo uploaded successfully.');
            } else {
                $errors[] = 'Failed to save logo. Check folder permissions.';
            }
        }
    } elseif ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Upload error code: ' . $file['error'];
    }
    $activeTab = 'branding';
}

// ── Remove logo ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_logo') {
    foreach (['jpg','png','webp','svg'] as $e) {
        $f = BASE_PATH . '/assets/images/company_logo.' . $e;
        if (file_exists($f)) @unlink($f);
    }
    $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('company_logo','') ON DUPLICATE KEY UPDATE setting_value=''")->execute();
    $settings['company_logo'] = '';
    setFlash('success', 'Logo removed.');
    $activeTab = 'branding';
}

// ── Main settings save ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $updates = [
        // Branding
        'company_name'      => trim($_POST['company_name'] ?? ''),
        'company_address'   => trim($_POST['company_address'] ?? ''),
        'company_phone'     => trim($_POST['company_phone'] ?? ''),
        'company_email'     => trim($_POST['company_email'] ?? ''),
        'company_pin'       => trim($_POST['company_pin'] ?? ''),
        // Financial
        'vat_rate'          => trim($_POST['vat_rate'] ?? '16'),
        'currency'          => trim($_POST['currency'] ?? 'KES'),
        // Prefixes
        'invoice_prefix'    => strtoupper(trim($_POST['invoice_prefix']    ?? 'INV')),
        'quotation_prefix'  => strtoupper(trim($_POST['quotation_prefix']  ?? 'QT')),
        'lpo_prefix'        => strtoupper(trim($_POST['lpo_prefix']        ?? 'LPO')),
        'job_prefix'        => strtoupper(trim($_POST['job_prefix']        ?? 'JOB')),
        'booking_prefix'    => strtoupper(trim($_POST['booking_prefix']    ?? 'BK')),
        'payment_prefix'    => strtoupper(trim($_POST['payment_prefix']    ?? 'PAY')),
        'sale_prefix'       => strtoupper(trim($_POST['sale_prefix']       ?? 'SALE')),
        'issue_prefix'      => strtoupper(trim($_POST['issue_prefix']      ?? 'ISS')),
        'assessment_prefix' => strtoupper(trim($_POST['assessment_prefix'] ?? 'ASS')),
        // Email
        'smtp_host'         => trim($_POST['smtp_host']       ?? ''),
        'smtp_port'         => trim($_POST['smtp_port']       ?? '587'),
        'smtp_user'         => trim($_POST['smtp_user']       ?? ''),
        'smtp_from_email'   => trim($_POST['smtp_from_email'] ?? ''),
        'smtp_from_name'    => trim($_POST['smtp_from_name']  ?? ''),
        'smtp_encryption'   => in_array($_POST['smtp_encryption'] ?? '', ['none','tls','ssl']) ? $_POST['smtp_encryption'] : 'tls',
        // M-Pesa
        'mpesa_env'             => in_array($_POST['mpesa_env'] ?? '', ['sandbox','production']) ? $_POST['mpesa_env'] : 'sandbox',
        'mpesa_consumer_key'    => trim($_POST['mpesa_consumer_key']    ?? ''),
        'mpesa_consumer_secret' => trim($_POST['mpesa_consumer_secret'] ?? ''),
        'mpesa_shortcode'       => trim($_POST['mpesa_shortcode']       ?? ''),
        'mpesa_passkey'         => trim($_POST['mpesa_passkey']         ?? ''),
        'mpesa_callback_url'    => trim($_POST['mpesa_callback_url']    ?? ''),
    ];
    // Only update password if provided (don't blank it out)
    if (!empty($_POST['smtp_pass'])) {
        $updates['smtp_pass'] = $_POST['smtp_pass'];
    }

    $activeTab = $_POST['_tab'] ?? 'branding';

    if (!$updates['company_name']) {
        $errors[] = 'Company name is required.';
    } else {
        $uStmt = $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        foreach ($updates as $k => $v) { $uStmt->execute([$k, $v]); }
        $settings = array_merge($settings, $updates);
        setFlash('success', 'Settings saved.');
        redirect(BASE_URL . '/modules/settings/index.php?tab=' . $activeTab);
    }
}

// ── Role permissions matrix ───────────────────────────────────────────────
$roleMatrix = [
    'workshop_manager' => [
        'access' => ['cars','mechanics','drivers','assessments','jobs','parts_requests','issues','quick_assessments','inventory','lpo'],
        'write'  => ['jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments'],
    ],
    'sales_person' => [
        'access' => ['cars','clients','service_bookings','quick_assessments','quotations','invoices','payments','sales'],
        'write'  => ['service_bookings','quick_assessments','clients','payments','sales'],
    ],
    'sales_officer' => [
        'access' => ['cars','clients','service_bookings','quotations','invoices','payments','quick_assessments','sales','reports'],
        'write'  => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales'],
    ],
    'mechanic' => [
        'access' => ['jobs','assessments','parts_requests','issues','inventory'],
        'write'  => ['assessments','parts_requests'],
    ],
];
$matrixModules = [
    'cars'              => 'Vehicles',
    'intake'            => 'Intake / Arrivals',
    'assessments'       => 'Assessments',
    'jobs'              => 'Workshop Jobs',
    'inventory'         => 'Inventory',
    'parts_requests'    => 'Quote Requests',
    'lpo'               => 'LPO / Orders',
    'quotations'        => 'Quotations',
    'invoices'          => 'Invoices',
    'payments'          => 'Payments',
    'service_bookings'  => 'Service Bookings',
    'quick_assessments' => 'Quick Assessments',
    'clients'           => 'Clients',
    'sales'             => 'Car Sales',
    'issues'            => 'Issues',
    'mechanics'         => 'Mechanics',
    'drivers'           => 'Drivers',
    'reports'           => 'Reports',
    'suppliers'         => 'Suppliers',
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1">System Settings</h5>
        <div class="text-muted small">Configure company information, integrations, and system defaults</div>
    </div>
    <a href="messaging.php" class="btn btn-sm btn-outline-primary">
        <i class="fa fa-comment-sms me-1"></i>Messaging &amp; Alerts
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3"><ul class="mb-0"><?php foreach ($errors as $er) echo '<li>'.e($er).'</li>'; ?></ul></div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
    <?php
    $tabs = [
        'branding'     => ['fa-palette',          'Branding'],
        'documents'    => ['fa-file-lines',        'Documents'],
        'email'        => ['fa-envelope',          'Email'],
        'integrations' => ['fa-mobile-screen-button','Integrations'],
        'permissions'  => ['fa-shield-halved',     'Permissions'],
        'system'       => ['fa-server',            'System'],
    ];
    foreach ($tabs as $tid => [$icon, $label]): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $activeTab === $tid ? 'active' : '' ?>"
                id="tab-<?= $tid ?>" data-bs-toggle="tab"
                data-bs-target="#pane-<?= $tid ?>" type="button" role="tab">
            <i class="fa <?= $icon ?> me-1"></i><?= $label ?>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">

<!-- ══ BRANDING ══════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'branding' ? 'show active' : '' ?>" id="pane-branding" role="tabpanel">
<form method="POST" enctype="multipart/form-data">
<input type="hidden" name="action" value="save">
<input type="hidden" name="_tab" value="branding">
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-building me-2"></i>Company Information</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" required value="<?= e($settings['company_name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="company_address" class="form-control" rows="2"><?= e($settings['company_address'] ?? '') ?></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="company_phone" class="form-control" value="<?= e($settings['company_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="company_email" class="form-control" value="<?= e($settings['company_email'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label">KRA PIN Number</label>
                    <input type="text" name="company_pin" class="form-control" placeholder="P051234567X" value="<?= e($settings['company_pin'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-image me-2"></i>Company Logo</div>
            <div class="card-body d-flex flex-column">
                <!-- Current logo preview -->
                <?php $logoPath = $settings['company_logo'] ?? ''; ?>
                <div class="text-center mb-3">
                    <?php if ($logoPath && file_exists(BASE_PATH . $logoPath)): ?>
                    <img src="<?= BASE_URL . e($logoPath) ?>?v=<?= filemtime(BASE_PATH . $logoPath) ?>"
                         alt="Company Logo" style="max-height:100px;max-width:100%;object-fit:contain;border-radius:6px">
                    <?php else: ?>
                    <div class="d-flex align-items-center justify-content-center bg-light rounded"
                         style="height:100px;color:#94a3b8">
                        <div class="text-center">
                            <i class="fa fa-image fa-2x mb-1"></i>
                            <div class="small">No logo uploaded</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Upload New Logo</label>
                    <input type="file" name="company_logo" class="form-control" accept="image/jpeg,image/png,image/webp,image/svg+xml">
                    <div class="form-text">JPG, PNG, WebP or SVG. Max 2 MB. Displays on documents and portal.</div>
                </div>
                <div class="mt-auto d-flex gap-2">
                    <button type="submit" form="logoUploadForm" class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-upload me-1"></i>Upload
                    </button>
                    <?php if ($logoPath): ?>
                    <button type="submit" form="logoRemoveForm" class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-trash me-1"></i>Remove
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="mt-4">
    <button type="submit" class="btn btn-primary px-5"><i class="fa fa-check me-2"></i>Save Company Info</button>
</div>
</form>

<!-- Separate forms for logo actions -->
<form id="logoUploadForm" method="POST" enctype="multipart/form-data" class="d-none">
    <input type="hidden" name="action" value="upload_logo">
    <input type="file" name="company_logo" id="logoFileHidden">
</form>
<form id="logoRemoveForm" method="POST">
    <input type="hidden" name="action" value="remove_logo">
</form>
<script>
document.querySelector('[form="logoUploadForm"]') && document.querySelector('[form="logoUploadForm"]').addEventListener('click', function(e) {
    e.preventDefault();
    var fileInput = document.querySelector('input[name="company_logo"][type="file"]');
    if (!fileInput || !fileInput.files.length) {
        alert('Please select a file first.');
        return;
    }
    var form = document.getElementById('logoUploadForm');
    var newInput = fileInput.cloneNode(true);
    newInput.id = 'logoFileTransfer';
    form.appendChild(newInput);
    // Copy the selected file
    var dt = new DataTransfer();
    dt.items.add(fileInput.files[0]);
    newInput.files = dt.files;
    form.submit();
});
</script>
</div><!-- /branding pane -->

<!-- ══ DOCUMENTS ═════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'documents' ? 'show active' : '' ?>" id="pane-documents" role="tabpanel">
<form method="POST">
<input type="hidden" name="action" value="save">
<input type="hidden" name="_tab" value="documents">
<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-coins me-2"></i>Financial Settings</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Currency</label>
                        <select name="currency" class="form-select">
                            <?php foreach (['KES','USD','EUR','GBP','TZS','UGX'] as $cur): ?>
                            <option value="<?= $cur ?>" <?= ($settings['currency'] ?? 'KES')===$cur?'selected':'' ?>><?= $cur ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Default VAT (%)</label>
                        <div class="input-group">
                            <input type="number" name="vat_rate" class="form-control" min="0" max="100" step="0.01"
                                   value="<?= e($settings['vat_rate'] ?? '16') ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-file-lines me-2"></i>Document Number Prefixes</div>
            <div class="card-body">
                <div class="row g-3">
                    <?php
                    $prefixFields = [
                        ['invoice_prefix',    'Invoice',          'INV'],
                        ['quotation_prefix',  'Quotation',        'QT'],
                        ['lpo_prefix',        'LPO',              'LPO'],
                        ['job_prefix',        'Job Card',         'JOB'],
                        ['booking_prefix',    'Service Booking',  'BK'],
                        ['payment_prefix',    'Payment',          'PAY'],
                        ['sale_prefix',       'Car Sale',         'SALE'],
                        ['issue_prefix',      'Issue',            'ISS'],
                        ['assessment_prefix', 'Assessment',       'ASS'],
                    ];
                    foreach ($prefixFields as [$key, $label, $def]):
                    ?>
                    <div class="col-4">
                        <label class="form-label small"><?= $label ?></label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="<?= $key ?>" class="form-control font-monospace text-uppercase"
                                   placeholder="<?= $def ?>" value="<?= e($settings[$key] ?? $def) ?>">
                            <span class="input-group-text text-muted" style="font-size:10px">-0001</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-info mt-3 mb-0 py-2 small">
                    <i class="fa fa-info-circle me-1"></i>Prefix changes only affect <strong>new</strong> documents. Existing numbers are unchanged.
                </div>
            </div>
        </div>
    </div>
</div>
<button type="submit" class="btn btn-primary px-5"><i class="fa fa-check me-2"></i>Save Document Settings</button>
</form>
</div><!-- /documents pane -->

<!-- ══ EMAIL ═════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'email' ? 'show active' : '' ?>" id="pane-email" role="tabpanel">
<form method="POST">
<input type="hidden" name="action" value="save">
<input type="hidden" name="_tab" value="email">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fa fa-envelope text-primary"></i>
                <span>SMTP Email Configuration</span>
                <?php $smtpOk = !empty($settings['smtp_host'] ?? ''); ?>
                <span class="badge bg-<?= $smtpOk ? 'success' : 'secondary' ?> ms-auto">
                    <?= $smtpOk ? 'Configured' : 'Not Configured' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 small mb-4">
                    <i class="fa fa-info-circle me-1"></i>
                    Used for sending invoices, booking confirmations, and status updates.
                    Common hosts: <strong>smtp.gmail.com</strong> (port 587, TLS) or <strong>smtp.office365.com</strong>.
                </div>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">SMTP Host</label>
                        <input type="text" name="smtp_host" class="form-control" placeholder="smtp.gmail.com"
                               value="<?= e($settings['smtp_host'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Port</label>
                        <input type="number" name="smtp_port" class="form-control" placeholder="587"
                               value="<?= e($settings['smtp_port'] ?? '587') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Username / Email</label>
                        <input type="text" name="smtp_user" class="form-control" autocomplete="off"
                               placeholder="your@email.com" value="<?= e($settings['smtp_user'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password / App Password</label>
                        <div class="input-group">
                            <input type="password" name="smtp_pass" id="smtpPassInput" class="form-control" autocomplete="new-password"
                                   placeholder="<?= $smtpOk ? '••••••• (unchanged if blank)' : 'Enter password' ?>">
                            <button type="button" class="btn btn-outline-secondary" onclick="var f=document.getElementById('smtpPassInput');f.type=f.type==='text'?'password':'text'">
                                <i class="fa fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Encryption</label>
                        <select name="smtp_encryption" class="form-select">
                            <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls')==='tls'?'selected':'' ?>>TLS (Recommended)</option>
                            <option value="ssl" <?= ($settings['smtp_encryption'] ?? '')==='ssl'?'selected':'' ?>>SSL</option>
                            <option value="none" <?= ($settings['smtp_encryption'] ?? '')==='none'?'selected':'' ?>>None</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">From Email</label>
                        <input type="email" name="smtp_from_email" class="form-control"
                               placeholder="noreply@yourdomain.com" value="<?= e($settings['smtp_from_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">From Name</label>
                        <input type="text" name="smtp_from_name" class="form-control"
                               placeholder="<?= e($settings['company_name'] ?? 'Mascardi') ?>"
                               value="<?= e($settings['smtp_from_name'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-paper-plane me-2"></i>Test Email</div>
            <div class="card-body">
                <p class="text-muted small">Send a test email to verify your SMTP settings are working correctly. Save settings first.</p>
                <div class="mb-3">
                    <label class="form-label small">Send test to</label>
                    <input type="email" id="testEmailAddr" class="form-control" placeholder="your@email.com">
                </div>
                <button type="button" class="btn btn-outline-primary w-100" id="sendTestEmail">
                    <i class="fa fa-paper-plane me-1"></i>Send Test Email
                </button>
                <div id="testEmailResult" class="mt-2 small"></div>
            </div>
        </div>
        <div class="card mt-3">
            <div class="card-header small fw-semibold">Quick Setup Guides</div>
            <div class="list-group list-group-flush" style="font-size:12.5px">
                <div class="list-group-item py-2">
                    <strong>Gmail:</strong> host <code>smtp.gmail.com</code>, port <code>587</code>, TLS.<br>
                    Use an <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a>, not your regular password.
                </div>
                <div class="list-group-item py-2">
                    <strong>Outlook/Office 365:</strong> host <code>smtp.office365.com</code>, port <code>587</code>, TLS.
                </div>
                <div class="list-group-item py-2">
                    <strong>cPanel:</strong> host <code>mail.yourdomain.com</code>, port <code>465</code>, SSL.
                </div>
            </div>
        </div>
    </div>
</div>
<div class="mt-4">
    <button type="submit" class="btn btn-primary px-5"><i class="fa fa-check me-2"></i>Save Email Settings</button>
</div>
</form>
<script>
document.getElementById('sendTestEmail').addEventListener('click', function () {
    var addr = document.getElementById('testEmailAddr').value.trim();
    var res  = document.getElementById('testEmailResult');
    if (!addr) { res.innerHTML = '<span class="text-danger">Enter a recipient email.</span>'; return; }
    var btn = this;
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    res.innerHTML = '';
    fetch('<?= BASE_URL ?>/modules/settings/test_email.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'to=' + encodeURIComponent(addr)
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-paper-plane me-1"></i>Send Test Email';
        res.innerHTML = d.ok
            ? '<span class="text-success"><i class="fa fa-check-circle me-1"></i>' + d.message + '</span>'
            : '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>' + d.error + '</span>';
    })
    .catch(() => {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-paper-plane me-1"></i>Send Test Email';
        res.innerHTML = '<span class="text-danger">Network error. Check the console.</span>';
    });
});
</script>
</div><!-- /email pane -->

<!-- ══ INTEGRATIONS ═══════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'integrations' ? 'show active' : '' ?>" id="pane-integrations" role="tabpanel">
<form method="POST">
<input type="hidden" name="action" value="save">
<input type="hidden" name="_tab" value="integrations">
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fa fa-mobile-screen-button text-success" style="font-size:18px"></i>
        <span>M-Pesa Integration (Daraja API)</span>
        <span class="badge bg-<?= ($settings['mpesa_consumer_key'] ?? '') ? 'success' : 'secondary' ?> ms-auto">
            <?= ($settings['mpesa_consumer_key'] ?? '') ? 'Configured' : 'Not Configured' ?>
        </span>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="fa fa-info-circle me-1"></i>
            Register at <strong>developer.safaricom.co.ke</strong> to get credentials.
            The Callback URL must be a publicly accessible HTTPS address.
        </div>
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Environment</label>
                <select name="mpesa_env" class="form-select">
                    <option value="sandbox"    <?= ($settings['mpesa_env']??'sandbox')==='sandbox'   ?'selected':'' ?>>Sandbox (Testing)</option>
                    <option value="production" <?= ($settings['mpesa_env']??'')==='production'?'selected':'' ?>>Production (Live)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Shortcode (Paybill/Till)</label>
                <input type="text" name="mpesa_shortcode" class="form-control" placeholder="e.g. 174379"
                       value="<?= e($settings['mpesa_shortcode'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Consumer Key</label>
                <input type="text" name="mpesa_consumer_key" class="form-control font-monospace"
                       placeholder="From Daraja portal" value="<?= e($settings['mpesa_consumer_key'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Consumer Secret</label>
                <input type="password" name="mpesa_consumer_secret" class="form-control font-monospace"
                       placeholder="From Daraja portal" value="<?= e($settings['mpesa_consumer_secret'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Lipa Na M-Pesa Passkey</label>
                <input type="password" name="mpesa_passkey" class="form-control font-monospace"
                       placeholder="From Daraja portal" value="<?= e($settings['mpesa_passkey'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Callback URL <span class="text-danger">*</span></label>
                <input type="url" name="mpesa_callback_url" class="form-control"
                       placeholder="https://yourdomain.com/modules/payments/mpesa_callback.php"
                       value="<?= e($settings['mpesa_callback_url'] ?? '') ?>">
                <div class="form-text">Must be publicly accessible HTTPS.</div>
            </div>
        </div>
    </div>
</div>
<div class="mt-4">
    <button type="submit" class="btn btn-primary px-5"><i class="fa fa-check me-2"></i>Save Integration Settings</button>
</div>
</form>
</div><!-- /integrations pane -->

<!-- ══ PERMISSIONS ════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'permissions' ? 'show active' : '' ?>" id="pane-permissions" role="tabpanel">
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-shield-halved me-2"></i>Default Role Permissions</span>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="btn btn-xs btn-outline-primary">Manage Users</a>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info mx-3 mt-3 mb-0 py-2 small">
            <i class="fa fa-info-circle me-1"></i>
            These are the default permissions assigned when creating a new user. Individual permissions can be customised per user.
            <strong>Admin</strong> always has full unrestricted access.
        </div>
        <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 mt-3" style="font-size:12.5px">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3" style="width:180px">Module</th>
                    <th class="text-center">Admin</th>
                    <th class="text-center">Workshop Mgr</th>
                    <th class="text-center">Sales Officer</th>
                    <th class="text-center">Sales Person</th>
                    <th class="text-center">Mechanic</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matrixModules as $mod => $label): ?>
                <tr>
                    <td class="ps-3 fw-medium"><?= $label ?></td>
                    <?php
                    // Admin
                    echo '<td class="text-center"><span class="badge bg-success">Full</span></td>';
                    // Other roles
                    $roleKeys = ['workshop_manager','sales_officer','sales_person','mechanic'];
                    foreach ($roleKeys as $rk):
                        $canWrite  = in_array($mod, $roleMatrix[$rk]['write']  ?? []);
                        $canAccess = in_array($mod, $roleMatrix[$rk]['access'] ?? []);
                        if ($canWrite):
                    ?>
                    <td class="text-center"><span class="badge bg-warning text-dark">Read &amp; Write</span></td>
                    <?php elseif ($canAccess): ?>
                    <td class="text-center"><span class="badge bg-info text-dark">Read</span></td>
                    <?php else: ?>
                    <td class="text-center text-muted">—</td>
                    <?php endif; endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="px-3 pb-3 pt-2">
            <div class="d-flex gap-3 flex-wrap" style="font-size:12px">
                <span><span class="badge bg-success me-1">Full</span>Access + Write</span>
                <span><span class="badge bg-warning text-dark me-1">Read &amp; Write</span>Can view and modify</span>
                <span><span class="badge bg-info text-dark me-1">Read</span>View only</span>
                <span class="text-muted">— No access</span>
            </div>
        </div>
    </div>
</div>
</div><!-- /permissions pane -->

<!-- ══ SYSTEM ═════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $activeTab === 'system' ? 'show active' : '' ?>" id="pane-system" role="tabpanel">
<div class="row g-4">
    <!-- Backup -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-database me-2"></i>Database Backup</div>
            <div class="card-body d-flex flex-column">
                <p class="text-muted small">
                    Create a full database backup. Backups are stored in <code>/backups/</code>
                    and auto-cleaned after 30 days. Schedule <code>scripts/backup.bat</code>
                    in Windows Task Scheduler for daily automated backups.
                </p>
                <div class="mt-auto d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>/scripts/backup.php" class="btn btn-outline-primary">
                        <i class="fa fa-download me-1"></i>Create Backup Now
                    </a>
                    <a href="<?= BASE_URL ?>/scripts/backup.php?download=1" class="btn btn-outline-secondary">
                        <i class="fa fa-file-arrow-down me-1"></i>Download Latest
                    </a>
                </div>
            </div>
        </div>
    </div>
    <!-- System Info -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-circle-info me-2"></i>System Information</div>
            <div class="card-body p-0">
                <?php
                $sysInfo = [
                    'PHP Version'       => PHP_VERSION,
                    'PHP SAPI'          => PHP_SAPI,
                    'OS'                => PHP_OS,
                    'Server Software'   => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
                    'Document Root'     => BASE_PATH,
                    'Max Upload Size'   => ini_get('upload_max_filesize'),
                    'Max POST Size'     => ini_get('post_max_size'),
                    'Memory Limit'      => ini_get('memory_limit'),
                    'Max Exec Time'     => ini_get('max_execution_time') . 's',
                ];
                try {
                    $ver = $db->query("SELECT VERSION()")->fetchColumn();
                    $sysInfo['MySQL Version'] = $ver;
                    $cnt = $db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
                    $sysInfo['Total Cars'] = number_format($cnt);
                    $ucnt = $db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
                    $sysInfo['Active Users'] = $ucnt;
                } catch (\Throwable $e) {}
                ?>
                <table class="table table-sm mb-0" style="font-size:12.5px">
                    <?php foreach ($sysInfo as $label => $val): ?>
                    <tr>
                        <td class="ps-3 text-muted" style="width:45%"><?= $label ?></td>
                        <td class="fw-medium"><code style="font-size:12px"><?= e($val) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <!-- Quick Links -->
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="fa fa-link me-2"></i>Quick Administration Links</div>
            <div class="card-body">
                <div class="row g-3">
                    <?php
                    $adminLinks = [
                        ['modules/users/index.php',     'fa-users-gear', '#dbeafe', '#2563eb', 'Manage Users'],
                        ['modules/inventory/index.php', 'fa-boxes-stacked','#dcfce7','#16a34a','Inventory'],
                        ['modules/suppliers/index.php', 'fa-truck',      '#fef3c7', '#d97706', 'Suppliers'],
                        ['modules/audit/index.php',     'fa-clock-rotate-left','#f3e8ff','#7c3aed','Audit Log'],
                        ['modules/reports/index.php',   'fa-chart-bar',  '#fff7ed', '#ea580c', 'Reports'],
                        ['modules/locations/index.php', 'fa-location-dot','#fef2f2','#dc2626', 'Locations'],
                        ['modules/email_logs/index.php','fa-envelope-open-text','#f0fdf4','#15803d','Email Logs'],
                    ];
                    foreach ($adminLinks as [$path, $icon, $bg, $clr, $lbl]):
                    ?>
                    <div class="col-6 col-md-2">
                        <a href="<?= BASE_URL ?>/<?= $path ?>" class="quick-action-card">
                            <div class="qa-icon" style="background:<?= $bg ?>;color:<?= $clr ?>;font-size:20px"><i class="fa <?= $icon ?>"></i></div>
                            <span><?= $lbl ?></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /system pane -->

</div><!-- /tab-content -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
