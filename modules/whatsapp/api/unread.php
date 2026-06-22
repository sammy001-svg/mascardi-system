<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { echo json_encode(['count' => 0]); exit; }

try {
    $count = (int) getDB()->query("SELECT COALESCE(SUM(unread_count), 0) FROM wa_conversations")->fetchColumn();
    echo json_encode(['count' => $count]);
} catch (\Throwable $_) {
    echo json_encode(['count' => 0]);
}
