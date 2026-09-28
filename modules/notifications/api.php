<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();

header('Content-Type: application/json');

$user   = authUser();
$userId = (int)$user['id'];
$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'count':
        // Every signed-in browser polls this every twelve seconds, which makes it
        // the one thing in the system that ticks on its own. Credit reminders
        // are triggered by a due date arriving — and nothing fires when nothing
        // happens — so the tick has to come from somewhere. Internally locked,
        // so twenty staff polling at once sweep once between them, and it never
        // delays the badge, which is what this endpoint is actually for.
        try {
            require_once __DIR__ . '/../finance/_credit.php';
            creditHeartbeat(getDB());
        } catch (\Throwable $e) {
            error_log('credit heartbeat: ' . $e->getMessage());
        }
        echo json_encode(['count' => getUnreadNotificationCount($userId)]);
        break;

    case 'list':
        $items = getRecentNotifications($userId, 25);
        $typeIcons = [
            'booking'   => ['icon' => 'fa-calendar-check',  'color' => '#2563eb'],
            'payment'   => ['icon' => 'fa-money-bill-wave',  'color' => '#16a34a'],
            'low_stock' => ['icon' => 'fa-boxes-stacked',    'color' => '#d97706'],
            'issue'     => ['icon' => 'fa-triangle-exclamation', 'color' => '#dc2626'],
            'lpo'       => ['icon' => 'fa-truck',            'color' => '#0284c7'],
            'job'       => ['icon' => 'fa-toolbox',          'color' => '#9333ea'],
            'sale'      => ['icon' => 'fa-tag',                      'color' => '#0f172a'],
            'info'      => ['icon' => 'fa-info-circle',             'color' => '#64748b'],
            'doc_expiry'=> ['icon' => 'fa-file-circle-exclamation', 'color' => '#d97706'],
            'alert'     => ['icon' => 'fa-bell',                    'color' => '#dc2626'],
            'warning'   => ['icon' => 'fa-triangle-exclamation',    'color' => '#d97706'],
        ];
        foreach ($items as &$n) {
            $meta = $typeIcons[$n['type']] ?? $typeIcons['info'];
            $n['icon']  = $meta['icon'];
            $n['color'] = $meta['color'];
            $n['ago']   = timeAgo($n['created_at']);
        }
        unset($n);
        echo json_encode(['notifications' => $items, 'count' => getUnreadNotificationCount($userId)]);
        break;

    case 'mark_read':
        $nid = (int)($_POST['id'] ?? 0);
        if ($nid) {
            try {
                getDB()->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
                       ->execute([$nid, $userId]);
            } catch (\Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'count' => getUnreadNotificationCount($userId)]);
        break;

    case 'mark_all_read':
        try {
            getDB()->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")
                   ->execute([$userId]);
        } catch (\Throwable $e) {}
        echo json_encode(['ok' => true, 'count' => 0]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M', strtotime($datetime));
}
