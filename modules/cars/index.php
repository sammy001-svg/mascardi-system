<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Cars';
$db = getDB();

$section = $_GET['section'] ?? 'inventory';
if (!in_array($section, ['inventory', 'client', 'workshop'])) $section = 'inventory';

// Three lightweight COUNT queries for tab badges — fast with indexes on car_type / status
$cntInv  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='inventory'")->fetchColumn();
$cntCli  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='client'")->fetchColumn();
$cntWork = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();

// Filter dropdowns — only needed on inventory tab
$invMakes     = [];
$invLocations = [];
if ($section === 'inventory') {
    $invMakes = $db->query(
        "SELECT DISTINCT make FROM cars WHERE car_type='inventory' AND make != '' ORDER BY make ASC"
    )->fetchAll(PDO::FETCH_COLUMN);
    $invLocations = $db->query(
        "SELECT l.id, l.name FROM locations l
         INNER JOIN cars c ON c.location_id = l.id AND c.car_type = 'inventory'
         GROUP BY l.id, l.name ORDER BY l.name ASC"
    )->fetchAll();
}

// All locations for stock take modal
$allLocations = $db->query("SELECT id, name FROM locations ORDER BY name ASC")->fetchAll();

$extraJs = '<script>
(function () {
    var section  = ' . json_encode($section) . ';
    var apiUrl   = ' . json_encode(BASE_URL . '/modules/cars/api/list.php') . ';
    var baseUrl  = ' . json_encode(BASE_URL) . ';
    var stApiUrl = ' . json_encode(BASE_URL . '/modules/cars/api/stocktake.php') . ';

    var table = $("#carsTable").DataTable({
        serverSide  : true,
        processing  : true,
        ajax: {
            url  : apiUrl,
            data : function (d) {
                d.section          = section;
                d.filter_make      = $("#filterMake").val()     || "";
                d.filter_location  = $("#filterLocation").val() || "";
            },
            error: function () {
                if (typeof window.showToast === "function") {
                    window.showToast("Could not load vehicle data. Please refresh.", "error");
                }
            }
        },
        columns: [
            { orderable: true  },   // 0  Vehicle
            { orderable: true  },   // 1  Type
            { orderable: true  },   // 2  Chassis
            { orderable: true  },   // 3  Location
            { orderable: true  },   // 4  Price
            { orderable: true  },   // 5  Status
            { orderable: false }    // 6  Actions
        ],
        order      : [[0, "asc"]],
        pageLength : 25,
        dom        : \'<"d-flex justify-content-between align-items-center mb-3"lf>t<"d-flex justify-content-between align-items-center mt-3"ip>\',
        language   : {
            search              : \'\',
            searchPlaceholder   : \'Search make, chassis, reg…\',
            emptyTable          : \'No vehicles found.\',
            zeroRecords         : \'No vehicles matched your search.\',
            processing          : \'<div class="text-center py-3 text-muted"><i class="fa fa-spinner fa-spin me-2"></i>Loading…</div>\',
            lengthMenu          : \'Show _MENU_ vehicles\',
            info                : \'Showing _START_–_END_ of _TOTAL_ vehicles\',
            infoEmpty           : \'No vehicles to show\',
            infoFiltered        : \'(filtered from _MAX_ total)\',
        },
        // Delegated confirm-delete already wired in main.js via $(document).on(...)
    });

    // Reload table when inventory filters change
    $(document).on("change", "#filterMake, #filterLocation", function () {
        table.ajax.reload();
    });
    $(document).on("click", "#filterReset", function () {
        $("#filterMake, #filterLocation").val("").trigger("change");
    });

    // ── Stock Take ──────────────────────────────────────────────────────────
    var _stSavedId = null;   // track saved record so re-print reuses same ID

    // Reset modal state when closed
    $("#stockTakeModal").on("hidden.bs.modal", function () {
        _stSavedId = null;
        $("#stCarList").html("");
        $("#stSummary").addClass("d-none");
        $("#stSaveBtn").prop("disabled", false).html(\'<i class="fa fa-save me-1"></i>Save & Print\');
        $("#stSelectAll").prop("checked", false);
    });

    // Load cars when location changes
    $("#stLocation").on("change", function () {
        var locId = $(this).val();
        _stSavedId = null;
        $("#stSaveBtn").prop("disabled", false).html(\'<i class="fa fa-save me-1"></i>Save & Print\');

        if (!locId) {
            $("#stCarList").html("");
            $("#stSummary").addClass("d-none");
            $("#stSelectAll").prop("checked", false);
            return;
        }

        $("#stCarList").html(\'<div class="text-center py-4 text-muted"><i class="fa fa-spinner fa-spin me-2"></i>Loading vehicles…</div>\');
        $("#stSummary").addClass("d-none");

        fetch(stApiUrl + "?location_id=" + locId)
            .then(function(r){ return r.json(); })
            .then(function(data){
                renderCarList(data.cars || [], data.total || 0, data.location);
            })
            .catch(function(){
                $("#stCarList").html(\'<div class="alert alert-danger m-3">Failed to load vehicles. Please try again.</div>\');
            });
    });

    function renderCarList(cars, total, location) {
        if (!cars.length) {
            $("#stCarList").html(\'<div class="alert alert-info m-3"><i class="fa fa-info-circle me-2"></i>No vehicles found at this location.</div>\');
            $("#stSummary").addClass("d-none");
            return;
        }

        var locName = location ? location.name : "";

        // Summary bar
        $("#stTotalCount").text(total);
        $("#stLocName").text(locName);
        $("#stSummary").removeClass("d-none");

        // Table rows
        var html = \'<div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:13px"><thead><tr>\' +
            \'<th style="width:36px"><input type="checkbox" id="stSelectAll" class="form-check-input"></th>\' +
            \'<th>#</th><th>Vehicle</th><th>Chassis</th><th>Reg. No.</th><th>Color</th><th>Status</th>\' +
            \'</tr></thead><tbody>\';

        cars.forEach(function(car, idx){
            var statusMap = {
                in_transit:"In Transit", arrived:"Arrived", in_assessment:"In Assessment",
                in_workshop:"In Workshop", completed:"Completed", sold:"Sold",
                delivered:"Delivered", reserved:"Reserved"
            };
            var statusLabel = statusMap[car.status] || car.status;
            var statusColor = {
                arrived:"success", in_transit:"secondary", in_assessment:"warning",
                in_workshop:"warning", completed:"info", sold:"dark",
                delivered:"dark", reserved:"purple"
            }[car.status] || "secondary";

            html += \'<tr>\' +
                \'<td><input type="checkbox" class="form-check-input st-car-check" value="\' + car.id + \'"></td>\' +
                \'<td class="text-muted">\' + (idx+1) + \'</td>\' +
                \'<td><strong>\' + escHtml(car.make + " " + car.model) + \'</strong> <span class="text-muted small">\' + (car.year||"") + \'</span></td>\' +
                \'<td><code style="font-size:11px">\' + escHtml(car.chassis_number||"—") + \'</code></td>\' +
                \'<td>\' + escHtml(car.registration_number||"—") + \'</td>\' +
                \'<td>\' + escHtml(car.color||"—") + \'</td>\' +
                \'<td><span class="badge bg-\' + statusColor + \'">\' + statusLabel + \'</span></td>\' +
                \'</tr>\';
        });

        html += \'</tbody></table></div>\';
        $("#stCarList").html(html);

        // Select-all wiring (delegated so it works after render)
        $("#stCarList").off("change", "#stSelectAll").on("change", "#stSelectAll", function(){
            $(".st-car-check").prop("checked", $(this).is(":checked"));
            updateCheckedCount();
        });
        $("#stCarList").on("change", ".st-car-check", function(){
            updateCheckedCount();
            var all = $(".st-car-check").length;
            var chk = $(".st-car-check:checked").length;
            $("#stSelectAll").prop("indeterminate", chk > 0 && chk < all);
            $("#stSelectAll").prop("checked", chk === all);
        });
    }

    function updateCheckedCount() {
        var chk = $(".st-car-check:checked").length;
        $("#stCheckedCount").text(chk + " confirmed");
    }

    // Save & Print button
    $("#stSaveBtn").on("click", function(){
        var locId = $("#stLocation").val();
        var date  = $("#stDate").val();
        var time  = $("#stTime").val();

        if (!locId) { alert("Please select a location."); return; }
        if (!date)  { alert("Please enter the date."); return; }
        if (!time)  { alert("Please enter the time."); return; }

        var confirmedIds = [];
        $(".st-car-check:checked").each(function(){ confirmedIds.push(parseInt($(this).val())); });

        var btn = $(this);
        btn.prop("disabled", true).html(\'<i class="fa fa-spinner fa-spin me-1"></i>Saving…\');

        var payload = {
            location_id:   parseInt(locId),
            date:          date,
            time:          time,
            confirmed_ids: confirmedIds,
            notes:         $("#stNotes").val().trim()
        };

        fetch(stApiUrl, {
            method: "POST",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify(payload)
        })
        .then(function(r){ return r.json(); })
        .then(function(data){
            if (data.error) {
                alert("Error: " + data.error);
                btn.prop("disabled", false).html(\'<i class="fa fa-save me-1"></i>Save & Print\');
                return;
            }
            _stSavedId = data.id;
            btn.html(\'<i class="fa fa-check me-1"></i>Saved!\');
            // Open print page in new tab
            window.open(baseUrl + "/modules/cars/stocktake_print.php?id=" + data.id, "_blank");
            // Show re-print button
            setTimeout(function(){
                btn.prop("disabled", false).html(\'<i class="fa fa-print me-1"></i>Print Again\');
                btn.off("click").on("click", function(){
                    window.open(baseUrl + "/modules/cars/stocktake_print.php?id=" + _stSavedId, "_blank");
                });
            }, 1200);
        })
        .catch(function(){
            alert("Failed to save stock take. Please try again.");
            btn.prop("disabled", false).html(\'<i class="fa fa-save me-1"></i>Save & Print\');
        });
    });

    function escHtml(s) {
        return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
    }
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
            <div class="col-auto">
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

<!-- ── Stock Take Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="stockTakeModal" tabindex="-1" aria-labelledby="stockTakeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">

            <div class="modal-header" style="background:#1e293b">
                <h5 class="modal-title text-white mb-0" id="stockTakeModalLabel">
                    <i class="fa fa-clipboard-check me-2"></i>Stock Taking
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-0">

                <!-- Form controls -->
                <div class="p-3 border-bottom bg-light">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-semibold mb-1">Date of Stock Take</label>
                            <input type="date" id="stDate" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label small fw-semibold mb-1">Time</label>
                            <input type="time" id="stTime" class="form-control form-control-sm"
                                   value="<?= date('H:i') ?>">
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label small fw-semibold mb-1">Location</label>
                            <select id="stLocation" class="form-select form-select-sm">
                                <option value="">— Select location —</option>
                                <?php foreach ($allLocations as $loc): ?>
                                <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-semibold mb-1">Notes <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" id="stNotes" class="form-control form-control-sm"
                                   placeholder="Any remarks…">
                        </div>
                    </div>
                </div>

                <!-- Summary bar (hidden until location selected) -->
                <div id="stSummary" class="d-none px-3 py-2 border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2"
                     style="background:#eff6ff">
                    <div>
                        <i class="fa fa-location-dot me-1 text-primary"></i>
                        <strong id="stLocName"></strong>
                        &mdash;
                        <span class="text-muted"><span id="stTotalCount">0</span> vehicles in system</span>
                    </div>
                    <div class="text-success fw-semibold small">
                        <i class="fa fa-check-circle me-1"></i>
                        <span id="stCheckedCount">0 confirmed</span>
                    </div>
                </div>

                <!-- Car checklist -->
                <div id="stCarList" style="min-height:200px">
                    <div class="text-center py-5 text-muted">
                        <i class="fa fa-location-dot fa-2x mb-2 d-block text-primary opacity-50"></i>
                        Select a location above to load the vehicle list
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="fa fa-xmark me-1"></i>Close
                </button>
                <button type="button" id="stSaveBtn" class="btn btn-primary px-4">
                    <i class="fa fa-save me-1"></i>Save &amp; Print
                </button>
            </div>

        </div>
    </div>
</div>
<!-- /Stock Take Modal -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
