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
    if ($user['role'] === 'admin' || $user['role'] === 'super_admin') return true;
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($user['role'], $roles);
}

/**
 * Top-level approver check.
 *
 * 'admin' and 'super_admin' are the same authority level everywhere in this app
 * (see hasRole/canAccess/canWrite above). Which one a deployment actually uses
 * depends on its users.role ENUM — this install only has 'admin'. Always use
 * this helper rather than comparing to 'super_admin' directly, or the check
 * silently matches nobody and gates become impossible to pass.
 */
function isSuperAdmin(): bool {
    $user = authUser();
    if (!$user) return false;
    return $user['role'] === 'admin' || $user['role'] === 'super_admin';
}

/** Roles that should receive "needs top-level approval" notifications. */
function superAdminRoles(): array {
    return ['super_admin', 'admin'];
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

// One-time per-request migration: fix stale user_permissions rows where a role's
// access was set to 0 for a module that was later added to the role's default map.
// Runs at most once per PHP process (static flag).
function _fixRolePermissions(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        // customer_relations: cars access was added to role map but existing DB rows
        // still have can_access=0 due to being saved before the default was configured.
        $db->exec("
            UPDATE user_permissions up
            JOIN   users u ON u.id = up.user_id AND u.role = 'customer_relations'
            SET    up.can_access = 1
            WHERE  up.module = 'cars' AND up.can_access = 0
        ");
    } catch (\Throwable $_) {}

    _repairBlankRoles();
}

/**
 * Restores accounts whose role was blanked by a schema change.
 *
 * A widening of users.role once listed the allowed values explicitly and left
 * out 'super_admin'. MySQL strict mode is off here, so instead of failing, the
 * ALTER silently rewrote every super-admin row to ''. A blank role matches no
 * entry in canAccess()'s map, so those accounts lost access to every module at
 * once — the sidebar collapsed to the single ungated Dashboard link.
 *
 * This has to live on the common auth path rather than in the HR migration that
 * caused it: an account in that state cannot reach the HR pages to trigger a
 * repair, because it cannot reach any page's menu.
 *
 * Cheap in the normal case — one indexed count that returns 0 and stops.
 */
function _repairBlankRoles(): void {
    try {
        $db = getDB();

        $blank = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = '' OR role IS NULL")->fetchColumn();
        if ($blank === 0) {
            // Still refresh a stale session below, then stop.
            _refreshSessionRole();
            return;
        }

        // Only meaningful once the value is legal again; the HR bootstrap adds
        // it back additively. Without this guard the UPDATE would re-blank them.
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if (!$col || !str_contains(strtolower($col['Type']), 'super_admin')) {
            require_once __DIR__ . '/../modules/hr/_bootstrap.php';
            hrMigrate($db, true);
            $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
            if (!$col || !str_contains(strtolower($col['Type']), 'super_admin')) return;
        }

        $rows = $db->query("SELECT id, username FROM users WHERE role = '' OR role IS NULL")
                   ->fetchAll(PDO::FETCH_ASSOC);
        $db->exec("UPDATE users SET role = 'super_admin' WHERE role = '' OR role IS NULL");
        foreach ($rows as $u) {
            error_log("_repairBlankRoles: restored super_admin for user #{$u['id']} ({$u['username']})");
        }
        _refreshSessionRole();
    } catch (\Throwable $_) {}
}

/**
 * Re-reads the signed-in user's role from the database when the session copy is
 * blank. The session is written at login, so repairing the row alone would
 * leave the person staring at the same empty menu until they signed out.
 */
function _refreshSessionRole(): void {
    if (empty($_SESSION['auth_user']['id'])) return;
    if (!empty($_SESSION['auth_user']['role'])) return;   // nothing wrong with it
    try {
        $st = getDB()->prepare("SELECT role FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['auth_user']['id']]);
        $role = (string)$st->fetchColumn();
        if ($role !== '') $_SESSION['auth_user']['role'] = $role;
    } catch (\Throwable $_) {}
}

// Load per-user permission rows from DB (cached per request via static)
function getUserPermissions(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $user = authUser();
    if (!$user || $user['role'] === 'admin' || $user['role'] === 'super_admin') return $cache = [];
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
    _fixRolePermissions();
    $user = authUser();
    if (!$user) return false;
    if ($user['role'] === 'admin' || $user['role'] === 'super_admin') return true;
    // DB grants are additive — role map is the floor (DB=0 falls through to role map)
    $perms = getUserPermissions();
    if (isset($perms[$module]) && $perms[$module][0]) {
        return true;
    }
    $map = [
        // ── Management ─────────────────────────────────────────────────────────
        'general_manager'   => [
            'cars','mechanics','drivers','intake','assessments','jobs','parts_requests','issues',
            'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
            'inspections','attendance','payroll','hr','chat','reports','clients','service_bookings',
            'crm','payments','invoices','quotations','sales','installments','expenses','imports','trade_in',
        ],

        // ── Finance roles ─────────────────────────────────────────────────────
        'finance_manager'   => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat','lpo','payroll','attendance','trade_in',
            'inventory','suppliers','parts_requests','imports',
        ],
        'accountant'        => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat','trade_in',
        ],
        'cashier'           => [
            'payments','invoices','installments','clients','chat','sales',
        ],

        // ── Sales roles ────────────────────────────────────────────────────────
        'sales_manager'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','reports','expenses','assessments','showroom',
            'showroom_transfers','key_handovers','dispatch','team','imports','trade_in',
        ],
        'sales_officer'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','showroom','showroom_transfers','key_handovers','dispatch','team','imports','trade_in',
        ],
        'sales_person'      => [
            'cars','clients','service_bookings','quick_assessments','quotations','invoices',
            'payments','sales','crm','installments','car_documents','inspections','chat','showroom',
            'showroom_transfers','key_handovers','dispatch','team','trade_in',
        ],
        'customer_relations' => [
            // trade_in is read-only here (absent from canWrite below): CR agents
            // sell consignment vehicles through leads, so they need to see the
            // deal terms — owner, commission, agreement dates — but the deal
            // itself stays owned by sales/management.
            'clients','crm','chat','cars','trade_in',
        ],

        // ── Supervisor role ────────────────────────────────────────────────────
        'supervisor'        => [
            'cars','service_bookings','quick_assessments','quotations','invoices','reports','crm','payments','clients','chat','trade_in',
        ],
        'receptionist'      => [
            'clients','service_bookings','quick_assessments','cars','chat','showroom',
            'showroom_transfers','key_handovers','dispatch','team',
        ],

        // ── Workshop / Operational roles ───────────────────────────────────────
        'workshop_manager'  => [
            'cars','mechanics','drivers','assessments','jobs','parts_requests','issues',
            'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
            'inspections','attendance','payroll','chat','reports',
            'showroom_transfers','key_handovers','dispatch','team','imports',
        ],
        'mechanic'          => [
            'jobs','assessments','parts_requests','issues','car_documents','inspections','chat','team',
        ],
        'driver'            => [
            'cars','assessments','key_handovers','dispatch','team',
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
        // Deliberately narrow. HR previously also carried 'expenses' (a finance
        // ledger), 'mechanics' and 'drivers' (fleet-operations records — HR
        // reaches the same people through the employee directory, which is
        // scoped to employment data rather than job assignment). Those were
        // removed so the portal only exposes what HR is accountable for.
        'hr_manager'        => [
            'hr','attendance','payroll','team','reports','chat',
        ],

        // ── Legacy ─────────────────────────────────────────────────────────────
        'manager'           => [
            'cars','mechanics','drivers','intake','assessments','jobs','quotations','invoices',
            'lpo','inventory','suppliers','reports','parts_requests','clients','service_bookings',
            'issues','chat','car_documents','crm','car_costs','installments','expenses',
            'inspections','attendance','payroll','hr','quick_assessments','sales',
            'showroom_transfers','key_handovers','dispatch','team','imports',
        ],
    ];
    return in_array($module, $map[$user['role']] ?? []);
}

// Create/edit permission per module (non-destructive writes)
function canWrite(string $module): bool {
    if (hasRole('admin')) return true;
    $perms = getUserPermissions();
    if (isset($perms[$module]) && $perms[$module][1]) {
        return true;
    }
    $map = [
        'general_manager'   => ['quotations','invoices','sales','imports','trade_in'],
        'supervisor'        => ['quick_assessments'], // read-only elsewhere; can log assessments at their own location
        'finance_manager'   => ['payments','invoices','quotations','expenses','sales','installments','payroll','lpo','imports','trade_in'],
        'accountant'        => ['payments','invoices','quotations','expenses','sales','installments','trade_in'],
        'cashier'           => ['payments','installments'],
        'sales_manager'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments','expenses','dispatch','team','imports','trade_in'],
        'sales_officer'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments','dispatch','team','trade_in'],
        'sales_person'      => ['service_bookings','quick_assessments','clients','payments','sales','crm','installments','dispatch','team','trade_in'],
        'customer_relations' => ['clients','crm','cars'],
        'receptionist'      => ['clients','service_bookings','quick_assessments','team'],
        'workshop_manager'  => ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments','lpo','inventory','suppliers','car_documents','car_costs','inspections','attendance','payroll','dispatch','team','imports'],
        'mechanic'          => ['assessments','parts_requests','team'],
        'driver'            => ['team'],
        'inventory_manager' => ['inventory','suppliers','lpo','parts_requests'],
        'procurement_officer' => ['lpo','suppliers','inventory','parts_requests'],
        'hr_manager'        => ['hr','attendance','payroll','team'],
        'manager'           => ['cars','jobs','assessments','mechanics','drivers','inventory','parts_requests','intake','issues','lpo','quotations','invoices','clients','service_bookings','car_documents','car_costs','installments','expenses','inspections','attendance','payroll','quick_assessments','sales','crm','imports'],
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

/**
 * Returns the location ID assigned to the current supervisor user.
 * Returns null if the user is not a supervisor or has no location assigned.
 */
function supervisorLocationId(): ?int {
    $user = authUser();
    if (!$user || $user['role'] !== 'supervisor') return null;
    // Try from session first (populated at login)
    if (!empty($user['location_id'])) return (int)$user['location_id'];
    // Fallback: query DB (runs when session predates location_id being assigned)
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT location_id FROM users WHERE id=?");
        $stmt->execute([$user['id']]);
        $locId = $stmt->fetchColumn();
        if ($locId) {
            // Backfill session so subsequent requests skip the DB query
            $_SESSION['auth_user']['location_id'] = (int)$locId;
            return (int)$locId;
        }
        return null;
    } catch (\Throwable $_) {
        return null;
    }
}
