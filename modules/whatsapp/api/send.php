<?php
/**
 * Send — words, an uploaded file, or a document already held in the system.
 *
 * Sending a document was the thing the old module could not do at all. The
 * database had the columns for it and the screen had no way to reach them, so
 * every quotation and logbook went out from somebody's personal phone, leaving
 * no trace against the customer.
 *
 * Three ways in, one path out:
 *   text   a message typed in the inbox
 *   file   something chosen from the sender's computer
 *   attach something this system already holds — a car photograph, a scanned
 *          logbook — chosen by id rather than by path, so a crafted request
 *          cannot walk out of the uploads folder and read the database config.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function waFail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') waFail('POST only', 405);
if (!waCanSend())                          waFail('You do not have permission to send WhatsApp messages.', 403);
verifyCsrf();

$db = getDB();
waMigrate($db);
$me  = authUser();
$uid = (int)$me['id'];

$convId = (int)($_POST['conversation_id'] ?? 0);
$phone  = trim($_POST['phone'] ?? '');

// Either an existing thread, or a number to start one with.
if ($convId > 0) {
    $conv = waConversationById($db, $convId);
    if (!$conv) waFail('That conversation no longer exists.');
} else {
    $chatId = waChatId($phone);
    if ($chatId === null) waFail('That does not look like a usable phone number.');
    $conv = waConversation($db, $chatId, trim($_POST['name'] ?? ''), waChatPhone($chatId));
    if (!$conv) waFail('That conversation could not be opened.');
    $convId = (int)$conv['id'];
}

if (!waConfigured()) {
    waFail('WhatsApp is not connected yet. An administrator needs to link the company phone.');
}

$kind    = $_POST['kind'] ?? 'text';
$caption = trim($_POST['body'] ?? '');

// ── Words ────────────────────────────────────────────────────────────────────
if ($kind === 'text') {
    if ($caption === '') waFail('There is nothing to send.');
    if (mb_strlen($caption) > 4000) waFail('That message is too long for WhatsApp.');

    $r = waSendText($db, $convId, $caption, $uid);
    if (!$r['ok']) {
        // The message is already in the thread marked failed, so the caller is
        // told what went wrong AND can see it sitting there.
        echo json_encode(['ok' => false, 'error' => $r['error'], 'conversation_id' => $convId,
                          'message_id' => $r['message_id']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    logActivity('create', 'wa_messages', $r['message_id'],
        'WhatsApp message sent to ' . ($conv['contact_name'] ?: $conv['contact_phone']) . '.');
    echo json_encode(['ok' => true, 'conversation_id' => $convId, 'message_id' => $r['message_id']]);
    exit;
}

// ── A file from the sender's computer ────────────────────────────────────────
if ($kind === 'file') {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        waFail('No file was chosen.');
    }
    try {
        $stored = handleUpload(
            $_FILES['file'],
            waStorageDir(),
            ['pdf','jpg','jpeg','png','webp','doc','docx','xls','xlsx','csv','txt','mp4'],
            16 * 1024 * 1024        // WhatsApp will not carry more than this
        );
    } catch (\Throwable $e) {
        waFail($e->getMessage());
    }
    $path = waStorageDir(false) . '/' . $stored;
    // The name the customer sees is the one they were sent, not the randomised
    // one this server stores it under.
    $shown = waSafeFileName($_FILES['file']['name'] ?? $stored);

    $r = waSendDocument($db, $convId, $path, $shown, $caption, $uid);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'conversation_id' => $convId,
                          'message_id' => $r['message_id']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    logActivity('create', 'wa_messages', $r['message_id'],
        'WhatsApp document "' . $shown . '" sent to ' . ($conv['contact_name'] ?: $conv['contact_phone']) . '.');
    echo json_encode(['ok' => true, 'conversation_id' => $convId, 'message_id' => $r['message_id']]);
    exit;
}

// ── Something the system already holds ───────────────────────────────────────
if ($kind === 'attach') {
    $source = $_POST['source'] ?? '';
    $id     = (int)($_POST['source_id'] ?? 0);

    $found = waResolveAttachment($db, $source, $id);
    if ($found === null) waFail('That document could not be found.');

    $r = waSendDocument($db, $convId, $found['path'], $found['name'], $caption, $uid);
    if (!$r['ok']) {
        echo json_encode(['ok' => false, 'error' => $r['error'], 'conversation_id' => $convId,
                          'message_id' => $r['message_id']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    logActivity('create', 'wa_messages', $r['message_id'],
        'WhatsApp document "' . $found['name'] . '" sent to '
        . ($conv['contact_name'] ?: $conv['contact_phone']) . '.');
    echo json_encode(['ok' => true, 'conversation_id' => $convId, 'message_id' => $r['message_id']]);
    exit;
}

waFail('Unknown kind of message.');

// ─────────────────────────────────────────────────────────────────────────────

/** A filename safe to show and safe to store. */
function waSafeFileName(string $raw): string
{
    $n = preg_replace('/[^\w.\- ]+/u', '', basename($raw)) ?? '';
    $n = trim(preg_replace('/\s+/', ' ', $n) ?? '');
    return $n !== '' ? mb_substr($n, 0, 120) : 'document';
}

/**
 * Turn "this record, that id" into a file on disk.
 *
 * Never takes a path from the request. The obvious shortcut — post the path and
 * send whatever is there — is a request away from mailing a customer the
 * database credentials, so the caller names a record and this looks the path up.
 */
function waResolveAttachment(PDO $db, string $source, int $id): ?array
{
    if ($id <= 0) return null;
    try {
        if ($source === 'car_document') {
            if (!canAccess('car_documents')) return null;
            $st = $db->prepare("SELECT cd.file_path, cd.file_name, cd.title, cd.doc_type,
                                       c.registration_number
                                  FROM car_documents cd
                             LEFT JOIN cars c ON c.id = cd.car_id
                                 WHERE cd.id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['file_path'])) return null;
            // The customer should receive something they can identify in a
            // crowded downloads folder, so the plate goes in the name.
            $name = trim((string)($row['title'] ?? '')) ?: (string)($row['doc_type'] ?? 'document');
            if (!empty($row['registration_number'])) $name .= ' - ' . $row['registration_number'];
            if (trim($name) === '') $name = (string)($row['file_name'] ?? 'document');
            return waUnderBase((string)$row['file_path'], $name);
        }

        if ($source === 'car_image') {
            if (!canAccess('cars')) return null;
            $st = $db->prepare("SELECT ci.file_path, c.make, c.model, c.registration_number
                                  FROM car_images ci
                             LEFT JOIN cars c ON c.id = ci.car_id
                                 WHERE ci.id = ? LIMIT 1");
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['file_path'])) return null;
            $name = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? '') . ' '
                       . ($row['registration_number'] ?? '')) ?: 'vehicle';
            return waUnderBase((string)$row['file_path'], $name);
        }
    } catch (\Throwable $e) {
        error_log('waResolveAttachment: ' . $e->getMessage());
    }
    return null;
}

/**
 * Resolve a stored path and refuse anything that escapes the uploads folder.
 *
 * realpath() collapses the ".." that a poisoned database row would rely on, and
 * the prefix test is done on the collapsed result — checking before resolving is
 * the mistake that makes this kind of guard decorative.
 */
function waUnderBase(string $stored, string $displayName): ?array
{
    $root = realpath(BASE_PATH . '/uploads');
    if ($root === false) return null;

    $candidates = [
        BASE_PATH . '/' . ltrim($stored, '/\\'),
        BASE_PATH . '/uploads/' . ltrim($stored, '/\\'),
        $stored,
    ];
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real === false || !is_file($real) || !is_readable($real)) continue;
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR) && $real !== $root) continue;

        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $name = waSafeFileName($displayName);
        if ($ext !== '' && !str_ends_with(strtolower($name), '.' . $ext)) $name .= '.' . $ext;
        return ['path' => $real, 'name' => $name];
    }
    return null;
}
