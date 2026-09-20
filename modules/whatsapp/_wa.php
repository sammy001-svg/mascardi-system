<?php
/**
 * WhatsApp — the shared inbox behind the connection.
 *
 * One number, one thread per customer, and every member of the team working the
 * same inbox. What the yard had instead was three half-finished things at once:
 * a Twilio sender nobody configured, a QR bridge nobody finished, and wa.me
 * links dotted through the CRM that open the agent's own phone and leave no
 * trace on the customer's record.
 *
 * Two rules shape everything here.
 *
 * A message is recorded before it is sent, and updated with what happened. The
 * opposite order — send, then write down if it worked — loses exactly the
 * messages you most need to find later, the ones that failed halfway.
 *
 * A conversation belongs to a customer, not to a phone number. The thread is
 * matched to a client and to a lead so the sales record and the conversation are
 * the same story, which is the whole reason for having this inside the system
 * rather than on somebody's handset.
 */

if (!function_exists('waMigrate')) {

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_drivers.php';

if (!defined('WA_SCHEMA_VERSION')) define('WA_SCHEMA_VERSION', '2');

/**
 * The tables, created and brought forward in place.
 *
 * The three tables already existed from the unfinished attempt and may hold real
 * conversations on a live install, so this extends them rather than replacing
 * them. Every ALTER is allowed to fail: on an install that already has the
 * column, failing is the correct outcome.
 */
function waMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'wa_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === WA_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $stmts = [
        "CREATE TABLE IF NOT EXISTS wa_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chat_id VARCHAR(60) NOT NULL,
            contact_name VARCHAR(150) NULL,
            contact_phone VARCHAR(30) NULL,
            client_id INT NULL,
            lead_id INT NULL,
            assigned_to INT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            last_message TEXT NULL,
            last_message_at TIMESTAMP NULL DEFAULT NULL,
            unread_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wa_chat (chat_id),
            KEY idx_wa_conv_recent (last_message_at),
            KEY idx_wa_conv_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS wa_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            message_id VARCHAR(120) NULL,
            direction ENUM('in','out') NOT NULL,
            type ENUM('text','image','document','audio','video','other') NOT NULL DEFAULT 'text',
            body TEXT NULL,
            media_url VARCHAR(500) NULL,
            file_name VARCHAR(255) NULL,
            file_path VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'sent',
            error TEXT NULL,
            provider VARCHAR(20) NULL,
            sent_by INT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_wa_msg_conv (conversation_id, id),
            KEY idx_wa_msg_mid (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Quick replies. A yard sends the same six sentences all day — directions,
        // opening hours, \"the car is ready\" — and retyping them is where the
        // typos and the unprofessional half-sentences come from.
        "CREATE TABLE IF NOT EXISTS wa_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(80) NOT NULL,
            body TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($stmts as $sql) { try { $db->exec($sql); } catch (\Throwable $e) {
        error_log('waMigrate: ' . $e->getMessage()); } }

    // Brought forward for installs that already carry the older shape.
    foreach ([
        "ALTER TABLE wa_conversations ADD COLUMN lead_id INT NULL",
        "ALTER TABLE wa_conversations ADD COLUMN assigned_to INT NULL",
        "ALTER TABLE wa_conversations ADD COLUMN status ENUM('open','closed') NOT NULL DEFAULT 'open'",
        "ALTER TABLE wa_conversations MODIFY chat_id VARCHAR(60) NOT NULL",
        "ALTER TABLE wa_messages ADD COLUMN file_name VARCHAR(255) NULL",
        "ALTER TABLE wa_messages ADD COLUMN file_path VARCHAR(500) NULL",
        "ALTER TABLE wa_messages ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sent'",
        "ALTER TABLE wa_messages ADD COLUMN error TEXT NULL",
        "ALTER TABLE wa_messages ADD COLUMN provider VARCHAR(20) NULL",
        "ALTER TABLE wa_messages MODIFY message_id VARCHAR(120) NULL",
        "ALTER TABLE wa_conversations ADD UNIQUE KEY uq_wa_chat (chat_id)",
        "ALTER TABLE wa_messages ADD KEY idx_wa_msg_conv (conversation_id, id)",
    ] as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('wa_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([WA_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

// ── Who may use it ───────────────────────────────────────────────────────────

/**
 * The inbox is customer correspondence, so it is gated like customer
 * correspondence.
 *
 * Before this it was behind requireLogin() alone, which meant every mechanic and
 * every driver could read every word the yard had exchanged with every customer.
 * Nobody chose that; it is simply what happens when a module ships without a
 * permission and the sidebar shows it to whoever is logged in.
 */
function waCanUse(): bool   { return canAccess('whatsapp'); }
function waCanSend(): bool  { return canWrite('whatsapp'); }
function waCanAdmin(): bool { return isSuperAdmin() || hasRole(['admin', 'general_manager']); }

/** Where the files people send from the inbox are kept. */
function waStorageDir(bool $create = true): string
{
    $dir = BASE_PATH . '/uploads/whatsapp/' . date('Y-m');
    if ($create && !is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

// ── Conversations ────────────────────────────────────────────────────────────

/**
 * The thread for a chat id, created on first contact.
 *
 * Races here are real: two webhooks for the same new customer arrive at the same
 * moment and both find nothing. The unique key on chat_id settles it, and the
 * duplicate insert is caught and re-read rather than thrown.
 */
function waConversation(PDO $db, string $chatId, string $name = '', string $phone = ''): ?array
{
    if ($chatId === '') return null;
    $phone = $phone !== '' ? $phone : waChatPhone($chatId);

    try {
        $st = $db->prepare("SELECT * FROM wa_conversations WHERE chat_id = ? LIMIT 1");
        $st->execute([$chatId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            try {
                $db->prepare("INSERT INTO wa_conversations (chat_id, contact_name, contact_phone, created_at)
                              VALUES (?,?,?,NOW())")->execute([$chatId, $name ?: null, $phone ?: null]);
            } catch (\Throwable $e) {
                // Another request got there first — that is the unique key doing
                // its job, not a failure.
            }
            $st->execute([$chatId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            waLinkToRecords($db, (int)$row['id'], $phone);
            $st->execute([$chatId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } elseif ($name !== '' && trim((string)$row['contact_name']) === '') {
            // WhatsApp only tells us the display name once they have written.
            $db->prepare("UPDATE wa_conversations SET contact_name = ? WHERE id = ?")
               ->execute([$name, (int)$row['id']]);
            $row['contact_name'] = $name;
        }
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('waConversation: ' . $e->getMessage());
        return null;
    }
}

/**
 * Tie the thread to the customer it belongs to.
 *
 * Matched on the last nine digits rather than the whole string, because the same
 * person is 0712345678 in the client record, +254712345678 on the lead and
 * 254712345678 on WhatsApp, and all three are the same human being.
 */
function waLinkToRecords(PDO $db, int $convId, string $phone): void
{
    $tail = substr(preg_replace('/\D+/', '', $phone) ?? '', -9);
    if (strlen($tail) < 9) return;
    $like = '%' . $tail;

    try {
        $c = $db->prepare("SELECT id, name FROM clients
                            WHERE REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') LIKE ?
                            ORDER BY id LIMIT 1");
        $c->execute([$like]);
        $client = $c->fetch(PDO::FETCH_ASSOC);

        $l = $db->prepare("SELECT id, name, assigned_to FROM crm_leads
                            WHERE REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+','') LIKE ?
                         ORDER BY (stage NOT IN ('lost','delivered')) DESC, updated_at DESC LIMIT 1");
        $l->execute([$like]);
        $lead = $l->fetch(PDO::FETCH_ASSOC);

        $sets = []; $args = [];
        if ($client) { $sets[] = 'client_id = ?'; $args[] = (int)$client['id']; }
        if ($lead)   { $sets[] = 'lead_id = ?';   $args[] = (int)$lead['id']; }
        // The agent already working this customer is the obvious person to answer,
        // so the thread lands with them rather than in a pile nobody owns.
        if ($lead && !empty($lead['assigned_to'])) {
            $sets[] = 'assigned_to = COALESCE(assigned_to, ?)';
            $args[] = (int)$lead['assigned_to'];
        }
        $named = $client['name'] ?? ($lead['name'] ?? '');
        if ($named !== '') { $sets[] = "contact_name = COALESCE(NULLIF(contact_name,''), ?)"; $args[] = $named; }

        if ($sets) {
            $args[] = $convId;
            $db->prepare("UPDATE wa_conversations SET " . implode(', ', $sets) . " WHERE id = ?")
               ->execute($args);
        }
    } catch (\Throwable $e) {
        error_log('waLinkToRecords: ' . $e->getMessage());
    }
}

/** The thread list, newest activity first. */
function waConversations(PDO $db, array $f = []): array
{
    // A thread nobody has said anything in is not a conversation, it is a
    // contact. The previous module imported the whole phone book as threads,
    // which buried the handful of real conversations under two hundred empty
    // ones. They are hidden rather than deleted — the phone numbers are still
    // worth having, and a thread reappears the moment anything is said in it.
    $where = ['c.last_message_at IS NOT NULL']; $args = [];
    if (!empty($f['include_empty'])) $where = ['1=1'];
    if (!empty($f['status']))   { $where[] = 'c.status = ?';      $args[] = $f['status']; }
    if (!empty($f['assigned'])) { $where[] = 'c.assigned_to = ?'; $args[] = (int)$f['assigned']; }
    if (!empty($f['unread']))   { $where[] = 'c.unread_count > 0'; }
    if (!empty($f['q'])) {
        $s = '%' . $f['q'] . '%';
        $where[] = '(c.contact_name LIKE ? OR c.contact_phone LIKE ? OR c.last_message LIKE ?)';
        array_push($args, $s, $s, $s);
    }
    $sql = implode(' AND ', $where);

    try {
        $st = $db->prepare("
            SELECT c.*, cl.name AS client_name, u.name AS agent_name,
                   l.stage AS lead_stage
              FROM wa_conversations c
         LEFT JOIN clients   cl ON cl.id = c.client_id
         LEFT JOIN crm_leads l  ON l.id  = c.lead_id
         LEFT JOIN users     u  ON u.id  = c.assigned_to
             WHERE $sql
          ORDER BY c.last_message_at IS NULL, c.last_message_at DESC, c.id DESC
             LIMIT " . (int)($f['limit'] ?? 200));
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('waConversations: ' . $e->getMessage());
        return [];
    }
}

/** One thread's messages, oldest first, which is how a conversation reads. */
function waMessages(PDO $db, int $convId, int $afterId = 0, int $limit = 200): array
{
    try {
        $st = $db->prepare("
            SELECT m.*, u.name AS sender_name
              FROM wa_messages m
         LEFT JOIN users u ON u.id = m.sent_by
             WHERE m.conversation_id = ? AND m.id > ?
          ORDER BY m.id ASC
             LIMIT " . max(1, min(500, $limit)));
        $st->execute([$convId, $afterId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('waMessages: ' . $e->getMessage());
        return [];
    }
}

/** Mark what the customer sent as seen. */
function waMarkRead(PDO $db, int $convId): void
{
    try {
        $db->prepare("UPDATE wa_messages SET is_read = 1
                       WHERE conversation_id = ? AND direction = 'in' AND is_read = 0")
           ->execute([$convId]);
        $db->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$convId]);
    } catch (\Throwable $e) { error_log('waMarkRead: ' . $e->getMessage()); }
}

/** How many unanswered messages are waiting, for the badge in the menu. */
function waUnreadTotal(PDO $db): int
{
    try { return (int)$db->query("SELECT COALESCE(SUM(unread_count),0) FROM wa_conversations
                                   WHERE status = 'open'")->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
}

/** Keep the thread's summary line in step with its last message. */
function waTouch(PDO $db, int $convId, string $preview, bool $incomingUnread = false): void
{
    try {
        $db->prepare("UPDATE wa_conversations
                         SET last_message = ?, last_message_at = NOW(),
                             unread_count = unread_count + ?, updated_at = NOW()
                       WHERE id = ?")
           ->execute([mb_substr(trim($preview), 0, 240), $incomingUnread ? 1 : 0, $convId]);
    } catch (\Throwable $e) { error_log('waTouch: ' . $e->getMessage()); }
}

// ── Sending ──────────────────────────────────────────────────────────────────

/**
 * Send words to a thread.
 *
 * Written down first, then sent, then updated with the outcome. A message that
 * fails halfway is still in the thread, marked failed, with the reason on it —
 * which is the one a person actually needs to find.
 *
 * @return array{ok:bool, message_id:int, error:string}
 */
function waSendText(PDO $db, int $convId, string $text, ?int $userId = null): array
{
    waMigrate($db);
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'message_id' => 0, 'error' => 'There is nothing to send.'];

    $conv = waConversationById($db, $convId);
    if (!$conv) return ['ok' => false, 'message_id' => 0, 'error' => 'That conversation no longer exists.'];

    $rowId = waRecordOutbound($db, $convId, 'text', $text, null, null, $userId);

    $r = waDriverSendText((string)$conv['chat_id'], $text);
    waSettleOutbound($db, $rowId, $r);
    waTouch($db, $convId, $text);

    return ['ok' => $r['ok'], 'message_id' => $rowId, 'error' => $r['error']];
}

/**
 * Send a file the customer can keep — a quotation, a logbook scan, photographs
 * of the car they asked about.
 *
 * The local copy is kept deliberately. Six months later the argument is about
 * what was sent, and "it is on somebody's phone" is not an answer.
 */
function waSendDocument(PDO $db, int $convId, string $path, string $fileName,
                        string $caption = '', ?int $userId = null): array
{
    waMigrate($db);
    $conv = waConversationById($db, $convId);
    if (!$conv) return ['ok' => false, 'message_id' => 0, 'error' => 'That conversation no longer exists.'];
    if (!is_readable($path)) return ['ok' => false, 'message_id' => 0, 'error' => 'That file could not be read.'];

    $kind  = str_starts_with(waMimeOf($path), 'image/') ? 'image' : 'document';
    $rel   = str_starts_with($path, BASE_PATH) ? ltrim(substr($path, strlen(BASE_PATH)), '/\\') : '';
    $rowId = waRecordOutbound($db, $convId, $kind, $caption, $fileName, $rel ?: null, $userId);

    $r = waDriverSendFile((string)$conv['chat_id'], $path, $fileName, $caption);
    waSettleOutbound($db, $rowId, $r);
    waTouch($db, $convId, $caption !== '' ? $caption : ('📎 ' . $fileName));

    return ['ok' => $r['ok'], 'message_id' => $rowId, 'error' => $r['error']];
}

/** The thread, by id. */
function waConversationById(PDO $db, int $id): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM wa_conversations WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

/** The row that exists before the send is attempted. */
function waRecordOutbound(PDO $db, int $convId, string $type, string $body,
                          ?string $fileName, ?string $filePath, ?int $userId): int
{
    try {
        $db->prepare("INSERT INTO wa_messages
                (conversation_id, direction, type, body, file_name, file_path,
                 status, provider, sent_by, sent_at, is_read)
              VALUES (?, 'out', ?, ?, ?, ?, 'queued', ?, ?, NOW(), 1)")
           ->execute([$convId, $type, $body, $fileName, $filePath, waProvider(), $userId]);
        return (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('waRecordOutbound: ' . $e->getMessage());
        return 0;
    }
}

/** What the provider said, written onto the message that was already recorded. */
function waSettleOutbound(PDO $db, int $rowId, array $r): void
{
    if ($rowId <= 0) return;
    try {
        $db->prepare("UPDATE wa_messages SET status = ?, message_id = ?, error = ? WHERE id = ?")
           ->execute([
               $r['ok'] ? 'sent' : 'failed',
               ($r['id'] ?? '') !== '' ? $r['id'] : null,
               $r['ok'] ? null : mb_substr((string)($r['error'] ?? ''), 0, 500),
               $rowId,
           ]);
    } catch (\Throwable $e) { error_log('waSettleOutbound: ' . $e->getMessage()); }
}

// ── Receiving ────────────────────────────────────────────────────────────────

/**
 * File an arriving message.
 *
 * Providers retry, and a retried webhook must not put the same sentence in the
 * thread twice, so the provider's own message id is what decides whether this
 * has been seen before.
 *
 * @return int  the new row, 0 when ignored or already filed
 */
function waRecordInbound(PDO $db, array $m): int
{
    waMigrate($db);
    $conv = waConversation($db, (string)$m['chat_id'], (string)($m['name'] ?? ''), (string)($m['phone'] ?? ''));
    if (!$conv) return 0;

    $mid = (string)($m['message_id'] ?? '');
    if ($mid !== '') {
        try {
            $st = $db->prepare("SELECT id FROM wa_messages WHERE message_id = ? LIMIT 1");
            $st->execute([$mid]);
            if ($st->fetchColumn()) return 0;
        } catch (\Throwable $_) {}
    }

    try {
        $db->prepare("INSERT INTO wa_messages
                (conversation_id, message_id, direction, type, body, media_url, file_name,
                 status, provider, sent_at, is_read)
              VALUES (?,?, 'in', ?,?,?,?, 'received', ?, FROM_UNIXTIME(?), 0)")
           ->execute([
               (int)$conv['id'], $mid ?: null, (string)$m['type'], (string)($m['body'] ?? ''),
               $m['file']['url'] ?? null, $m['file']['name'] ?? null,
               waProvider(), (int)($m['at'] ?? time()),
           ]);
        $id = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('waRecordInbound: ' . $e->getMessage());
        return 0;
    }

    $preview = trim((string)($m['body'] ?? ''));
    if ($preview === '') $preview = '📎 ' . ucfirst((string)$m['type']);
    waTouch($db, (int)$conv['id'], $preview, true);

    // A closed thread that speaks again is an open thread. Nobody thinks to go
    // looking in the archive when a customer replies three weeks later.
    try { $db->prepare("UPDATE wa_conversations SET status='open' WHERE id=? AND status='closed'")
             ->execute([(int)$conv['id']]); } catch (\Throwable $_) {}

    return $id;
}

// ── Quick replies ────────────────────────────────────────────────────────────

function waTemplates(PDO $db): array
{
    waMigrate($db);
    try { return $db->query("SELECT * FROM wa_templates ORDER BY sort_order, title")
                    ->fetchAll(PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { return []; }
}

/**
 * Fill the placeholders a template carries.
 *
 * Kept to the handful a yard actually uses. A template language is a thing to
 * maintain; four substitutions are a thing to read.
 */
function waFillTemplate(string $body, array $conv, ?array $user = null): string
{
    $name = trim((string)($conv['client_name'] ?? $conv['contact_name'] ?? ''));
    if ($name !== '') $name = explode(' ', $name)[0];
    return strtr($body, [
        '{name}'    => $name !== '' ? $name : 'there',
        '{company}' => (string)getSetting('company_name', 'Mascardi'),
        '{agent}'   => (string)($user['name'] ?? ''),
        '{phone}'   => (string)getSetting('company_phone', ''),
    ]);
}

} // function_exists('waMigrate')
