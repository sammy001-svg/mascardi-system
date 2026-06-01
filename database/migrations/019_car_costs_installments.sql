-- Migration 019: Car Import Costs + Installment Payment Plans

-- Per-car cost breakdown (one row per car)
CREATE TABLE IF NOT EXISTS car_costs (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    car_id             INT            NOT NULL,
    purchase_price     DECIMAL(15,2)  NOT NULL DEFAULT 0,
    freight            DECIMAL(15,2)  NOT NULL DEFAULT 0,
    marine_insurance   DECIMAL(15,2)  NOT NULL DEFAULT 0,
    port_charges       DECIMAL(15,2)  NOT NULL DEFAULT 0,
    duty_tax           DECIMAL(15,2)  NOT NULL DEFAULT 0,
    clearing_fees      DECIMAL(15,2)  NOT NULL DEFAULT 0,
    transport_to_yard  DECIMAL(15,2)  NOT NULL DEFAULT 0,
    workshop_costs     DECIMAL(15,2)  NOT NULL DEFAULT 0,
    other_costs        DECIMAL(15,2)  NOT NULL DEFAULT 0,
    other_notes        TEXT           NULL,
    currency           VARCHAR(3)     NOT NULL DEFAULT 'KES',
    notes              TEXT           NULL,
    recorded_by        INT            NULL,
    created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_car_cost (car_id),
    CONSTRAINT fk_cost_car  FOREIGN KEY (car_id)      REFERENCES cars(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cost_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Installment plan header (one per sale)
CREATE TABLE IF NOT EXISTS sale_payment_plans (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    sale_id             INT            NOT NULL,
    total_amount        DECIMAL(15,2)  NOT NULL,
    deposit_paid        DECIMAL(15,2)  NOT NULL DEFAULT 0,
    balance_financed    DECIMAL(15,2)  NOT NULL DEFAULT 0,
    installment_amount  DECIMAL(15,2)  NOT NULL,
    frequency           VARCHAR(20)    NOT NULL DEFAULT 'monthly',
    total_installments  INT            NOT NULL,
    start_date          DATE           NOT NULL,
    end_date            DATE           NULL,
    status              VARCHAR(20)    NOT NULL DEFAULT 'active',
    notes               TEXT           NULL,
    created_by          INT            NULL,
    created_at          TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan_sale (sale_id),
    CONSTRAINT fk_plan_sale FOREIGN KEY (sale_id)    REFERENCES car_sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_user FOREIGN KEY (created_by) REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual installment rows (auto-generated from plan)
CREATE TABLE IF NOT EXISTS sale_installments (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    plan_id            INT            NOT NULL,
    installment_number INT            NOT NULL,
    due_date           DATE           NOT NULL,
    amount_due         DECIMAL(15,2)  NOT NULL,
    amount_paid        DECIMAL(15,2)  NOT NULL DEFAULT 0,
    paid_date          DATE           NULL,
    payment_method     VARCHAR(30)    NULL,
    reference          VARCHAR(100)   NULL,
    status             VARCHAR(20)    NOT NULL DEFAULT 'pending',
    notes              TEXT           NULL,
    recorded_by        INT            NULL,
    created_at         TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_plan (plan_id),
    INDEX idx_due  (due_date, status),
    CONSTRAINT fk_inst_plan FOREIGN KEY (plan_id)      REFERENCES sale_payment_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_inst_user FOREIGN KEY (recorded_by)  REFERENCES users(id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
