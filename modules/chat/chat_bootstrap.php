<?php
/**
 * Team chat — schema and shared helpers.
 *
 * Every chat API file used to carry its own copy of the CREATE TABLE block, so
 * a `CREATE TABLE IF NOT EXISTS` (and an `ALTER TABLE` that threw an exception
 * every single time, because the column was already there) ran on each request.
 * With the client polling twice a second that was thousands of pointless DDL
 * round-trips an hour, each one taking a metadata lock.
 *
 * The schema now lives here and runs at most once per PHP process. Callers just
 * invoke chatMigrate(); the static guard makes repeat calls free.
 */

if (!function_exists('chatMigrate')) {

/** Bump when the schema below changes; that is what re-triggers the migration. */
const CHAT_SCHEMA_VERSION = '3';

/**
 * Idempotent, and — importantly — nearly free once the schema is current.
 *
 * A static flag alone would not help: each PHP request is its own process, so
 * "once per process" still means once per request, and this block issues more
 * DDL than the per-endpoint code it replaced. The version row is the real
 * guard — a settings lookup (already cached per request) instead of a dozen
 * DDL round-trips, so the steady state costs nothing.
 */
function chatMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'chat_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === CHAT_SCHEMA_VERSION) return;   // already current
        } catch (\Throwable $_) {
            // No settings table yet — fall through and migrate.
        }
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS chat_conversations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('direct','group') NOT NULL DEFAULT 'direct',
            name VARCHAR(150) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_participants (
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_msg_id INT NOT NULL DEFAULT 0,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            type ENUM('text','image','file','voice','call','system') NOT NULL DEFAULT 'text',
            content TEXT NULL,
            file_path VARCHAR(500) NULL,
            file_name VARCHAR(255) NULL,
            file_size BIGINT NULL,
            mime_type VARCHAR(120) NULL,
            duration SMALLINT NULL,
            reply_to_id INT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_calls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            caller_id INT NOT NULL,
            callee_id INT NULL,
            call_type ENUM('audio','video') NOT NULL DEFAULT 'audio',
            status ENUM('ringing','active','ended','rejected','missed') NOT NULL DEFAULT 'ringing',
            offer_sdp MEDIUMTEXT NULL,
            answer_sdp MEDIUMTEXT NULL,
            caller_ice MEDIUMTEXT NULL,
            callee_ice MEDIUMTEXT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            answered_at TIMESTAMP NULL,
            ended_at TIMESTAMP NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_typing (
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS chat_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            emoji VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_react (message_id, user_id),
            KEY idx_msg (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    // Columns added after the tables shipped. Checked rather than blindly
    // ALTERed — a failing ALTER costs a round-trip and an exception each time.
    $columns = [
        ['users',         'last_seen',   "ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL"],
        ['chat_messages', 'reply_to_id', "ALTER TABLE chat_messages ADD COLUMN reply_to_id INT NULL"],
        ['chat_messages', 'edited_at',   "ALTER TABLE chat_messages ADD COLUMN edited_at TIMESTAMP NULL DEFAULT NULL"],
    ];
    foreach ($columns as [$table, $col, $sql]) {
        try {
            if (!$db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($col))->fetch()) {
                $db->exec($sql);
            }
        } catch (\Throwable $_) {}
    }

    // Indexes for the queries the poller runs constantly. Checked first for the
    // same reason — a duplicate-key ALTER is an exception per request.
    $indexes = [
        // Conversation list: newest message per conversation, and unread counts.
        ['chat_messages', 'idx_cm_conv_del', "ALTER TABLE chat_messages ADD INDEX idx_cm_conv_del (conversation_id, is_deleted, id)"],
        // "who is typing right now" filters on recency.
        ['chat_typing',   'idx_ct_updated',  "ALTER TABLE chat_typing ADD INDEX idx_ct_updated (conversation_id, updated_at)"],
        // Incoming-call check runs on every poll for every user.
        ['chat_calls',    'idx_cc_ring',     "ALTER TABLE chat_calls ADD INDEX idx_cc_ring (callee_id, status, started_at)"],
        // Presence lookups for the conversation list.
        ['users',         'idx_users_seen',  "ALTER TABLE users ADD INDEX idx_users_seen (last_seen)"],
    ];
    foreach ($indexes as [$table, $name, $sql]) {
        try {
            $found = false;
            foreach ($db->query("SHOW INDEX FROM `{$table}`") as $r) {
                if ($r['Key_name'] === $name) { $found = true; break; }
            }
            if (!$found) $db->exec($sql);
        } catch (\Throwable $_) {}
    }
}

/**
 * Records that a user is online, at most once a minute.
 *
 * This used to be an unconditional UPDATE on every message poll — a row write
 * every two seconds per open chat, purely to move a timestamp a couple of
 * seconds. Presence is only rendered to the minute, so a write that often buys
 * nothing and costs a lock on the users row.
 */
function chatTouchPresence(PDO $db, int $userId, int $everySeconds = 60): void
{
    static $lastWrite = [];
    $now = time();
    if (isset($lastWrite[$userId]) && ($now - $lastWrite[$userId]) < $everySeconds) return;
    $lastWrite[$userId] = $now;

    try {
        $db->prepare("UPDATE users SET last_seen = NOW()
                      WHERE id = ? AND (last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND))")
           ->execute([$userId, $everySeconds]);
    } catch (\Throwable $_) {}
}

/** True when the user belongs to the conversation. Cached per request. */
function chatIsParticipant(PDO $db, int $convId, int $userId): bool
{
    static $cache = [];
    $k = $convId . ':' . $userId;
    if (isset($cache[$k])) return $cache[$k];
    try {
        $st = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=? LIMIT 1");
        $st->execute([$convId, $userId]);
        return $cache[$k] = (bool)$st->fetchColumn();
    } catch (\Throwable $_) { return $cache[$k] = false; }
}

/** Short label for a message in the conversation list. */
function chatPreview(?string $type, ?string $content, ?string $fileName): string
{
    return match ($type) {
        'voice'  => '🎤 Voice note',
        'image'  => '📷 Photo',
        'file'   => '📎 ' . ($fileName ?: 'File'),
        'call'   => '📞 ' . ($content ?: 'Call'),
        default  => (string)$content,
    };
}

} // function_exists guard
