<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Cars';
$db = getDB();

$section = $_GET['section'] ?? 'inventory';
if (!in_array($section, ['inventory', 'client', 'workshop'])) $section = 'inventory';

// Location scope for supervisors
$supLocId  = supervisorLocationId();
$locFilter = $supLocId ? " AND location_id = $supLocId" : '';

// Three lightweight COUNT queries for tab badges — scoped for supervisors
$cntInv  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='inventory'$locFilter")->fetchColumn();
$cntCli  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='client'$locFilter")->fetchColumn();
$cntWork = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'$locFilter")->fetchColumn();

// Filter dropdowns — only needed on inventory tab, scoped for supervisors
$invMakes     = [];
$invLocations = [];
if ($section === 'inventory') {
    $invMakes = $db->query(
        "SELECT DISTINCT make FROM cars WHERE car_type='inventory' AND make != ''$locFilter ORDER BY make ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$supLocId) {
        $invLocations = $db->query(
            "SELECT l.id, l.name FROM locations l
             INNER JOIN cars c ON c.location_id = l.id AND c.car_type = 'inventory'
             GROUP BY l.id, l.name ORDER BY l.name ASC"
        )->fetchAll();
    }
}

// All locations for stock take modal (supervisors only see their own location)
$allLocations = $supLocId
    ? $db->query("SELECT id, name FROM locations WHERE id = $supLocId")->fetchAll()
    : $db->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll();

$extraJs = '<script>
(function () {
    var section = ' . json_encode($section) . ';
    var apiUrl  = ' . json_encode(BASE_URL . '/modules/cars/api/list.php') . ';

    var table = $("#carsTable").DataTable({
        serverSide  : true,
        processing  : true,
        ajax: {
            url  : apiUrl,
            data : function (d) {
                d.section         = section;
                d.filter_make     = $("#filterMake").val()     || "";
                d.filter_location = $("#filterLocation").val() || "";
            },
            error: function () {
                if (typeof window.showToast === "function") {
                    window.showToast("Could not load vehicle data. Please refresh.", "error");
                }
            }
        },
        columns: [
            { orderable: true  },
            { orderable: true  },
            { orderable: true  },
            { orderable: true  },
            { orderable: true  },
            { orderable: true  },
            { orderable: false }
        ],
        order      : [[0, "asc"]],
        pageLength : 25,
        dom        : \'<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>\',
        language   : {
            search            : \'\',
            searchPlaceholder : \'Search make, chassis, reg…\',
            emptyTable        : \'No vehicles found.\',
            zeroRecords       : \'No vehicles matched your search.\',
            processing        : \'<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin me-2"></i>Loading…</div>\',
            lengthMenu        : \'Show _MENU_ vehicles\',
            info              : \'Showing _START_–_END_ of _TOTAL_ vehicles\',
            infoEmpty         : \'No vehicles to show\',
            infoFiltered      : \'(filtered from _MAX_ total)\',
        },
    });

    $(document).on("click",  "#filterApply", function () { table.ajax.reload(); });
    $(document).on("click",  "#filterReset", function () {
        $("#filterMake, #filterLocation").val("").trigger("change");
    });
}());
</script>';

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa fa-car-side me-2 text-primary"></i>
        <?= $section === 'inventory' ? 'Mascardi Inventory'
          : ($section === 'client'   ? 'Client Cars' : 'Workshop') ?>
    </h5>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#stockTakeModal">
            <i class="fa fa-clipboard-check me-1"></i>Stock Taking
        </button>
        <?php if (canWrite('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-primary btn-sm">
            <i class="fa fa-plus me-1"></i>Add Car
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Section tabs ──────────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="?section=inventory"
       class="btn btn-lg <?= $section === 'inventory' ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-warehouse me-2"></i>Mascardi Inventory
        <span class="badge <?= $section === 'inventory' ? 'bg-white text-primary' : 'bg-primary' ?> ms-1"><?= $cntInv ?></span>
    </a>
    <a href="?section=client"
       class="btn btn-lg <?= $section === 'client' ? 'btn-info text-dark' : 'btn-outline-info' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-user-tie me-2"></i>Client Cars
        <span class="badge <?= $section === 'client' ? 'bg-white text-dark' : 'bg-info' ?> ms-1"><?= $cntCli ?></span>
    </a>
    <a href="?section=workshop"
       class="btn btn-lg <?= $section === 'workshop' ? 'btn-warning text-dark' : 'btn-outline-warning' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-screwdriver-wrench me-2"></i>Workshop
        <?php if ($cntWork): ?>
        <span class="badge <?= $section === 'workshop' ? 'bg-dark' : 'bg-warning text-dark border' ?> ms-1"><?= $cntWork ?></span>
        <?php endif; ?>
    </a>
</div>

<?php if ($section === 'inventory' && ($invMakes || $invLocations)): ?>
<!-- ── Inventory filters ─────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4 col-sm-6">
                <label class="form-label small text-muted mb-1">Make</label>
                <select id="filterMake" class="form-select form-select-sm">
                    <option value="">All Makes</option>
                    <?php foreach ($invMakes as $mk): ?>
                    <option value="<?= e($mk) ?>"><?= e($mk) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-sm-6">
                <label class="form-label small text-muted mb-1">Location</label>
                <select id="filterLocation" class="form-select form-select-sm">
                    <option value="">All Locations</option>
                    <?php foreach ($invLocations as $loc): ?>
                    <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button id="filterApply" class="btn btn-sm btn-primary">
                    <i class="fa fa-filter me-1"></i>Filter
                </button>
                <button id="filterReset" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-xmark me-1"></i>Reset
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Cars Table — empty shell, DataTables fills via AJAX ──────────────── -->
<div class="card">
    <div class="card-body p-0">
        <table id="carsTable" class="table table-hover mb-0" style="width:100%">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th><?= $section === 'workshop' ? 'Owner / Type' : 'Type' ?></th>
                    <th>Chassis</th>
                    <th>Location</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php
// STOCK TAKE MODAL — captured here, echoed by footer.php line 120 as a direct child of <body>.
// Must be OUTSIDE .page-body stacking context to prevent Bootstrap backdrop z-index bug.
ob_start(); ?>
<div class="modal fade" id="stockTakeModal" tabindex="-1" aria-labelledby="stockTakeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header border-0 pb-0" style="background:#1e293b;border-radius:8px 8px 0 0">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:36px;height:36px;background:rgba(37,99,235,.25);border-radius:8px;display:flex;align-items:center;justify-content:center">
                        <i class="fa fa-clipboard-check text-white" style="font-size:15px"></i>
                    </div>
                    <div>
                        <h5 class="modal-title text-white mb-0 fw-bold" id="stockTakeModalLabel">Stock Taking</h5>
                        <div style="font-size:11px;color:#94a3b8">Physical vehicle count &amp; verification</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">

                <!-- ── Form controls ──────────────────────────────────────── -->
                <div class="p-4 border-bottom" style="background:#f8fafc">
                    <div class="row g-3">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                <i class="fa fa-calendar me-1"></i>Date of Stock Take
                            </label>
                            <input type="date" id="stDate" class="form-control form-control-sm shadow-none"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                <i class="fa fa-clock me-1"></i>Time
                            </label>
                            <input type="time" id="stTime" class="form-control form-control-sm shadow-none"
                                   value="<?= date('H:i') ?>">
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                <i class="fa fa-location-dot me-1"></i>Location
                            </label>
                            <select id="stLocation" class="form-select form-select-sm shadow-none">
                                <option value="">— Select location —</option>
                                <?php foreach ($allLocations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-semibold text-secondary mb-1">
                                <i class="fa fa-note-sticky me-1"></i>Notes <span class="fw-normal text-muted">(optional)</span>
                            </label>
                            <input type="text" id="stNotes" class="form-control form-control-sm shadow-none"
                                   placeholder="Any remarks…">
                        </div>
                    </div>
                </div>

                <!-- ── Summary bar ────────────────────────────────────────── -->
                <div id="stSummary" class="d-none px-4 py-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2"
                     style="background:#eff6ff">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary rounded-pill" style="font-size:12px" id="stTotalCount">0</span>
                        <span class="fw-semibold" id="stLocName"></span>
                        <span class="text-muted small">— vehicles in system</span>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="text-success fw-semibold small">
                            <i class="fa fa-check-circle me-1"></i><span id="stCheckedCount">0 confirmed</span>
                        </span>
                        <span class="text-danger small" id="stMissingWrap" style="display:none">
                            <i class="fa fa-circle-xmark me-1"></i><span id="stMissingCount">0 unconfirmed</span>
                        </span>
                    </div>
                </div>

                <!-- ── Car checklist ──────────────────────────────────────── -->
                <div id="stCarList" style="min-height:220px;max-height:55vh;overflow-y:auto">
                    <div class="text-center py-5 text-muted">
                        <i class="fa fa-location-dot fa-2x mb-3 d-block" style="color:#2563eb;opacity:.35"></i>
                        <div class="fw-semibold">Select a location to begin</div>
                        <div class="small mt-1">The vehicle list will load automatically</div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer border-top d-flex justify-content-between align-items-center"
                 style="background:#f8fafc">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                    <i class="fa fa-xmark me-1"></i>Cancel
                </button>
                <button type="button" id="stSaveBtn" class="btn btn-primary px-4">
                    <i class="fa fa-save me-1"></i>Save &amp; Print
                </button>
            </div>

        </div>
    </div>
</div>

<script>
/* Stock Take JS — runs here, AFTER the modal HTML above is in the DOM */
(function () {
    var stApiUrl  = '<?= BASE_URL ?>/modules/cars/api/stocktake.php';
    var printUrl  = '<?= BASE_URL ?>/modules/cars/stocktake_print.php';
    var _savedId  = null;

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function updateCounts() {
        var total = $('.st-car-check').length;
        var chk   = $('.st-car-check:checked').length;
        $('#stCheckedCount').text(chk + ' confirmed');
        $('#stMissingCount').text((total - chk) + ' unconfirmed');
        $('#stMissingWrap').toggle(total - chk > 0);
        $('#stSelectAll').prop('indeterminate', chk > 0 && chk < total)
                         .prop('checked', chk === total && total > 0);
    }

    function renderCarList(cars, total, location) {
        if (!cars.length) {
            $('#stCarList').html('<div class="alert alert-info m-3"><i class="fa fa-info-circle me-2"></i>No vehicles on record at this location.</div>');
            $('#stSummary').addClass('d-none');
            return;
        }
        $('#stTotalCount').text(total);
        $('#stLocName').text(location ? location.name : '');
        $('#stSummary').removeClass('d-none');

        var statusLabel = {in_transit:'In Transit',arrived:'Arrived',in_assessment:'In Assessment',in_workshop:'In Workshop',completed:'Completed',sold:'Sold',delivered:'Delivered',reserved:'Reserved'};
        var statusColor = {arrived:'success',in_transit:'secondary',in_assessment:'warning',in_workshop:'warning',completed:'info',sold:'dark',delivered:'dark',reserved:'purple'};

        var rows = '';
        cars.forEach(function(car, i) {
            var sc = statusColor[car.status] || 'secondary';
            var sl = statusLabel[car.status] || car.status;
            rows += '<tr>' +
                '<td class="text-center" style="width:44px"><input type="checkbox" class="form-check-input st-car-check" value="' + car.id + '"></td>' +
                '<td class="text-muted" style="width:36px">' + (i+1) + '</td>' +
                '<td><strong>' + esc(car.make + ' ' + car.model) + '</strong> <span class="text-muted small">' + esc(car.year||'') + '</span></td>' +
                '<td><code style="font-size:11px">' + esc(car.chassis_number||'—') + '</code></td>' +
                '<td>' + esc(car.registration_number||'—') + '</td>' +
                '<td>' + esc(car.color||'—') + '</td>' +
                '<td><span class="badge bg-' + sc + '">' + sl + '</span></td>' +
                '</tr>';
        });

        $('#stCarList').html(
            '<div class="table-responsive">' +
            '<table class="table table-sm table-hover align-middle mb-0" style="font-size:13px">' +
            '<thead style="background:#f8fafc;position:sticky;top:0;z-index:1"><tr>' +
            '<th style="width:44px" class="text-center"><input type="checkbox" id="stSelectAll" class="form-check-input" title="Select all"></th>' +
            '<th style="width:36px">#</th><th>Vehicle</th><th>Chassis</th><th>Reg. No.</th><th>Color</th><th>Status</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table></div>'
        );
        updateCounts();

        $('#stCarList').off('change').on('change', '#stSelectAll', function () {
            $('.st-car-check').prop('checked', $(this).is(':checked'));
            updateCounts();
        }).on('change', '.st-car-check', function () {
            updateCounts();
        });
    }

    // Location change — reload car list
    $(document).on('change', '#stLocation', function () {
        var locId = $(this).val();
        _savedId = null;
        resetSaveBtn();
        if (!locId) {
            $('#stCarList').html('<div class="text-center py-5 text-muted"><i class="fa fa-location-dot fa-2x mb-3 d-block" style="color:#2563eb;opacity:.35"></i><div class="fw-semibold">Select a location to begin</div><div class="small mt-1">The vehicle list will load automatically</div></div>');
            $('#stSummary').addClass('d-none');
            return;
        }
        $('#stCarList').html('<div class="text-center py-5 text-muted"><i class="fa fa-spinner fa-spin fa-2x mb-3 d-block text-primary"></i>Loading vehicles…</div>');
        $('#stSummary').addClass('d-none');

        fetch(stApiUrl + '?location_id=' + locId)
            .then(function(r){ return r.json(); })
            .then(function(d){ renderCarList(d.cars||[], d.total||0, d.location); })
            .catch(function(){ $('#stCarList').html('<div class="alert alert-danger m-3">Failed to load vehicles. Please try again.</div>'); });
    });

    // Reset modal on close
    $(document).on('hidden.bs.modal', '#stockTakeModal', function () {
        _savedId = null;
        resetSaveBtn();
        $('#stLocation').val('');
        $('#stNotes').val('');
        $('#stSummary').addClass('d-none');
        $('#stCarList').html('<div class="text-center py-5 text-muted"><i class="fa fa-location-dot fa-2x mb-3 d-block" style="color:#2563eb;opacity:.35"></i><div class="fw-semibold">Select a location to begin</div><div class="small mt-1">The vehicle list will load automatically</div></div>');
    });

    function resetSaveBtn() {
        $('#stSaveBtn').off('click').prop('disabled', false)
            .html('<i class="fa fa-save me-1"></i>Save &amp; Print')
            .on('click', doSaveAndPrint);
    }

    function doSaveAndPrint() {
        if (_savedId) { window.open(printUrl + '?id=' + _savedId, '_blank'); return; }
        var locId = $('#stLocation').val();
        var date  = $('#stDate').val();
        var time  = $('#stTime').val();
        if (!locId) { alert('Please select a location.'); return; }
        if (!date)  { alert('Please enter the date.'); return; }
        if (!time)  { alert('Please enter the time.'); return; }

        var ids = [];
        $('.st-car-check:checked').each(function(){ ids.push(parseInt(this.value)); });

        var btn = $('#stSaveBtn');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin me-1"></i>Saving…');

        fetch(stApiUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ location_id: parseInt(locId), date: date, time: time, confirmed_ids: ids, notes: $('#stNotes').val().trim() })
        })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.error) { alert('Error: ' + data.error); btn.prop('disabled', false).html('<i class="fa fa-save me-1"></i>Save &amp; Print'); return; }
            _savedId = data.id;
            window.open(printUrl + '?id=' + data.id, '_blank');
            btn.prop('disabled', false).html('<i class="fa fa-print me-1"></i>Print Again');
        })
        .catch(function() {
            alert('Failed to save. Please try again.');
            btn.prop('disabled', false).html('<i class="fa fa-save me-1"></i>Save &amp; Print');
        });
    }

    resetSaveBtn();
}());
</script>
<?php $extraModal = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
