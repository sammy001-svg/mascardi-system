<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !canAccess('crm')) {
    http_response_code(403); echo json_encode(['success'=>false]); exit;
}

$tdId    = (int)($_POST['td_id'] ?? 0);
$action  = $_POST['action'] ?? '';
$outcome = trim($_POST['outcome'] ?? '');

if (!$tdId || !in_array($action, ['complete','no_show','cancel'])) {
    echo json_encode(['success'=>false,'error'=>'Invalid request']); exit;
}

try {
    $db  = getDB();
    $me  = authUser();
    $uid = (int)$me['id'];

    // Load test drive + verify ownership
    $td = $db->prepare("SELECT td.*, l.assigned_to FROM crm_test_drives td JOIN crm_leads l ON l.id = td.lead_id WHERE td.id = ?");
    $td->execute([$tdId]);
    $td = $td->fetch();

    if (!$td) { echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
    if ($me['role'] === 'customer_relations' && (int)$td['assigned_to'] !== $uid) {
        echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
    }

    $statusMap = ['complete'=>'completed','no_show'=>'no_show','cancel'=>'cancelled'];
    $newStatus = $statusMap[$action];

    $db->prepare("UPDATE crm_test_drives SET status=?, outcome=?, updated_at=NOW() WHERE id=?")
       ->execute([$newStatus, $outcome ?: null, $tdId]);

    // Log activity on lead
    if ($newStatus === 'completed') {
        $summary = "Test drive completed" . ($outcome ? ": {$outcome}" : '');
        $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, outcome, created_by) VALUES (?,?,?,?,?)")
           ->execute([$td['lead_id'], 'test_drive', $summary, $outcome ?: null, $uid]);
    } elseif ($newStatus === 'no_show') {
        $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by) VALUES (?,?,?,?)")
           ->execute([$td['lead_id'], 'note', 'Test drive — client no-show', $uid]);
    }

    echo json_encode(['success'=>true,'new_status'=>$newStatus]);
} catch (\Throwable $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
