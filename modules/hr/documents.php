<?php
/**
 * HR — Employee documents
 *
 * Contracts, IDs, certificates and licences, with expiry tracking so renewals
 * are noticed before they lapse rather than after.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$canEdit = canWrite('hr');
$errors  = [];

// Files are never linked from this directory directly — see document_file.php
// and the .htaccess deny alongside them.
$uploadDir = __DIR__ . '/../../uploads/hr_documents';

// ── Upload ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_doc' && $canEdit) {
    verifyCsrf();

    $p       = hrParseKey($_POST['staff_key'] ?? '');
    $docType = $_POST['doc_type'] ?? 'other';
    $title   = trim($_POST['title'] ?? '');
    $issue   = trim($_POST['issue_date']  ?? '') ?: null;
    $expiry  = trim($_POST['expiry_date'] ?? '') ?: null;
    $notes   = trim($_POST['notes'] ?? '');

    if (!$p)                                $errors[] = 'Select an employee.';
    if (!isset(hrDocumentTypes()[$docType])) $docType = 'other';
    if ($title === '')                      $errors[] = 'Give the document a title.';
    if ($issue && $expiry && strtotime($expiry) < strtotime($issue)) {
        $errors[] = 'The expiry date cannot be before the issue date.';
    }

    $storedName = null;
    if (!$errors && !empty($_FILES['file']['name'])) {
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The file could not be uploaded (error code ' . (int)$f['error'] . ').';
        } elseif ($f['size'] > 10 * 1024 * 1024) {
            $errors[] = 'Files must be 10 MB or smaller.';
        } else {
            // Whitelist by extension AND sniffed type — an employee file store
            // that accepts arbitrary uploads is a way into the server.
            $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png', 'webp' => 'image/webp'];
            if (!isset($allowed[$ext])) {
                $errors[] = 'Only PDF, JPG, PNG or WEBP files are accepted.';
            } else {
                $mime = function_exists('finfo_open')
                      ? (finfo_file($fi = finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) ?: '')
                      : $allowed[$ext];
                if (isset($fi)) finfo_close($fi);
                if ($mime !== $allowed[$ext]) {
                    $errors[] = 'That file is not a valid ' . strtoupper($ext) . '.';
                } else {
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                    $storedName = 'hrdoc_' . $p['type'] . $p['id'] . '_' . time() . '_'
                                . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $storedName)) {
                        $errors[] = 'The file could not be saved to the server.';
                        $storedName = null;
                    }
                }
            }
        }
    }

    if (!$errors) {
        try {
            $db->prepare("INSERT INTO hr_documents
                    (staff_type, staff_id, doc_type, title, file_path, issue_date, expiry_date, notes, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$p['type'], $p['id'], $docType, $title, $storedName,
                          $issue, $expiry, $notes ?: null, (int)(authUser()['id'] ?? 0)]);
            logActivity('create', 'hr', $p['id'], "Added HR document '{$title}'");
            setFlash('success', 'Document added.');
            redirect(BASE_URL . '/modules/hr/documents.php' . ($_POST['return_staff'] ?? '' ? '?staff=' . urlencode($_POST['return_staff']) : ''));
        } catch (\Throwable $e) {
            error_log('hr/documents add: ' . $e->getMessage());
            $errors[] = 'Could not save the document: ' . $e->getMessage();
        }
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_doc' && $canEdit) {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    try {
        $st = $db->prepare("SELECT * FROM hr_documents WHERE id = ?");
        $st->execute([$id]);
        if ($doc = $st->fetch(PDO::FETCH_ASSOC)) {
            $db->prepare("DELETE FROM hr_documents WHERE id = ?")->execute([$id]);
            // Remove the file too, otherwise deleted personnel records leave
            // their scans sitting in a web-reachable directory.
            if ($doc['file_path'] && is_file($uploadDir . '/' . $doc['file_path'])) {
                @unlink($uploadDir . '/' . $doc['file_path']);
            }
            logActivity('delete', 'hr', (int)$doc['staff_id'], "Deleted HR document '{$doc['title']}'");
            setFlash('success', 'Document deleted.');
        }
    } catch (\Throwable $e) {
        error_log('hr/documents delete: ' . $e->getMessage());
        setFlash('error', 'Could not delete the document: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/hr/documents.php');
}

// ── Data ──────────────────────────────────────────────────────────────────────
$staff    = hrStaffDirectory($db, ['include_exited' => true]);
$focus    = hrParseKey($_GET['staff'] ?? '');
$focusKey = $focus ? hrKey($focus['type'], $focus['id']) : '';
$fType    = $_GET['doc_type'] ?? '';
$fExpiry  = $_GET['expiry'] ?? '';

$where = []; $params = [];
if ($focus)  { $where[] = 'd.staff_type = ? AND d.staff_id = ?'; $params[] = $focus['type']; $params[] = $focus['id']; }
if (isset(hrDocumentTypes()[$fType])) { $where[] = 'd.doc_type = ?'; $params[] = $fType; } else { $fType = ''; }
if ($fExpiry === 'expiring') $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)';
elseif ($fExpiry === 'expired') $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date < CURDATE()';
else $fExpiry = '';

$docs = [];
try {
    $sql = "SELECT d.*, DATEDIFF(d.expiry_date, CURDATE()) AS days_left FROM hr_documents d"
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY (d.expiry_date IS NULL), d.expiry_date ASC, d.id DESC LIMIT 300";
    $st = $db->prepare($sql);
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$cnt = ['all' => 0, 'expiring' => 0, 'expired' => 0];
try {
    $cnt['all']      = (int)$db->query("SELECT COUNT(*) FROM hr_documents")->fetchColumn();
    $cnt['expiring'] = (int)$db->query("SELECT COUNT(*) FROM hr_documents
        WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetchColumn();
    $cnt['expired']  = (int)$db->query("SELECT COUNT(*) FROM hr_documents
        WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();
} catch (\Throwable $_) {}

$pageTitle = 'Employee Documents';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.dc-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.dc-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.dc-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.dc-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.dc-chip{ padding:6px 14px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.dc-chip:hover{ border-color:var(--brand); color:var(--brand); }
.dc-chip.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.dc-chip.warn.on{ background:#f59e0b; border-color:#f59e0b; }
.dc-chip.danger.on{ background:#dc2626; border-color:#dc2626; }

.dc-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.dc-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.dc-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.dc-card-title i{ color:var(--brand); }
.dc-card-body{ padding:16px; }

.dc-row{ display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border); flex-wrap:wrap; }
.dc-row:last-child{ border-bottom:0; }
.dc-icon{ width:38px; height:38px; border-radius:10px; flex:0 0 38px; display:flex; align-items:center;
    justify-content:center; font-size:15px; background:var(--brand-soft); color:var(--brand); }
.dc-main{ flex:1; min-width:190px; }
.dc-title{ font-size:13.5px; font-weight:700; color:var(--text); }
.dc-sub{ font-size:12px; color:var(--text-2,#64748b); margin-top:2px; }
.dc-tag{ font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.dc-empty{ text-align:center; padding:40px 20px; color:var(--text-2,#64748b); }
.dc-empty i{ font-size:32px; opacity:.3; display:block; margin-bottom:11px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
</style>

<div class="dc-head">
    <div>
        <h1><i class="fa fa-folder-open me-2" style="color:var(--brand)"></i>Employee Documents</h1>
        <p>
            <?php if ($focus && isset($staff[$focusKey])): ?>
                Files held for <strong><?= e($staff[$focusKey]['name']) ?></strong>.
            <?php else: ?>
                Contracts, identification and certificates, with renewal dates tracked.
            <?php endif; ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($focus): ?>
        <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($focusKey) ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-user me-1"></i>Their record
        </a>
        <a href="?" class="btn btn-sm btn-outline-secondary">All documents</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/hr/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>HR Dashboard
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="dc-chips">
    <?php $base = $focus ? ['staff' => $focusKey] : []; ?>
    <a class="dc-chip <?= !$fExpiry && !$fType ? 'on' : '' ?>" href="?<?= http_build_query($base) ?>">All <?= $cnt['all'] ?></a>
    <a class="dc-chip warn <?= $fExpiry === 'expiring' ? 'on' : '' ?>"
       href="?<?= http_build_query($base + ['expiry' => 'expiring']) ?>">Expiring in 60 days <?= $cnt['expiring'] ?></a>
    <a class="dc-chip danger <?= $fExpiry === 'expired' ? 'on' : '' ?>"
       href="?<?= http_build_query($base + ['expiry' => 'expired']) ?>">Expired <?= $cnt['expired'] ?></a>
</div>

<?php if ($canEdit): ?>
<div class="dc-card">
    <div class="dc-card-head"><h2 class="dc-card-title"><i class="fa fa-file-circle-plus"></i>Add a Document</h2></div>
    <div class="dc-card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_doc">
            <?php if ($focus): ?><input type="hidden" name="return_staff" value="<?= e($focusKey) ?>"><?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">Employee</label>
                <select name="staff_key" class="form-select form-select-sm" required>
                    <option value="">Select…</option>
                    <?php foreach ($staff as $k => $s): ?>
                    <option value="<?= e($k) ?>" <?= $focusKey === $k ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Document type</label>
                <select name="doc_type" class="form-select form-select-sm">
                    <?php foreach (hrDocumentTypes() as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control form-control-sm" required
                       placeholder="e.g. Employment contract 2026">
            </div>
            <div class="col-md-3">
                <label class="form-label">File <span class="text-muted fw-normal">(optional)</span></label>
                <input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-3">
                <label class="form-label">Issued</label>
                <input type="date" name="issue_date" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label">Expires</label>
                <input type="date" name="expiry_date" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label">Notes</label>
                <input type="text" name="notes" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus me-1"></i>Add</button>
            </div>
            <div class="col-12">
                <div class="form-text" style="font-size:11px">
                    PDF, JPG, PNG or WEBP up to 10 MB. Set an expiry date on anything that needs renewing —
                    it then appears on the HR dashboard 60 days ahead.
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="dc-card">
    <div class="dc-card-head">
        <h2 class="dc-card-title"><i class="fa fa-files"></i>Documents</h2>
        <span class="small text-muted"><?= count($docs) ?> shown</span>
    </div>

    <?php if (!$docs): ?>
        <div class="dc-empty">
            <i class="fa fa-folder-open"></i>
            <?= ($fExpiry || $fType || $focus) ? 'No documents match this filter.' : 'No documents on file yet.' ?>
        </div>
    <?php else: foreach ($docs as $d):
        $person = $staff[hrKey($d['staff_type'], (int)$d['staff_id'])] ?? null;
        $dl  = $d['expiry_date'] !== null ? (int)$d['days_left'] : null;
        $col = $dl === null ? '#64748b' : ($dl < 0 ? '#dc2626' : ($dl <= 30 ? '#f59e0b' : '#16a34a'));
        $ico = str_contains(strtolower((string)$d['file_path']), '.pdf') ? 'fa-file-pdf'
             : ($d['file_path'] ? 'fa-file-image' : 'fa-file-lines');
    ?>
    <div class="dc-row">
        <div class="dc-icon"><i class="fa <?= $ico ?>"></i></div>
        <div class="dc-main">
            <div class="dc-title"><?= e($d['title']) ?></div>
            <div class="dc-sub">
                <?= e(hrDocumentTypes()[$d['doc_type']] ?? 'Document') ?>
                <?php if ($person): ?>
                &middot; <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($person['key']) ?>"><?= e($person['name']) ?></a>
                <?php endif; ?>
                <?php if ($d['issue_date']): ?> &middot; issued <?= date('j M Y', strtotime($d['issue_date'])) ?><?php endif; ?>
                <?php if ($d['expiry_date']): ?> &middot; expires <?= date('j M Y', strtotime($d['expiry_date'])) ?><?php endif; ?>
            </div>
            <?php if ($d['notes']): ?><div class="dc-sub" style="font-style:italic">“<?= e($d['notes']) ?>”</div><?php endif; ?>
        </div>

        <span class="dc-tag" style="background:<?= $col ?>1f;color:<?= $col ?>">
            <?= $dl === null ? 'No expiry' : ($dl < 0 ? abs($dl) . 'd overdue' : $dl . 'd left') ?>
        </span>

        <div class="d-flex gap-1">
            <?php if ($d['file_path']): ?>
            <a href="<?= BASE_URL ?>/modules/hr/document_file.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-outline-primary" title="Open file"><i class="fa fa-arrow-up-right-from-square"></i></a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <form method="POST" class="d-inline"
                  onsubmit="return confirm('Delete “<?= e(addslashes($d['title'])) ?>”?\n\nThe stored file is removed too. This cannot be undone.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_doc">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
