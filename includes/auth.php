<?php
// ── Auth helpers (requires session already started via config/app.php) ──

function authUser(): ?array {
    return $_SESSION['auth_user'] ?? null;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['auth_user']);
}

function authRole(): string {
    return $_SESSION['auth_user']['role'] ?? '';
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . BASE_URL . '/login.php' . ($back ? "?next={$back}" : ''));
        exit;
    }
    // Verify CSRF on every authenticated POST request
    verifyCsrf();
}

function hasRole(string|array $roles): bool {
    $user = authUser();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($user['role'], $roles);
}

function requireRole(string|array $roles): void {
    requireLogin();
    if (!hasRole($roles)) {
        $label = is_array($roles) ? implode(' or ', $roles) : $roles;
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
        <style>
            body { background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: "Inter", sans-serif; }
            .error-card { background: white; padding: 3rem; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); max-width: 500px; width: 100%; text-align: center; }
            .icon-box { width: 80px; height: 80px; background: #fee2e2; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2rem; }
        </style>
        </head><body>
        <div class="error-card">
            <div class="icon-box"><i class="fa fa-ban"></i></div>
            <h3 class="mb-2">Access Denied</h3>
            <p class="text-muted mb-4">You need <strong>' . htmlspecialchars($label) . '</strong> access to view this page.</p>
            <div class="d-grid gap-2">
                <a href="' . BASE_URL . '/index.php" class="btn btn-primary shadow-sm">Back to Dashboard</a>
                <a href="' . BASE_URL . '/logout.php" class="btn btn-outline-danger mt-2">
                    <i class="fa fa-right-from-bracket me-1"></i>Sign Out
                </a>
            </div>
        </div>
        </body></html>');
    }
}

// Load per-user permission rows from DB (cached per request via static)
function getUserPermissions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $user = authUser();
    if (!$user || $user['role'] === 'admin') return $cache = [];
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT module, can_access, can_write FROM user_permissions WHERE user_id=?");
        $stmt->execute([$user['id']]);
        $cache = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['module']] = [(bool)$row['can_access'], (bool)$row['can_write']];
        }
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}

// Module access map (non-admin roles)
function canAccess(string $module): bool {
    $user = authUser();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    // Custom per-user permissions override role defaults when any rows exist
    $perms = getUserPermissions();
    if (!empty($perms)) {
        return (bool)($perms[$module][0] ?? false);
    }
    $map = [
        // ── Management ─────────────────────────────────────────────────────────
        'general_manager'   => [
            'cars','mechanics','drivers','intake','assessments','jobs','parts_requests','issues',
            'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
            'inspections','attendance','payroll','chat','reports','clients','service_bookings',
            'crm','payments','invoices','quotations','sales','installments','expenses',
        ],

        // ── Finance roles ─────────────────────────────────────────────────────
        'finance_manager'   => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat','lpo','payroll','attendance',
            'inventory','suppliers','parts_requests',
        ],
        'accountant'        => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat',
        ],
        'cashier'           => [
            'payments','invoices','installments','clients','chat','sales',
        ],

        // ── Sales roles ────────────────────────────────────────────────────────
        'sales_manager'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','reports','expenses','assessments','showroom',
        ],
        'sales_officer'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','showroom',
        ],
        'sales_person'      => [
            'cars','clients','service_bookings','quick_assessments','quotations','invoices',
            'payments','sales','crm','installments','car_documents','inspections','chat','showroom',
        ],
        'customer_relations' => [
            'clients','service_bookings','crm','quick_assessments','cars','chat',
            'inspections','installments','quotations','invoices','showroom',
        ],
        'receptionist'      => [
            'clients','service_bookings','quick_assessments','cars','chat','showroom',
        ],

        // ── Workshop / Operational roles ───────────────────────────────────────
        'workshop_manager'  => [
            'cars','mechanics','drivers','assessments','jobs','parts_requests','issues',
            'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
            'inspections','attendance','payroll','chat','reports',
        ],
        'mechanic'          => [
            'jobs','assessments','parts_requests','issues','car_documents','inspections','chat',
        ],
        'driver'            => [
            'cars','assessments',
        ],

        // ── Inventory / Procurement roles ──────────────────────────────────────
        'inventory_manager' => [
            'inventory','suppliers','lpo','parts_requests','cars','issues',
            'car_documents','chat',
        ],
        'procurement_officer' => [
            'inventory','suppliers','lpo','parts_requests','cars','issues',
            'car_documents','chat','reports',
        ],

        // ── HR roles ───────────────────────────────────────────────────────────
        'hr_manager'        => [
            'attendance','payroll','mechanics','drivers','expenses','reports','chat',
        ],

        // ── Legacy ─────────────────────────────────────────────────────────────
        'manager'           => [
            'cars','mechanics','drivers','intake','assessments','jobs','quotations','invoices',
            'lpo','inventory','suppliers','reports','parts_requests','clients','service_bookings',
            'issues','chat','car_documents','crm','car_costs','installments','expenses',
            'inspections','attendance','payroll','quick_assessments','sales',
        ],
    ];
    return in_array($module, $map[$user['role']] ?? []);
}

// Create/edit permission per module (non-destructive writes)
function canWrite(string $module): bool {
    if (hasRole('admin')) return true;
    $perms = getUserPermissions();
    if (!empty($perms)) {
        return (bool)($perms[$module][1] ?? false);
    }
    $map = [
        'general_manager'   => ['quotations','invoices','sales'],
        'finance_manager'   => ['payments','invoices','quotations','expenses','sales','installments','payroll','lpo'],
        'accountant'        => ['payments','invoices','quotations','expenses','sales','installments'],
        'cashier'           => ['payments','installments'],
        'sales_manager'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments','expenses'],
        'sales_officer'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments'],
        'sales_person'      => ['service_bookings','quick_assessments','clients','payments','sales','crm','installments'],
        'customer_relations' => ['clients','service_bookings','crm','quick_assessments','installments'],
        'receptionist'      => ['clients','service_bookings','quick_assessments'],
        'workshop_manager'  => ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments','lpo','inventory','suppliers','car_documents','car_costs','inspections','attendance','payroll'],
        'mechanic'          => ['assessments','parts_requests'],
        'driver'            => [],
        'inventory_manager' => ['inventory','suppliers','lpo','parts_requests'],
        'procurement_officer' => ['lpo','suppliers','inventory','parts_requests'],
        'hr_manager'        => ['attendance','payroll'],
        'manager'           => ['cars','jobs','assessments','mechanics','drivers','inventory','parts_requests','intake','issues','lpo','quotations','invoices','clients','service_bookings','car_documents','car_costs','installments','expenses','inspections','attendance','payroll','quick_assessments','sales','crm'],
    ];
    $role = authRole();
    return in_array($module, $map[$role] ?? []);
}

// Gate for write/edit operations per module (non-destructive writes).
// Returns true if the current user has can_write on $module.
// Delete remains admin-only via canEditDelete().
function requireWrite(string $module): void {
    requireLogin();
    if (!canWrite($module)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
        <style>
            body{background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:"Inter",sans-serif}
            .error-card{background:#fff;padding:3rem;border-radius:16px;box-shadow:0 10px 25px -5px rgba(0,0,0,.1);max-width:500px;width:100%;text-align:center}
            .icon-box{width:80px;height:80px;background:#fee2e2;color:#dc2626;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem}
        </style>
        </head><body>
        <div class="error-card">
            <div class="icon-box"><i class="fa fa-ban"></i></div>
            <h3 class="mb-2">Access Denied</h3>
            <p class="text-muted mb-4">You do not have write permission for <strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $module))) . '</strong>.</p>
            <div class="d-grid gap-2">
                <a href="' . BASE_URL . '/index.php" class="btn btn-primary shadow-sm">Back to Dashboard</a>
                <a href="' . BASE_URL . '/logout.php" class="btn btn-outline-danger mt-2">
                    <i class="fa fa-right-from-bracket me-1"></i>Sign Out
                </a>
            </div>
        </div>
        </body></html>');
    }
}

// Only admins may delete records
function canEditDelete(): bool {
    return hasRole('admin');
}
