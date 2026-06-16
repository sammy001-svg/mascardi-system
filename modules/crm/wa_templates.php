<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// ── Auto-migrations ───────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS crm_wa_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category ENUM('first_contact','follow_up','test_drive_invite','price_quote','post_delivery','general') DEFAULT 'general',
        body TEXT NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $_) {}

// ── Seed default templates if table is empty ──────────────────────────────────
try {
    $count = (int)$db->query("SELECT COUNT(*) FROM crm_wa_templates")->fetchColumn();
    if ($count === 0) {
        $seeds = [
            [
                'name'     => 'First Contact',
                'category' => 'first_contact',
                'body'     => "Hello {name}! I'm {agent} from {company}. I came across your enquiry about {car}. I'd love to help you find your perfect vehicle. When would be a good time to chat?",
            ],
            [
                'name'     => 'Follow-up',
                'category' => 'follow_up',
                'body'     => "Hi {name}, just checking in to see if you're still interested in {car}. We have some great options available. Feel free to reach out anytime! — {agent}, {company}",
            ],
            [
                'name'     => 'Test Drive Invite',
                'category' => 'test_drive_invite',
                'body'     => "Hello {name}! 🚗 I'd like to invite you for a test drive of the {car}. We're available on weekdays and weekends. When works best for you? — {agent}",
            ],
            [
                'name'     => 'Price Quote',
                'category' => 'price_quote',
                'body'     => "Hi {name}, as discussed, the {car} is priced at {price}. This includes all standard features. Would you like to proceed or discuss further? — {agent}, {company}",
            ],
            [
                'name'     => 'Post Delivery',
                'category' => 'post_delivery',
                'body'     => "Hello {name}! Congratulations on your new {car}! 🎉 Thank you for choosing {company}. Please don't hesitate to reach out if you need anything. Enjoy your new ride! — {agent}",
            ],
            [
                'name'     => 'General Follow-up',
                'category' => 'general',
                'body'     => "Hi {name}, hope you're doing well! Just reaching out to see if you have any questions about our vehicles. We're always here to help. — {agent}, {company}",
            ],
        ];
        $ins = $db->prepare("
            INSERT INTO crm_wa_templates (name, category, body, is_active, sort_order, created_by)
            VALUES (?, ?, ?, 1, ?, NULL)
        ");
        foreach ($seeds as $i => $seed) {
            $ins->execute([$seed['name'], $seed['category'], $seed['body'], ($i + 1) * 10]);
        }
    }
} catch (\Throwable $_) {}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Save template (create or update) ─────────────────────────────────────
    if ($action === 'save_template' && canWrite('crm')) {
        $tplId    = (int)($_POST['id']       ?? 0);
        $name     = trim($_POST['name']      ?? '');
        $category = trim($_POST['category']  ?? 'general');
        $body     = trim($_POST['body']      ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        $validCats = ['first_contact','follow_up','test_drive_invite','price_quote','post_delivery','general'];
        if (!in_array($category, $validCats, true)) $category = 'general';

        $errors = [];
        if (!$name) $errors[] = 'Template name is required.';
        if (!$body) $errors[] = 'Template body is required.';

        if (empty($errors)) {
            try {
                if ($tplId) {
                    $db->prepare("
                        UPDATE crm_wa_templates
                        SET name = ?, category = ?, body = ?, is_active = ?
                        WHERE id = ?
                    ")->execute([$name, $category, $body, $isActive, $tplId]);
                    setFlash('success', 'Template updated successfully.');
                } else {
                    $maxOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order),0) FROM crm_wa_templates")->fetchColumn();
                    $db->prepare("
                        INSERT INTO crm_wa_templates (name, category, body, is_active, sort_order, created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$name, $category, $body, $isActive, $maxOrder + 10, $uid]);
                    setFlash('success', 'Template created successfully.');
                }
                logActivity($tplId ? 'update' : 'create', 'crm_wa_templates', $tplId ?: null,
                    ($tplId ? 'Updated' : 'Created') . " template: $name");
            } catch (\Throwable $e) {
                setFlash('danger', 'Error saving template: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', implode(' ', $errors));
        }
        redirect(BASE_URL . '/modules/crm/wa_templates.php');
    }

    // ── Delete template ───────────────────────────────────────────────────────
    if ($action === 'delete_template') {
        $tplId = (int)($_POST['id'] ?? 0);
        if (!$tplId) {
            setFlash('danger', 'Invalid request.');
            redirect(BASE_URL . '/modules/crm/wa_templates.php');
        }

        // Only allow delete if created_by = current user OR admin/manager
        $isAdmin = in_array($me['role'], ['admin', 'sales_manager', 'general_manager']);
        $chk = $db->prepare("SELECT created_by FROM crm_wa_templates WHERE id = ?");
        $chk->execute([$tplId]);
        $row = $chk->fetch();

        if (!$row) {
            setFlash('danger', 'Template not found.');
        } elseif (!$isAdmin && (int)$row['created_by'] !== $uid) {
            setFlash('danger', 'You can only delete templates you created.');
        } else {
            try {
                $db->prepare("DELETE FROM crm_wa_templates WHERE id = ?")->execute([$tplId]);
                logActivity('delete', 'crm_wa_templates', $tplId, 'Deleted WA template');
                setFlash('success', 'Template deleted.');
            } catch (\Throwable $e) {
                setFlash('danger', 'Error deleting template: ' . $e->getMessage());
            }
        }
        redirect(BASE_URL . '/modules/crm/wa_templates.php');
    }

    // ── Toggle active ─────────────────────────────────────────────────────────
    if ($action === 'toggle_active') {
        $tplId = (int)($_POST['id'] ?? 0);
        if ($tplId) {
            try {
                $db->prepare("UPDATE crm_wa_templates SET is_active = 1 - is_active WHERE id = ?")
                   ->execute([$tplId]);
                setFlash('success', 'Template status toggled.');
            } catch (\Throwable $e) {
                setFlash('danger', 'Error toggling template: ' . $e->getMessage());
            }
        }
        redirect(BASE_URL . '/modules/crm/wa_templates.php');
    }

    // ── Reorder templates ─────────────────────────────────────────────────────
    if ($action === 'reorder') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids'])
            ? array_map('intval', $_POST['ids'])
            : [];
        try {
            $upd = $db->prepare("UPDATE crm_wa_templates SET sort_order = ? WHERE id = ?");
            foreach ($ids as $i => $id) {
                if ($id > 0) $upd->execute([($i + 1) * 10, $id]);
            }
            setFlash('success', 'Order saved.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Error reordering templates: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/crm/wa_templates.php');
    }
}

// ── Load templates ────────────────────────────────────────────────────────────
try {
    $templates = $db->query("
        SELECT t.*, u.name AS creator_name
        FROM crm_wa_templates t
        LEFT JOIN users u ON u.id = t.created_by
        ORDER BY t.sort_order ASC, t.id ASC
    ")->fetchAll();
} catch (\Throwable $e) {
    $templates = [];
}

// Group by category
$grouped = [];
foreach ($templates as $tpl) {
    $grouped[$tpl['category']][] = $tpl;
}

$pageTitle = 'WhatsApp Templates';

$categoryMeta = [
    'first_contact'      => ['First Contact',       'primary'],
    'follow_up'          => ['Follow-up',            'warning'],
    'test_drive_invite'  => ['Test Drive Invite',    'info'],
    'price_quote'        => ['Price Quote',          'success'],
    'post_delivery'      => ['Post Delivery',        'purple'],
    'general'            => ['General',              'secondary'],
];
$categoryOrder = ['first_contact','follow_up','test_drive_invite','price_quote','post_delivery','general'];

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* Purple badge for post_delivery */
.bg-purple { background-color: #7c3aed !important; color: #fff; }
.border-purple { border-color: #7c3aed !important; }
/* Template body clamp */
.tpl-body-clamp {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    white-space: pre-wrap;
}
.tpl-body-full { display: none; white-space: pre-wrap; }
.tpl-card-inactive { opacity: 0.52; }
</style>

<!-- ── Page Header ───────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fab fa-whatsapp me-2 text-success"></i>WhatsApp Templates
        <span class="badge bg-secondary ms-1"><?= count($templates) ?></span>
    </h5>
    <?php if (canWrite('crm')): ?>
    <button type="button" class="btn btn-success btn-sm"
            data-bs-toggle="modal" data-bs-target="#editModal"
            onclick="openCreateModal()">
        <i class="fa fa-plus me-1"></i>Add Template
    </button>
    <?php endif; ?>
</div>

<!-- ── Placeholder Reference (collapsible) ───────────────────────────────────── -->
<div class="card mb-4 border-info">
    <div class="card-header d-flex justify-content-between align-items-center py-2"
         style="cursor:pointer;background:#e0f2fe" data-bs-toggle="collapse"
         data-bs-target="#placeholderRef" aria-expanded="false">
        <span class="fw-semibold text-info">
            <i class="fa fa-tags me-2"></i>Available Placeholders
        </span>
        <i class="fa fa-chevron-down text-info" id="placeholderChevron"></i>
    </div>
    <div class="collapse" id="placeholderRef">
        <div class="card-body py-3">
            <div class="row g-2">
                <?php
                $placeholders = [
                    '{name}'    => 'Lead name',
                    '{agent}'   => 'Your name',
                    '{company}' => 'Company name',
                    '{car}'     => 'Vehicle interest',
                    '{price}'   => 'Car price',
                    '{date}'    => "Today's date",
                ];
                foreach ($placeholders as $ph => $desc):
                ?>
                <div class="col-sm-4 col-md-3 col-lg-2">
                    <div class="d-flex align-items-start gap-2 p-2 rounded"
                         style="background:#f0fdf4;border:1px solid #bbf7d0">
                        <code class="text-success fw-bold" style="font-size:13px"><?= $ph ?></code>
                        <span class="text-muted small"><?= $desc ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-muted small mt-3">
                <i class="fa fa-info-circle me-1"></i>
                These placeholders are replaced with real values when you send a WhatsApp message from a lead's profile.
            </div>
        </div>
    </div>
</div>

<!-- ── Templates List ────────────────────────────────────────────────────────── -->
<?php if (empty($templates)): ?>
<div class="card">
    <div class="text-center py-5 text-muted">
        <i class="fab fa-whatsapp fa-3x mb-3 opacity-25 d-block"></i>
        <div class="fw-semibold mb-2">No WhatsApp templates yet.</div>
        <?php if (canWrite('crm')): ?>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#editModal"
                onclick="openCreateModal()">
            <i class="fa fa-plus me-1"></i>Add First Template
        </button>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<?php foreach ($categoryOrder as $catKey):
    if (empty($grouped[$catKey])) continue;
    [$catLabel, $catColor] = $categoryMeta[$catKey] ?? [$catKey, 'secondary'];
?>
<div class="mb-4">
    <h6 class="text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:.08em">
        <span class="badge bg-<?= $catColor ?> me-2"><?= $catLabel ?></span>
        <?= count($grouped[$catKey]) ?> template<?= count($grouped[$catKey]) !== 1 ? 's' : '' ?>
    </h6>
    <div class="row g-3">
        <?php foreach ($grouped[$catKey] as $tpl):
            $isInactive = !(bool)$tpl['is_active'];
            $charCount  = mb_strlen($tpl['body']);
            $isAdmin    = in_array($me['role'], ['admin','sales_manager','general_manager']);
            $canDelete  = $isAdmin || (int)$tpl['created_by'] === $uid;
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 <?= $isInactive ? 'tpl-card-inactive' : '' ?>"
                 style="<?= $isInactive ? 'border-style:dashed' : '' ?>">
                <div class="card-body">
                    <!-- Header row -->
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-semibold mb-1"><?= e($tpl['name']) ?></div>
                            <span class="badge bg-<?= $catColor ?>" style="font-size:10px"><?= $catLabel ?></span>
                            <?php if ($isInactive): ?>
                            <span class="badge bg-secondary ms-1" style="font-size:10px">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-light text-dark border" style="font-size:10px;white-space:nowrap">
                            <?= $charCount ?> chars
                        </span>
                    </div>

                    <!-- Body preview -->
                    <div class="mt-2 mb-3" style="font-size:13px;color:#374151">
                        <div class="tpl-body-clamp" id="body-clamp-<?= (int)$tpl['id'] ?>">
                            <?= nl2br(e($tpl['body'])) ?>
                        </div>
                        <div class="tpl-body-full" id="body-full-<?= (int)$tpl['id'] ?>">
                            <?= nl2br(e($tpl['body'])) ?>
                        </div>
                        <?php if ($charCount > 120): ?>
                        <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-primary"
                                style="font-size:12px"
                                onclick="toggleBodyExpand(<?= (int)$tpl['id'] ?>, this)">
                            Show more
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-2 flex-wrap align-items-center mt-auto pt-2 border-top">
                        <?php if (canWrite('crm')): ?>
                        <!-- Edit -->
                        <button type="button" class="btn btn-xs btn-outline-primary"
                                onclick="openEditModal(<?= htmlspecialchars(json_encode([
                                    'id'       => (int)$tpl['id'],
                                    'name'     => $tpl['name'],
                                    'category' => $tpl['category'],
                                    'body'     => $tpl['body'],
                                    'is_active'=> (int)$tpl['is_active'],
                                ]), ENT_QUOTES) ?>)">
                            <i class="fa fa-edit me-1"></i>Edit
                        </button>
                        <!-- Toggle Active -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id"     value="<?= (int)$tpl['id'] ?>">
                            <button type="submit" class="btn btn-xs <?= $isInactive ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                    title="<?= $isInactive ? 'Activate' : 'Deactivate' ?>">
                                <i class="fa <?= $isInactive ? 'fa-toggle-off' : 'fa-toggle-on' ?> me-1"></i>
                                <?= $isInactive ? 'Activate' : 'Active' ?>
                            </button>
                        </form>
                        <!-- Delete -->
                        <?php if ($canDelete): ?>
                        <form method="POST" class="d-inline ms-auto"
                              onsubmit="return confirm('Delete this template? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id"     value="<?= (int)$tpl['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Add / Edit Template                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (canWrite('crm')): ?>
<div class="modal fade" id="editModal" tabindex="-1"
     aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="editTplForm">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="id"     id="tplId">
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">
                        <i class="fab fa-whatsapp me-2 text-success"></i>
                        <span id="editModalTitleText">Add Template</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Template name -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Template Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="name" id="tplName" class="form-control"
                                   placeholder="e.g. Weekend Follow-up" required maxlength="100">
                        </div>
                        <!-- Category -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category" id="tplCategory" class="form-select">
                                <?php foreach ($categoryMeta as $catKey => [$catLabel, $catColor]): ?>
                                <option value="<?= $catKey ?>"><?= $catLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Body -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                Message Body <span class="text-danger">*</span>
                            </label>
                            <textarea name="body" id="tplBody" class="form-control" rows="5"
                                      placeholder="Type your message here. Use {name}, {agent}, {company}, {car}, {price}, {date} as placeholders."
                                      required oninput="updateCharCount()"></textarea>
                            <div class="d-flex justify-content-between mt-1">
                                <div class="form-text text-muted">
                                    Use placeholders like <code>{name}</code>, <code>{car}</code>, <code>{agent}</code>
                                </div>
                                <div class="form-text" id="charCountDisplay" style="font-weight:500">0 chars</div>
                            </div>
                        </div>
                        <!-- Active -->
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="tplIsActive" value="1" checked>
                                <label class="form-check-label" for="tplIsActive">
                                    Active — available for use on lead profiles
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="editModalSaveBtn">
                        <i class="fa fa-save me-1"></i>Save Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Character count ───────────────────────────────────────────────────────────
function updateCharCount() {
    var body    = document.getElementById('tplBody');
    var display = document.getElementById('charCountDisplay');
    if (!body || !display) return;
    var n = body.value.length;
    display.textContent = n + ' char' + (n !== 1 ? 's' : '');
    display.style.color = n > 1000 ? '#dc2626' : (n > 500 ? '#d97706' : '#374151');
}

// ── Open modal to create a new template ──────────────────────────────────────
function openCreateModal() {
    document.getElementById('tplId').value      = '';
    document.getElementById('tplName').value    = '';
    document.getElementById('tplCategory').value = 'general';
    document.getElementById('tplBody').value    = '';
    document.getElementById('tplIsActive').checked = true;
    document.getElementById('editModalTitleText').textContent = 'Add Template';
    document.getElementById('editModalSaveBtn').innerHTML =
        '<i class="fa fa-plus me-1"></i>Add Template';
    updateCharCount();
}

// ── Open modal to edit an existing template ───────────────────────────────────
function openEditModal(data) {
    document.getElementById('tplId').value         = data.id || '';
    document.getElementById('tplName').value       = data.name || '';
    document.getElementById('tplCategory').value   = data.category || 'general';
    document.getElementById('tplBody').value       = data.body || '';
    document.getElementById('tplIsActive').checked = !!data.is_active;
    document.getElementById('editModalTitleText').textContent = 'Edit Template';
    document.getElementById('editModalSaveBtn').innerHTML =
        '<i class="fa fa-save me-1"></i>Save Template';
    updateCharCount();
    var modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
}

// ── Toggle body expand / collapse ─────────────────────────────────────────────
function toggleBodyExpand(id, btn) {
    var clamp = document.getElementById('body-clamp-' + id);
    var full  = document.getElementById('body-full-'  + id);
    if (!clamp || !full) return;
    if (full.style.display === 'none' || full.style.display === '') {
        clamp.style.display = 'none';
        full.style.display  = 'block';
        btn.textContent     = 'Show less';
    } else {
        full.style.display  = 'none';
        clamp.style.display = '-webkit-box';
        btn.textContent     = 'Show more';
    }
}

// ── Placeholder chevron rotate ────────────────────────────────────────────────
(function () {
    var ref = document.getElementById('placeholderRef');
    var chv = document.getElementById('placeholderChevron');
    if (!ref || !chv) return;
    ref.addEventListener('show.bs.collapse',  function () { chv.style.transform = 'rotate(180deg)'; });
    ref.addEventListener('hidden.bs.collapse', function () { chv.style.transform = 'rotate(0deg)'; });
}());
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('editModal');
    if (el && el.parentNode !== document.body) document.body.appendChild(el);
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
