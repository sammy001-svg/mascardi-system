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

$extraJs = '<script>
(function () {
    var section  = ' . json_encode($section) . ';
    var apiUrl   = ' . json_encode(BASE_URL . '/modules/cars/api/list.php') . ';
    var baseUrl  = ' . json_encode(BASE_URL) . ';

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
    <?php if (canWrite('cars')): ?>
    <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>Add Car
    </a>
    <?php endif; ?>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
