<?php
/**
 * One-time migration runner — delete this file after use.
 * Navigate to: /run_migration.php
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

// Require admin session
session_start();
if (empty($_SESSION['auth_user']) || ($_SESSION['auth_user']['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:20px">Access denied. You must be logged in as admin.</p>');
}

$db = getDB();
$results = [];

// ── Migration 014: Car Sales ──────────────────────────────────────────────────

// 1. Extend cars.status ENUM to include 'sold'
try {
    $db->exec("ALTER TABLE cars
        MODIFY COLUMN status
        ENUM('in_transit','arrived','in_assessment','in_workshop','completed','sold','delivered')
        DEFAULT 'in_transit'");
    $results[] = ['ok', 'cars.status ENUM extended to include "sold"'];
} catch (PDOException $e) {
    $results[] = ['warn', 'cars.status ENUM: ' . $e->getMessage()];
}

// 2. Create car_sales table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS car_sales (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        sale_number       VARCHAR(30)  UNIQUE NOT NULL,
        car_id            INT          NOT NULL,
        sale_date         DATE         NOT NULL,
        sale_price        DECIMAL(12,2) NOT NULL,
        buyer_name        VARCHAR(150) NOT NULL,
        buyer_phone       VARCHAR(30),
        buyer_email       VARCHAR(150),
        buyer_id_number   VARCHAR(30),
        payment_method    ENUM('cash','bank_transfer','financing','cheque','mpesa') DEFAULT 'cash',
        payment_status    ENUM('paid_full','partial','financed','pending') DEFAULT 'paid_full',
        deposit_amount    DECIMAL(12,2) DEFAULT 0.00,
        balance_amount    DECIMAL(12,2) DEFAULT 0.00,
        finance_company   VARCHAR(150),
        delivered_at      DATETIME     NULL,
        delivery_notes    TEXT,
        sold_by           INT          NULL,
        notes             TEXT,
        status            ENUM('active','cancelled') DEFAULT 'active',
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_sale_car  FOREIGN KEY (car_id)  REFERENCES cars(id)  ON DELETE RESTRICT,
        CONSTRAINT fk_sale_user FOREIGN KEY (sold_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $results[] = ['ok', 'car_sales table created (or already exists)'];
} catch (PDOException $e) {
    $results[] = ['err', 'car_sales table: ' . $e->getMessage()];
}

// 3. Insert sale_prefix setting
try {
    $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('sale_prefix', 'SALE')");
    $results[] = ['ok', 'sale_prefix setting inserted'];
} catch (PDOException $e) {
    $results[] = ['warn', 'sale_prefix setting: ' . $e->getMessage()];
}

// ── Render results ────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Migration Runner</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:640px">
    <h4 class="mb-4"><i class="fa fa-database me-2"></i>Migration 014 — Car Sales</h4>
    <div class="card">
        <div class="card-body">
            <?php foreach ($results as [$type, $msg]): ?>
            <?php $cls = $type === 'ok' ? 'success' : ($type === 'warn' ? 'warning' : 'danger'); ?>
            <div class="alert alert-<?= $cls ?> py-2 mb-2 small">
                <?= $type === 'ok' ? '✔' : ($type === 'warn' ? '⚠' : '✖') ?> <?= htmlspecialchars($msg) ?>
            </div>
            <?php endforeach; ?>
            <?php $hasError = in_array('err', array_column($results, 0)); ?>
            <?php if (!$hasError): ?>
            <div class="alert alert-success mt-3">
                <strong>Migration complete.</strong> Delete <code>run_migration.php</code> now.
            </div>
            <a href="/modules/sales/index.php" class="btn btn-primary">Go to Sales Module</a>
            <?php else: ?>
            <div class="alert alert-danger mt-3"><strong>Migration failed.</strong> Check errors above.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
