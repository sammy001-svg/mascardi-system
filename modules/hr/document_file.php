<?php
/**
 * HR — authenticated document download
 *
 * Employee files hold national IDs, contracts and bank details. Serving them
 * straight from /uploads/ would make every personnel scan readable by anyone
 * who has (or guesses) the URL, with no login involved. Everything goes through
 * this gate instead, and the directory itself is denied by .htaccess.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$doc = null;
try {
    $st = $db->prepare("SELECT * FROM hr_documents WHERE id = ?");
    $st->execute([$id]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    error_log('hr/document_file: ' . $e->getMessage());
}

if (!$doc || !$doc['file_path']) {
    http_response_code(404);
    exit('Document not found.');
}

// basename() strips any traversal in a stored value, so a poisoned row cannot
// reach outside the documents directory.
$file = __DIR__ . '/../../uploads/hr_documents/' . basename((string)$doc['file_path']);
if (!is_file($file)) {
    http_response_code(404);
    exit('The stored file is missing.');
}

$ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'png' => 'image/png', 'webp' => 'image/webp'];
$mime  = $types[$ext] ?? 'application/octet-stream';

// inline for the formats a browser renders safely; anything else downloads.
$disposition = isset($types[$ext]) ? 'inline' : 'attachment';
$safeName    = preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['title'] ?: 'document') . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($file);
