<?php
// Chat module — one-time DB setup (admin only)
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
if (!hasRole('admin')) die('Admin only.');

$db = getDB();
$errors = [];
$done   = [];

$statements = [
    'chat_conversations' => "
        CREATE TABLE IF NOT EXISTS chat_conversations (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            type       ENUM('direct','group') DEFAULT 'direct',
            name       VARCHAR(150) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'chat_participants' => "
        CREATE TABLE IF NOT EXISTS chat_participants (
            conversation_id  INT NOT NULL,
            user_id          INT NOT NULL,
            last_read_msg_id INT NOT NULL DEFAULT 0,
            joined_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conversation_id, user_id),
            CONSTRAINT fk_cp_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_cp_user FOREIGN KEY (user_id)         REFERENCES users(id)             ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'chat_messages' => "
        CREATE TABLE IF NOT EXISTS chat_messages (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            sender_id       INT NOT NULL,
            type            ENUM('text','file','image','voice','call','system') DEFAULT 'text',
            content         TEXT NULL,
            file_path       VARCHAR(500) NULL,
            file_name       VARCHAR(255) NULL,
            file_size       BIGINT NULL,
            mime_type       VARCHAR(100) NULL,
            duration        SMALLINT NULL,
            is_deleted      TINYINT(1) DEFAULT 0,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)             ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
    'chat_calls' => "
        CREATE TABLE IF NOT EXISTS chat_calls (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            conversation_id INT NOT NULL,
            caller_id       INT NOT NULL,
            callee_id       INT NULL,
            call_type       ENUM('audio','video') DEFAULT 'audio',
            status          ENUM('ringing','active','ended','missed','rejected') DEFAULT 'ringing',
            offer_sdp       MEDIUMTEXT NULL,
            answer_sdp      MEDIUMTEXT NULL,
            caller_ice      MEDIUMTEXT NULL,
            callee_ice      MEDIUMTEXT NULL,
            started_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            answered_at     TIMESTAMP NULL,
            ended_at        TIMESTAMP NULL,
            CONSTRAINT fk_call_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_call_caller FOREIGN KEY (caller_id)       REFERENCES users(id)             ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_msg_conv_id ON chat_messages(conversation_id, id)",
    "CREATE INDEX IF NOT EXISTS idx_call_status  ON chat_calls(conversation_id, status)",
];

// Ensure uploads/chat directory exists
$uploadDir = __DIR__ . '/../../uploads/chat';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    $done[] = 'Created uploads/chat/ directory';
}

foreach ($statements as $table => $sql) {
    try {
        $db->exec($sql);
        $done[] = "Table <strong>{$table}</strong> — OK";
    } catch (Exception $e) {
        $errors[] = "Table {$table}: " . htmlspecialchars($e->getMessage());
    }
}

// Indexes (ignore errors — may already exist or syntax differs by MySQL version)
foreach ($indexes as $sql) {
    try { $db->exec($sql); } catch (Exception $e) { /* ignore */ }
}

// ── Phase-2 migrations (safe to re-run) ────────────────────────────────────
$migrations = [
    'chat_messages.reply_to_id' => "ALTER TABLE chat_messages ADD COLUMN reply_to_id INT NULL DEFAULT NULL AFTER is_deleted",
];
foreach ($migrations as $label => $sql) {
    try {
        $db->exec($sql);
        $done[] = "Migration <strong>{$label}</strong> — applied";
    } catch (Exception $e) {
        // "Duplicate column name" means it already exists — not an error
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            $done[] = "Migration <strong>{$label}</strong> — already applied";
        } else {
            $errors[] = "Migration {$label}: " . htmlspecialchars($e->getMessage());
        }
    }
}

?><!DOCTYPE html>
<html>
<head>
<title>Chat Setup</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
<style>body{padding:40px;background:#f8fafc;font-family:'Segoe UI',sans-serif}</style>
</head>
<body>
<div style="max-width:600px;margin:0 auto">
    <h4 class="mb-4"><i class="fa fa-comments me-2 text-success"></i>Chat Module Setup</h4>

    <?php if ($errors): ?>
    <div class="alert alert-danger">
        <strong>Errors:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errors as $e): ?>
            <li><?= $e ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if ($done): ?>
    <div class="alert alert-success">
        <strong>Done:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($done as $d): ?>
            <li><?= $d ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!$errors): ?>
    <div class="alert alert-info">
        All chat tables are set up. The chat module is ready to use.
    </div>
    <a href="<?= BASE_URL ?>/modules/chat/index.php" class="btn btn-success">
        Go to Chat &rarr;
    </a>
    <?php endif; ?>

    <div class="mt-3">
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-link btn-sm text-muted">
            &larr; Back to Dashboard
        </a>
    </div>
</div>
</body>
</html>
