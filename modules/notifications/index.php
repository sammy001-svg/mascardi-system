<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();

$pageTitle = 'Notifications';
$db     = getDB();
$me     = authUser();
$userId = (int)$me['id'];

// ── Filters ──────────────────────────────────────────────────────────────────
$filter   = $_GET['filter'] ?? 'all';   // all | unread | read
$type     = $_GET['type']   ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    try {
        if ($act === 'mark_all_read') {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$userId]);
            setFlash('success', 'All notifications marked as read.');
        } elseif ($act === 'delete_read') {
            $db->prepare("DELETE FROM notifications WHERE user_id=? AND is_read=1")->execute([$userId]);
            setFlash('success', 'Read notifications cleared.');
        } elseif ($act === 'mark_read' && isset($_POST['id'])) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([(int)$_POST['id'], $userId]);
        }
    } catch (\Throwable $e) {}
    redirect(BASE_URL . '/modules/notifications/index.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
}

// ── Query ─────────────────────────────────────────────────────────────────────
$where   = ['user_id = ?'];
$params  = [$userId];

if ($filter === 'unread') { $where[] = 'is_read = 0'; }
if ($filter === 'read')   { $where[] = 'is_read = 1'; }
if ($type)                { $where[] = 'type = ?'; $params[] = $type; }

$whereStr = 'WHERE ' . implode(' AND ', $where);

try {
    $total = (int)$db->prepare("SELECT COUNT(*) FROM notifications $whereStr")
                     ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM notifications $whereStr")->execute($params) : 0;
    $countStmt = $db->prepare("SELECT COUNT(*) FROM notifications $whereStr");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT * FROM notifications $whereStr ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    $unreadCount = getUnreadNotificationCount($userId);
} catch (\Throwable $e) {
    $notifications = [];
    $total = 0;
    $unreadCount = 0;
}

// ── Type meta ─────────────────────────────────────────────────────────────────
$typeMeta = [
    'booking'   => ['icon' => 'fa-calendar-check',       'color' => '#2563eb', 'bg' => '#eff6ff', 'label' => 'Booking'],
    'payment'   => ['icon' => 'fa-money-bill-wave',       'color' => '#16a34a', 'bg' => '#f0fdf4', 'label' => 'Payment'],
    'low_stock' => ['icon' => 'fa-boxes-stacked',         'color' => '#d97706', 'bg' => '#fffbeb', 'label' => 'Low Stock'],
    'issue'     => ['icon' => 'fa-triangle-exclamation',  'color' => '#dc2626', 'bg' => '#fef2f2', 'label' => 'Issue'],
    'lpo'       => ['icon' => 'fa-truck',                 'color' => '#0284c7', 'bg' => '#f0f9ff', 'label' => 'LPO'],
    'job'       => ['icon' => 'fa-toolbox',               'color' => '#9333ea', 'bg' => '#faf5ff', 'label' => 'Job'],
    'sale'      => ['icon' => 'fa-tag',                   'color' => '#0f172a', 'bg' => '#f8fafc', 'label' => 'Sale'],
    'info'      => ['icon' => 'fa-info-circle',           'color' => '#64748b', 'bg' => '#f8fafc', 'label' => 'Info'],
    'doc_expiry'=> ['icon' => 'fa-file-circle-exclamation','color' => '#d97706', 'bg' => '#fffbeb', 'label' => 'Document'],
];

function notifMeta(string $type, array $typeMeta): array {
    return $typeMeta[$type] ?? $typeMeta['info'];
}

function timeAgoFull(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('d M Y, H:i', strtotime($datetime));
}

// Build query string helper (preserves filters across pages)
function qstr(array $override = []): string {
    $base = array_filter(['filter' => $_GET['filter'] ?? '', 'type' => $_GET['type'] ?? '']);
    $merged = array_merge($base, $override);
    return $merged ? '?' . http_build_query($merged) : '';
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-0"><i class="fa fa-bell me-2 text-primary"></i>Notifications
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-1" style="font-size:12px;vertical-align:middle"><?= $unreadCount ?> unread</span>
            <?php endif; ?>
        </h5>
    </div>
    <div class="d-flex gap-2">
        <?php if ($unreadCount > 0): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="act" value="mark_all_read">
            <button class="btn btn-sm btn-outline-primary">
                <i class="fa fa-check-double me-1"></i>Mark all read
            </button>
        </form>
        <?php endif; ?>
        <form method="POST" class="d-inline"
              onsubmit="return confirm('Delete all read notifications?')">
            <input type="hidden" name="act" value="delete_read">
            <button class="btn btn-sm btn-outline-danger">
                <i class="fa fa-trash me-1"></i>Clear read
            </button>
        </form>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-2">

            <!-- Read/Unread filter -->
            <div class="d-flex gap-1">
                <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $val => $lbl): ?>
                <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['filter' => $val, 'page' => 1]) ?>"
                   class="btn btn-sm <?= $filter === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= $lbl ?>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="vr mx-1"></div>

            <!-- Type filter -->
            <div class="d-flex flex-wrap gap-1">
                <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['type' => '', 'page' => 1]) ?>"
                   class="btn btn-sm <?= !$type ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                    All Types
                </a>
                <?php foreach ($typeMeta as $key => $meta): ?>
                <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['type' => $key, 'page' => 1]) ?>"
                   class="btn btn-sm <?= $type === $key ? 'btn-secondary' : 'btn-outline-secondary' ?>"
                   style="<?= $type === $key ? "background:{$meta['color']};border-color:{$meta['color']}" : '' ?>">
                    <i class="fa <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- List -->
<div class="card">
    <?php if (empty($notifications)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="fa fa-bell-slash fa-3x mb-3 d-block opacity-25"></i>
        <div class="fw-medium">No notifications found</div>
        <div class="small mt-1">
            <?= $filter === 'unread' ? 'You\'re all caught up.' : 'Nothing matches these filters.' ?>
        </div>
    </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($notifications as $n):
            $meta = notifMeta($n['type'], $typeMeta);
            $isUnread = !(bool)$n['is_read'];
            $href = $n['link'] ?: null;
        ?>
        <div class="list-group-item list-group-item-action px-4 py-3 d-flex align-items-start gap-3
                    <?= $isUnread ? 'notif-unread-row' : '' ?>"
             style="<?= $isUnread ? 'background:#f8fbff;' : '' ?>">

            <!-- Icon -->
            <div class="flex-shrink-0 mt-1" style="
                width:40px;height:40px;border-radius:50%;
                background:<?= $meta['bg'] ?>;
                display:flex;align-items:center;justify-content:center">
                <i class="fa <?= $meta['icon'] ?>" style="color:<?= $meta['color'] ?>;font-size:16px"></i>
            </div>

            <!-- Body -->
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <span class="fw-<?= $isUnread ? 'bold' : 'medium' ?>" style="font-size:13.5px">
                            <?= e($n['title']) ?>
                        </span>
                        <?php if ($isUnread): ?>
                        <span class="ms-1" style="width:7px;height:7px;background:#2563eb;border-radius:50%;display:inline-block;vertical-align:middle"></span>
                        <?php endif; ?>
                    </div>
                    <span class="text-muted flex-shrink-0" style="font-size:11.5px;white-space:nowrap">
                        <?= timeAgoFull($n['created_at']) ?>
                    </span>
                </div>
                <?php if ($n['message']): ?>
                <div class="text-muted mt-1" style="font-size:12.5px;line-height:1.5">
                    <?= e($n['message']) ?>
                </div>
                <?php endif; ?>
                <div class="d-flex gap-2 mt-2">
                    <?php if ($href): ?>
                    <a href="<?= e($href) ?>" class="btn btn-xs btn-outline-primary">
                        <i class="fa fa-arrow-right me-1"></i>View
                    </a>
                    <?php endif; ?>
                    <?php if ($isUnread): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="act" value="mark_read">
                        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                        <button class="btn btn-xs btn-outline-secondary">Mark read</button>
                    </form>
                    <?php endif; ?>
                    <span class="badge ms-auto align-self-center" style="
                        background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>;
                        font-size:10px;font-weight:600;border:1px solid <?= $meta['color'] ?>22">
                        <?= $meta['label'] ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total > $perPage):
        $pages = (int)ceil($total / $perPage);
    ?>
    <div class="card-footer bg-white border-top d-flex align-items-center justify-content-between py-2 px-4">
        <small class="text-muted">
            Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?>
            of <?= number_format($total) ?>
        </small>
        <div class="d-flex gap-1">
            <?php if ($page > 1): ?>
            <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['page' => $page - 1]) ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end   = min($pages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
            <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['page' => $p]) ?>"
               class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="<?= BASE_URL ?>/modules/notifications/index.php<?= qstr(['page' => $page + 1]) ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
