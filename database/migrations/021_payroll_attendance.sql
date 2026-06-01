-- Migration 021: Payroll + Attendance

-- Staff salary profiles (one row per mechanic/driver)
CREATE TABLE IF NOT EXISTS staff_salaries (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    staff_type       VARCHAR(20)   NOT NULL,           -- mechanic | driver
    staff_id         INT           NOT NULL,
    basic_salary     DECIMAL(15,2) NOT NULL DEFAULT 0,
    house_allowance  DECIMAL(15,2) NOT NULL DEFAULT 0,
    transport_allow  DECIMAL(15,2) NOT NULL DEFAULT 0,
    status           VARCHAR(20)   NOT NULL DEFAULT 'active',
    effective_date   DATE          NOT NULL,
    notes            TEXT          NULL,
    updated_by       INT           NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff (staff_type, staff_id),
    CONSTRAINT fk_sal_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Monthly payroll run header
CREATE TABLE IF NOT EXISTS payroll_runs (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    run_number       VARCHAR(20)   NOT NULL,
    period_month     TINYINT       NOT NULL,            -- 1–12
    period_year      SMALLINT      NOT NULL,
    working_days     TINYINT       NOT NULL DEFAULT 26,
    status           VARCHAR(20)   NOT NULL DEFAULT 'draft',  -- draft | approved | paid
    total_gross      DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_net        DECIMAL(15,2) NOT NULL DEFAULT 0,
    notes            TEXT          NULL,
    created_by       INT           NULL,
    approved_by      INT           NULL,
    approved_at      TIMESTAMP     NULL,
    paid_at          TIMESTAMP     NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_period (period_month, period_year),
    CONSTRAINT fk_pr_creator  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pr_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual payslip rows inside a run
CREATE TABLE IF NOT EXISTS payroll_items (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    run_id               INT           NOT NULL,
    staff_type           VARCHAR(20)   NOT NULL,
    staff_id             INT           NOT NULL,
    staff_name           VARCHAR(200)  NOT NULL,
    basic_salary         DECIMAL(15,2) NOT NULL DEFAULT 0,
    house_allowance      DECIMAL(15,2) NOT NULL DEFAULT 0,
    transport_allow      DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_allowance      DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_allow_note     VARCHAR(200)  NULL,
    gross_pay            DECIMAL(15,2) NOT NULL DEFAULT 0,
    paye                 DECIMAL(15,2) NOT NULL DEFAULT 0,
    nhif                 DECIMAL(15,2) NOT NULL DEFAULT 0,
    nssf                 DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_deduction      DECIMAL(15,2) NOT NULL DEFAULT 0,
    other_deduct_note    VARCHAR(200)  NULL,
    total_deductions     DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_pay              DECIMAL(15,2) NOT NULL DEFAULT 0,
    days_worked          TINYINT       NOT NULL DEFAULT 26,
    payment_method       VARCHAR(30)   NULL,
    payment_reference    VARCHAR(100)  NULL,
    status               VARCHAR(20)   NOT NULL DEFAULT 'pending', -- pending | paid
    INDEX idx_pi_run (run_id),
    CONSTRAINT fk_pi_run FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Daily attendance records
CREATE TABLE IF NOT EXISTS attendance_records (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    staff_type       VARCHAR(20)   NOT NULL,
    staff_id         INT           NOT NULL,
    attendance_date  DATE          NOT NULL,
    status           VARCHAR(20)   NOT NULL DEFAULT 'present',  -- present | absent | late | half_day | leave
    clock_in         TIME          NULL,
    clock_out        TIME          NULL,
    notes            VARCHAR(300)  NULL,
    recorded_by      INT           NULL,
    created_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_date (staff_type, staff_id, attendance_date),
    INDEX idx_att_date   (attendance_date),
    INDEX idx_att_staff  (staff_type, staff_id),
    CONSTRAINT fk_att_user FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
