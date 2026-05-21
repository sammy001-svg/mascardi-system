<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$pageTitle = 'System Settings';
$db = getDB();

$error = '';

// Ensure settings table has all required keys
$defaults = [
    'company_name'      => 'Mascardi Car Yard',
    'company_address'   => 'Nairobi, Kenya',
    'company_phone'     => '+254 700 000 000',
    'company_email'     => 'info@mascardi.co.ke',
    'company_pin'       => 'P051234567X',
    'vat_rate'          => '16',
    'currency'          => 'KES',
    'invoice_prefix'    => 'INV',
    'quotation_prefix'  => 'QT',
    'lpo_prefix'        => 'LPO',
    'job_prefix'        => 'JOB',
];

// Load all current settings
$rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings = array_column($rows, 'setting_value', 'setting_key');

// Insert any missing defaults
foreach ($defaults as $k => $v) {
    if (!array_key_exists($k, $settings)) {
        $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)")->execute([$k, $v]);
        $settings[$k] = $v;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updates = [
        'company_name'      => trim($_POST['company_name'] ?? ''),
        'company_address'   => trim($_POST['company_address'] ?? ''),
        'company_phone'     => trim($_POST['company_phone'] ?? ''),
        'company_email'     => trim($_POST['company_email'] ?? ''),
        'company_pin'       => trim($_POST['company_pin'] ?? ''),
        'vat_rate'          => trim($_POST['vat_rate'] ?? '16'),
        'currency'          => trim($_POST['currency'] ?? 'KES'),
        'invoice_prefix'    => strtoupper(trim($_POST['invoice_prefix'] ?? 'INV')),
        'quotation_prefix'  => strtoupper(trim($_POST['quotation_prefix'] ?? 'QT')),
        'lpo_prefix'        => strtoupper(trim($_POST['lpo_prefix'] ?? 'LPO')),
        'job_prefix'        => strtoupper(trim($_POST['job_prefix'] ?? 'JOB')),
    ];

    // M-Pesa settings
    $updates['mpesa_env']             = in_array($_POST['mpesa_env'] ?? '', ['sandbox','production']) ? $_POST['mpesa_env'] : 'sandbox';
    $updates['mpesa_consumer_key']    = trim($_POST['mpesa_consumer_key']    ?? '');
    $updates['mpesa_consumer_secret'] = trim($_POST['mpesa_consumer_secret'] ?? '');
    $updates['mpesa_shortcode']       = trim($_POST['mpesa_shortcode']       ?? '');
    $updates['mpesa_passkey']         = trim($_POST['mpesa_passkey']         ?? '');
    $updates['mpesa_callback_url']    = trim($_POST['mpesa_callback_url']    ?? '');

    if (!$updates['company_name']) {
        $error = 'Company name is required.';
    } else {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        foreach ($updates as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        $settings = array_merge($settings, $updates);
        setFlash('success', 'Settings saved successfully.');
        redirect(BASE_URL . '/modules/settings/index.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1">System Settings</h5>
        <div class="text-muted small">Configure company information and system defaults</div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Company Info -->
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <i class="fa fa-building me-2 text-primary"></i>Company Information
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Company Name <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" required value="<?= e($settings['company_name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="company_address" class="form-control" rows="2"><?= e($settings['company_address'] ?? '') ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone</label>
                    <input type="text" name="company_phone" class="form-control" value="<?= e($settings['company_phone'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="company_email" class="form-control" value="<?= e($settings['company_email'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">KRA PIN</label>
                    <input type="text" name="company_pin" class="form-control" placeholder="P051234567X" value="<?= e($settings['company_pin'] ?? '') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Financial & Document Settings -->
    <div class="col-12 col-lg-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="fa fa-coins me-2 text-primary"></i>Financial Settings
            </div>
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
                        <label class="form-label">VAT / Tax Rate (%)</label>
                        <div class="input-group">
                            <input type="number" name="vat_rate" class="form-control" min="0" max="100" step="0.01" value="<?= e($settings['vat_rate'] ?? '16') ?>">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <i class="fa fa-file-lines me-2 text-primary"></i>Document Numbering Prefixes
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label">Invoice Prefix</label>
                        <input type="text" name="invoice_prefix" class="form-control" placeholder="INV" value="<?= e($settings['invoice_prefix'] ?? 'INV') ?>">
                        <div class="form-text">e.g. INV-0001</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Quotation Prefix</label>
                        <input type="text" name="quotation_prefix" class="form-control" placeholder="QT" value="<?= e($settings['quotation_prefix'] ?? 'QT') ?>">
                        <div class="form-text">e.g. QT-0001</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">LPO Prefix</label>
                        <input type="text" name="lpo_prefix" class="form-control" placeholder="LPO" value="<?= e($settings['lpo_prefix'] ?? 'LPO') ?>">
                        <div class="form-text">e.g. LPO-0001</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Job Card Prefix</label>
                        <input type="text" name="job_prefix" class="form-control" placeholder="JOB" value="<?= e($settings['job_prefix'] ?? 'JOB') ?>">
                        <div class="form-text">e.g. JOB-0001</div>
                    </div>
                </div>
                <div class="alert alert-info mt-3 mb-0 py-2 small">
                    <i class="fa fa-info-circle me-1"></i>Changing prefixes affects <strong>new</strong> documents only. Existing document numbers are not changed.
                </div>
            </div>
        </div>
    </div>

    <!-- M-Pesa Settings -->
    <div class="col-12">
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
                    Register your app at <strong>developer.safaricom.co.ke</strong> to get these credentials.
                    The <strong>Callback URL</strong> must be a publicly accessible HTTPS address.
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Environment</label>
                        <select name="mpesa_env" class="form-select">
                            <option value="sandbox"    <?= ($settings['mpesa_env']??'sandbox')==='sandbox'    ?'selected':'' ?>>Sandbox (Testing)</option>
                            <option value="production" <?= ($settings['mpesa_env']??'')==='production' ?'selected':'' ?>>Production (Live)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Shortcode (Paybill/Till)</label>
                        <input type="text" name="mpesa_shortcode" class="form-control" placeholder="e.g. 174379" value="<?= e($settings['mpesa_shortcode'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Consumer Key</label>
                        <input type="text" name="mpesa_consumer_key" class="form-control font-monospace" placeholder="From Daraja portal" value="<?= e($settings['mpesa_consumer_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Consumer Secret</label>
                        <input type="password" name="mpesa_consumer_secret" class="form-control font-monospace" placeholder="From Daraja portal" value="<?= e($settings['mpesa_consumer_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Lipa Na M-Pesa Passkey</label>
                        <input type="password" name="mpesa_passkey" class="form-control font-monospace" placeholder="From Daraja portal" value="<?= e($settings['mpesa_passkey'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Callback URL <span class="text-danger">*</span></label>
                        <input type="url" name="mpesa_callback_url" class="form-control" placeholder="https://yourdomain.com/modules/payments/mpesa_callback.php"
                               value="<?= e($settings['mpesa_callback_url'] ?? '') ?>">
                        <div class="form-text">Must be publicly accessible HTTPS. Safaricom will POST confirmation to this URL.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Database Backup -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="fa fa-database me-2 text-primary"></i>Database Backup
            </div>
            <div class="card-body">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <p class="text-muted small mb-0">
                            Create a full database backup now. Backups are stored in the <code>/backups/</code> directory
                            and are automatically cleaned up after 30 days.
                            For automated daily backups, schedule <code>scripts/backup.bat</code> in Windows Task Scheduler.
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="<?= BASE_URL ?>/scripts/backup.php" class="btn btn-outline-primary">
                            <i class="fa fa-download me-1"></i>Create Backup Now
                        </a>
                        <a href="<?= BASE_URL ?>/scripts/backup.php?download=1" class="btn btn-outline-secondary ms-1">
                            <i class="fa fa-file-arrow-down me-1"></i>Download Latest
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="fa fa-link me-2 text-primary"></i>Quick Administration Links
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <a href="<?= BASE_URL ?>/modules/users/index.php" class="quick-action-card">
                            <div class="qa-icon" style="background:#dbeafe;color:#2563eb;font-size:20px"><i class="fa fa-users-gear"></i></div>
                            <span>Manage Users</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="quick-action-card">
                            <div class="qa-icon" style="background:#dcfce7;color:#16a34a;font-size:20px"><i class="fa fa-boxes-stacked"></i></div>
                            <span>Inventory</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="quick-action-card">
                            <div class="qa-icon" style="background:#fef3c7;color:#d97706;font-size:20px"><i class="fa fa-truck"></i></div>
                            <span>Suppliers</span>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="<?= BASE_URL ?>/modules/reports/index.php" class="quick-action-card">
                            <div class="qa-icon" style="background:#f3e8ff;color:#7c3aed;font-size:20px"><i class="fa fa-chart-bar"></i></div>
                            <span>Reports</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /row -->

<div class="mt-4">
    <button type="submit" class="btn btn-primary btn-lg px-5">
        <i class="fa fa-check me-2"></i>Save Settings
    </button>
</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
