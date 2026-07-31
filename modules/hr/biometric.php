<?php
/**
 * HR — ZKTeco biometric integration console.
 *
 * Devices, PIN-to-employee mapping, the raw punch log, manual sync and the
 * setup instructions an installer needs at the terminal itself.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/zk_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);
zkMigrate($db);

$canEdit = canWrite('hr');
$errors  = [];
$cfg     = zkConfig();

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_device') {
        $id     = (int)($_POST['device_id'] ?? 0);
        $sn     = trim($_POST['serial_number'] ?? '');
        $name   = trim($_POST['name'] ?? '');
        $mode   = in_array($_POST['mode'] ?? '', ['push','pull','import'], true) ? $_POST['mode'] : 'push';
        $ip     = trim($_POST['ip_address'] ?? '');
        $port   = (int)($_POST['port'] ?? 4370) ?: 4370;
        $key    = (int)($_POST['comm_key'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending','active','disabled'], true) ? $_POST['status'] : 'pending';
        $loc    = (int)($_POST['location_id'] ?? 0) ?: null;

        if ($sn === '') $errors[] = 'The serial number is required — it is how the device identifies itself.';
        if ($mode === 'pull' && $ip === '') $errors[] = 'Pull mode needs the device IP address.';

        if (!$errors) {
            try {
                if ($id) {
                    $db->prepare("UPDATE zk_devices SET serial_number=?, name=?, mode=?, ip_address=?,
                                  port=?, comm_key=?, status=?, location_id=? WHERE id=?")
                       ->execute([$sn, $name ?: null, $mode, $ip ?: null, $port, $key, $status, $loc, $id]);
                    logActivity('update', 'hr', $id, "Updated biometric device {$sn}");
                } else {
                    $db->prepare("INSERT INTO zk_devices (serial_number,name,mode,ip_address,port,comm_key,status,location_id)
                                  VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$sn, $name ?: null, $mode, $ip ?: null, $port, $key, $status, $loc]);
                    logActivity('create', 'hr', (int)$db->lastInsertId(), "Registered biometric device {$sn}");
                }
                setFlash('success', 'Device saved.');
                redirect(BASE_URL . '/modules/hr/biometric.php');
            } catch (\PDOException $e) {
                $errors[] = $e->getCode() === '23000'
                    ? 'Another device is already registered with that serial number.'
                    : 'Could not save the device: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_device') {
        $id = (int)($_POST['device_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM zk_devices WHERE id=?")->execute([$id]);
            // Punches are deliberately left behind — they are the attendance
            // evidence, and removing a terminal should not erase history.
            logActivity('delete', 'hr', $id, 'Removed biometric device');
            setFlash('success', 'Device removed. Its recorded punches were kept.');
        } catch (\Throwable $e) { setFlash('error', 'Could not remove the device.'); }
        redirect(BASE_URL . '/modules/hr/biometric.php');
    }

    if ($action === 'map_pin') {
        $pin = trim($_POST['device_pin'] ?? '');
        $sn  = trim($_POST['device_sn'] ?? '');
        $p   = hrParseKey($_POST['staff_key'] ?? '');
        if ($pin === '' || !$p) {
            $errors[] = 'Choose both a device number and an employee.';
        } else {
            try {
                $db->prepare("INSERT INTO zk_enrollments (device_sn, device_pin, staff_type, staff_id, created_by)
                              VALUES (?,?,?,?,?)
                              ON DUPLICATE KEY UPDATE staff_type=VALUES(staff_type), staff_id=VALUES(staff_id)")
                   ->execute([$sn, $pin, $p['type'], $p['id'], (int)(authUser()['id'] ?? 0)]);

                // Attach punches already captured under this PIN, then rebuild
                // the affected days so they count immediately.
                $n = zkBackfillPin($db, $sn, $pin, $p['type'], $p['id']);
                if ($n > 0) {
                    $r = $db->prepare("SELECT MIN(DATE(punch_time)), MAX(DATE(punch_time)) FROM zk_punches
                                       WHERE staff_type=? AND staff_id=?");
                    $r->execute([$p['type'], $p['id']]);
                    [$from, $to] = $r->fetch(PDO::FETCH_NUM);
                    if ($from) zkRollupRange($db, $from, $to, $cfg);
                }
                $who = hrStaffMember($db, $p['type'], $p['id']);
                setFlash('success', 'Device number ' . $pin . ' linked to ' . ($who['name'] ?? 'employee')
                    . ($n ? " — {$n} existing punch(es) applied." : '.'));
            } catch (\Throwable $e) {
                error_log('zk map_pin: ' . $e->getMessage());
                setFlash('error', 'Could not save the mapping: ' . $e->getMessage());
            }
            redirect(BASE_URL . '/modules/hr/biometric.php?tab=pins');
        }
    }

    if ($action === 'unmap_pin') {
        $id = (int)($_POST['enrol_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM zk_enrollments WHERE id=?")->execute([$id]);
            setFlash('success', 'Mapping removed. Past attendance already recorded is unaffected.');
        } catch (\Throwable $_) {}
        redirect(BASE_URL . '/modules/hr/biometric.php?tab=pins');
    }

    if ($action === 'save_rules') {
        foreach ([
            'zk_work_start'     => trim($_POST['work_start'] ?? '08:00'),
            'zk_work_end'       => trim($_POST['work_end'] ?? '17:00'),
            'zk_late_grace_min' => (string)max(0, (int)($_POST['late_grace_min'] ?? 10)),
            'zk_min_hours_full' => (string)max(0, (float)($_POST['min_hours_full'] ?? 6)),
            'zk_dedupe_seconds' => (string)max(0, (int)($_POST['dedupe_seconds'] ?? 60)),
            'zk_auto_rollup'    => empty($_POST['auto_rollup']) ? '0' : '1',
            'zk_push_key'       => trim($_POST['push_key'] ?? ''),
        ] as $k => $v) {
            zkSetSetting($db, $k, $v);
        }
        setFlash('success', 'Attendance rules saved. Use “Rebuild attendance” to apply them to past days.');
        redirect(BASE_URL . '/modules/hr/biometric.php?tab=setup');
    }

    if ($action === 'rebuild') {
        $from = $_POST['from'] ?? date('Y-m-01');
        $to   = $_POST['to']   ?? date('Y-m-d');
        $r = zkRollupRange($db, $from, $to);
        if (!empty($r['error'])) {
            setFlash('error', 'Rebuild failed: ' . $r['error']);
        } else {
            setFlash('success', sprintf(
                'Rebuilt %d employee-day(s): %d attendance record(s) written%s.',
                $r['days'], $r['written'],
                $r['skipped_manual'] ? ", {$r['skipped_manual']} left alone because HR had edited them" : ''));
        }
        redirect(BASE_URL . '/modules/hr/biometric.php?tab=log');
    }

    if ($action === 'pull_now') {
        require_once __DIR__ . '/zk_pull.php';
        $id = (int)($_POST['device_id'] ?? 0);
        $st = $db->prepare("SELECT * FROM zk_devices WHERE id=?");
        $st->execute([$id]);
        $dev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dev || !$dev['ip_address']) {
            setFlash('error', 'That device has no IP address recorded.');
        } else {
            $res = zkPullFromDevice($dev['ip_address'], (int)$dev['port'], (int)$dev['comm_key']);
            if (!$res['ok']) {
                setFlash('error', $res['error']);
            } else {
                $stored = zkStorePunches($db, $dev['serial_number'], $res['rows'], 'pull');
                $roll   = $stored['days'] ? zkRollupRange($db, min($stored['days']), max($stored['days']), $cfg) : ['written' => 0];
                setFlash('success', sprintf('%s New: %d, already had: %d, unrecognised number: %d. Attendance rows: %d.',
                    $res['info'], $stored['stored'], $stored['duplicates'], $stored['unmapped'], $roll['written'] ?? 0));
            }
        }
        redirect(BASE_URL . '/modules/hr/biometric.php');
    }

    if ($action === 'import_file') {
        $sn = trim($_POST['import_sn'] ?? '');
        if (empty($_FILES['logfile']['name'])) {
            $errors[] = 'Choose a file exported from the device software.';
        } elseif ($_FILES['logfile']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The file could not be uploaded (error ' . (int)$_FILES['logfile']['error'] . ').';
        } elseif ($_FILES['logfile']['size'] > 8 * 1024 * 1024) {
            $errors[] = 'Files must be 8 MB or smaller.';
        } else {
            $body   = (string)file_get_contents($_FILES['logfile']['tmp_name']);
            // ZKTime writes some exports as UTF-16; decode so the parser sees text.
            if (str_starts_with($body, "\xFF\xFE") || str_starts_with($body, "\xFE\xFF")) {
                $body = mb_convert_encoding($body, 'UTF-8', 'UTF-16');
            }
            $parsed = zkParseAttlog($body);
            if (!$parsed['rows']) {
                $errors[] = 'No attendance rows were recognised in that file. It should contain an employee '
                          . 'number and a date/time on each line.';
            } else {
                $res  = zkStorePunches($db, $sn, $parsed['rows'], 'import');
                $roll = $res['days'] ? zkRollupRange($db, min($res['days']), max($res['days']), $cfg) : ['written' => 0];
                logActivity('create', 'hr', 0, "Imported {$res['stored']} biometric punch(es) from file");
                setFlash('success', sprintf(
                    'Read %d row(s): %d new, %d already recorded, %d from an unrecognised number, %d unreadable. '
                    . '%d attendance record(s) updated.',
                    count($parsed['rows']), $res['stored'], $res['duplicates'],
                    $res['unmapped'], $parsed['skipped'], $roll['written'] ?? 0));
                redirect(BASE_URL . '/modules/hr/biometric.php?tab=log');
            }
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'devices';
if (!in_array($tab, ['devices','pins','log','setup'], true)) $tab = 'devices';

$devices = [];
try { $devices = $db->query("SELECT * FROM zk_devices ORDER BY status='pending' DESC, name, serial_number")->fetchAll(PDO::FETCH_ASSOC); }
catch (\Throwable $_) {}

$pendingDevices = array_values(array_filter($devices, fn($d) => $d['status'] === 'pending'));
$staff    = hrStaffDirectory($db);
$unmapped = zkUnmappedPins($db);

$mappings = [];
try {
    $mappings = $db->query("SELECT * FROM zk_enrollments ORDER BY CAST(device_pin AS UNSIGNED), device_pin")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$locations = [];
try { $locations = $db->query("SELECT id, name FROM locations ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); }
catch (\Throwable $_) {}

$punches = [];
$punchDate = $_GET['d'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $punchDate)) $punchDate = date('Y-m-d');
try {
    $st = $db->prepare("SELECT * FROM zk_punches WHERE DATE(punch_time) = ? ORDER BY punch_time DESC LIMIT 300");
    $st->execute([$punchDate]);
    $punches = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$stats = ['devices' => count($devices), 'today' => 0, 'unmapped' => count($unmapped), 'mapped' => count($mappings)];
try {
    $st = $db->prepare("SELECT COUNT(*) FROM zk_punches WHERE DATE(punch_time)=CURDATE()");
    $st->execute(); $stats['today'] = (int)$st->fetchColumn();
} catch (\Throwable $_) {}

$recentLog = [];
try { $recentLog = $db->query("SELECT * FROM zk_push_log ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC); }
catch (\Throwable $_) {}

$editDevice = null;
if (($eid = (int)($_GET['edit'] ?? 0)) > 0) {
    foreach ($devices as $d) if ((int)$d['id'] === $eid) $editDevice = $d;
}

$pageTitle = 'Biometric Attendance';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.bi-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.bi-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.bi-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.bi-stats{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
@media(max-width:768px){ .bi-stats{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.bi-stat{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:14px 16px; box-shadow:var(--sh-sm); }
.bi-stat-v{ font-size:22px; font-weight:900; line-height:1.1; color:var(--text); }
.bi-stat-l{ font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); margin-top:5px; }

.bi-tabs{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.bi-tab{ padding:7px 15px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.bi-tab:hover{ border-color:var(--brand); color:var(--brand); }
.bi-tab.on{ background:var(--brand); border-color:var(--brand); color:#fff; }

.bi-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.bi-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
.bi-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.bi-card-title i{ color:var(--brand); }
.bi-card-body{ padding:16px; }

.bi-row{ display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border); flex-wrap:wrap; }
.bi-row:last-child{ border-bottom:0; }
.bi-dot{ width:9px; height:9px; border-radius:50%; flex:0 0 9px; }
.bi-main{ flex:1; min-width:180px; }
.bi-name{ font-size:13.5px; font-weight:700; color:var(--text); }
.bi-sub{ font-size:12px; color:var(--text-2,#64748b); margin-top:2px; }
.bi-tag{ font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.bi-empty{ text-align:center; padding:38px 20px; color:var(--text-2,#64748b); }
.bi-empty i{ font-size:32px; opacity:.3; display:block; margin-bottom:11px; }

.bi-url{ display:flex; align-items:center; gap:8px; background:var(--surface-alt); border:1px solid var(--border);
    border-radius:var(--r); padding:10px 12px; font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:12.5px; color:var(--text); word-break:break-all; }
.bi-steps{ counter-reset:s; list-style:none; padding:0; margin:0; }
.bi-steps li{ counter-increment:s; position:relative; padding:0 0 14px 34px; font-size:13px; color:var(--text); line-height:1.55; }
.bi-steps li::before{ content:counter(s); position:absolute; left:0; top:0; width:23px; height:23px; border-radius:50%;
    background:var(--brand); color:#fff; font-size:11.5px; font-weight:800; display:flex; align-items:center; justify-content:center; }
.bi-steps b{ font-weight:700; }
.bi-kv{ font-family:ui-monospace,Menlo,Consolas,monospace; background:var(--surface-alt);
    border:1px solid var(--border); border-radius:5px; padding:1px 6px; font-size:12px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
.bi-table{ width:100%; font-size:12.5px; border-collapse:collapse; }
.bi-table th{ text-align:left; padding:9px 16px; background:var(--surface-alt); border-bottom:1px solid var(--border);
    font-size:10.5px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-2,#64748b); font-weight:800; }
.bi-table td{ padding:9px 16px; border-bottom:1px solid var(--border); color:var(--text); }
.bi-table tr:last-child td{ border-bottom:0; }
.bi-scroll{ overflow-x:auto; }
</style>

<div class="bi-head">
    <div>
        <h1><i class="fa fa-fingerprint me-2" style="color:var(--brand)"></i>Biometric Attendance</h1>
        <p>ZKTeco terminals feeding clock-in and clock-out straight into the attendance register.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/hr/attendance.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-calendar-days me-1"></i>Register
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>HR Dashboard
        </a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if ($pendingDevices): ?>
<div class="alert alert-warning py-2">
    <i class="fa fa-triangle-exclamation me-1"></i>
    <strong><?= count($pendingDevices) ?></strong>
    device<?= count($pendingDevices) === 1 ? ' has' : 's have' ?> connected but
    <?= count($pendingDevices) === 1 ? 'is' : 'are' ?> not approved yet, so
    <?= count($pendingDevices) === 1 ? 'its' : 'their' ?> punches are being discarded.
    Approve <?= count($pendingDevices) === 1 ? 'it' : 'them' ?> below to start recording.
</div>
<?php endif; ?>

<?php if ($unmapped && $tab !== 'pins'): ?>
<div class="alert alert-info py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="small">
        <i class="fa fa-user-question me-1"></i>
        <strong><?= count($unmapped) ?></strong> device number<?= count($unmapped) === 1 ? '' : 's' ?>
        <?= count($unmapped) === 1 ? 'is' : 'are' ?> scanning but not linked to anyone —
        those punches are stored but count for nobody.
    </span>
    <a href="?tab=pins" class="btn btn-sm btn-info text-dark"><i class="fa fa-link me-1"></i>Link them</a>
</div>
<?php endif; ?>

<div class="bi-stats">
    <div class="bi-stat"><div class="bi-stat-v"><?= $stats['devices'] ?></div><div class="bi-stat-l">Terminals</div></div>
    <div class="bi-stat"><div class="bi-stat-v" style="color:#16a34a"><?= $stats['today'] ?></div><div class="bi-stat-l">Scans Today</div></div>
    <div class="bi-stat"><div class="bi-stat-v"><?= $stats['mapped'] ?></div><div class="bi-stat-l">Employees Linked</div></div>
    <div class="bi-stat"><div class="bi-stat-v" style="color:<?= $stats['unmapped'] ? '#f59e0b' : 'var(--text)' ?>"><?= $stats['unmapped'] ?></div><div class="bi-stat-l">Unlinked Numbers</div></div>
</div>

<div class="bi-tabs">
    <a class="bi-tab <?= $tab === 'devices' ? 'on' : '' ?>" href="?tab=devices">Terminals</a>
    <a class="bi-tab <?= $tab === 'pins'    ? 'on' : '' ?>" href="?tab=pins">Employee Numbers<?= $unmapped ? ' (' . count($unmapped) . ')' : '' ?></a>
    <a class="bi-tab <?= $tab === 'log'     ? 'on' : '' ?>" href="?tab=log">Scan Log</a>
    <a class="bi-tab <?= $tab === 'setup'   ? 'on' : '' ?>" href="?tab=setup">Setup &amp; Rules</a>
</div>

<?php if ($tab === 'devices'): ?>

    <?php if ($canEdit): ?>
    <div class="bi-card">
        <div class="bi-card-head">
            <h2 class="bi-card-title"><i class="fa fa-<?= $editDevice ? 'pen' : 'plus' ?>"></i>
                <?= $editDevice ? 'Edit Terminal' : 'Register a Terminal' ?></h2>
            <?php if ($editDevice): ?><a href="?tab=devices" class="btn btn-xs btn-outline-secondary">Cancel</a><?php endif; ?>
        </div>
        <div class="bi-card-body">
            <form method="POST" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_device">
                <input type="hidden" name="device_id" value="<?= (int)($editDevice['id'] ?? 0) ?>">
                <div class="col-md-3">
                    <label class="form-label">Serial number</label>
                    <input type="text" name="serial_number" class="form-control form-control-sm" required
                           value="<?= e($editDevice['serial_number'] ?? '') ?>" placeholder="e.g. CGE7231900123">
                    <div class="form-text" style="font-size:11px">On the device: Menu → System Info.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control form-control-sm"
                           value="<?= e($editDevice['name'] ?? '') ?>" placeholder="e.g. Main Gate">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Connection</label>
                    <select name="mode" class="form-select form-select-sm" id="modeSel">
                        <?php foreach (['push' => 'Push (recommended)', 'pull' => 'Pull over LAN', 'import' => 'File import only'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= ($editDevice['mode'] ?? 'push') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach (['active' => 'Active', 'pending' => 'Awaiting approval', 'disabled' => 'Disabled'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= ($editDevice['status'] ?? 'pending') === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Location</label>
                    <select name="location_id" class="form-select form-select-sm">
                        <option value="">—</option>
                        <?php foreach ($locations as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (int)($editDevice['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Device IP <span class="text-muted fw-normal">(pull only)</span></label>
                    <input type="text" name="ip_address" class="form-control form-control-sm"
                           value="<?= e($editDevice['ip_address'] ?? '') ?>" placeholder="192.168.1.201">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Port</label>
                    <input type="number" name="port" class="form-control form-control-sm" value="<?= (int)($editDevice['port'] ?? 4370) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Comm key</label>
                    <input type="number" name="comm_key" class="form-control form-control-sm" value="<?= (int)($editDevice['comm_key'] ?? 0) ?>">
                    <div class="form-text" style="font-size:11px">0 if unset on the device.</div>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100"><i class="fa fa-floppy-disk me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="bi-card">
        <div class="bi-card-head">
            <h2 class="bi-card-title"><i class="fa fa-microchip"></i>Registered Terminals</h2>
            <span class="small text-muted"><?= count($devices) ?></span>
        </div>
        <?php if (!$devices): ?>
            <div class="bi-empty">
                <i class="fa fa-microchip"></i>
                No terminals yet.<br>
                <span class="small">Point a device at the push URL under <a href="?tab=setup">Setup</a> and it will
                appear here by itself the moment it connects.</span>
            </div>
        <?php else: foreach ($devices as $d):
            $sc = ['active' => '#16a34a', 'pending' => '#f59e0b', 'disabled' => '#64748b'][$d['status']];
            $sl = ['active' => 'Active', 'pending' => 'Awaiting approval', 'disabled' => 'Disabled'][$d['status']];
            $seen = $d['last_seen_at'] ? strtotime($d['last_seen_at']) : 0;
            $online = $seen && (time() - $seen) < 3600;
        ?>
        <div class="bi-row">
            <span class="bi-dot" style="background:<?= $online ? '#16a34a' : '#cbd5e1' ?>"
                  title="<?= $online ? 'Seen in the last hour' : 'Not seen recently' ?>"></span>
            <div class="bi-main">
                <div class="bi-name"><?= e($d['name'] ?: 'Terminal') ?>
                    <span class="text-muted fw-normal" style="font-size:12px">· <?= e($d['serial_number']) ?></span></div>
                <div class="bi-sub">
                    <?= ucfirst($d['mode']) ?> mode
                    <?php if ($d['ip_address']): ?>· <?= e($d['ip_address']) ?>:<?= (int)$d['port'] ?><?php endif; ?>
                    · <?= (int)$d['punch_count'] ?> scan<?= (int)$d['punch_count'] === 1 ? '' : 's' ?>
                    · <?= $d['last_seen_at'] ? 'last seen ' . date('j M H:i', $seen) : 'never connected' ?>
                    <?php if ($d['firmware']): ?>· fw <?= e($d['firmware']) ?><?php endif; ?>
                </div>
            </div>
            <span class="bi-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
            <?php if ($canEdit): ?>
            <div class="d-flex gap-1">
                <?php if ($d['status'] === 'pending'): ?>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_device">
                    <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="serial_number" value="<?= e($d['serial_number']) ?>">
                    <input type="hidden" name="name" value="<?= e($d['name']) ?>">
                    <input type="hidden" name="mode" value="<?= e($d['mode']) ?>">
                    <input type="hidden" name="ip_address" value="<?= e($d['ip_address']) ?>">
                    <input type="hidden" name="port" value="<?= (int)$d['port'] ?>">
                    <input type="hidden" name="comm_key" value="<?= (int)$d['comm_key'] ?>">
                    <input type="hidden" name="location_id" value="<?= (int)$d['location_id'] ?>">
                    <input type="hidden" name="status" value="active">
                    <button class="btn btn-xs btn-success"><i class="fa fa-check me-1"></i>Approve</button>
                </form>
                <?php endif; ?>
                <?php if ($d['mode'] === 'pull' && $d['ip_address']): ?>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="pull_now">
                    <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-xs btn-outline-primary"><i class="fa fa-download me-1"></i>Pull now</button>
                </form>
                <?php endif; ?>
                <a href="?tab=devices&edit=<?= (int)$d['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Remove <?= e(addslashes($d['name'] ?: $d['serial_number'])) ?>?\n\nRecorded scans are kept.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_device">
                    <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
                    <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>

<?php elseif ($tab === 'pins'): ?>

    <?php if ($unmapped): ?>
    <div class="bi-card">
        <div class="bi-card-head">
            <h2 class="bi-card-title"><i class="fa fa-user-question"></i>Numbers Waiting to be Linked</h2>
            <span class="small text-muted"><?= count($unmapped) ?></span>
        </div>
        <div class="bi-card-body" style="padding-bottom:6px">
            <p class="small text-muted">
                These enrolment numbers are scanning on a terminal but no employee is attached, so their
                clock-ins count for nobody. Linking one also applies every scan already recorded under it.
            </p>
        </div>
        <?php foreach ($unmapped as $u): ?>
        <div class="bi-row">
            <div class="bi-main">
                <div class="bi-name">Enrolment number <?= e($u['device_pin']) ?></div>
                <div class="bi-sub">
                    <?= (int)$u['punches'] ?> scan<?= (int)$u['punches'] === 1 ? '' : 's' ?>
                    · <?= date('j M H:i', strtotime($u['first_seen'])) ?> – <?= date('j M H:i', strtotime($u['last_seen'])) ?>
                    <?php if ($u['device_sn']): ?>· <?= e($u['device_sn']) ?><?php endif; ?>
                </div>
            </div>
            <?php if ($canEdit): ?>
            <form method="POST" class="d-flex gap-2 align-items-center flex-wrap">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="map_pin">
                <input type="hidden" name="device_pin" value="<?= e($u['device_pin']) ?>">
                <input type="hidden" name="device_sn" value="<?= e($u['device_sn']) ?>">
                <select name="staff_key" class="form-select form-select-sm" style="min-width:230px" required>
                    <option value="">Link to employee…</option>
                    <?php foreach ($staff as $k => $s): ?>
                    <option value="<?= e($k) ?>"><?= e($s['name']) ?> — <?= e(hrStaffTypes()[$s['staff_type']]['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary"><i class="fa fa-link me-1"></i>Link</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="bi-card">
        <div class="bi-card-head">
            <h2 class="bi-card-title"><i class="fa fa-link"></i>Linked Employees</h2>
            <span class="small text-muted"><?= count($mappings) ?></span>
        </div>
        <?php if ($canEdit): ?>
        <div class="bi-card-body" style="border-bottom:1px solid var(--border)">
            <form method="POST" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="map_pin">
                <div class="col-md-3">
                    <label class="form-label">Enrolment number on the device</label>
                    <input type="text" name="device_pin" class="form-control form-control-sm" required placeholder="e.g. 7">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Employee</label>
                    <select name="staff_key" class="form-select form-select-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($staff as $k => $s): ?>
                        <option value="<?= e($k) ?>"><?= e($s['name']) ?> — <?= e(hrStaffTypes()[$s['staff_type']]['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Terminal</label>
                    <select name="device_sn" class="form-select form-select-sm">
                        <option value="">All terminals</option>
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= e($d['serial_number']) ?>"><?= e($d['name'] ?: $d['serial_number']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus me-1"></i>Add link</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!$mappings): ?>
            <div class="bi-empty"><i class="fa fa-link-slash"></i>No employees linked to a device number yet.</div>
        <?php else: foreach ($mappings as $m):
            $p = hrStaffMember($db, $m['staff_type'], (int)$m['staff_id']);
        ?>
        <div class="bi-row">
            <div class="bi-main">
                <div class="bi-name"><?= e($p['name'] ?? 'Employee #' . $m['staff_id']) ?></div>
                <div class="bi-sub">
                    Enrolment number <strong><?= e($m['device_pin']) ?></strong>
                    · <?= $m['device_sn'] ? e($m['device_sn']) : 'all terminals' ?>
                </div>
            </div>
            <?php if ($p): ?>
            <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($p['key']) ?>"
               class="btn btn-xs btn-outline-secondary"><i class="fa fa-user"></i></a>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this link?\n\nAttendance already recorded is not affected.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="unmap_pin">
                <input type="hidden" name="enrol_id" value="<?= (int)$m['id'] ?>">
                <button class="btn btn-xs btn-outline-danger"><i class="fa fa-link-slash"></i></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>

<?php elseif ($tab === 'log'): ?>

    <?php if ($canEdit): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="bi-card mb-0 h-100">
                <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-file-import"></i>Import from a File</h2></div>
                <div class="bi-card-body">
                    <p class="small text-muted">
                        Export the attendance log from ZKTime / ZKAccess and upload it here. Works with tab, comma
                        or semicolon separated files. Re-importing the same file is safe — scans already recorded
                        are recognised and skipped.
                    </p>
                    <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="import_file">
                        <div class="col-sm-5">
                            <label class="form-label">File</label>
                            <input type="file" name="logfile" class="form-control form-control-sm" required
                                   accept=".txt,.dat,.csv,.log">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">From which terminal</label>
                            <select name="import_sn" class="form-select form-select-sm">
                                <option value="">Not specified</option>
                                <?php foreach ($devices as $d): ?>
                                <option value="<?= e($d['serial_number']) ?>"><?= e($d['name'] ?: $d['serial_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <button class="btn btn-sm btn-primary w-100"><i class="fa fa-upload me-1"></i>Import</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="bi-card mb-0 h-100">
                <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-rotate"></i>Rebuild Attendance</h2></div>
                <div class="bi-card-body">
                    <p class="small text-muted">
                        Recalculates clock-in, clock-out and status from the stored scans. Use it after linking an
                        employee or changing the rules. Days an HR user edited by hand are never overwritten.
                    </p>
                    <form method="POST" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="rebuild">
                        <div class="col-sm-4">
                            <label class="form-label">From</label>
                            <input type="date" name="from" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">To</label>
                            <input type="date" name="to" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-sm-4">
                            <button class="btn btn-sm btn-outline-primary w-100"><i class="fa fa-rotate me-1"></i>Rebuild</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="bi-card">
        <div class="bi-card-head">
            <h2 class="bi-card-title"><i class="fa fa-list"></i>Scans</h2>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="tab" value="log">
                <input type="date" name="d" value="<?= e($punchDate) ?>" class="form-control form-control-sm"
                       style="width:160px" onchange="this.form.submit()">
            </form>
        </div>
        <?php if (!$punches): ?>
            <div class="bi-empty"><i class="fa fa-fingerprint"></i>No scans on <?= date('j M Y', strtotime($punchDate)) ?>.</div>
        <?php else: ?>
        <div class="bi-scroll">
            <table class="bi-table">
                <thead><tr><th>Time</th><th>Number</th><th>Employee</th><th>How</th><th>Terminal</th><th>Source</th></tr></thead>
                <tbody>
                <?php foreach ($punches as $p):
                    $who = $p['staff_id'] ? hrStaffMember($db, $p['staff_type'], (int)$p['staff_id']) : null;
                ?>
                <tr>
                    <td class="fw-semibold"><?= date('H:i:s', strtotime($p['punch_time'])) ?></td>
                    <td><code><?= e($p['device_pin']) ?></code></td>
                    <td>
                        <?php if ($who): ?>
                            <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($who['key']) ?>"><?= e($who['name']) ?></a>
                        <?php else: ?>
                            <span class="bi-tag" style="background:#f59e0b1f;color:#b45309">Not linked</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted"><?= e(zkVerifyModes()[$p['verify_mode']] ?? 'Unknown') ?></td>
                    <td class="text-muted"><?= e($p['device_sn'] ?: '—') ?></td>
                    <td class="text-muted"><?= e(ucfirst($p['source'])) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($recentLog): ?>
    <div class="bi-card">
        <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-tower-broadcast"></i>Recent Device Traffic</h2></div>
        <div class="bi-scroll">
            <table class="bi-table">
                <thead><tr><th>When</th><th>Terminal</th><th>Request</th><th>Rows</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($recentLog as $l): ?>
                <tr>
                    <td class="text-muted text-nowrap"><?= date('j M H:i:s', strtotime($l['created_at'])) ?></td>
                    <td><?= e($l['device_sn'] ?: '—') ?></td>
                    <td class="text-muted"><?= e($l['method'] . ' ' . $l['endpoint'] . ($l['table_name'] ? ' · ' . $l['table_name'] : '')) ?></td>
                    <td><?= (int)$l['rows_received'] ?> / <?= (int)$l['rows_stored'] ?></td>
                    <td class="text-muted small"><?= e($l['note']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php else: /* setup */ ?>

    <div class="bi-card">
        <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-tower-broadcast"></i>Connect a Terminal (Push)</h2></div>
        <div class="bi-card-body">
            <p class="small text-muted">
                The terminal sends scans to this server by itself, so it works over the internet from any branch
                and needs no fixed IP or open port at the yard. This is the mode to use.
            </p>
            <div class="bi-url mb-3">
                <i class="fa fa-link" style="color:var(--brand)"></i>
                <span id="pushUrl"><?= e(zkPushUrl()) ?><?= $cfg['push_key'] !== '' ? '?key=' . e($cfg['push_key']) : '' ?></span>
                <button type="button" class="btn btn-xs btn-outline-secondary ms-auto" onclick="
                    navigator.clipboard.writeText(document.getElementById('pushUrl').textContent.trim());
                    this.innerHTML='<i class=&quot;fa fa-check&quot;></i>';">
                    <i class="fa fa-copy"></i>
                </button>
            </div>
            <ol class="bi-steps">
                <li>On the terminal: <b>Menu → Comm. → Ethernet</b>. Give it an IP on the yard network and confirm
                    it can reach the internet.</li>
                <li><b>Menu → Comm. → Cloud Server Setting</b> (called <i>ADMS</i> or <i>Server Setting</i> on some
                    models).</li>
                <li>Set <span class="bi-kv">Server Address</span> to
                    <span class="bi-kv"><?= e(parse_url(BASE_URL, PHP_URL_HOST) ?: 'your-domain') ?></span>
                    and <span class="bi-kv">Server Port</span> to
                    <span class="bi-kv"><?= (parse_url(BASE_URL, PHP_URL_SCHEME) === 'https') ? '443' : '80' ?></span>.
                    Turn <span class="bi-kv">Enable Domain Name</span> on, and
                    <span class="bi-kv">Enable Proxy Server</span> off.</li>
                <li>Save and reboot the terminal. It connects within about a minute and appears under
                    <a href="?tab=devices">Terminals</a> as <b>awaiting approval</b>.</li>
                <li>Approve it there, then link each enrolment number to an employee under
                    <a href="?tab=pins">Employee Numbers</a>. Scans start counting from that moment, and any
                    already captured are applied too.</li>
            </ol>
            <div class="alert alert-secondary py-2 small mb-0">
                <i class="fa fa-circle-info me-1"></i>
                If the terminal never appears, the usual causes are the path <span class="bi-kv">/iclock/</span>
                not being reachable (check <code>iclock/.htaccess</code> is deployed and <code>mod_rewrite</code>
                is on), or the device being unable to resolve the domain. Every request the server does receive is
                listed under <a href="?tab=log">Scan Log</a> → Recent Device Traffic.
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="bi-card h-100">
                <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-sliders"></i>Attendance Rules</h2></div>
                <div class="bi-card-body">
                    <form method="POST" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_rules">
                        <div class="col-6">
                            <label class="form-label">Work starts</label>
                            <input type="time" name="work_start" class="form-control form-control-sm" value="<?= e($cfg['work_start']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Work ends</label>
                            <input type="time" name="work_end" class="form-control form-control-sm" value="<?= e($cfg['work_end']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Grace before “late” (min)</label>
                            <input type="number" name="late_grace_min" min="0" class="form-control form-control-sm" value="<?= (int)$cfg['late_grace_min'] ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Hours below which it is a half day</label>
                            <input type="number" name="min_hours_full" min="0" step="0.5" class="form-control form-control-sm" value="<?= e((string)$cfg['min_hours_full']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Ignore repeat scans within (sec)</label>
                            <input type="number" name="dedupe_seconds" min="0" class="form-control form-control-sm" value="<?= (int)$cfg['dedupe_seconds'] ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Shared key <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="push_key" class="form-control form-control-sm" value="<?= e($cfg['push_key']) ?>" placeholder="Blank = off">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_rollup" id="ar" <?= $cfg['auto_rollup'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="ar">
                                    Update the attendance register the moment a scan arrives
                                </label>
                            </div>
                        </div>
                        <div class="col-12 mt-2">
                            <button class="btn btn-sm btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save rules</button>
                        </div>
                        <div class="col-12">
                            <div class="form-text" style="font-size:11px">
                                A shared key appends <code>?key=…</code> to the push URL so a stranger who guesses a
                                serial number cannot post attendance. Only set it if your terminal allows a URL
                                with parameters — many older models do not.
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="bi-card h-100">
                <div class="bi-card-head"><h2 class="bi-card-title"><i class="fa fa-circle-question"></i>How Scans Become Attendance</h2></div>
                <div class="bi-card-body">
                    <ul class="small mb-0" style="padding-left:18px;line-height:1.75;color:var(--text)">
                        <li>The <strong>first scan</strong> of a day is the clock-in, the <strong>last</strong> is the
                            clock-out. The F1–F6 in/out keys are ignored, because in practice almost nobody presses
                            them and trusting them would leave every clock-out empty.</li>
                        <li>Scans repeated within the dedupe window count once, so a double tap does not read as a
                            same-minute arrival and departure.</li>
                        <li>Arriving after the start time plus grace marks the day <strong>late</strong>.</li>
                        <li>A short day is a <strong>half day</strong> — but only when there is a clock-out. A missing
                            clock-out is treated as a forgotten tap, never as leaving early.</li>
                        <li>Anything an HR user types into the register by hand <strong>wins permanently</strong>.
                            Rebuilding never overwrites it.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
