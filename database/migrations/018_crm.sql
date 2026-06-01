-- Migration 018: CRM — Leads & Activity Log

CREATE TABLE IF NOT EXISTS crm_leads (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(200)   NOT NULL,
    phone          VARCHAR(30)    NULL,
    email          VARCHAR(200)   NULL,
    source         VARCHAR(50)    NOT NULL DEFAULT 'walk_in',
    interested_in  TEXT           NULL,     -- free-text car description/preference
    budget         DECIMAL(15,2)  NULL,
    stage          VARCHAR(30)    NOT NULL DEFAULT 'new',
    assigned_to    INT            NULL,     -- FK users.id
    client_id      INT            NULL,     -- set when converted to client
    notes          TEXT           NULL,
    lost_reason    TEXT           NULL,
    follow_up_date DATE           NULL,
    converted_at   TIMESTAMP      NULL,
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_stage        (stage),
    INDEX idx_follow_up    (follow_up_date),
    INDEX idx_assigned     (assigned_to),
    CONSTRAINT fk_lead_user   FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lead_client FOREIGN KEY (client_id)   REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_activities (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    lead_id        INT            NULL,
    client_id      INT            NULL,
    type           VARCHAR(30)    NOT NULL DEFAULT 'note',
    summary        VARCHAR(300)   NOT NULL,
    outcome        TEXT           NULL,
    follow_up_date DATE           NULL,
    created_by     INT            NULL,
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_act_lead   (lead_id),
    INDEX idx_act_client (client_id),
    CONSTRAINT fk_act_lead   FOREIGN KEY (lead_id)     REFERENCES crm_leads(id) ON DELETE CASCADE,
    CONSTRAINT fk_act_client FOREIGN KEY (client_id)   REFERENCES clients(id)   ON DELETE CASCADE,
    CONSTRAINT fk_act_user   FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
