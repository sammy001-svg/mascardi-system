-- Phase 9: Cron Job Execution Log
-- Run in phpMyAdmin on mascardi_db

CREATE TABLE IF NOT EXISTS cron_runs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    job_name    VARCHAR(100) NOT NULL,
    status      ENUM('success','error','skipped') NOT NULL DEFAULT 'success',
    duration_ms INT          NOT NULL DEFAULT 0,
    records     INT          NOT NULL DEFAULT 0,       -- rows processed / messages sent
    message     TEXT         NULL,                     -- success summary or error detail
    ran_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cron_job_ran (job_name, ran_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
