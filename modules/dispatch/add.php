<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireWrite('dispatch');
$db   = getDB();
$user = authUser();

// Pre-fills from integration buttons
$preSaleId     = (int)($_GET['sale_id']     ?? 0);
$preBookingId  = (int)($_GET['booking_id']  ?? 0);
$preCarId      = (int)($_GET['car_id']      ?? 0);
$preType       = $_GET['type'] ?? 'ad_hoc';
$preDate       = $_GET['date'] ?? date('Y-m-d');

// Contextual data for pre-fills
$preSale = $preBooking = null;
if ($preSaleId) {
    $s = $db->prepare("SELECT cs.*, c.make,c.model,c.year,c.registration_number,c.id AS cid FROM car_sales cs JOIN cars c ON c.id=cs.car_id WHERE cs.id=?");
    $s->execute([$preSaleId]); $preSale = $s->fetch();
    if ($preSale) { $preCarId = $preSale['cid']; $preType = 'delivery'; }
}
if ($preBookingId) {
    $b = $db->prepare("SELECT sb.*, c.make,c.model,c.year,c.registration_number FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id WHERE sb.id=?");
    $b->execute([$preBookingId]); $preBooking = $b->fetch();
    if ($preBooking && $preBooking['car_id']) $preCarId = $preBooking['car_id'];
}

// Reference data
$cars      = $db->query("SELECT c.id, c.make, c.model, c.year, c.registration_number, l.name AS loc FROM cars c LEFT JOIN locations l ON l.id=c.location_id WHERE c.status NOT IN ('delivered') ORDER BY c.make,c.model")->fetchAll();
$drivers   = $db->query("SELECT * FROM drivers WHERE status='active' ORDER BY name")->fetchAll();
$clients   = $db->query("SELECT id, name, phone FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$locations = $db->query("SELECT * FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$bookings  = $db->query("SELECT sb.id, sb.booking_number, sb.client_name, c.make, c.model FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id WHERE sb.status NOT IN ('completed','cancelled') ORDER BY sb.created_at DESC LIMIT 100")->fetchAll();
$sales     = $db->query("SELECT cs.id, cs.sale_number, cs.buyer_name, c.make, c.model FROM car_sales cs JOIN cars c ON c.id=cs.car_id WHERE cs.status='active' AND cs.delivered_at IS NULL ORDER BY cs.created_at DESC LIMIT 100")->fetchAll();

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'job_number'          => nextNumber('dispatch_jobs','job_number','DJ'),
        'job_type'            => $_POST['job_type']           ?? 'ad_hoc',
        'status'              => 'scheduled',
        'car_id'              => (int)($_POST['car_id']       ?? 0) ?: null,
        'driver_id'           => (int)($_POST['driver_id']    ?? 0) ?: null,
        'scheduled_date'      => $_POST['scheduled_date']     ?? date('Y-m-d'),
        'scheduled_time'      => $_POST['scheduled_time']     ?: null,
        'from_type'           => $_POST['from_type']          ?? 'location',
        'from_location_id'    => (int)($_POST['from_location_id'] ?? 0) ?: null,
        'from_address'        => trim($_POST['from_address']  ?? '') ?: null,
        'to_type'             => $_POST['to_type']            ?? 'location',
        'to_location_id'      => (int)($_POST['to_location_id']   ?? 0) ?: null,
        'to_address'          => trim($_POST['to_address']    ?? '') ?: null,
        'client_id'           => (int)($_POST['client_id']    ?? 0) ?: null,
        'service_booking_id'  => (int)($_POST['service_booking_id'] ?? 0) ?: null,
        'sale_id'             => (int)($_POST['sale_id']      ?? 0) ?: null,
        'notes'               => trim($_POST['notes']         ?? '') ?: null,
        'raised_by'           => $user['name'],
    ];

    // Auto-assign driver if selected
    if ($d['driver_id']) { $d['status'] = 'assigned'; $d['assigned_by'] = $user['name']; }

    $db->prepare("INSERT INTO dispatch_jobs
        (job_number,job_type,status,car_id,driver_id,scheduled_date,scheduled_time,
         from_type,from_location_id,from_address,to_type,to_location_id,to_address,
         client_id,service_booking_id,sale_id,notes,raised_by,assigned_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute(array_values(array_merge(
            array_intersect_key($d, array_flip(['job_number','job_type','status','car_id','driver_id','scheduled_date','scheduled_time'])),
            array_intersect_key($d, array_flip(['from_type','from_location_id','from_address','to_type','to_location_id','to_address'])),
            array_intersect_key($d, array_flip(['client_id','service_booking_id','sale_id','notes','raised_by'])),
            [($d['driver_id'] ? $user['name'] : null)]
        )));
    $newId = $db->lastInsertId();
    logActivity('create','dispatch',$newId,"New dispatch job {$d['job_number']} ({$d['job_type']})");
    setFlash('success', "Dispatch job {$d['job_number']} created.");
    redirect(BASE_URL . '/modules/dispatch/view.php?id=' . $newId);
}

$pageTitle = 'New Dispatch Job';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-plus-circle me-2 text-primary"></i>New Dispatch Job</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Board</a>
</div>

<form method="POST" id="dispatchForm">

<!-- Job Type -->
<div class="card mb-4">
    <div class="card-header fw-semibold">Job Type</div>
    <div class="card-body">
        <div class="row g-2">
        <?php
        $types = [
            'client_pickup'  => ['Client Pickup',  'fa-person-walking-arrow-right',       'info',      'Collect a client\'s car from their address'],
            'client_return'  => ['Client Return',   'fa-person-walking-arrow-loop-left',   'success',   'Return a client\'s car after service'],
            'test_drive'     => ['Test Drive',      'fa-road',                             'warning',   'Accompany or log a test drive'],
            'delivery'       => ['Delivery',        'fa-truck',                            'primary',   'Deliver a sold car to the buyer'],
            'transfer'       => ['Transfer',        'fa-right-left',                       'secondary', 'Move a car between showrooms/locations'],
            'ad_hoc'         => ['Ad Hoc',          'fa-bolt',                             'dark',      'Any other car movement'],
        ];
        foreach ($types as $val => [$label,$icon,$color,$desc]): ?>
        <div class="col-md-4 col-6">
            <label class="w-100 cursor-pointer" style="cursor:pointer">
                <input type="radio" name="job_type" value="<?= $val ?>" class="btn-check" id="type_<?= $val ?>"
                       <?= ($preType === $val) ? 'checked' : '' ?>>
                <div class="card p-3 text-center border-2" style="transition:all .15s" id="card_<?= $val ?>">
                    <i class="fa <?= $icon ?> fa-lg mb-2 text-<?= $color ?>"></i>
                    <div class="fw-semibold small"><?= $label ?></div>
                    <div class="text-muted" style="font-size:10.5px"><?= $desc ?></div>
                </div>
            </label>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">

        <!-- Core details -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2"></i>Vehicle &amp; Driver</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Vehicle</label>
                    <select name="car_id" class="form-select select2" id="carSelect">
                        <option value="">— Select vehicle (optional) —</option>
                        <?php foreach ($cars as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $preCarId == $c['id'] ? 'selected' : '' ?>
                                data-loc="<?= e($c['loc'] ?? '') ?>">
                            <?= e($c['make'].' '.$c['model'].' '.$c['year']) ?>
                            <?= $c['registration_number'] ? ' ['.e($c['registration_number']).']' : '' ?>
                            <?= $c['loc'] ? ' — '.e($c['loc']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Driver <span class="text-muted small">(optional — assign later)</span></label>
                    <select name="driver_id" class="form-select select2">
                        <option value="">— Select driver —</option>
                        <?php foreach ($drivers as $dr): ?>
                        <option value="<?= $dr['id'] ?>"><?= e($dr['name']) ?> — <?= e($dr['phone']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Client (shown for pickup/return/test drive/delivery) -->
                <div class="mb-3" id="clientRow">
                    <label class="form-label">Client / Buyer</label>
                    <select name="client_id" class="form-select select2">
                        <option value="">— Select client (optional) —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> — <?= e($c['phone']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Schedule -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-clock me-2"></i>Schedule</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-7">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="scheduled_date" class="form-control" value="<?= $preDate ?>" required>
                    </div>
                    <div class="col-5">
                        <label class="form-label">Time</label>
                        <input type="time" name="scheduled_time" class="form-control">
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div class="col-lg-6">

        <!-- Route -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-route me-2"></i>Route</div>
            <div class="card-body">
                <!-- FROM -->
                <div class="mb-1"><label class="form-label mb-1 small fw-semibold text-muted text-uppercase">From</label></div>
                <div class="d-flex gap-2 mb-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="from_type" value="location" id="from_loc" checked>
                        <label class="form-check-label small" for="from_loc">Showroom/Location</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="from_type" value="address" id="from_addr">
                        <label class="form-check-label small" for="from_addr">Street Address</label>
                    </div>
                </div>
                <div id="from_location_wrap" class="mb-3">
                    <select name="from_location_id" class="form-select select2" id="fromLocSelect">
                        <option value="">— Pick-up location —</option>
                        <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="from_address_wrap" class="mb-3" style="display:none">
                    <input type="text" name="from_address" class="form-control" placeholder="e.g. 14 Riverside Drive, Westlands">
                </div>

                <!-- Arrow -->
                <div class="text-center text-muted mb-2"><i class="fa fa-arrow-down"></i></div>

                <!-- TO -->
                <div class="mb-1"><label class="form-label mb-1 small fw-semibold text-muted text-uppercase">To</label></div>
                <div class="d-flex gap-2 mb-2">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="to_type" value="location" id="to_loc" checked>
                        <label class="form-check-label small" for="to_loc">Showroom/Location</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="to_type" value="address" id="to_addr">
                        <label class="form-check-label small" for="to_addr">Street Address</label>
                    </div>
                </div>
                <div id="to_location_wrap" class="mb-3">
                    <select name="to_location_id" class="form-select select2" id="toLocSelect">
                        <option value="">— Destination —</option>
                        <?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div id="to_address_wrap" class="mb-3" style="display:none">
                    <input type="text" name="to_address" class="form-control" id="toAddressInput"
                           placeholder="e.g. Buyer's address or client home"
                           value="<?= $preSale ? e($preSale['buyer_name'] . ' — (enter address)') : '' ?>">
                </div>
            </div>
        </div>

        <!-- Links + Notes -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-link me-2"></i>Links &amp; Notes</div>
            <div class="card-body">
                <div class="mb-3" id="bookingRow">
                    <label class="form-label small">Linked Service Booking</label>
                    <select name="service_booking_id" class="form-select select2">
                        <option value="">— None —</option>
                        <?php foreach ($bookings as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $preBookingId == $b['id'] ? 'selected' : '' ?>>
                            <?= e($b['booking_number']) ?> — <?= e($b['client_name'] ?? '') ?> (<?= e($b['make'].' '.$b['model']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3" id="saleRow">
                    <label class="form-label small">Linked Sale</label>
                    <select name="sale_id" class="form-select select2">
                        <option value="">— None —</option>
                        <?php foreach ($sales as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $preSaleId == $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['sale_number']) ?> — <?= e($s['buyer_name'] ?? '') ?> (<?= e($s['make'].' '.$s['model']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label small">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Any special instructions…"></textarea>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="fa fa-check me-1"></i>Create Dispatch Job</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
</div>

</form>

<script>
// Type card highlight
document.querySelectorAll('[name="job_type"]').forEach(function(r){
    r.addEventListener('change', function(){
        document.querySelectorAll('[id^="card_"]').forEach(function(c){ c.style.borderColor=''; c.style.background=''; });
        var card = document.getElementById('card_' + r.value);
        if(card){ card.style.borderColor='#2563eb'; card.style.background='#eff6ff'; }
        updateForm(r.value);
    });
    if(r.checked){ var card=document.getElementById('card_'+r.value); if(card){card.style.borderColor='#2563eb';card.style.background='#eff6ff';} }
});

function updateForm(type){
    var needClient   = ['client_pickup','client_return','test_drive','delivery'].includes(type);
    var needBooking  = ['client_pickup','client_return'].includes(type);
    var needSale     = type === 'delivery';
    document.getElementById('clientRow').style.display  = needClient  ? '' : 'none';
    document.getElementById('bookingRow').style.display = needBooking ? '' : 'none';
    document.getElementById('saleRow').style.display    = needSale    ? '' : 'none';
    // For delivery, default to_type=address
    if(type === 'delivery'){
        document.getElementById('to_addr').checked = true;
        document.getElementById('to_location_wrap').style.display = 'none';
        document.getElementById('to_address_wrap').style.display  = '';
    }
    // For client_pickup, default from_type=address
    if(type === 'client_pickup'){
        document.getElementById('from_addr').checked = true;
        document.getElementById('from_location_wrap').style.display = 'none';
        document.getElementById('from_address_wrap').style.display  = '';
    }
}
updateForm(document.querySelector('[name="job_type"]:checked')?.value || 'ad_hoc');

// From/To toggles
document.querySelectorAll('[name="from_type"]').forEach(function(r){
    r.addEventListener('change', function(){
        document.getElementById('from_location_wrap').style.display = r.value==='location' ? '' : 'none';
        document.getElementById('from_address_wrap').style.display  = r.value==='address'  ? '' : 'none';
    });
});
document.querySelectorAll('[name="to_type"]').forEach(function(r){
    r.addEventListener('change', function(){
        document.getElementById('to_location_wrap').style.display = r.value==='location' ? '' : 'none';
        document.getElementById('to_address_wrap').style.display  = r.value==='address'  ? '' : 'none';
    });
});

// Auto-fill from_location from selected car's current location
document.getElementById('carSelect').addEventListener('change', function(){
    var opt = this.options[this.selectedIndex];
    var loc = opt.dataset.loc;
    // Only auto-fill if user hasn't manually set it
    if(loc && document.getElementById('from_loc').checked){
        // Find matching option in from location select
        var sel = document.getElementById('fromLocSelect');
        for(var i=0;i<sel.options.length;i++){
            if(sel.options[i].text.includes(loc)){ sel.selectedIndex=i; break; }
        }
        if($(sel).hasClass('select2-hidden-accessible')) $(sel).trigger('change.select2');
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
