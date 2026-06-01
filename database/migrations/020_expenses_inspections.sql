-- Migration 020: Expenses Tracker + Pre-delivery Inspection Checklists

-- ── Expenses ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    expense_number  VARCHAR(20)    NULL,
    category        VARCHAR(50)    NOT NULL DEFAULT 'other',
    description     VARCHAR(300)   NOT NULL,
    amount          DECIMAL(15,2)  NOT NULL,
    expense_date    DATE           NOT NULL,
    payment_method  VARCHAR(30)    NOT NULL DEFAULT 'cash',
    reference       VARCHAR(100)   NULL,
    vendor          VARCHAR(200)   NULL,
    receipt_file    VARCHAR(500)   NULL,
    notes           TEXT           NULL,
    recorded_by     INT            NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_exp_date     (expense_date),
    INDEX idx_exp_category (category),
    CONSTRAINT fk_exp_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Inspection Checklists ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inspection_checklists (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    car_id          INT            NOT NULL,
    sale_id         INT            NULL,
    checklist_type  VARCHAR(30)    NOT NULL DEFAULT 'pre_delivery',
    status          VARCHAR(20)    NOT NULL DEFAULT 'draft',
    inspector_id    INT            NULL,
    approved_by     INT            NULL,
    approved_at     TIMESTAMP      NULL,
    overall_notes   TEXT           NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cl_car  (car_id),
    INDEX idx_cl_sale (sale_id),
    CONSTRAINT fk_cl_car  FOREIGN KEY (car_id)       REFERENCES cars(id)      ON DELETE CASCADE,
    CONSTRAINT fk_cl_sale FOREIGN KEY (sale_id)      REFERENCES car_sales(id) ON DELETE SET NULL,
    CONSTRAINT fk_cl_insp FOREIGN KEY (inspector_id) REFERENCES users(id)     ON DELETE SET NULL,
    CONSTRAINT fk_cl_appr FOREIGN KEY (approved_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Inspection Items ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inspection_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    checklist_id    INT            NOT NULL,
    category        VARCHAR(100)   NOT NULL,
    item            VARCHAR(200)   NOT NULL,
    result          VARCHAR(10)    NOT NULL DEFAULT 'pending',
    notes           VARCHAR(300)   NULL,
    sort_order      INT            NOT NULL DEFAULT 0,
    CONSTRAINT fk_item_cl FOREIGN KEY (checklist_id) REFERENCES inspection_checklists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
