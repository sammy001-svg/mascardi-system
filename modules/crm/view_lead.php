<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Lead';
$db  = getDB();
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN pinned_car_id INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN lead_score TINYINT UNSIGNED DEFAULT 0"); } catch (\Throwable $_) {}
require_once __DIR__ . '/crm_helpers.php';
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/crm/leads.php');

$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

$lead = $db->prepare("
    SELECT l.*, u.name AS assigned_name, c.name AS client_name
    FROM crm_leads l
    LEFT JOIN users u ON u.id = l.assigned_to
    LEFT JOIN clients c ON c.id = l.client_id
    WHERE l.id = ?
");
$lead->execute([$id]); $lead = $lead->fetch();
if (!$lead) { setFlash('error','Lead not found.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

// Data isolation: CRM agents can only view leads assigned to themselves
if ($isCrmAgent && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$pageTitle = $lead['name'];

$stages = [
    'hot'       => ['Hot',       'danger',    'fa-fire'],
    'lukewarm'  => ['Lukewarm',  'warning',   'fa-temperature-half'],
    'cold'      => ['Cold',      'info',      'fa-snowflake'],
    'lost'      => ['Lost',      'secondary', 'fa-circle-xmark'],
    'reserved'  => ['Reserved',  'purple',    'fa-bookmark'],
    'delivered' => ['Delivered', 'success',   'fa-truck'],
];

$activityTypes = [
    'call'       => ['Call',       'fa-phone',        'text-success'],
    'whatsapp'   => ['WhatsApp',   'fa-comment-dots', 'text-success'],
    'email'      => ['Email',      'fa-envelope',     'text-primary'],
    'visit'      => ['Visit',      'fa-location-dot', 'text-warning'],
    'test_drive' => ['Test Drive', 'fa-car-side',     'text-purple'],
    'meeting'    => ['Meeting',    'fa-users',        'text-info'],
    'note'       => ['Note',       'fa-note-sticky',  'text-secondary'],
];

$sourceLabels = [
    'walk_in'    => 'Walk-in',    'referral'   => 'Referral',
    'facebook'   => 'Facebook',   'instagram'  => 'Instagram',
    'website'    => 'Website',    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'      => 'Other',
];

$salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_stage' && canWrite('crm')) {
        $newStage   = $_POST['stage']       ?? '';
        $lostReason = trim($_POST['lost_reason'] ?? '') ?: null;
        if (array_key_exists($newStage, $stages)) {
            $db->prepare("UPDATE crm_leads SET stage=?, lost_reason=COALESCE(?,lost_reason), updated_at=NOW() WHERE id=?")
               ->execute([$newStage, $lostReason, $id]);
            if ($newStage === 'delivered') {
                $db->prepare("UPDATE crm_leads SET converted_at=NOW() WHERE id=? AND converted_at IS NULL")->execute([$id]);
            }
            logActivity('update','crm_leads',$id,"Stage changed to: $newStage");
            require_once __DIR__ . '/../../includes/notifications.php';
            if ($newStage === 'delivered') {
                notifyRoles(['admin','sales_manager','general_manager'], 'sale',
                    "Lead Delivered: {$lead['name']}",
                    $lead['interested_in'] ? "Interested in: {$lead['interested_in']}" : '',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            } elseif ($newStage === 'lost') {
                notifyRoles(['admin','sales_manager'], 'info',
                    "Lead Lost: {$lead['name']}",
                    $lostReason ?: 'No reason recorded',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            }
            setFlash('success','Stage updated.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'log_activity' && canWrite('crm')) {
        $type      = $_POST['type']           ?? 'note';
        $summary   = trim($_POST['summary']   ?? '');
        $outcome   = trim($_POST['outcome']   ?? '') ?: null;
        $followUp  = trim($_POST['follow_up_date'] ?? '') ?: null;

        if ($summary) {
            $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, outcome, follow_up_date, created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$id, $type, $summary, $outcome, $followUp, authUser()['id']]);

            if ($followUp) {
                $db->prepare("UPDATE crm_leads SET follow_up_date=?, updated_at=NOW() WHERE id=?")->execute([$followUp, $id]);
            }
            setFlash('success','Activity logged.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'update_details' && canWrite('crm')) {
        $name        = trim($_POST['name']           ?? '');
        $phone       = trim($_POST['phone']          ?? '') ?: null;
        $email       = trim($_POST['email']          ?? '') ?: null;
        $interestedIn = trim($_POST['interested_in'] ?? '') ?: null;
        $budget      = $_POST['budget'] !== '' ? (float)$_POST['budget'] : null;
        $assignedTo  = (int)($_POST['assigned_to']   ?? 0) ?: null;
        $notes       = trim($_POST['notes']          ?? '') ?: null;
        $followUp    = trim($_POST['follow_up_date'] ?? '') ?: null;
        $lostReason  = trim($_POST['lost_reason']    ?? '') ?: null;

        if ($name) {
            $prevAssigned = (int)$lead['assigned_to'];
            $db->prepare("UPDATE crm_leads SET name=?,phone=?,email=?,interested_in=?,budget=?,assigned_to=?,notes=?,follow_up_date=?,lost_reason=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$phone,$email,$interestedIn,$budget,$assignedTo,$notes,$followUp,$lostReason,$id]);
            if ($assignedTo && $assignedTo !== $prevAssigned) {
                require_once __DIR__ . '/../../includes/notifications.php';
                createNotification((int)$assignedTo, 'info',
                    "Lead Assigned: {$name}",
                    $interestedIn ? "Interested in: {$interestedIn}" : 'You have been assigned a CRM lead.',
                    BASE_URL . '/modules/crm/view_lead.php?id=' . $id
                );
            }
            setFlash('success','Lead updated.');
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'convert' && canWrite('crm')) {
        // Convert to client if not already linked
        if (!$lead['client_id']) {
            $db->prepare("INSERT INTO clients (name,phone,email,status) VALUES (?,?,?,'active')")
               ->execute([$lead['name'], $lead['phone'], $lead['email']]);
            $clientId = (int)$db->lastInsertId();
            $db->prepare("UPDATE crm_leads SET client_id=?,stage='delivered',converted_at=NOW(),updated_at=NOW() WHERE id=?")
               ->execute([$clientId, $id]);
            logActivity('create','clients',$clientId,"Converted from CRM lead #{$id}");
            setFlash('success','Lead converted to client. You can now record a sale.');
            redirect(BASE_URL.'/modules/clients/view.php?id='.$clientId);
        }
        redirect(BASE_URL.'/modules/crm/view_lead.php?id='.$id);
    }

    if ($action === 'pin_car' && canWrite('crm')) {
        $carId = (int)($_POST['car_id'] ?? 0) ?: null;
        $db->prepare("UPDATE crm_leads SET pinned_car_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$carId, $id]);
        setFlash('success', $carId ? 'Vehicle linked to lead.' : 'Vehicle link removed.');
        redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
    }
}

// Load activities
$activities = $db->prepare("
    SELECT a.*, u.name AS by_name
    FROM crm_activities a
    LEFT JOIN users u ON u.id = a.created_by
    WHERE a.lead_id = ?
    ORDER BY a.created_at DESC
");
$activities->execute([$id]); $activities = $activities->fetchAll();

// Load pinned car + all images
$pinnedCar       = null;
$pinnedCarImages = [];
if ($lead['pinned_car_id']) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([$lead['pinned_car_id']]);
        $pinnedCar = $s->fetch() ?: null;

        if ($pinnedCar) {
            $imgQ = $db->prepare("SELECT file_path, is_primary FROM car_images WHERE car_id = ? ORDER BY is_primary DESC, id ASC");
            $imgQ->execute([$lead['pinned_car_id']]);
            $pinnedCarImages = $imgQ->fetchAll();
        }
    } catch (\Throwable $_) {}
}

// Calculate and persist lead score
$score = calculateLeadScore($lead, $db);
try {
    $db->prepare("UPDATE crm_leads SET lead_score = ? WHERE id = ?")->execute([$score, $id]);
} catch (\Throwable $_) {}

[$stageLabel, $stageColor, $stageIcon] = $stages[$lead['stage']] ?? ['Unknown','secondary','fa-circle'];
$isOverdue = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d')
             && !in_array($lead['stage'],['lost','delivered']);

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user me-2 text-primary"></i><?= e($lead['name']) ?></h5>
        <span class="badge bg-<?= $stageColor ?> me-1"><i class="fa <?= $stageIcon ?> me-1"></i><?= $stageLabel ?></span>
        <?php if ($isOverdue): ?>
        <span class="badge bg-danger">Follow-up overdue</span>
        <?php endif; ?>
        <span class="badge bg-<?= scoreColor($score) ?> ms-1" title="Lead Score">
            <i class="fa fa-star me-1"></i>Score: <?= $score ?>/100
        </span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($lead['client_id']): ?>
        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-success">
            <i class="fa fa-user-check me-1"></i>View Client
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/client_history.php?client_id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-clock-rotate-left me-1"></i>Comm. History
        </a>
        <a href="<?= BASE_URL ?>/modules/quotations/add.php?client_id=<?= $lead['client_id'] ?>"
           class="btn btn-sm btn-outline-info">
            <i class="fa fa-file-lines me-1"></i>New Quotation
        </a>
        <?php elseif (canWrite('crm') && $lead['stage'] !== 'lost'): ?>
        <a href="<?= BASE_URL ?>/modules/crm/convert_lead.php?id=<?= $id ?>"
           class="btn btn-sm btn-success">
            <i class="fa fa-user-plus me-1"></i>Convert to Client
        </a>
        <?php endif; ?>
        <!-- Quick contact actions -->
        <?php if ($lead['phone']): ?>
        <a href="tel:<?= e($lead['phone']) ?>" class="btn btn-sm btn-outline-success" title="Call">
            <i class="fa fa-phone me-1"></i><?= e($lead['phone']) ?>
        </a>
        <?php
        $waNum = preg_replace('/[^0-9]/', '', $lead['phone']);
        if (str_starts_with($lead['phone'], '0')) $waNum = '254' . substr($waNum, 1);
        $agentName = $me['name'] ?? '';
        $company = getSetting('company_name', 'us');
        $interested = $lead['interested_in'] ?? '';
        $waMsg = "Hello {$lead['name']}! I'm {$agentName} from {$company}." . ($interested ? " Following up on your interest in the {$interested}." : '') . " When would be a good time to connect or visit our showroom?";
        ?>
        <a href="https://wa.me/<?= $waNum ?>?text=<?= rawurlencode($waMsg) ?>"
           target="_blank" class="btn btn-sm btn-success" title="WhatsApp <?= e($lead['phone']) ?>">
            <i class="fab fa-whatsapp me-1"></i>WhatsApp
        </a>
        <?php endif; ?>
        <a href="leads.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: details + stage -->
    <div class="col-lg-4">

        <!-- Stage changer -->
        <?php if (canWrite('crm')): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-arrow-right me-2"></i>Move Stage</div>
            <div class="card-body p-2">
                <form method="POST" id="stageForm">
                    <input type="hidden" name="action" value="update_stage">
                    <input type="hidden" name="stage" id="stageInput" value="">
                    <input type="hidden" name="lost_reason" id="lostReasonInput" value="">
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($stages as $sk => [$sl,$sc,$si]): ?>
                        <?php if ($sk === 'lost'): ?>
                        <button type="button"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>"
                                data-bs-toggle="modal" data-bs-target="#lostModal">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php else: ?>
                        <button type="submit" name="stage" value="<?= $sk ?>"
                                class="btn btn-sm <?= $lead['stage'] === $sk ? 'btn-'.$sc : 'btn-outline-'.$sc ?>">
                            <i class="fa <?= $si ?> me-1"></i><?= $sl ?>
                        </button>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Details -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between">
                <span><i class="fa fa-id-card me-2"></i>Lead Details</span>
            </div>
            <div class="card-body" style="font-size:13.5px">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Phone</dt>
                    <dd class="col-7"><?= $lead['phone'] ? e($lead['phone']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7 small"><?= $lead['email'] ? e($lead['email']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Source</dt>
                    <dd class="col-7"><?= e($sourceLabels[$lead['source']] ?? $lead['source']) ?></dd>
                    <dt class="col-5 text-muted">Interested In</dt>
                    <dd class="col-7 small"><?= $lead['interested_in'] ? e($lead['interested_in']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Budget</dt>
                    <dd class="col-7 fw-semibold text-success"><?= $lead['budget'] ? money((float)$lead['budget']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Assigned To</dt>
                    <dd class="col-7"><?= e($lead['assigned_name'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Follow-up</dt>
                    <dd class="col-7">
                        <?php if ($lead['follow_up_date']): ?>
                        <span class="badge <?= $isOverdue ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <?= fmtDate($lead['follow_up_date'],'d M Y') ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Added</dt>
                    <dd class="col-7 small text-muted"><?= fmtDate($lead['created_at'],'d M Y') ?></dd>
                    <?php if ($lead['notes']): ?>
                    <dt class="col-5 text-muted">Notes</dt>
                    <dd class="col-7 small"><?= nl2br(e($lead['notes'])) ?></dd>
                    <?php endif; ?>
                    <?php if ($lead['lost_reason']): ?>
                    <dt class="col-5 text-muted">Lost Reason</dt>
                    <dd class="col-7 small text-danger"><?= e($lead['lost_reason']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Linked Vehicle -->
        <?php
        $_primaryImg = null;
        foreach ($pinnedCarImages as $_img) { if ($_img['is_primary']) { $_primaryImg = $_img; break; } }
        if (!$_primaryImg && !empty($pinnedCarImages)) $_primaryImg = $pinnedCarImages[0];
        ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
                <span><i class="fa fa-car me-2 text-primary"></i>Linked Vehicle</span>
                <?php if ($pinnedCar && canWrite('crm')): ?>
                <form method="POST" id="unpinForm" class="d-inline">
                    <input type="hidden" name="action" value="pin_car">
                    <input type="hidden" name="car_id" value="">
                    <button type="submit" class="btn btn-xs btn-outline-danger"
                            style="font-size:11px;padding:2px 8px"
                            onclick="return confirm('Remove linked vehicle?')">
                        <i class="fa fa-unlink me-1"></i>Unlink
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($pinnedCar): ?>
            <!-- ── Rich vehicle display ──────────────────────────────── -->

            <?php if ($_primaryImg): ?>
            <!-- Main image + thumbnails -->
            <div style="background:#111;line-height:0">
                <img id="crmMainImg"
                     src="<?= BASE_URL ?>/uploads/cars/<?= e($_primaryImg['file_path']) ?>"
                     alt="<?= e(($pinnedCar['make'] ?? '') . ' ' . ($pinnedCar['model'] ?? '')) ?>"
                     style="width:100%;height:210px;object-fit:cover;display:block;cursor:zoom-in"
                     onclick="window.open(this.src,'_blank')">
            </div>
            <?php if (count($pinnedCarImages) > 1): ?>
            <div class="d-flex gap-1 p-2" style="overflow-x:auto;background:#f0f0f0;line-height:0">
                <?php foreach ($pinnedCarImages as $_thumb): ?>
                <img src="<?= BASE_URL ?>/uploads/cars/<?= e($_thumb['file_path']) ?>"
                     alt=""
                     onclick="document.getElementById('crmMainImg').src=this.src;
                              document.querySelectorAll('.crm-thumb').forEach(function(t){t.style.borderColor='#ccc'});
                              this.style.borderColor='#2563eb'"
                     class="crm-thumb rounded"
                     style="width:58px;height:44px;object-fit:cover;cursor:pointer;flex-shrink:0;
                            border:2px solid <?= $_thumb['is_primary'] ? '#2563eb' : '#ccc' ?>;
                            transition:border-color .15s">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="d-flex align-items-center justify-content-center bg-light"
                 style="height:120px;border-bottom:1px solid #dee2e6">
                <i class="fa fa-car text-muted" style="font-size:40px;opacity:.35"></i>
            </div>
            <?php endif; ?>

            <!-- Vehicle specs -->
            <div class="p-3" style="font-size:13px">
                <div class="fw-bold mb-1" style="font-size:15px">
                    <?= e(trim(($pinnedCar['year'] ?? '') . ' ' . ($pinnedCar['make'] ?? '') . ' ' . ($pinnedCar['model'] ?? ''))) ?>
                </div>
                <div class="text-muted small mb-2">
                    <?php if (!empty($pinnedCar['registration_number'])): ?>
                    <span class="badge bg-light text-dark border me-1"><?= e($pinnedCar['registration_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($pinnedCar['color'])): ?>
                    <span><?= e($pinnedCar['color']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="row g-1 mb-2" style="font-size:12px">
                    <?php
                    $specFields = [
                        'body_type'    => 'Body',
                        'transmission' => 'Gearbox',
                        'fuel_type'    => 'Fuel',
                        'engine_cc'    => 'Engine CC',
                        'mileage'      => 'Mileage',
                    ];
                    foreach ($specFields as $col => $label):
                        $val = $pinnedCar[$col] ?? null;
                        if (empty($val)) continue;
                        if ($col === 'mileage') $val = number_format((int)$val) . ' km';
                    ?>
                    <div class="col-6">
                        <span class="text-muted"><?= $label ?>:</span>
                        <span class="fw-semibold ms-1"><?= e($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php
                $salePrice = (float)($pinnedCar['asking_price'] ?? $pinnedCar['selling_price'] ?? 0);
                ?>
                <?php if ($salePrice > 0): ?>
                <div class="d-flex align-items-center justify-content-between mb-2 py-2 border-top border-bottom">
                    <span class="text-muted small">Asking Price</span>
                    <span class="fw-bold text-success" style="font-size:17px"><?= money($salePrice) ?></span>
                </div>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$pinnedCar['id'] ?>"
                   target="_blank"
                   class="btn btn-sm btn-outline-primary w-100 mt-1">
                    <i class="fa fa-arrow-up-right-from-square me-1"></i>View Full Vehicle Record
                </a>
            </div>

            <?php if (canWrite('crm')): ?>
            <!-- Change vehicle search -->
            <div class="px-3 pb-3 border-top pt-2">
                <div class="small text-muted mb-1 fw-semibold">Change linked vehicle:</div>
                <div style="position:relative">
                    <input type="text" id="carSearchInput" class="form-control form-control-sm"
                           placeholder="Search make, model or registration…" autocomplete="off">
                    <div id="carResults" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;margin-top:2px"></div>
                </div>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <!-- ── No vehicle linked: search-first UI ─────────────────── -->
            <div class="p-3">
                <p class="text-muted small mb-2">
                    <i class="fa fa-search me-1"></i>Search your inventory to link a vehicle to this lead.
                </p>
                <?php if (canWrite('crm')): ?>
                <div style="position:relative">
                    <input type="text" id="carSearchInput" class="form-control form-control-sm"
                           placeholder="Type make, model or registration…" autocomplete="off">
                    <div id="carResults" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1050;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 6px 20px rgba(0,0,0,.15);max-height:280px;overflow-y:auto;margin-top:2px"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── Car preview panel (shown on selection) ──────────────── -->
            <?php if (canWrite('crm')): ?>
            <div id="carPreviewPanel" style="display:none;border-top:2px solid #2563eb">
                <div class="px-3 py-2 bg-primary bg-opacity-10 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold text-primary small"><i class="fa fa-eye me-1"></i>Selected Vehicle — confirm to link</span>
                    <button type="button" onclick="clearCarPreview()" class="btn-close" style="font-size:10px"></button>
                </div>
                <div class="d-flex" style="min-height:90px">
                    <div id="previewImgBox" style="width:120px;flex-shrink:0;background:#eee;display:flex;align-items:center;justify-content:center;overflow:hidden">
                        <i class="fa fa-car text-muted fa-2x" id="previewImgIcon"></i>
                        <img id="previewImg" src="" alt="" style="display:none;width:120px;height:100%;object-fit:cover;min-height:90px">
                    </div>
                    <div class="p-2 flex-grow-1" style="font-size:12.5px">
                        <div class="fw-bold" id="previewTitle"></div>
                        <div class="text-muted" id="previewSub"></div>
                        <div class="text-success fw-bold mt-1" id="previewPrice" style="font-size:14px"></div>
                        <div class="text-muted small mt-1" id="previewSpecs"></div>
                    </div>
                </div>
                <div class="px-3 pb-3 pt-2">
                    <form method="POST" id="pinCarForm">
                        <input type="hidden" name="action" value="pin_car">
                        <input type="hidden" name="car_id" id="pinCarId">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fa fa-link me-1"></i>Link This Vehicle to Lead
                        </button>
                    </form>
                </div>
            </div>

            <script>
            (function () {
                var inp     = document.getElementById('carSearchInput');
                var res     = document.getElementById('carResults');
                var panel   = document.getElementById('carPreviewPanel');
                var pImg    = document.getElementById('previewImg');
                var pIcon   = document.getElementById('previewImgIcon');
                var pTitle  = document.getElementById('previewTitle');
                var pSub    = document.getElementById('previewSub');
                var pPrice  = document.getElementById('previewPrice');
                var pSpecs  = document.getElementById('previewSpecs');
                var idField = document.getElementById('pinCarId');
                var base    = '<?= BASE_URL ?>';
                var timer;

                if (!inp) return;

                inp.addEventListener('input', function () {
                    clearTimeout(timer);
                    var q = inp.value.trim();
                    if (q.length < 2) { res.style.display = 'none'; return; }
                    timer = setTimeout(function () {
                        fetch(base + '/modules/crm/api/search_cars.php?q=' + encodeURIComponent(q))
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                res.innerHTML = '';
                                if (!d.cars || !d.cars.length) {
                                    res.innerHTML = '<div style="padding:10px 12px;font-size:12.5px;color:#6c757d">No vehicles found in inventory</div>';
                                    res.style.display = '';
                                    return;
                                }
                                d.cars.forEach(function (c) {
                                    var row = document.createElement('div');
                                    row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:12.5px';
                                    row.onmouseover = function () { row.style.background = '#f0f6ff'; };
                                    row.onmouseout  = function () { row.style.background = ''; };

                                    // Thumbnail
                                    var thumb = document.createElement('div');
                                    thumb.style.cssText = 'width:54px;height:40px;flex-shrink:0;border-radius:5px;overflow:hidden;background:#e9ecef;display:flex;align-items:center;justify-content:center';
                                    if (c.primary_image) {
                                        var img = document.createElement('img');
                                        img.src = base + '/uploads/cars/' + c.primary_image;
                                        img.style.cssText = 'width:100%;height:100%;object-fit:cover';
                                        img.onerror = function () { thumb.innerHTML = '<i class="fa fa-car" style="color:#adb5bd;font-size:15px"></i>'; };
                                        thumb.appendChild(img);
                                    } else {
                                        thumb.innerHTML = '<i class="fa fa-car" style="color:#adb5bd;font-size:15px"></i>';
                                    }
                                    row.appendChild(thumb);

                                    // Text
                                    var info = document.createElement('div');
                                    info.style.flex = '1';
                                    var title = [c.year, c.make, c.model].filter(Boolean).join(' ');
                                    var sub   = c.reg || '';
                                    var specs = [c.transmission, c.fuel_type].filter(Boolean).join(' · ');
                                    var priceStr = c.price ? '<span style="color:#198754;font-weight:600">KES ' + Number(c.price).toLocaleString() + '</span>' : '';
                                    info.innerHTML =
                                        '<div style="font-weight:600">' + title + '</div>' +
                                        '<div style="color:#6c757d;font-size:11.5px">' + sub + (specs ? ' &nbsp;·&nbsp; ' + specs : '') + (priceStr ? ' &nbsp;·&nbsp; ' + priceStr : '') + '</div>';
                                    row.appendChild(info);

                                    row.addEventListener('click', function () {
                                        showPreview(c);
                                        res.style.display = 'none';
                                        inp.value = '';
                                    });
                                    res.appendChild(row);
                                });
                                res.style.display = '';
                            }).catch(function () {});
                    }, 280);
                });

                function showPreview(c) {
                    idField.value = c.id;
                    pTitle.textContent = [c.year, c.make, c.model].filter(Boolean).join(' ');
                    pSub.textContent   = [c.reg, c.color].filter(Boolean).join(' · ');
                    pPrice.textContent = c.price ? 'KES ' + Number(c.price).toLocaleString() : '';
                    pSpecs.textContent = [c.body_type, c.transmission, c.fuel_type, c.mileage ? Number(c.mileage).toLocaleString() + ' km' : ''].filter(Boolean).join(' · ');

                    if (c.primary_image) {
                        pImg.src          = base + '/uploads/cars/' + c.primary_image;
                        pImg.style.display = '';
                        pIcon.style.display = 'none';
                    } else {
                        pImg.style.display  = 'none';
                        pIcon.style.display = '';
                    }
                    panel.style.display = '';
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                window.clearCarPreview = function () {
                    panel.style.display = 'none';
                    idField.value = '';
                    pImg.src = '';
                };

                document.addEventListener('click', function (e) {
                    if (res && !res.contains(e.target) && e.target !== inp) {
                        res.style.display = 'none';
                    }
                });
            }());
            </script>
            <?php endif; ?>
        </div>

        <!-- Edit details -->
        <?php if (canWrite('crm')): ?>
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-pen me-2"></i>Edit Details</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_details">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Name</label>
                        <input type="text" name="name" class="form-control form-control-sm" value="<?= e($lead['name']) ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Phone</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?= e($lead['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" value="<?= e($lead['email'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Interested In</label>
                        <input type="text" name="interested_in" class="form-control form-control-sm" value="<?= e($lead['interested_in'] ?? '') ?>">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Budget (KES)</label>
                            <input type="number" name="budget" class="form-control form-control-sm" value="<?= e($lead['budget'] ?? '') ?>" step="1000">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Follow-up</label>
                            <input type="date" name="follow_up_date" class="form-control form-control-sm" value="<?= e($lead['follow_up_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Assigned To</label>
                        <select name="assigned_to" class="form-select form-select-sm">
                            <option value="">— Unassigned —</option>
                            <?php foreach ($salesUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $lead['assigned_to'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= e($lead['notes'] ?? '') ?></textarea>
                    </div>
                    <?php if (in_array($lead['stage'],['lost'])): ?>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Lost Reason</label>
                        <input type="text" name="lost_reason" class="form-control form-control-sm" value="<?= e($lead['lost_reason'] ?? '') ?>" placeholder="e.g. Price too high, bought elsewhere…">
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="lost_reason" value="<?= e($lead['lost_reason'] ?? '') ?>">
                    <?php endif; ?>
                    <button class="btn btn-sm btn-primary w-100 mt-1"><i class="fa fa-save me-1"></i>Save Changes</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: log activity + activity timeline -->
    <div class="col-lg-8">

        <!-- Log Activity -->
        <?php if (canWrite('crm')): ?>
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-plus me-2 text-success"></i>Log Activity</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="log_activity">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <?php foreach ($activityTypes as $k => [$lbl, $ico, $cls]): ?>
                                <option value="<?= $k ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Summary <span class="text-danger">*</span></label>
                            <input type="text" name="summary" class="form-control form-control-sm" required
                                   placeholder="e.g. Called client, discussed Land Cruiser pricing…">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Next Follow-up</label>
                            <input type="date" name="follow_up_date" class="form-control form-control-sm">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Outcome / Notes</label>
                            <textarea name="outcome" class="form-control form-control-sm" rows="2"
                                      placeholder="What was the outcome? Any next steps?"></textarea>
                        </div>
                        <div class="col-12">
                            <button class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i>Log Activity</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activity Timeline -->
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-clock-rotate-left me-2 text-primary"></i>Activity Timeline
                <span class="badge bg-secondary ms-1"><?= count($activities) ?></span>
            </div>
            <?php if (empty($activities)): ?>
            <div class="card-body text-center py-4 text-muted">
                <i class="fa fa-clipboard fa-2x mb-2 d-block opacity-25"></i>No activities logged yet.
            </div>
            <?php else: ?>
            <div class="card-body p-0">
                <div class="timeline ps-3 pe-3 pt-3">
                <?php foreach ($activities as $act):
                    [$aLabel, $aIcon, $aClass] = $activityTypes[$act['type']] ?? ['Note','fa-note-sticky','text-secondary'];
                ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="flex-shrink-0" style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
                        <i class="fa <?= $aIcon ?> <?= $aClass ?>"></i>
                    </div>
                    <div class="flex-grow-1 border-bottom pb-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge bg-light text-dark border me-1" style="font-size:10px"><?= $aLabel ?></span>
                                <span class="fw-medium" style="font-size:13.5px"><?= e($act['summary']) ?></span>
                            </div>
                            <span class="text-muted small flex-shrink-0 ms-2"><?= fmtDate($act['created_at'],'d M Y, H:i') ?></span>
                        </div>
                        <?php if ($act['outcome']): ?>
                        <div class="text-muted mt-1" style="font-size:12.5px"><?= nl2br(e($act['outcome'])) ?></div>
                        <?php endif; ?>
                        <?php if ($act['follow_up_date']): ?>
                        <div class="mt-1">
                            <span class="badge bg-warning text-dark" style="font-size:10px">
                                <i class="fa fa-calendar me-1"></i>Follow-up: <?= fmtDate($act['follow_up_date'],'d M Y') ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted mt-1" style="font-size:11px">
                            By <?= e($act['by_name'] ?? 'System') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lost Reason Modal -->
<div class="modal fade" id="lostModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2 border-0">
                <h6 class="modal-title fw-bold"><i class="fa fa-circle-xmark me-2 text-danger"></i>Mark as Lost</h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-2">
                <label class="form-label small fw-semibold">Reason (optional)</label>
                <input type="text" id="lostReasonField" class="form-control form-control-sm"
                       placeholder="e.g. Price too high, bought elsewhere…"
                       value="<?= e($lead['lost_reason'] ?? '') ?>">
                <div class="form-text">This will be recorded on the lead for analysis.</div>
            </div>
            <div class="modal-footer py-2 border-0">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger" id="confirmLost">
                    <i class="fa fa-circle-xmark me-1"></i>Mark Lost
                </button>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('confirmLost') && document.getElementById('confirmLost').addEventListener('click', function () {
    document.getElementById('stageInput').value    = 'lost';
    document.getElementById('lostReasonInput').value = document.getElementById('lostReasonField').value;
    document.getElementById('stageForm').submit();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
