<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn() || !canAccess('crm') || !canWrite('crm')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$name        = trim($_POST['name'] ?? '');
$phone       = trim($_POST['phone'] ?? '');
$interested  = trim($_POST['interested_in'] ?? '');
$source      = $_POST['source'] ?? 'walk_in';
$assignTo    = (int)($_POST['assigned_to'] ?? 0);

$validSources = ['walk_in','referral','facebook','instagram','website','phone_call','whatsapp','other'];
if (!in_array($source, $validSources)) $source = 'walk_in';

if (!$name) {
    echo json_encode(['success' => false, 'error' => 'Name is required']);
    exit;
}

try {
    $db  = getDB();
    $me  = authUser();
    $uid = (int)$me['id'];

    // Assign to self if not specified or if CRM agent
    if ($me['role'] === 'customer_relations' || !$assignTo) {
        $assignTo = $uid;
    }

    $db->prepare("INSERT INTO crm_leads (name, phone, interested_in, source, stage, assigned_to, created_at, updated_at)
                  VALUES (?, ?, ?, ?, 'hot', ?, NOW(), NOW())")
       ->execute([$name, $phone ?: null, $interested ?: null, $source, $assignTo]);

    $leadId = (int)$db->lastInsertId();
    logActivity('create', 'crm_leads', $leadId, "Walk-in quick capture: {$name}");

    echo json_encode([
        'success' => true,
        'lead_id' => $leadId,
        'name'    => $name,
        'view_url'=> BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
