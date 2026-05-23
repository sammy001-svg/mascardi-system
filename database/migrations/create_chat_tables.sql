-- ═══════════════════════════════════════════════════════════════
-- Chat Module Database Migration
-- Creates the four tables required for real-time messaging,
-- file sharing, voice notes, and WebRTC call signaling.
-- ═══════════════════════════════════════════════════════════════

-- ── 1. chat_conversations ────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_conversations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('direct','group') NOT NULL DEFAULT 'direct',
    name        VARCHAR(150)  NULL,           -- group name; NULL for direct chats
    created_by  INT           NOT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 2. chat_participants ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_participants (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id     INT  NOT NULL,
    user_id             INT  NOT NULL,
    last_read_msg_id    INT  NOT NULL DEFAULT 0,
    joined_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE  KEY uq_conv_user (conversation_id, user_id),
    CONSTRAINT fk_part_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_part_user FOREIGN KEY (user_id)         REFERENCES users(id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 3. chat_messages ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT           NOT NULL,
    sender_id       INT           NOT NULL,
    type            ENUM('text','image','file','voice','call','system') NOT NULL DEFAULT 'text',
    content         TEXT          NULL,
    file_path       VARCHAR(500)  NULL,
    file_name       VARCHAR(255)  NULL,
    file_size       INT           NULL,
    mime_type       VARCHAR(120)  NULL,
    duration        INT           NULL,        -- seconds, for voice notes
    is_deleted      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cm_conv_id  (conversation_id),
    INDEX idx_cm_sender   (sender_id),
    CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 4. chat_calls ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chat_calls (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT           NOT NULL,
    caller_id       INT           NOT NULL,
    callee_id       INT           NOT NULL,
    call_type       ENUM('audio','video') NOT NULL DEFAULT 'audio',
    status          ENUM('ringing','active','ended','rejected','missed') NOT NULL DEFAULT 'ringing',
    offer_sdp       MEDIUMTEXT    NULL,
    answer_sdp      MEDIUMTEXT    NULL,
    caller_ice      MEDIUMTEXT    NULL,
    callee_ice      MEDIUMTEXT    NULL,
    started_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at     TIMESTAMP     NULL,
    ended_at        TIMESTAMP     NULL,
    INDEX idx_cc_conv   (conversation_id),
    INDEX idx_cc_callee (callee_id, status),
    CONSTRAINT fk_call_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_caller FOREIGN KEY (caller_id)       REFERENCES users(id)             ON DELETE CASCADE,
    CONSTRAINT fk_call_callee FOREIGN KEY (callee_id)       REFERENCES users(id)             ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
