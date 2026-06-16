<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Convert Lead to Client';
$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/crm/leads.php');

$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

// Load lead with assigned user name and linked client name
$stmt = $db->prepare("
    SELECT l.*, u.name AS assigned_name, c.name AS client_name
    FROM crm_leads l
    LEFT JOIN users u ON u.id = l.assigned_to
    LEFT JOIN clients c ON c.id = l.client_id
    WHERE l.id = ?
");
$stmt->execute([$id]);
$lead = $stmt->fetch();

if (!$lead) {
    setFlash('error', 'Lead not found.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

// CRM agent isolation: agents can only convert leads assigned to themselves
if ($isCrmAgent && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only manage leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

// Already converted — redirect with info
if ($lead['client_id']) {
    setFlash('info', 'This lead has already been converted to a client.');
    redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
}

// Lost leads cannot be converted
if ($lead['stage'] === 'lost') {
    setFlash('error', 'Lost leads cannot be converted to clients.');
    redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);
}

// Write permission required
canWrite('crm') || redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $id);

$pageTitle = 'Convert Lead — ' . $lead['name'];

$stages = [
    'hot'       => ['Hot',       'danger',    'fa-fire'],
    'lukewarm'  => ['Lukewarm',  'warning',   'fa-temperature-half'],
    'cold'      => ['Cold',      'info',      'fa-snowflake'],
    'lost'      => ['Lost',      'secondary', 'fa-circle-xmark'],
    'reserved'  => ['Reserved',  'purple',    'fa-bookmark'],
    'delivered' => ['Delivered', 'success',   'fa-truck'],
];
[$stageLabel, $stageColor, $stageIcon] = $stages[$lead['stage']] ?? ['Unknown', 'secondary', 'fa-circle'];

$errors = [];

// ── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_new') {
        $name   = trim($_POST['name']   ?? '');
        $phone  = trim($_POST['phone']  ?? '') ?: null;
        $email  = trim($_POST['email']  ?? '') ?: null;
        $kraPin = strtoupper(trim($_POST['kra_pin'] ?? '')) ?: null;
        $notes  = trim($_POST['notes']  ?? '') ?: null;

        if (!$name) $errors[] = 'Client name is required.';

        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Create new client
                $db->prepare("INSERT INTO clients (name, phone, email, kra_pin, notes, status) VALUES (?,?,?,?,?,'active')")
                   ->execute([$name, $phone, $email, $kraPin, $notes]);
                $clientId = (int)$db->lastInsertId();

                // Link lead to client and mark as delivered
                $db->prepare("UPDATE crm_leads SET client_id=?, stage='delivered', converted_at=NOW(), updated_at=NOW() WHERE id=?")
                   ->execute([$clientId, $id]);

                $db->commit();

                logActivity('create', 'clients', $clientId, "Converted from CRM lead #{$id}");

                require_once __DIR__ . '/../../includes/notifications.php';
                notifyRoles(['admin', 'sales_manager', 'general_manager'], 'sale',
                    "Lead Converted: {$lead['name']}",
                    "New client created from CRM lead. Client: {$name}",
                    BASE_URL . '/modules/clients/view.php?id=' . $clientId
                );

                setFlash('success', 'Lead converted to client successfully.');
                redirect(BASE_URL . '/modules/clients/view.php?id=' . $clientId);
            } catch (\Throwable $e) {
                $db->rollBack();
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'link_existing') {
        $clientId = (int)($_POST['existing_client_id'] ?? 0);
        if (!$clientId) $errors[] = 'Please search for and select an existing client.';

        if (empty($errors)) {
            // Verify client exists
            $check = $db->prepare("SELECT id, name FROM clients WHERE id = ?");
            $check->execute([$clientId]);
            $existingClient = $check->fetch();

            if (!$existingClient) {
                $errors[] = 'Selected client not found.';
            } else {
                try {
                    $db->prepare("UPDATE crm_leads SET client_id=?, stage='delivered', converted_at=NOW(), updated_at=NOW() WHERE id=?")
                       ->execute([$clientId, $id]);

                    logActivity('create', 'clients', $clientId, "Converted from CRM lead #{$id}");

                    require_once __DIR__ . '/../../includes/notifications.php';
                    notifyRoles(['admin', 'sales_manager', 'general_manager'], 'sale',
                        "Lead Linked to Client: {$lead['name']}",
                        "CRM lead linked to existing client: {$existingClient['name']}",
                        BASE_URL . '/modules/clients/view.php?id=' . $clientId
                    );

                    setFlash('success', 'Lead converted to client successfully.');
                    redirect(BASE_URL . '/modules/clients/view.php?id=' . $clientId);
                } catch (\Throwable $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user-plus me-2 text-success"></i>Convert Lead to Client</h5>
        <span class="text-muted small">Lead: <strong><?= e($lead['name']) ?></strong></span>
    </div>
    <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Lead
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <i class="fa fa-circle-exclamation me-2"></i>
    <?php foreach ($errors as $err) echo e($err) . '<br>'; ?>
</div>
<?php endif; ?>

<!-- Lead Summary Card (read-only) -->
<div class="card mb-4" style="border-left: 4px solid #2563eb">
    <div class="card-header fw-semibold py-2">
        <i class="fa fa-id-card me-2 text-primary"></i>Lead Summary
    </div>
    <div class="card-body py-3">
        <div class="row g-2" style="font-size:13.5px">
            <div class="col-md-3">
                <span class="text-muted d-block small">Name</span>
                <strong><?= e($lead['name']) ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-muted d-block small">Phone</span>
                <?= $lead['phone'] ? e($lead['phone']) : '<span class="text-muted">—</span>' ?>
            </div>
            <div class="col-md-3">
                <span class="text-muted d-block small">Email</span>
                <?= $lead['email'] ? e($lead['email']) : '<span class="text-muted">—</span>' ?>
            </div>
            <div class="col-md-3">
                <span class="text-muted d-block small">Stage</span>
                <span class="badge bg-<?= $stageColor ?>">
                    <i class="fa <?= $stageIcon ?> me-1"></i><?= $stageLabel ?>
                </span>
            </div>
            <?php if ($lead['interested_in']): ?>
            <div class="col-md-6">
                <span class="text-muted d-block small">Interested In</span>
                <?= e($lead['interested_in']) ?>
            </div>
            <?php endif; ?>
            <?php if ($lead['budget']): ?>
            <div class="col-md-3">
                <span class="text-muted d-block small">Budget</span>
                <span class="fw-semibold text-success"><?= money((float)$lead['budget']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Conversion Tabs -->
<div class="card">
    <div class="card-header p-0">
        <ul class="nav nav-tabs border-0 px-3 pt-2" id="convertTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold" id="tab-new" data-bs-toggle="tab"
                        data-bs-target="#pane-new" type="button" role="tab" aria-controls="pane-new" aria-selected="true">
                    <i class="fa fa-user-plus me-1 text-success"></i>Create New Client
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-link" data-bs-toggle="tab"
                        data-bs-target="#pane-link" type="button" role="tab" aria-controls="pane-link" aria-selected="false">
                    <i class="fa fa-link me-1 text-primary"></i>Link Existing Client
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body pt-4">
        <div class="tab-content" id="convertTabContent">

            <!-- Tab 1: Create New Client -->
            <div class="tab-pane fade show active" id="pane-new" role="tabpanel" aria-labelledby="tab-new">
                <form method="POST">
                    <input type="hidden" name="action" value="create_new">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= e($_POST['name'] ?? $lead['name']) ?>"
                                   placeholder="Client full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= e($_POST['phone'] ?? $lead['phone'] ?? '') ?>"
                                   placeholder="+254 7xx xxx xxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= e($_POST['email'] ?? $lead['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">KRA PIN</label>
                            <input type="text" name="kra_pin" class="form-control text-uppercase"
                                   value="<?= e($_POST['kra_pin'] ?? '') ?>"
                                   placeholder="e.g. A001234567B" maxlength="20"
                                   oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"
                                      placeholder="Any additional notes about this client…"><?= e($_POST['notes'] ?? $lead['notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12 d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-user-plus me-1"></i>Create Client &amp; Convert Lead
                            </button>
                            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tab 2: Link Existing Client -->
            <div class="tab-pane fade" id="pane-link" role="tabpanel" aria-labelledby="tab-link">
                <form method="POST" id="linkExistingForm">
                    <input type="hidden" name="action" value="link_existing">
                    <input type="hidden" name="existing_client_id" id="existingClientId" value="">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Search for Client</label>
                        <div class="input-group">
                            <input type="text" id="clientSearchInput" class="form-control"
                                   placeholder="Search by name, phone or email…" autocomplete="off">
                            <button type="button" class="btn btn-outline-primary" id="clientSearchBtn">
                                <i class="fa fa-search me-1"></i>Search
                            </button>
                        </div>
                        <div class="form-text">
                            <i class="fa fa-circle-info me-1"></i>Search by name, phone or email. Click a result to select.
                        </div>
                    </div>

                    <!-- Search results list -->
                    <div id="clientSearchResults" class="mb-3" style="display:none">
                        <div class="list-group" id="clientResultsList"></div>
                    </div>

                    <!-- Selected client display -->
                    <div id="selectedClientBox" class="alert alert-success d-flex align-items-center gap-2 mb-3" style="display:none">
                        <i class="fa fa-circle-check fa-lg"></i>
                        <div>
                            <strong>Selected:</strong>
                            <span id="selectedClientName"></span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="clearClientSelection">
                            <i class="fa fa-xmark me-1"></i>Clear
                        </button>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary" id="linkSubmitBtn" disabled>
                            <i class="fa fa-link me-1"></i>Link Client &amp; Convert Lead
                        </button>
                        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<?php
$extraJs = <<<'ENDJS'
<script>
(function () {
    var BASE_URL          = document.querySelector('meta[name="base-url"]') ? document.querySelector('meta[name="base-url"]').content : '';
    var searchInput       = document.getElementById('clientSearchInput');
    var searchBtn         = document.getElementById('clientSearchBtn');
    var resultsBox        = document.getElementById('clientSearchResults');
    var resultsList       = document.getElementById('clientResultsList');
    var clientIdInput     = document.getElementById('existingClientId');
    var selectedBox       = document.getElementById('selectedClientBox');
    var selectedNameEl    = document.getElementById('selectedClientName');
    var clearBtn          = document.getElementById('clearClientSelection');
    var submitBtn         = document.getElementById('linkSubmitBtn');

    // Derive BASE_URL from current page URL if meta tag is absent
    if (!BASE_URL) {
        var parts = window.location.pathname.split('/modules/');
        BASE_URL = window.location.origin + (parts[0] || '');
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function selectClient(id, name) {
        clientIdInput.value = id;
        selectedNameEl.textContent = name;
        selectedBox.style.display = '';
        submitBtn.disabled = false;
        // Highlight chosen row
        Array.from(resultsList.children).forEach(function (el) {
            var check = el.querySelector('.select-check');
            if (check) {
                check.classList.toggle('opacity-0', String(el.dataset.id) !== String(id));
            }
        });
    }

    function clearSelection() {
        clientIdInput.value = '';
        selectedBox.style.display = 'none';
        submitBtn.disabled = true;
        Array.from(resultsList.children).forEach(function (el) {
            var check = el.querySelector('.select-check');
            if (check) check.classList.add('opacity-0');
        });
    }

    function doSearch() {
        var q = (searchInput.value || '').trim();
        if (!q) return;

        searchBtn.disabled = true;
        searchBtn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Searching…';

        fetch(BASE_URL + '/modules/crm/api/search_clients.php?q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                resultsList.innerHTML = '';
                var clients = data.clients || [];
                if (!clients.length) {
                    resultsList.innerHTML =
                        '<div class="list-group-item text-muted small">' +
                        '<i class="fa fa-circle-info me-1"></i>No active clients found matching "' +
                        escHtml(q) + '"</div>';
                } else {
                    clients.forEach(function (c) {
                        var btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                        btn.dataset.id   = c.id;
                        btn.dataset.name = c.name;
                        btn.innerHTML =
                            '<div>' +
                            '<span class="fw-semibold">' + escHtml(c.name) + '</span>' +
                            (c.phone ? '<span class="text-muted small ms-2"><i class="fa fa-phone fa-xs me-1"></i>' + escHtml(c.phone) + '</span>' : '') +
                            (c.email ? '<span class="text-muted small ms-2"><i class="fa fa-envelope fa-xs me-1"></i>' + escHtml(c.email) + '</span>' : '') +
                            '</div>' +
                            '<i class="fa fa-circle-check text-success opacity-0 select-check"></i>';
                        btn.addEventListener('click', function () { selectClient(c.id, c.name); });
                        resultsList.appendChild(btn);
                    });
                }
                resultsBox.style.display = '';
            })
            .catch(function () {
                resultsList.innerHTML =
                    '<div class="list-group-item text-danger small">' +
                    '<i class="fa fa-triangle-exclamation me-1"></i>Search failed. Please try again.</div>';
                resultsBox.style.display = '';
            })
            .finally(function () {
                searchBtn.disabled = false;
                searchBtn.innerHTML = '<i class="fa fa-search me-1"></i>Search';
            });
    }

    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
    });
    clearBtn && clearBtn.addEventListener('click', clearSelection);

    // Guard: ensure a client is chosen before form submit
    document.getElementById('linkExistingForm').addEventListener('submit', function (e) {
        if (!clientIdInput.value) {
            e.preventDefault();
            alert('Please search for and select a client before submitting.');
        }
    });
}());
</script>
ENDJS;
include __DIR__ . '/../../includes/footer.php';
?>
