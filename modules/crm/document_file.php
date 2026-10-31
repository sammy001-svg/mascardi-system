<?php
/**
 * Serves a document attached to a lead, or to a credit account.
 *
 * The files sit under the web root because this host gives us nowhere else to
 * put them, so uploads/lead_docs/ denies direct access and everything comes
 * through here instead — signed, with a login checked first. These are sales
 * agreements and ID copies; a guessable URL is not an acceptable lock.
 *
 * Who may read one follows what it belongs to, which is the only rule that
 * stays true as the owners multiply. A lead's paperwork needs the CRM; an
 * imported credit account's needs the receivables book. Checking only the CRM
 * meant a finance manager could file the signed agreement on an account and
 * then be refused it on the way back, which is a worse outcome than not
 * offering the upload at all.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_documents.php';
require_once __DIR__ . '/../finance/_credit.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid request.'); }

$db = getDB();
leadDocsSchema($db);
$st = $db->prepare("SELECT * FROM crm_lead_documents WHERE id = ?");
$st->execute([$id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);

if (!$doc) { http_response_code(404); exit('Document not found.'); }

// The document is read before the permission, because the permission depends on
// it. A missing document answers 404 either way, so nothing is disclosed by the
// order: an id that does not exist and one the caller may not have both look
// the same from outside.
$allowed = !empty($doc['lead_id'])
    ? canAccess('crm')
    : (!empty($doc['agreement_id']) ? creditCanView() : canAccess('crm'));
if (!$allowed) { http_response_code(403); exit('Access denied.'); }

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
