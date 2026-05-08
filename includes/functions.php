<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/auth.php';

// ── Sanitize output ──────────────────────────────────────
function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

// ── Format currency ──────────────────────────────────────
function money(float $amount): string {
    return 'KES ' . number_format($amount, 2);
}

// ── Format date ──────────────────────────────────────────
function fmtDate(?string $date, string $format = 'd M Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

// ── Status badge ─────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'in_transit'     => 'warning',
        'arrived'        => 'info',
        'in_assessment'  => 'primary',
        'in_workshop'    => 'secondary',
        'completed'      => 'success',
        'delivered'      => 'dark',
        'active'         => 'success',
        'inactive'       => 'danger',
        'pending'        => 'warning',
        'in_progress'    => 'primary',
        'waiting_parts'  => 'info',
        'on_hold'        => 'secondary',
        'cancelled'      => 'danger',
        'draft'          => 'secondary',
        'sent'           => 'info',
        'approved'       => 'success',
        'rejected'       => 'danger',
        'converted'      => 'dark',
        'unpaid'         => 'danger',
        'partial'        => 'warning',
        'paid'           => 'success',
        'acknowledged'   => 'info',
        'received'       => 'success',
        'good'           => 'success',
        'fair'           => 'warning',
        'poor'           => 'danger',
        'critical'       => 'danger',
        'excellent'      => 'success',
        'high'           => 'danger',
        'urgent'         => 'danger',
        'normal'         => 'primary',
        'low'            => 'secondary',
    ];
    $class = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge bg-{$class}\">{$label}</span>";
}

// ── Generate next document number ────────────────────────
function nextNumber(string $table, string $column, string $prefix): string {
    $db  = getDB();
    $row = $db->query("SELECT MAX(CAST(SUBSTRING({$column}, " . (strlen($prefix) + 2) . ") AS UNSIGNED)) AS mx FROM {$table}")->fetch();
    $next = ($row['mx'] ?? 0) + 1;
    return $prefix . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

// ── Pagination helper ─────────────────────────────────────
function paginate(int $total, int $page, int $perPage, string $url): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = $i === $page ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"{$url}&page={$i}\">{$i}</a></li>";
    }
    $html .= '</ul></nav>';
    return $html;
}

// ── Redirect helper ───────────────────────────────────────
function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

// ── Car parts checklist ───────────────────────────────────
function getPartsList(): array {
    return [
        'Exterior' => ['Front Bumper','Rear Bumper','Hood/Bonnet','Trunk/Boot Lid','Left Front Door','Right Front Door','Left Rear Door','Right Rear Door','Left Front Fender','Right Front Fender','Roof','Windshield (Front)','Windshield (Rear)','Left Side Mirror','Right Side Mirror'],
        'Lighting' => ['Headlights (Left)','Headlights (Right)','Tail Lights (Left)','Tail Lights (Right)','Fog Lights (Front)','Fog Lights (Rear)','Turn Signals'],
        'Wheels & Tyres' => ['Front Left Tyre','Front Right Tyre','Rear Left Tyre','Rear Right Tyre','Spare Tyre','Wheel Rims','Wheel Caps'],
        'Interior' => ['Dashboard','Steering Wheel','Seats (Front)','Seats (Rear)','Carpets/Mats','Headliner','Door Panels','Centre Console','Gear Shift'],
        'Electronics' => ['Radio/Infotainment','Air Conditioning','Power Windows','Central Locking','Alarm System','Battery'],
        'Engine & Mechanical' => ['Engine','Transmission/Gearbox','Radiator','Exhaust System','Brake System','Suspension','Steering System','Fuel System'],
        'Documents' => ['Logbook','Insurance','Road Licence','Keys'],
    ];
}

// ── Dashboard counts ──────────────────────────────────────
function getDashboardStats(): array {
    $db = getDB();
    $stats = [];
    $stats['total_cars']         = $db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $stats['in_transit']         = $db->query("SELECT COUNT(*) FROM cars WHERE status='in_transit'")->fetchColumn();
    $stats['in_workshop']        = $db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
    $stats['completed']          = $db->query("SELECT COUNT(*) FROM cars WHERE status='completed'")->fetchColumn();
    $stats['open_jobs']          = $db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $stats['unpaid_invoices']    = $db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
    $stats['low_stock']          = $db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
    $stats['pending_lpo']        = $db->query("SELECT COUNT(*) FROM lpo WHERE status IN ('draft','sent')")->fetchColumn();
    $stats['revenue_month']      = $db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    return $stats;
}

/**
 * Log system activity to audit_logs table
 */
function logActivity(string $action, string $module, ?int $recordId = null, ?string $details = null, $oldValues = null, $newValues = null): void {
    try {
        $db = getDB();
        $user = authUser();
        $userId = $user ? $user['id'] : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, details, old_values, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId,
            $action,
            $module,
            $recordId,
            $details,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            $ip
        ]);
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}
/**
 * Handle file upload with basic security checks
 */
function handleUpload(array $file, string $targetDir, array $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'], int $maxSize = 5242880): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload error: " . $file['error']);
    }

    if ($file['size'] > $maxSize) {
        throw new Exception("File too large. Maximum " . ($maxSize / 1048576) . "MB allowed.");
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedTypes)) {
        throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowedTypes));
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception("Failed to move uploaded file.");
    }

    return $filename;
}
