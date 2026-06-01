<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('car_documents') || redirect(BASE_URL . '/index.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/car_documents/index.php');

$id       = (int)($_POST['id'] ?? 0);
$redirect = $_POST['redirect'] ?? BASE_URL . '/modules/car_documents/index.php';

// Validate redirect is local
if (!str_starts_with($redirect, BASE_URL)) {
    $redirect = BASE_URL . '/modules/car_documents/index.php';
}

if ($id) {
    $db   = getDB();
    $doc  = $db->prepare("SELECT * FROM car_documents WHERE id=?");
    $doc->execute([$id]);
    $doc  = $doc->fetch();

    if ($doc) {
        $filePath = BASE_PATH . '/uploads/car_docs/' . $doc['file_path'];
        if (file_exists($filePath)) @unlink($filePath);
        $db->prepare("DELETE FROM car_documents WHERE id=?")->execute([$id]);
        logActivity('delete', 'car_documents', $id, 'Deleted document: ' . $doc['title']);
        setFlash('success', 'Document deleted.');
    } else {
        setFlash('error', 'Document not found.');
    }
}

redirect($redirect);
