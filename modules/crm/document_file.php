<?php
/**
 * Serves a document attached to a lead.
 *
 * The files sit under the web root because this host gives us nowhere else to
 * put them, so uploads/lead_docs/ denies direct access and everything comes
 * through here instead — signed, with a login checked first. These are sales
 * agreements and ID copies; a guessable URL is not an acceptable lock.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_documents.php';
requireLogin();
canAccess('crm') || (http_response_code(403) && exit('Access denied.'));

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid request.'); }

$db = getDB();
leadDocsSchema($db);
$st = $db->prepare("SELECT * FROM crm_lead_documents WHERE id = ?");
$st->execute([$id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);

if (!$doc) { http_response_code(404); exit('Document not found.'); }

$path = leadDocsDir() . $doc['file_path'];
if (!is_file($path)) { http_response_code(404); exit('That file is no longer on the server.'); }

$mime = (string)($doc['mime_type'] ?: (@mime_content_type($path) ?: 'application/octet-stream'));
$name = (string)($doc['file_name'] ?: 'document');

// Only the formats a browser renders safely are shown inline. Anything else
// downloads, so a stray .html or .svg cannot run as a page on our own origin.
$inline = isset($_GET['view'])
       && in_array($mime, ['application/pdf','image/jpeg','image/png','image/gif','image/webp'], true);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
     . '; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($path);
exit;
