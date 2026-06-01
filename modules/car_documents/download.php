<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('car_documents') || http_response_code(403) || exit('Access denied.');

$id      = (int)($_GET['id']   ?? 0);
$inline  = isset($_GET['view']); // view=1 → inline (browser), else force-download

if (!$id) { http_response_code(400); exit('Invalid request.'); }

$db  = getDB();
$doc = $db->prepare("SELECT * FROM car_documents WHERE id=?");
$doc->execute([$id]);
$doc = $doc->fetch();

if (!$doc) { http_response_code(404); exit('Document not found.'); }

$filePath = BASE_PATH . '/uploads/car_docs/' . $doc['file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File no longer exists on disk.');
}

$mime = $doc['mime_type'] ?: mime_content_type($filePath) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));

if ($inline && in_array($mime, ['application/pdf','image/jpeg','image/png','image/gif','image/webp'])) {
    header('Content-Disposition: inline; filename="' . addslashes($doc['file_name']) . '"');
} else {
    header('Content-Disposition: attachment; filename="' . addslashes($doc['file_name']) . '"');
}

header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
