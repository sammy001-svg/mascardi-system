<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');
$db = getDB();

carsEnsureServiceBilling($db);

// Changing who work is billed to. Post-redirect-get so a refresh does not resave.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'set_billing') {
    verifyCsrf();
    if (!canWrite('cars')) { http_response_code(403); exit('Not permitted.'); }
    $raw = trim((string)($_POST['service_client_id'] ?? ''));
    if ($raw === 'internal') {
        $ic = carInternalClient($db);
        $target = $ic ? (int)$ic['id'] : null;
    } else {
        $target = $raw === '' ? null : (int)$raw;
    }
    if (carSetBillingClient($db, $id, $target)) {
        $who = 'nobody';
        if ($target) {
            $q = $db->prepare("SELECT name FROM clients WHERE id = ?");
            $q->execute([$target]); $who = (string)$q->fetchColumn();
        }
        logActivity('update', 'cars', $id, 'Service billing set to ' . $who . '.');
        setFlash('success', $target
            ? 'Work on this vehicle is now billed to ' . $who . '. It stays in inventory.'
            : 'Service billing cleared.');
    } else {
        setFlash('error', 'Could not change the service billing.');
    }
    redirect(BASE_URL . '/modules/cars/view.php?id=' . $id);
}

$car = $db->prepare("SELECT c.*, l.name AS location_name, cl.phone AS owner_phone FROM cars c LEFT JOIN locations l ON l.id = c.location_id LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id=?");
$car->execute([$id]);
$car = $car->fetch();
if (!$car) { setFlash('error','Car not found.'); redirect(BASE_URL.'/modules/cars/index.php'); }

// In-workshop vehicles have their own dedicated progress page
if ($car['status'] === 'in_workshop') {
    redirect(BASE_URL . '/modules/cars/workshop.php?id=' . $id);
}

$intake   = $db->prepare("SELECT * FROM car_intake WHERE car_id=? ORDER BY id DESC LIMIT 1");
$intake->execute([$id]); $intake = $intake->fetch();

$transfers = $db->prepare("SELECT ct.*, d.name AS transported_by FROM car_transfers ct LEFT JOIN drivers d ON d.id = ct.driver_id WHERE ct.car_id=? ORDER BY ct.id DESC");
$transfers->execute([$id]); $transfers = $transfers->fetchAll();

$assessments = $db->prepare("SELECT ca.*, m.name AS mechanic_name FROM car_assessments ca LEFT JOIN mechanics m ON m.id=ca.mechanic_id WHERE ca.car_id=? ORDER BY ca.id DESC");
$assessments->execute([$id]); $assessments = $assessments->fetchAll();

$jobs = $db->prepare("SELECT j.*, m.name AS mechanic_name FROM workshop_jobs j LEFT JOIN mechanics m ON m.id=j.mechanic_id WHERE j.car_id=? ORDER BY j.id DESC");
$jobs->execute([$id]); $jobs = $jobs->fetchAll();

$billingClients = $db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
$quotations = $db->prepare("SELECT * FROM quotations WHERE car_id=? ORDER BY id DESC");
$quotations->execute([$id]); $quotations = $quotations->fetchAll();

$invoices = $db->prepare("SELECT * FROM invoices WHERE car_id=? ORDER BY id DESC");
$invoices->execute([$id]); $invoices = $invoices->fetchAll();

$images = $db->prepare("SELECT * FROM car_images WHERE car_id=? ORDER BY is_primary DESC, created_at DESC");
$images->execute([$id]); $images = $images->fetchAll();
$primaryImage = null;
foreach($images as $img) if($img['is_primary']) $primaryImage = $img;

$existingSale = $db->prepare("SELECT id, sale_number FROM car_sales WHERE car_id=? AND status='active' LIMIT 1");
$existingSale->execute([$id]); $existingSale = $existingSale->fetch();

$docCount = 0;
if (canAccess('car_documents')) {
    try {
        $dcS = $db->prepare("SELECT COUNT(*) FROM car_documents WHERE car_id=?");
        $dcS->execute([$id]);
        $docCount = (int)$dcS->fetchColumn();
    } catch (\Throwable $_) {}
}

$pageTitle = $car['make'] . ' ' . $car['model'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><?= e($car['make'].' '.$car['model']) ?> <code class="ms-2"><?= e($car['chassis_number']) ?></code></h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="media.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-camera me-1"></i>Photos (<?= count($images) ?>)</a>
        <?php
        // Public share link — points to the no-login car page. A token rather
        // than the row id, so the client cannot count through the rest of the
        // stock from the link they were sent.
        require_once __DIR__ . '/_bootstrap.php';
        $shareTok = carShareToken($db, $id);
        $shareUrl = rtrim(BASE_URL, '/') . '/modules/cars/share.php?'
                  . ($shareTok !== '' ? 't=' . $shareTok : 'id=' . $id);
        ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="copyShareBtn"
                onclick="(function(b){
                    navigator.clipboard ? navigator.clipboard.writeText('<?= addslashes($shareUrl) ?>').then(function(){
                        b.innerHTML='<i class=\'fa fa-check me-1\'></i>Copied!';
                        setTimeout(function(){b.innerHTML='<i class=\'fa fa-share-nodes me-1\'></i>Share link';},2000);
                    }) : (function(){var t=document.createElement('textarea');t.value='<?= addslashes($shareUrl) ?>';document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);b.innerHTML='<i class=\'fa fa-check me-1\'></i>Copied!';setTimeout(function(){b.innerHTML='<i class=\'fa fa-share-nodes me-1\'></i>Share link';},2000);})();
                })(this)">
            <i class="fa fa-share-nodes me-1"></i>Share link
        </button>
        <?php if (canAccess('car_documents')): ?>
        <a href="#documents" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-folder-open me-1"></i>Docs<?= $docCount > 0 ? " ({$docCount})" : '' ?>
        </a>
        <?php endif; ?>
        <?php if ($car['car_type'] === 'inventory' && canWrite('sales')): ?>
            <?php if ($existingSale): ?>
            <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $existingSale['id'] ?>" class="btn btn-sm btn-success">
                <i class="fa fa-tag me-1"></i>View Sale <span class="ms-1 opacity-75">(<?= e($existingSale['sale_number']) ?>)</span>
            </a>
            <?php elseif (in_array($car['status'], ['completed','arrived','in_workshop'])): ?>
            <a href="<?= BASE_URL ?>/modules/sales/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-success">
                <i class="fa fa-tag me-1"></i>Record Sale
            </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (canAccess('inspections')): ?>
        <a href="<?= BASE_URL ?>/modules/inspections/create.php?car_id=<?= $id ?>"
           class="btn btn-sm btn-outline-info">
            <i class="fa fa-clipboard-check me-1"></i>Inspect
        </a>
        <?php endif; ?>
        <?php if ($car['status'] === 'in_workshop'): ?>
        <a href="workshop.php?id=<?= $id ?>" class="btn btn-sm btn-primary">
            <i class="fa fa-screwdriver-wrench me-1"></i>Workshop Progress
        </a>
        <?php endif; ?>
        <?php if (canEditDelete()): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php
// ── Service billing ──────────────────────────────────────────────────────────
// Who pays for work on this vehicle, which is not always who owns it. Setting
// it writes service_client_id and nothing else, so an inventory car billed to
// ourselves stays in inventory and stays for sale.
$billTo   = carBillingClient($db, $car);
$internal = (int)($car['service_client_id'] ?? 0) > 0
         && $billTo && stripos((string)$billTo['name'], 'mascardi') !== false;
$isStock  = ($car['car_type'] ?? '') === 'inventory';
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fa fa-file-invoice-dollar me-2"></i>Service Billing</span>
        <?php if ($billTo): ?>
            <span class="badge <?= $internal ? 'bg-warning text-dark' : 'bg-info' ?>">
                <?= $internal ? 'Internal expense' : 'Billed to customer' ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Quotations and invoices for work on this vehicle are raised against the client
            below. This is separate from who owns it — billing our own stock to ourselves
            records the expense without taking the car out of inventory.
        </p>

        <div class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">Currently billed to</label>
                <div class="fw-semibold">
                    <?php if ($billTo): ?>
                        <?= e($billTo['name']) ?>
                        <?php if ((int)($car['service_client_id'] ?? 0) === 0): ?>
                            <span class="text-muted small fw-normal">(the vehicle owner)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted fw-normal">Nobody yet</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (canWrite('cars')): ?>
            <div class="col-md-7">
                <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="set_billing">
                    <div class="flex-grow-1" style="min-width:200px">
                        <label class="form-label small text-muted mb-1">Change to</label>
                        <select name="service_client_id" class="form-select form-select-sm">
                            <option value="">— nobody —</option>
                            <?php foreach ($billingClients as $bc): ?>
                                <option value="<?= (int)$bc['id'] ?>"
                                    <?= (int)($car['service_client_id'] ?? 0) === (int)$bc['id'] ? 'selected' : '' ?>>
                                    <?= e($bc['name']) ?><?= stripos($bc['name'], 'mascardi') !== false ? ' (internal)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="fa fa-check me-1"></i>Save</button>
                    <?php if ($isStock && !$internal): ?>
                        <button name="service_client_id" value="internal"
                                class="btn btn-sm btn-outline-warning" title="Bill this work to Mascardi as an expense">
                            <i class="fa fa-building me-1"></i>Bill to Mascardi
                        </button>
                    <?php endif; ?>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($billTo): ?>
        <hr class="my-3">
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $id ?>&client_id=<?= (int)$billTo['id'] ?>"
               class="btn btn-sm btn-outline-primary"><i class="fa fa-file-lines me-1"></i>New Quotation</a>
            <a href="<?= BASE_URL ?>/modules/invoices/add.php?car_id=<?= $id ?>&client_id=<?= (int)$billTo['id'] ?>"
               class="btn btn-sm btn-outline-primary"><i class="fa fa-file-invoice me-1"></i>New Invoice</a>
            <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?>"
               class="btn btn-sm btn-outline-secondary"><i class="fa fa-screwdriver-wrench me-1"></i>New Job Card</a>
            <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= (int)$billTo['id'] ?>"
               class="btn btn-sm btn-outline-secondary"><i class="fa fa-user me-1"></i>Open <?= e($billTo['name']) ?></a>
        </div>
        <?php if ($isStock && $internal): ?>
        <p class="text-muted small mb-0 mt-3">
            <i class="fa fa-circle-info me-1"></i>
            This vehicle is still in inventory and still for sale. When it is delivered,
            billing moves to the buyer automatically.
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($images): ?>
<div class="card mb-4 overflow-hidden" id="carGallery">
    <div class="card-body p-0">
        <!-- Big main image -->
        <div id="gMain" style="position:relative;aspect-ratio:16/9;background:#f1f5f9;cursor:zoom-in;overflow:hidden" onclick="openGalleryLightbox(gIdx)">
            <img id="gMainImg"
                 src="<?= BASE_URL ?>/uploads/cars/<?= e($images[0]['file_path']) ?>"
                 alt="<?= e($car['make'].' '.$car['model']) ?>"
                 fetchpriority="high" decoding="async"
                 style="width:100%;height:100%;object-fit:cover;display:block;transition:opacity .2s">
            <?php if (count($images) > 1): ?>
            <button class="g-arrow g-arrow-l" onclick="event.stopPropagation();gChange(-1)" aria-label="Previous"><i class="fa fa-chevron-left"></i></button>
            <button class="g-arrow g-arrow-r" onclick="event.stopPropagation();gChange(1)" aria-label="Next"><i class="fa fa-chevron-right"></i></button>
            <div class="g-counter" id="gCounter">1 / <?= count($images) ?></div>
            <?php endif; ?>
            <div class="g-zoom"><i class="fa fa-expand"></i></div>
        </div>

        <?php if (count($images) > 1): ?>
        <!-- Scrollable thumbnail strip -->
        <div id="gThumbs" style="display:flex;gap:6px;padding:8px;overflow-x:auto;scrollbar-width:thin;background:#f8fafc">
            <?php foreach ($images as $i => $img): ?>
            <button class="g-thumb <?= $i === 0 ? 'active' : '' ?>"
                    onclick="gSelect(<?= $i ?>)"
                    data-full="<?= BASE_URL ?>/uploads/cars/<?= e($img['file_path']) ?>"
                    style="flex-shrink:0;width:88px;height:58px;padding:0;border:2px solid <?= $i === 0 ? '#0d6efd' : '#dee2e6' ?>;border-radius:6px;overflow:hidden;cursor:pointer;background:none;transition:border-color .15s">
                <img src="<?= thumbUrl('cars', $img['file_path']) ?>" alt="Photo <?= $i+1 ?>"
                     loading="<?= $i < 4 ? 'eager' : 'lazy' ?>" decoding="async"
                     style="width:100%;height:100%;object-fit:cover;display:block">
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lightbox -->
<div id="gLightbox" onclick="closeGalleryLightbox()" style="display:none;position:fixed;inset:0;background:rgba(4,4,4,.96);z-index:99999;align-items:center;justify-content:center;flex-direction:column;gap:16px;padding:24px">
    <button onclick="event.stopPropagation();closeGalleryLightbox()" style="position:absolute;top:20px;right:20px;background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:44px;height:44px;border-radius:6px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa fa-xmark"></i></button>
    <button onclick="event.stopPropagation();gChange(-1,true)" style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:6px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa fa-chevron-left"></i></button>
    <button onclick="event.stopPropagation();gChange(1,true)" style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:6px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center"><i class="fa fa-chevron-right"></i></button>
    <img id="gLbImg" src="" alt="" onclick="event.stopPropagation()" style="max-width:90vw;max-height:80vh;object-fit:contain">
    <div id="gLbCounter" style="color:rgba(255,255,255,.55);font-size:12px;font-weight:600;letter-spacing:.1em"></div>
</div>

<style>
.g-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:42px;height:42px;border-radius:6px;background:rgba(255,255,255,.92);border:1px solid #dee2e6;color:#212529;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s}
.g-arrow:hover{background:#fff}
.g-arrow-l{left:12px}
.g-arrow-r{right:12px}
.g-counter{position:absolute;bottom:12px;right:14px;background:rgba(10,10,10,.6);color:#fff;font-size:11px;font-weight:500;letter-spacing:.06em;padding:3px 10px;border-radius:20px;z-index:2}
.g-zoom{position:absolute;bottom:12px;left:14px;background:rgba(255,255,255,.85);color:#555;width:32px;height:32px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:12px;opacity:0;transition:opacity .2s;z-index:2}
#gMain:hover .g-zoom{opacity:1}
.g-thumb.active{border-color:#0d6efd!important}
.g-thumb:hover{border-color:#6c757d!important}
#gLightbox{display:none}
#gLightbox.open{display:flex!important}
</style>

<script>
var gPhotos = <?= json_encode(array_values(array_map(fn($img) => BASE_URL . '/uploads/cars/' . $img['file_path'], $images))) ?>;
var gIdx = 0;
var gMainImg = document.getElementById('gMainImg');
var gCounter = document.getElementById('gCounter');
var gThumbs  = document.querySelectorAll('.g-thumb');
var gLbImg   = document.getElementById('gLbImg');
var gLbCnt   = document.getElementById('gLbCounter');
var gLb      = document.getElementById('gLightbox');

function gSelect(i) {
    gIdx = (i + gPhotos.length) % gPhotos.length;
    gMainImg.style.opacity = '0';
    setTimeout(function(){ gMainImg.src = gPhotos[gIdx]; gMainImg.style.opacity = '1'; }, 100);
    if (gCounter) gCounter.textContent = (gIdx + 1) + ' / ' + gPhotos.length;
    gThumbs.forEach(function(t, j) {
        t.style.borderColor = j === gIdx ? '#0d6efd' : '#dee2e6';
        t.classList.toggle('active', j === gIdx);
    });
    // Scroll active thumb into view
    if (gThumbs[gIdx]) {
        gThumbs[gIdx].scrollIntoView({inline:'nearest', block:'nearest', behavior:'smooth'});
    }
    if (gLb && gLb.classList.contains('open')) {
        gLbImg.src = gPhotos[gIdx];
        if (gLbCnt) gLbCnt.textContent = (gIdx + 1) + ' / ' + gPhotos.length;
    }
}
function gChange(dir, inLb) {
    gSelect(gIdx + dir);
}
function openGalleryLightbox(i) {
    if (!gLb) return;
    gSelect(i);
    gLbImg.src = gPhotos[gIdx];
    if (gLbCnt) gLbCnt.textContent = (gIdx + 1) + ' / ' + gPhotos.length;
    gLb.style.display = 'flex';
    gLb.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeGalleryLightbox() {
    if (!gLb) return;
    gLb.style.display = 'none';
    gLb.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
    if (!gLb || !gLb.classList.contains('open')) return;
    if (e.key === 'ArrowLeft')  gChange(-1, true);
    if (e.key === 'ArrowRight') gChange(1, true);
    if (e.key === 'Escape')     closeGalleryLightbox();
});
</script>

<?php else: ?>
<div class="card mb-4">
    <div class="card-body text-center py-5 text-muted">
        <i class="fa fa-camera fa-2x mb-2 d-block opacity-25"></i>
        No photos uploaded yet. <a href="media.php?id=<?= $id ?>">Upload photos</a>
    </div>
</div>
<?php endif; ?>


<?php
$stageSteps = [
    ['label' => 'Port Intake',  'icon' => 'fa-anchor',           'statuses' => ['arrived']],
    ['label' => 'Transport',    'icon' => 'fa-truck-moving',      'statuses' => ['in_transit']],
    ['label' => 'Assessment',   'icon' => 'fa-clipboard-check',   'statuses' => []],
    ['label' => 'Workshop',     'icon' => 'fa-toolbox',           'statuses' => ['in_workshop']],
    ['label' => 'Completed',    'icon' => 'fa-circle-check',      'statuses' => ['completed']],
    ['label' => 'Delivered',    'icon' => 'fa-flag-checkered',    'statuses' => ['delivered','sold']],
];
$activeStep = 0;
if ($intake)            $activeStep = 1;
if (!empty($transfers)) $activeStep = 2;
if (!empty($assessments)) $activeStep = 3;
if (!empty($jobs))      $activeStep = 4;
if (in_array($car['status'], ['completed']))            $activeStep = 5;
if (in_array($car['status'], ['delivered','sold']))     $activeStep = 6;
?>
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between" style="overflow-x:auto">
        <?php foreach ($stageSteps as $i => $step):
            $stepNum  = $i + 1;
            $isDone   = $stepNum < $activeStep;
            $isActive = $stepNum === $activeStep;
        ?>
            <div class="text-center flex-fill" style="min-width:80px">
                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-1
                    <?= $isDone ? 'bg-success text-white' : ($isActive ? 'bg-primary text-white' : 'bg-light text-muted border') ?>"
                    style="width:40px;height:40px">
                    <i class="fa <?= $step['icon'] ?> fa-sm"></i>
                </div>
                <div class="small fw-<?= $isActive ? 'bold' : 'normal' ?> <?= $isDone ? 'text-success' : ($isActive ? 'text-primary' : 'text-muted') ?>">
                    <?= $step['label'] ?>
                </div>
            </div>
            <?php if ($i < count($stageSteps) - 1): ?>
            <div class="flex-fill" style="height:2px;background:<?= $isDone ? '#198754' : '#dee2e6' ?>;max-width:60px;min-width:20px;margin-bottom:18px"></div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Car Details -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Vehicle Type</dt>
                    <dd class="col-7">
                        <?php if ($car['car_type'] === 'client'): ?>
                            <span class="badge bg-info text-dark">CLIENT VEHICLE</span>
                        <?php else: ?>
                            <span class="badge bg-primary">INVENTORY (STOCK)</span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($car['car_type'] === 'client'): ?>
                    <dt class="col-5 text-muted">Owner</dt>
                    <dd class="col-7">
                        <div class="fw-bold"><?= e($car['owner_name']) ?></div>
                        <div class="small text-muted"><?= e(($car['owner_phone'] ?? '') ?: 'No Phone') ?></div>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Current Location</dt>
                    <dd class="col-7 fw-bold text-primary"><i class="fa fa-location-dot me-1"></i><?= e($car['location_name'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= statusBadge($car['status']) ?></dd>
                    <dt class="col-5 text-muted">Chassis</dt>
                    <dd class="col-7"><code><?= e($car['chassis_number']) ?></code></dd>
                    <?php // Internal reference — deliberately absent from every showroom page. ?>
                    <dt class="col-5 text-muted">Entry No.</dt>
                    <dd class="col-7"><code><?= e($car['entry_number'] ?: '—') ?></code></dd>
                    <dt class="col-5 text-muted">Mileage</dt>
                    <dd class="col-7"><?= $car['mileage'] ? number_format((int)$car['mileage']) . ' km' : '—' ?></dd>
                    <dt class="col-5 text-muted">Reg. No.</dt>
                    <dd class="col-7"><?= e($car['registration_number'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Engine No.</dt>
                    <dd class="col-7"><?= e($car['engine_number'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Make</dt>
                    <dd class="col-7"><?= e($car['make']) ?></dd>
                    <dt class="col-5 text-muted">Model</dt>
                    <dd class="col-7"><?= e($car['model']) ?></dd>
                    <dt class="col-5 text-muted">Year</dt>
                    <dd class="col-7"><?= e($car['year']) ?></dd>
                    <dt class="col-5 text-muted">Color</dt>
                    <dd class="col-7"><?= e($car['color'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Body Type</dt>
                    <dd class="col-7"><?= e($car['body_type'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Transmission</dt>
                    <dd class="col-7"><?= ucfirst($car['transmission'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Fuel</dt>
                    <dd class="col-7"><?= ucfirst($car['fuel_type'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Added</dt>
                    <dd class="col-7"><?= fmtDate($car['created_at']) ?></dd>
                </dl>
                <?php if ($car['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= e($car['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $viewFeatureList = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($car['features'] ?? '')))));
        ?>
        <?php if (!empty($car['description']) || !empty($viewFeatureList)): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fa fa-globe me-2"></i>Website Description &amp; Features</div>
            <div class="card-body" style="font-size:13.5px">
                <?php if (!empty($car['description'])): ?>
                <p class="mb-<?= $viewFeatureList ? '3' : '0' ?>"><?= nl2br(e($car['description'])) ?></p>
                <?php endif; ?>
                <?php if ($viewFeatureList): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($viewFeatureList as $feat): ?>
                    <span class="badge bg-light text-dark border"><i class="fa fa-check text-success me-1"></i><?= e($feat) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick links -->
        <?php 
        $hasAnyAction = canAccess('intake') || canAccess('assessments') || canAccess('jobs') || canAccess('quotations');
        if ($hasAnyAction): 
        ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fa fa-bolt me-2"></i>Actions</div>
            <div class="card-body d-grid gap-2">
                <?php if (canAccess('intake')): ?>
                <a href="<?= BASE_URL ?>/modules/intake/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-anchor me-1"></i>Register Intake</a>
                <?php endif; ?>
                <?php if (canAccess('assessments')): ?>
                <a href="<?= BASE_URL ?>/modules/assessments/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-clipboard-check me-1"></i>New Assessment</a>
                <?php endif; ?>
                <?php if (canAccess('jobs')): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-dark"><i class="fa fa-toolbox me-1"></i>Create Job Card</a>
                <?php endif; ?>
                <?php if (canAccess('quotations')): ?>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="fa fa-file-lines me-1"></i>New Quotation</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <!-- Timeline/History -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-route me-2"></i>Journey Timeline</div>
            <div class="card-body">
                <div class="timeline">
                    <?php if ($intake): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-success"></div>
                        <div class="fw-semibold">Arrived at Mombasa Port</div>
                        <div class="small text-muted"><?= fmtDate($intake['intake_date']) ?> — <?= e($intake['port']) ?></div>
                        <?php if ($intake['condition_notes']): ?><div class="small"><?= e($intake['condition_notes']) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($transfers as $t): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-<?= $t['status'] === 'arrived' ? 'success' : 'warning' ?>"></div>
                        <div class="fw-semibold">Transfer: <?= e($t['from_location']) ?> → <?= e($t['to_location']) ?></div>
                        <div class="small text-muted"><?= $t['transported_by'] ? 'Transported by: <strong>'.e($t['transported_by']).'</strong> | ' : '' ?><?= fmtDate($t['departure_date']) ?></div>
                        <div><?= statusBadge($t['status']) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($assessments as $a): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="fw-semibold"><?= ucwords(str_replace('_',' ',$a['assessment_type'])) ?> Assessment</div>
                        <div class="small text-muted"><?= fmtDate($a['assessment_date']) ?><?= $a['mechanic_name'] ? ' — ' . e($a['mechanic_name']) : '' ?></div>
                        <div><?= statusBadge($a['overall_status']) ?> <a href="<?= BASE_URL ?>/modules/assessments/view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-secondary ms-2">View</a></div>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($jobs as $j): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="fw-semibold">Workshop Job: <?= e($j['job_number']) ?></div>
                        <div class="small text-muted"><?= fmtDate($j['start_date']) ?><?= $j['mechanic_name'] ? ' — ' . e($j['mechanic_name']) : '' ?></div>
                        <div><?= statusBadge($j['status']) ?> <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-secondary ms-2">View Job</a></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($intake) && empty($transfers) && empty($assessments) && empty($jobs)): ?>
                    <p class="text-muted small">No history yet for this vehicle.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quotations & Invoices -->
        <?php if ($quotations || $invoices): ?>
        <div class="row g-3">
            <?php if ($quotations): ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa fa-file-lines me-2"></i>Quotations</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($quotations as $q): ?>
                        <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span><?= e($q['quotation_number']) ?></span>
                            <span><?= statusBadge($q['status']) ?> <strong class="ms-2"><?= money($q['total']) ?></strong></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($invoices): ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa fa-file-invoice-dollar me-2"></i>Invoices</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($invoices as $inv): ?>
                        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span><?= e($inv['invoice_number']) ?></span>
                            <span><?= statusBadge($inv['status']) ?> <strong class="ms-2"><?= money($inv['total']) ?></strong></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
// ── Car Import Costs ──────────────────────────────────────────────────────────
if (canAccess('car_costs')):
    try {
        $costRow = $db->prepare("SELECT * FROM car_costs WHERE car_id=?");
        $costRow->execute([$id]); $costRow = $costRow->fetch();
        $totalCost = $costRow
            ? array_sum(array_map(fn($f)=>(float)($costRow[$f]??0),
                ['purchase_price','freight','marine_insurance','port_charges','duty_tax',
                 'clearing_fees','transport_to_yard','workshop_costs','other_costs']))
            : null;
        $saleForCost = $db->prepare("SELECT sale_price FROM car_sales WHERE car_id=? AND status='active' LIMIT 1");
        $saleForCost->execute([$id]); $saleForCost = $saleForCost->fetch();
    } catch (\Throwable $e) { $costRow = null; $totalCost = null; $saleForCost = null; }
?>
<div class="card mt-4" id="costs">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="fa fa-calculator me-2 text-primary"></i>Import Costs & Margin</span>
        <?php if (canWrite('car_costs')): ?>
        <a href="<?= BASE_URL ?>/modules/car_costs/edit.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL.'/modules/cars/view.php?id='.$id.'#costs') ?>"
           class="btn btn-sm btn-outline-primary">
            <i class="fa fa-<?= $costRow ? 'pen' : 'plus' ?> me-1"></i><?= $costRow ? 'Edit Costs' : 'Record Costs' ?>
        </a>
        <?php endif; ?>
    </div>
    <?php if (!$costRow): ?>
    <div class="card-body text-center py-3 text-muted">
        <i class="fa fa-calculator fa-2x mb-2 d-block opacity-25"></i>No import costs recorded yet.
        <?php if (canWrite('car_costs')): ?>
        <div class="mt-2">
            <a href="<?= BASE_URL ?>/modules/car_costs/edit.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL.'/modules/cars/view.php?id='.$id.'#costs') ?>"
               class="btn btn-sm btn-outline-primary">Record Import Costs</a>
        </div>
        <?php endif; ?>
    </div>
    <?php else:
        $salePrice = $saleForCost ? (float)$saleForCost['sale_price'] : null;
        $profit    = $salePrice !== null ? $salePrice - $totalCost : null;
        $margin    = $salePrice && $salePrice > 0 && $profit !== null ? round($profit / $salePrice * 100, 1) : null;
        $costItems = [
            'purchase_price'    => 'Purchase Price',
            'freight'           => 'Freight',
            'marine_insurance'  => 'Marine Insurance',
            'port_charges'      => 'Port Charges',
            'duty_tax'          => 'Duty & Taxes',
            'clearing_fees'     => 'Clearing Fees',
            'transport_to_yard' => 'Transport to Yard',
            'workshop_costs'    => 'Workshop Costs',
            'other_costs'       => 'Other Costs',
        ];
    ?>
    <div class="card-body p-0">
        <div class="row g-0">
            <div class="col-md-8">
                <table class="table table-sm mb-0" style="font-size:13px">
                    <tbody>
                    <?php foreach ($costItems as $field => $label):
                        $val = (float)($costRow[$field] ?? 0);
                        if ($val <= 0) continue;
                    ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= $label ?></td>
                        <td class="text-end pe-3 fw-medium"><?= money($val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark">
                        <td class="ps-3 fw-bold">Total Cost</td>
                        <td class="text-end pe-3 fw-bold"><?= money($totalCost) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-4 border-start d-flex flex-column justify-content-center align-items-center p-4 text-center">
                <?php if ($salePrice !== null): ?>
                <div class="text-muted small mb-1">Sale Price</div>
                <div class="fw-bold text-success fs-6 mb-2"><?= money($salePrice) ?></div>
                <div class="text-muted small mb-1">Gross Profit</div>
                <div class="fw-bold fs-5 <?= $profit >= 0 ? 'text-success' : 'text-danger' ?> mb-2"><?= money($profit) ?></div>
                <span class="badge fs-6 <?= $margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>"><?= $margin ?>% margin</span>
                <?php else: ?>
                <div class="text-muted small"><i class="fa fa-tag me-1"></i>Profit will show once the car is sold.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ── Car Documents ─────────────────────────────────────────────────────────────
if (canAccess('car_documents')):
    $docTypes = [
        'logbook'           => ['Logbook',                  'fa-book',             'primary'],
        'import_entry'      => ['Import Entry',              'fa-file-import',      'info'],
        'ntsa_inspection'   => ['NTSA Inspection',           'fa-clipboard-check',  'success'],
        'ntsa_registration' => ['NTSA Registration',         'fa-id-card',          'success'],
        'insurance'         => ['Insurance',                 'fa-shield-halved',    'warning'],
        'duty_clearance'    => ['Duty Clearance',            'fa-stamp',            'secondary'],
        'purchase_invoice'  => ['Purchase Invoice',          'fa-file-invoice',     'dark'],
        'other'             => ['Other',                     'fa-file',             'secondary'],
    ];
    try {
        $carDocs = $db->prepare("SELECT * FROM car_documents WHERE car_id=? ORDER BY created_at DESC");
        $carDocs->execute([$id]);
        $carDocs = $carDocs->fetchAll();
    } catch (\Throwable $e) { $carDocs = []; }
?>
<div class="card mt-4" id="documents">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="fa fa-folder-open me-2 text-primary"></i>Documents
            <?php if ($carDocs): ?>
            <span class="badge bg-secondary ms-1"><?= count($carDocs) ?></span>
            <?php endif; ?>
        </span>
        <?php if (canWrite('car_documents')): ?>
        <a href="<?= BASE_URL ?>/modules/car_documents/upload.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>"
           class="btn btn-sm btn-primary">
            <i class="fa fa-upload me-1"></i>Upload
        </a>
        <?php endif; ?>
    </div>
    <?php if (empty($carDocs)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="fa fa-folder-open fa-2x mb-2 d-block opacity-25"></i>
        No documents uploaded yet.
        <?php if (canWrite('car_documents')): ?>
        <div class="mt-2">
            <a href="<?= BASE_URL ?>/modules/car_documents/upload.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="fa fa-upload me-1"></i>Upload first document
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($carDocs as $doc):
            [$dtLabel, $dtIcon, $dtColor] = $docTypes[$doc['doc_type']] ?? ['Other','fa-file','secondary'];
            $expired  = $doc['expiry_date'] && $doc['expiry_date'] < date('Y-m-d');
            $expSoon  = !$expired && $doc['expiry_date'] && $doc['expiry_date'] <= date('Y-m-d', strtotime('+30 days'));
        ?>
        <div class="list-group-item d-flex align-items-center gap-3 px-3 py-2">
            <div class="flex-shrink-0 text-<?= $dtColor ?>" style="width:32px;text-align:center">
                <i class="fa <?= $dtIcon ?> fa-lg"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-medium"><?= e($doc['title']) ?></div>
                <div class="d-flex gap-2 align-items-center mt-1 flex-wrap">
                    <span class="badge bg-<?= $dtColor ?>-subtle text-<?= $dtColor ?> border border-<?= $dtColor ?>-subtle"
                          style="font-size:10px"><?= $dtLabel ?></span>
                    <?php if ($doc['expiry_date']): ?>
                    <span class="badge bg-<?= $expired ? 'danger' : ($expSoon ? 'warning' : 'success') ?>"
                          style="font-size:10px">
                        <i class="fa fa-<?= $expired ? 'circle-xmark' : ($expSoon ? 'triangle-exclamation' : 'circle-check') ?> me-1"></i>
                        <?= $expired ? 'Expired ' : ($expSoon ? 'Expires ' : 'Valid until ') ?>
                        <?= fmtDate($doc['expiry_date'], 'd M Y') ?>
                    </span>
                    <?php endif; ?>
                    <span class="text-muted small"><?= fmtDate($doc['created_at'], 'd M Y') ?></span>
                </div>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
                <a href="<?= BASE_URL ?>/modules/car_documents/download.php?id=<?= $doc['id'] ?>&view=1"
                   class="btn btn-xs btn-outline-secondary" target="_blank" title="View">
                    <i class="fa fa-eye"></i>
                </a>
                <a href="<?= BASE_URL ?>/modules/car_documents/download.php?id=<?= $doc['id'] ?>"
                   class="btn btn-xs btn-outline-primary" title="Download">
                    <i class="fa fa-download"></i>
                </a>
                <?php if (canWrite('car_documents')): ?>
                <form method="POST" action="<?= BASE_URL ?>/modules/car_documents/delete.php"
                      class="d-inline"
                      onsubmit="return confirm('Delete this document?')">
                    <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                    <input type="hidden" name="redirect"
                           value="<?= e(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>">
                    <button class="btn btn-xs btn-outline-danger" title="Delete">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
