<?php
/**
 * Who owns a thread, and whether it is still open.
 *
 * A shared inbox without ownership is a room where everybody assumes somebody
 * else replied. Assigning is how a conversation stops being everyone's problem
 * and becomes one person's, and closing is how the list stays short enough to
 * be worth reading.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
if (!waCanSend()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to change conversations.']);
    exit;
}
verifyCsrf();

$db = getDB();
waMigrate($db);
$me = authUser();

$convId = (int)($_POST['conversation_id'] ?? 0);
$action = $_POST['action'] ?? '';
$conv   = $convId > 0 ? waConversationById($db, $convId) : null;

if (!$conv) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That conversation no longer exists.']);
    exit;
}

try {
    if ($action === 'assign') {
        $to = (int)($_POST['user_id'] ?? 0);
        if ($to > 0) {
            // Only somebody who could actually answer it. Assigning a customer
            // conversation to a mechanic means it is never seen again.
            $st = $db->prepare("SELECT id, name, role FROM users WHERE id = ? AND status = 'active'");
            $st->execute([$to]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) { echo json_encode(['ok' => false, 'error' => 'That person could not be found.']); exit; }
            $db->prepare("UPDATE wa_conversations SET assigned_to = ? WHERE id = ?")->execute([$to, $convId]);
            logActivity('update', 'wa_conversations', $convId,
                'WhatsApp conversation assigned to ' . $u['name'] . ' by ' . $me['name'] . '.');
        } else {
            $db->prepare("UPDATE wa_conversations SET assigned_to = NULL WHERE id = ?")->execute([$convId]);
            logActivity('update', 'wa_conversations', $convId,
                'WhatsApp conversation unassigned by ' . $me['name'] . '.');
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'close' || $action === 'reopen') {
        $new = $action === 'close' ? 'closed' : 'open';
        $db->prepare("UPDATE wa_conversations SET status = ? WHERE id = ?")->execute([$new, $convId]);
        logActivity('update', 'wa_conversations', $convId,
            'WhatsApp conversation ' . ($new === 'closed' ? 'closed' : 'reopened') . ' by ' . $me['name'] . '.');
        echo json_encode(['ok' => true, 'status' => $new]);
        exit;
    }

    if ($action === 'link_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!canAccess('clients')) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You cannot change client links.']);
            exit;
        }
        if ($clientId > 0) {
            $st = $db->prepare("SELECT id, name FROM clients WHERE id = ?");
            $st->execute([$clientId]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) { echo json_encode(['ok' => false, 'error' => 'That client could not be found.']); exit; }
            $db->prepare("UPDATE wa_conversations SET client_id = ? WHERE id = ?")->execute([$clientId, $convId]);
            logActivity('update', 'wa_conversations', $convId,
                'WhatsApp conversation linked to client ' . $c['name'] . ' by ' . $me['name'] . '.');
        } else {
            $db->prepare("UPDATE wa_conversations SET client_id = NULL WHERE id = ?")->execute([$convId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
} catch (\Throwable $e) {
    error_log('wa assign: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'That could not be saved.']);
}
