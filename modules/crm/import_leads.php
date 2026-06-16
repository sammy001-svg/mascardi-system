<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');
if (!canWrite('crm')) {
    setFlash('error', 'You do not have permission to import leads.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

$db = getDB();

// Auto-migration: add import_batch column if it does not exist yet
try {
    $db->exec("ALTER TABLE crm_leads ADD COLUMN import_batch VARCHAR(36) NULL");
} catch (\Throwable $_) {}

// ── Template CSV download ────────────────────────────────────────────────────
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="leads_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name','phone','email','source','interested_in','budget','notes']);
    fputcsv($out, ['John Doe','+254712345678','john@example.com','walk_in','Toyota Land Cruiser V8','3500000','Prefers white colour']);
    fclose($out);
    exit;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
$validSources = ['walk_in','referral','facebook','instagram','website','phone_call','whatsapp','other'];
$crmFields    = ['name','phone','email','source','interested_in','budget','notes'];
$fieldLabels  = [
    'name'          => 'Name',
    'phone'         => 'Phone',
    'email'         => 'Email',
    'source'        => 'Source',
    'interested_in' => 'Interested In',
    'budget'        => 'Budget',
    'notes'         => 'Notes',
];

/**
 * Auto-detect a CRM field from a raw CSV column header.
 * Returns the matched field key or empty string for "skip".
 */
function detectField(string $col): string {
    $col = strtolower(trim($col));
    $map = [
        'name'          => ['name','full name','fullname','lead name','client name','customer'],
        'phone'         => ['phone','phone number','mobile','mobile number','tel','telephone','cell'],
        'email'         => ['email','email address','e-mail','mail'],
        'source'        => ['source','lead source','channel'],
        'interested_in' => ['interested_in','interested in','interest','car interest','looking for','vehicle','model'],
        'budget'        => ['budget','price','amount','max price','max budget'],
        'notes'         => ['notes','note','comments','comment','remarks','remark','description'],
    ];
    foreach ($map as $field => $synonyms) {
        if (in_array($col, $synonyms, true)) return $field;
    }
    return '';
}

// ── Step routing ─────────────────────────────────────────────────────────────
$step = (int)($_GET['step'] ?? 1);

// ════════════════════════════════════════════════════════════════════════════
// POST from Step 1 — process uploaded file
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step1_submit'])) {
    $fileError = null;
    $file      = $_FILES['csv_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $fileError = 'No file uploaded or upload failed. Please choose a CSV file.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $fileError = 'File is too large. Maximum allowed size is 5 MB.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $fileError = 'Only .csv files are accepted.';
        }
    }

    if ($fileError) {
        setFlash('error', $fileError);
        redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
    }

    // Parse CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        setFlash('error', 'Could not read the uploaded file.');
        redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
    }

    // First row = headers
    $rawHeaders = fgetcsv($handle);
    if ($rawHeaders === false || empty($rawHeaders)) {
        fclose($handle);
        setFlash('error', 'The CSV file appears to be empty or has no header row.');
        redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
    }

    // Build column map from header auto-detection
    $colmap = [];
    foreach ($rawHeaders as $i => $h) {
        $colmap[$i] = detectField($h);
    }

    // Require at least a 'name' column to be detectable
    $nameColFound = in_array('name', $colmap, true);

    // Read data rows (max 500, skip completely blank rows and rows with blank name)
    $rows     = [];
    $skipped  = 0;
    while (($row = fgetcsv($handle)) !== false) {
        if (count($rows) >= 500) { $skipped++; continue; }
        // Skip rows where every cell is empty
        if (count(array_filter(array_map('trim', $row))) === 0) continue;
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) {
        setFlash('error', 'The CSV file contains no data rows (only a header was found).');
        redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
    }

    // Store in session
    $_SESSION['import_headers'] = $rawHeaders;
    $_SESSION['import_preview'] = $rows;
    $_SESSION['import_colmap']  = $colmap;
    if ($skipped > 0) {
        setFlash('warning', "Note: Only the first 500 rows were loaded; {$skipped} additional row(s) were truncated.");
    }
    if (!$nameColFound) {
        setFlash('warning', 'Could not auto-detect a "name" column. Please map it manually on the next step.');
    }

    redirect(BASE_URL . '/modules/crm/import_leads.php?step=2');
}

// ════════════════════════════════════════════════════════════════════════════
// POST from Step 2 — save column mapping and proceed to step 3
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step2_submit'])) {
    $colmap   = $_POST['col_map']   ?? [];   // array: csv_index => crm_field|''
    $assignTo = (int)($_POST['assign_to'] ?? 0);

    $_SESSION['import_colmap']    = $colmap;
    $_SESSION['import_assignto']  = $assignTo;

    redirect(BASE_URL . '/modules/crm/import_leads.php?step=3');
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 3 — Execute import
// ════════════════════════════════════════════════════════════════════════════
$importResults = null;
if ($step === 3) {
    $rows    = $_SESSION['import_preview'] ?? [];
    $colmap  = $_SESSION['import_colmap']  ?? [];
    $assignTo = (int)($_SESSION['import_assignto'] ?? 0) ?: null;

    if (empty($rows)) {
        redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
    }

    $batch     = uniqid('imp_', true);
    $imported  = 0;
    $dupes     = 0;
    $failed    = [];
    $now       = date('Y-m-d H:i:s');

    $insertStmt = $db->prepare("
        INSERT INTO crm_leads
            (name, phone, email, source, interested_in, budget, notes,
             assigned_to, import_batch, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($rows as $rowNum => $row) {
        // Map each column to a CRM field
        $data = [];
        foreach ($colmap as $colIdx => $field) {
            if ($field === '' || $field === 'skip') continue;
            $data[$field] = trim($row[$colIdx] ?? '');
        }

        $name = $data['name'] ?? '';
        if ($name === '') continue; // silently skip blank-name rows

        $phone       = $data['phone']         ?? '' ?: null;
        $email       = $data['email']         ?? '' ?: null;
        $source      = $data['source']        ?? '';
        $interestedIn = $data['interested_in'] ?? '' ?: null;
        $budgetRaw   = $data['budget']        ?? '';
        $notes       = $data['notes']         ?? '' ?: null;

        // Validate / normalise source
        if (!in_array($source, $validSources, true)) $source = 'other';

        // Budget: strip non-numeric chars, keep decimals
        $budget = null;
        if ($budgetRaw !== '') {
            $cleaned = preg_replace('/[^0-9.]/', '', $budgetRaw);
            if ($cleaned !== '') $budget = (float)$cleaned;
        }

        // Duplicate check (only if phone or email is present)
        try {
            if ($phone || $email) {
                $checkPhone = $phone ?? '';
                $checkEmail = $email ?? '';
                $dupeSql = "SELECT COUNT(*) FROM crm_leads WHERE ";
                $dupeParams = [];
                if ($phone && $email) {
                    $dupeSql    .= "(phone = ? AND phone != '') OR (email = ? AND email != '')";
                    $dupeParams  = [$checkPhone, $checkEmail];
                } elseif ($phone) {
                    $dupeSql    .= "phone = ? AND phone != ''";
                    $dupeParams  = [$checkPhone];
                } else {
                    $dupeSql    .= "email = ? AND email != ''";
                    $dupeParams  = [$checkEmail];
                }
                $dupeCheck = $db->prepare($dupeSql);
                $dupeCheck->execute($dupeParams);
                if ((int)$dupeCheck->fetchColumn() > 0) {
                    $dupes++;
                    continue;
                }
            }

            $insertStmt->execute([
                $name, $phone, $email, $source, $interestedIn,
                $budget, $notes, $assignTo, $batch, $now, $now,
            ]);
            $imported++;
        } catch (\Throwable $ex) {
            $failed[] = ['row' => $rowNum + 2, 'name' => $name, 'reason' => $ex->getMessage()];
        }
    }

    logActivity('create', 'crm_leads', null,
        "CSV import batch {$batch}: {$imported} imported, {$dupes} skipped (dupes), " . count($failed) . " failed");

    $importResults = compact('imported', 'dupes', 'failed', 'batch');

    // Clear session
    unset($_SESSION['import_preview'], $_SESSION['import_colmap'], $_SESSION['import_assignto'], $_SESSION['import_headers']);
}

// ════════════════════════════════════════════════════════════════════════════
// Guard: steps 2 & 3 need session data
// ════════════════════════════════════════════════════════════════════════════
if ($step === 2 && empty($_SESSION['import_preview'])) {
    setFlash('warning', 'Please upload a CSV file first.');
    redirect(BASE_URL . '/modules/crm/import_leads.php?step=1');
}

// ── Load session data for rendering ─────────────────────────────────────────
$previewRows    = $_SESSION['import_preview'] ?? [];
$previewHeaders = $_SESSION['import_headers'] ?? [];
$savedColmap    = $_SESSION['import_colmap']  ?? [];
$totalRows      = count($previewRows);
$previewSample  = array_slice($previewRows, 0, 5);

// Active users for assignment dropdown
$salesUsers = [];
try {
    $salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
} catch (\Throwable $_) {}

$pageTitle = 'Import Leads';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.import-step-indicator { display:flex; align-items:center; gap:0; margin-bottom:1.5rem; }
.step-bubble {
    width:32px; height:32px; border-radius:50%; display:flex; align-items:center;
    justify-content:center; font-weight:700; font-size:13px; flex-shrink:0;
    border:2px solid #dee2e6; background:#fff; color:#6c757d; transition:.2s;
}
.step-bubble.active  { background:#0d6efd; border-color:#0d6efd; color:#fff; }
.step-bubble.done    { background:#198754; border-color:#198754; color:#fff; }
.step-label { font-size:12px; color:#6c757d; margin-top:3px; text-align:center; }
.step-label.active { color:#0d6efd; font-weight:600; }
.step-label.done   { color:#198754; }
.step-connector { flex:1; height:2px; background:#dee2e6; margin:0 6px; margin-bottom:18px; }
.step-connector.done { background:#198754; }
.step-wrapper { display:flex; flex-direction:column; align-items:center; }
.table-preview th { background:#f8f9fa; font-size:12px; }
.table-preview td { font-size:12px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fa fa-file-import me-2 text-primary"></i>Import Leads from CSV
    </h5>
    <a href="leads.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Leads
    </a>
</div>

<!-- Step indicator -->
<?php
$currentStep = ($importResults !== null) ? 3 : $step;
function stepClass(int $s, int $current): string {
    if ($s < $current)  return 'done';
    if ($s === $current) return 'active';
    return '';
}
?>
<div class="import-step-indicator mb-4">
    <div class="step-wrapper">
        <div class="step-bubble <?= stepClass(1, $currentStep) ?>">
            <?= $currentStep > 1 ? '<i class="fa fa-check" style="font-size:11px"></i>' : '1' ?>
        </div>
        <div class="step-label <?= stepClass(1, $currentStep) ?>">Upload</div>
    </div>
    <div class="step-connector <?= $currentStep > 1 ? 'done' : '' ?>"></div>
    <div class="step-wrapper">
        <div class="step-bubble <?= stepClass(2, $currentStep) ?>">
            <?= $currentStep > 2 ? '<i class="fa fa-check" style="font-size:11px"></i>' : '2' ?>
        </div>
        <div class="step-label <?= stepClass(2, $currentStep) ?>">Map &amp; Preview</div>
    </div>
    <div class="step-connector <?= $currentStep > 2 ? 'done' : '' ?>"></div>
    <div class="step-wrapper">
        <div class="step-bubble <?= stepClass(3, $currentStep) ?>">3</div>
        <div class="step-label <?= stepClass(3, $currentStep) ?>">Import</div>
    </div>
</div>

<?php
// Flash messages
$flash = $_SESSION['flash'] ?? null;
if ($flash) {
    unset($_SESSION['flash']);
    $flashType = $flash['type'] === 'error' ? 'danger' : $flash['type'];
    echo '<div class="alert alert-' . $flashType . ' alert-dismissible fade show" role="alert">'
        . e($flash['message'])
        . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}
?>

<?php // ══════════════════════════════════════════════════════════════════════
// RENDER: STEP 1 — Upload form
// ══════════════════════════════════════════════════════════════════════════ ?>
<?php if ($currentStep === 1): ?>

<div class="row justify-content-center">
    <div class="col-lg-7">

        <div class="alert alert-info d-flex gap-2 align-items-start">
            <i class="fa fa-circle-info mt-1 flex-shrink-0"></i>
            <div>
                <strong>How it works:</strong> Download the template CSV below, fill in your leads,
                then upload the file here. Maximum <strong>500 rows</strong> per import.
                Rows where phone or email already exists in the system will be skipped automatically.
            </div>
        </div>

        <!-- Template download -->
        <div class="card mb-3">
            <div class="card-body d-flex align-items-center justify-content-between gap-3 flex-wrap">
                <div>
                    <div class="fw-semibold mb-1"><i class="fa fa-download me-1 text-primary"></i>Step 1 of 3 — Download the template</div>
                    <div class="text-muted small">
                        Headers: <code>name, phone, email, source, interested_in, budget, notes</code>
                    </div>
                </div>
                <a href="?download_template=1" class="btn btn-outline-primary btn-sm flex-shrink-0">
                    <i class="fa fa-file-csv me-1"></i>Download Template CSV
                </a>
            </div>
        </div>

        <!-- Upload form -->
        <div class="card">
            <div class="card-header fw-semibold py-2">
                <i class="fa fa-upload me-1"></i>Step 2 of 3 — Upload your filled CSV
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="step1_submit" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Choose CSV File <span class="text-danger">*</span>
                        </label>
                        <input type="file" name="csv_file" accept=".csv,text/csv"
                               class="form-control" required id="csvFileInput">
                        <div class="form-text">Accepted format: .csv — Max 5 MB — Max 500 data rows</div>
                    </div>

                    <div class="alert alert-secondary py-2 small mb-3">
                        <strong>Valid values for <code>source</code> column:</strong><br>
                        <code>walk_in</code>, <code>referral</code>, <code>facebook</code>,
                        <code>instagram</code>, <code>website</code>, <code>phone_call</code>,
                        <code>whatsapp</code>, <code>other</code>
                        <br><span class="text-muted">Any unrecognised value defaults to <code>other</code>.</span>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="fa fa-arrow-right me-1"></i>Upload &amp; Preview
                        </button>
                        <a href="leads.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
document.getElementById('uploadBtn').addEventListener('click', function() {
    var f = document.getElementById('csvFileInput');
    if (f.files.length) {
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';
        this.disabled = true;
    }
});
</script>

<?php // ══════════════════════════════════════════════════════════════════════
// RENDER: STEP 2 — Preview & column mapping
// ══════════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($currentStep === 2): ?>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="step2_submit" value="1">

    <div class="row g-3">

        <!-- Column mapping card -->
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header fw-semibold py-2">
                    <i class="fa fa-table-columns me-1"></i>Map CSV Columns to CRM Fields
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        We auto-detected the mapping below. Adjust any mismatches, then click Import.
                    </p>
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th style="width:45%">CSV Column</th>
                                <th>Maps to CRM Field</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewHeaders as $i => $header): ?>
                            <tr>
                                <td class="small align-middle fw-semibold"><?= e($header) ?></td>
                                <td>
                                    <select name="col_map[<?= (int)$i ?>]" class="form-select form-select-sm">
                                        <option value="">— Skip —</option>
                                        <?php foreach ($fieldLabels as $fk => $fl):
                                            $sel = (($savedColmap[$i] ?? '') === $fk) ? 'selected' : ''; ?>
                                        <option value="<?= $fk ?>" <?= $sel ?>><?= e($fl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right column: assign + preview -->
        <div class="col-lg-7">

            <!-- Assign to -->
            <div class="card mb-3">
                <div class="card-header fw-semibold py-2">
                    <i class="fa fa-user-tie me-1"></i>Assign Imported Leads To
                </div>
                <div class="card-body py-2">
                    <select name="assign_to" class="form-select form-select-sm select2">
                        <option value="0">— Leave Unassigned —</option>
                        <?php foreach ($salesUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">All <?= $totalRows ?> rows will be assigned to this person.</div>
                </div>
            </div>

            <!-- Duplicate note -->
            <div class="alert alert-warning d-flex gap-2 align-items-start py-2 small">
                <i class="fa fa-triangle-exclamation mt-1 flex-shrink-0"></i>
                <span>
                    <strong>Duplicate check:</strong> Rows where <em>phone</em> OR <em>email</em>
                    already exists in the system will be skipped automatically.
                </span>
            </div>

            <!-- Preview table -->
            <div class="card">
                <div class="card-header fw-semibold py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-eye me-1"></i>Preview — first <?= min(5, $totalRows) ?> of <?= $totalRows ?> row(s)</span>
                    <span class="badge bg-primary"><?= $totalRows ?> total</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-preview table-hover table-bordered mb-0">
                            <thead>
                                <tr>
                                    <?php foreach ($previewHeaders as $h): ?>
                                    <th class="px-2 py-1"><?= e($h) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewSample as $row): ?>
                                <tr>
                                    <?php foreach ($previewHeaders as $i => $_): ?>
                                    <td class="px-2 py-1" title="<?= e($row[$i] ?? '') ?>"><?= e($row[$i] ?? '') ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalRows > 5): ?>
                    <div class="text-muted small px-3 py-2 border-top">
                        … and <?= $totalRows - 5 ?> more row(s) not shown in preview.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Action buttons -->
    <div class="d-flex gap-2 mt-3">
        <a href="?step=1" class="btn btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
        <button type="submit" class="btn btn-primary" id="importBtn">
            <i class="fa fa-file-import me-1"></i>Import <?= $totalRows ?> Lead<?= $totalRows !== 1 ? 's' : '' ?>
        </button>
    </div>
</form>

<script>
document.getElementById('importBtn').addEventListener('click', function() {
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing…';
    this.disabled = true;
    this.closest('form').submit();
});
</script>

<?php // ══════════════════════════════════════════════════════════════════════
// RENDER: STEP 3 — Import results
// ══════════════════════════════════════════════════════════════════════════ ?>
<?php elseif ($currentStep === 3 && $importResults !== null): ?>

<div class="row justify-content-center">
    <div class="col-lg-7">

        <div class="card">
            <div class="card-header fw-semibold py-2">
                <i class="fa fa-clipboard-check me-1"></i>Import Complete
            </div>
            <div class="card-body">

                <!-- Summary counts -->
                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="card border-success text-center py-3">
                            <div class="fs-2 fw-bold text-success"><?= $importResults['imported'] ?></div>
                            <div class="small text-muted">Imported successfully</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="card border-warning text-center py-3">
                            <div class="fs-2 fw-bold text-warning"><?= $importResults['dupes'] ?></div>
                            <div class="small text-muted">Duplicates skipped</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="card border-danger text-center py-3">
                            <div class="fs-2 fw-bold text-danger"><?= count($importResults['failed']) ?></div>
                            <div class="small text-muted">Rows failed</div>
                        </div>
                    </div>
                </div>

                <!-- Result detail lines -->
                <?php if ($importResults['imported'] > 0): ?>
                <div class="alert alert-success py-2 small mb-2">
                    <i class="fa fa-circle-check me-1"></i>
                    <strong><?= $importResults['imported'] ?></strong> lead<?= $importResults['imported'] !== 1 ? 's' : '' ?> imported successfully.
                </div>
                <?php endif; ?>

                <?php if ($importResults['dupes'] > 0): ?>
                <div class="alert alert-warning py-2 small mb-2">
                    <i class="fa fa-forward-step me-1"></i>
                    <strong><?= $importResults['dupes'] ?></strong> row<?= $importResults['dupes'] !== 1 ? 's' : '' ?> skipped — phone or email already exists in the system.
                </div>
                <?php endif; ?>

                <?php if (!empty($importResults['failed'])): ?>
                <div class="mb-2">
                    <div class="alert alert-danger py-2 small mb-1">
                        <i class="fa fa-circle-xmark me-1"></i>
                        <strong><?= count($importResults['failed']) ?></strong> row<?= count($importResults['failed']) !== 1 ? 's' : '' ?> could not be inserted.
                        <a class="ms-1 link-danger" data-bs-toggle="collapse" href="#failedRowsDetail">Show details</a>
                    </div>
                    <div class="collapse" id="failedRowsDetail">
                        <div class="card card-body py-2 small">
                            <table class="table table-sm table-bordered mb-0">
                                <thead>
                                    <tr><th>Row #</th><th>Name</th><th>Reason</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($importResults['failed'] as $f): ?>
                                    <tr>
                                        <td><?= (int)$f['row'] ?></td>
                                        <td><?= e($f['name']) ?></td>
                                        <td class="text-danger"><?= e($f['reason']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action buttons -->
                <div class="d-flex flex-wrap gap-2 mt-4 pt-2 border-top">
                    <?php if ($importResults['imported'] > 0): ?>
                    <a href="leads.php?q=<?= urlencode($importResults['batch']) ?>" class="btn btn-success">
                        <i class="fa fa-users me-1"></i>View Imported Leads
                    </a>
                    <?php endif; ?>
                    <a href="?step=1" class="btn btn-outline-primary">
                        <i class="fa fa-file-import me-1"></i>Import Another File
                    </a>
                    <a href="leads.php" class="btn btn-outline-secondary">
                        <i class="fa fa-check me-1"></i>Done
                    </a>
                </div>

            </div>
        </div>

    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
