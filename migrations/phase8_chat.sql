-- Phase 8: Internal Chat System
-- Run in phpMyAdmin on mascardi_db

-- 1. Conversations (direct or group)
CREATE TABLE IF NOT EXISTS chat_conversations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('direct','group') DEFAULT 'direct',
    name        VARCHAR(150) NULL,          -- group name only
    created_by  INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_conv_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 2. Participants
CREATE TABLE IF NOT EXISTS chat_participants (
    conversation_id     INT NOT NULL,
    user_id             INT NOT NULL,
    last_read_msg_id    INT NOT NULL DEFAULT 0,
    joined_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, user_id),
    CONSTRAINT fk_cp_conv FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_cp_user FOREIGN KEY (user_id)         REFERENCES users(id)             ON DELETE CASCADE
);

-- 3. Messages
CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    sender_id       INT NOT NULL,
    type            ENUM('text','file','image','voice','call','system') DEFAULT 'text',
    content         TEXT NULL,
    file_path       VARCHAR(500) NULL,
    file_name       VARCHAR(255) NULL,
    file_size       BIGINT       NULL,
    mime_type       VARCHAR(100) NULL,
    duration        SMALLINT     NULL,      -- voice note seconds
    is_deleted      TINYINT(1)   DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_msg_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id)       REFERENCES users(id)             ON DELETE CASCADE
);

-- 4. WebRTC call sessions (signaling via DB)
CREATE TABLE IF NOT EXISTS chat_calls (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT NOT NULL,
    caller_id       INT NOT NULL,
    callee_id       INT NULL,
    call_type       ENUM('audio','video') DEFAULT 'audio',
    status          ENUM('ringing','active','ended','missed','rejected') DEFAULT 'ringing',
    offer_sdp       MEDIUMTEXT NULL,
    answer_sdp      MEDIUMTEXT NULL,
    caller_ice      MEDIUMTEXT NULL,        -- JSON array
    callee_ice      MEDIUMTEXT NULL,        -- JSON array
    started_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    answered_at     TIMESTAMP NULL,
    ended_at        TIMESTAMP NULL,
    CONSTRAINT fk_call_conv   FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_call_caller FOREIGN KEY (caller_id)       REFERENCES users(id)             ON DELETE CASCADE
);

-- Index for fast polling
CREATE INDEX idx_msg_conv_id  ON chat_messages(conversation_id, id);
CREATE INDEX idx_call_status  ON chat_calls(conversation_id, status);
