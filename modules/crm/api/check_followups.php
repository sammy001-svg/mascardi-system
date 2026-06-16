<?php
/**
 * AJAX endpoint — returns due/overdue follow-up count for the current CRM user
 * and creates in-app notifications for any due today that haven't been notified yet.
 * Polled by the CRM sidebar every 2 minutes.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['count' => 0, 'error' => 'unauthenticated']);
    exit;
}

if (!canAccess('crm')) {
    echo json_encode(['count' => 0, 'error' => 'forbidden']);
    exit;
}

try {
    $db  = getDB();
    $me  = authUser();
    $uid = (int)$me['id'];
    $isCrmAgent = ($me['role'] === 'customer_relations');
    $ownerWhere = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

    $today = date('Y-m-d');

    // Count overdue + today
    $count = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads l
        WHERE l.follow_up_date <= '{$today}'
          AND l.stage NOT IN ('lost','delivered')
          {$ownerWhere}
    ")->fetchColumn();

    // Notify for leads due today that haven't been notified today yet
    // We use a simple "last_notified_date" column, added via auto-migration
    try {
        $db->exec("ALTER TABLE crm_leads ADD COLUMN last_notified_date DATE NULL DEFAULT NULL");
    } catch (\Throwable $_) {}

    $dueToday = $db->query("
        SELECT id, name, interested_in FROM crm_leads
        WHERE follow_up_date = '{$today}'
          AND stage NOT IN ('lost','delivered')
          AND (last_notified_date IS NULL OR last_notified_date < '{$today}')
          {$ownerWhere}
        LIMIT 10
    ")->fetchAll();

    foreach ($dueToday as $lead) {
        $targetUid = $isCrmAgent ? $uid : (int)($lead['assigned_to'] ?? $uid);
        createNotification(
            $targetUid,
            'reminder',
            "Follow-up due: {$lead['name']}",
            $lead['interested_in'] ? "Interested in: {$lead['interested_in']}" : 'Follow-up scheduled for today.',
            BASE_URL . '/modules/crm/view_lead.php?id=' . (int)$lead['id']
        );
        $db->prepare("UPDATE crm_leads SET last_notified_date = ? WHERE id = ?")
           ->execute([$today, (int)$lead['id']]);
    }

    // Count overdue separately for the badge colour hint
    $overdue = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads l
        WHERE l.follow_up_date < '{$today}'
          AND l.stage NOT IN ('lost','delivered')
          {$ownerWhere}
    ")->fetchColumn();

    echo json_encode([
        'count'   => $count,
        'overdue' => $overdue,
        'today'   => $count - $overdue,
    ]);

} catch (\Throwable $e) {
    echo json_encode(['count' => 0, 'error' => $e->getMessage()]);
}
