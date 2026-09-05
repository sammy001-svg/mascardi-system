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
    _confineVisitorBook();
    // Verify CSRF on every authenticated POST request
    verifyCsrf();
}

/**
 * Keeps the reception kiosk account on the visitors book.
 *
 * canAccess() already refuses every other module for this role, but not every
 * page asks it — modules/cars/index.php, for one, has no access check at all, so
 * any signed-in account could open the full stock listing. That is survivable for
 * staff accounts; it is not survivable for an account that sits logged in on an
 * unattended screen in reception, facing the public.
 *
 * So containment is enforced here, on the path every authenticated page already
 * goes through, rather than by adding a guard to each file and hoping the next
 * page added remembers one. Allowing by location (the visitorbook directory)
 * rather than by a list of blocked modules means a new module is denied by
 * default instead of being exposed until someone notices.
 */
function _confineVisitorBook(): void {
    $u = authUser();
    if (!$u || ($u['role'] ?? '') !== 'visitor_book') return;

    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    // Compare on the path within the application, so this holds whether the
    // system is served from the document root or a subdirectory.
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
    if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    $path = '/' . ltrim($path, '/');

    $allowed = ['/visitorbook/', '/logout.php', '/login.php'];
    foreach ($allowed as $ok) {
        if ($ok === $path || str_starts_with($path, rtrim($ok, '/') . '/')) return;
    }
    // Also allow the shared assets a page in that directory pulls in.
    if (preg_match('~^/(assets|uploads|static)/~', $path)) return;

    header('Location: ' . BASE_URL . '/visitorbook/index.php', true, 302);
    exit;
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
/**
 * Makes sure users.role accepts a value, widening the ENUM if it does not.
 *
 * Strictly additive: it reads what the column already allows and appends. Never
 * write a literal ENUM list against this column — one did, left 'super_admin'
 * out, and with MySQL strict mode off the ALTER silently blanked every super
 * admin's role instead of failing (see _repairBlankRoles above). Anything that
 * introduces a new role goes through here.
 *
 * @return bool Whether the column now accepts the role.
 */
function ensureUserRole(string $role, ?string &$detail = null): bool {
    $detail = '';
    if ($role === '' || !preg_match('/^[a-z_]+$/', $role)) {
        $detail = 'Role name must be lowercase letters and underscores only.';
        return false;
    }
    try {
        $db  = getDB();
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $detail = 'No users.role column found.';
            return false;
        }
        $type = trim((string)$col['Type']);

        // Not an ENUM — a VARCHAR/TEXT column already accepts any role, so there
        // is nothing to widen and nothing wrong. This used to report failure,
        // which stopped the visitors book seeding on installations whose role
        // column had been changed to a string at some point.
        if (!preg_match('/^enum\((.*)\)$/is', $type, $m)) {
            $detail = 'users.role is ' . $type . ' — accepts any value, no change needed.';
            return true;
        }

        $existing = [];
        foreach (explode("','", trim($m[1], "'")) as $v) {
            $v = trim($v, "' ");
            if ($v !== '') $existing[] = $v;
        }
        if (in_array($role, $existing, true)) {
            $detail = 'Already allowed by the ENUM.';
            return true;
        }

        // Preserve whatever the column already had, and keep its own nullability
        // and default rather than asserting ones that may not suit this install —
        // forcing NOT NULL on a column holding NULLs is a needless way to fail.
        $notNull = (($col['Null'] ?? 'YES') === 'NO') ? ' NOT NULL' : ' NULL';
        $default = '';
        if (($col['Default'] ?? null) !== null && $col['Default'] !== '') {
            $default = " DEFAULT '" . str_replace("'", "''", (string)$col['Default']) . "'";
        } elseif ($notNull === ' NOT NULL') {
            $default = " DEFAULT '" . str_replace("'", "''", $existing[0] ?? $role) . "'";
        }

        $all  = array_merge($existing, [$role]);
        $list = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM({$list}){$notNull}{$default}");
        $detail = 'Added to the ENUM (' . count($all) . ' roles).';
        return true;
    } catch (\Throwable $e) {
        // Surfaced to the caller as well as logged. On shared hosting this is
        // usually a missing ALTER privilege, and a caller that only sees "false"
        // cannot tell the operator that.
        $detail = $e->getMessage();
        error_log('ensureUserRole(' . $role . '): ' . $e->getMessage());
        return false;
    }
}

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

        // Promote ONLY when the system has no administrator left.
        //
        // This repair exists for one situation: every super admin was blanked at
        // once and nobody could administer anything. Applying it whenever a role
        // is blank is dangerous, because any role can be blanked the same way —
        // and the newest one, visitor_book, is the likeliest to be dropped by the
        // next careless rewrite of this column. That account sits permanently
        // signed in on an unattended screen in reception, so handing it
        // super_admin would open the whole system to whoever walks in.
        //
        // With an administrator still present there is no lockout to recover
        // from, so the rows are logged and left alone for a human to set
        // deliberately. A blank role grants nothing, which fails safe.
        $admins = (int)$db->query("SELECT COUNT(*) FROM users
                                   WHERE role IN ('super_admin','admin') AND status = 'active'")->fetchColumn();
        if ($admins > 0) {
            foreach ($rows as $u) {
                error_log("_repairBlankRoles: user #{$u['id']} ({$u['username']}) has a blank role. "
                        . "NOT promoting — {$admins} administrator(s) still active. Set the role manually.");
            }
            _refreshSessionRole();
            return;
        }

        $db->exec("UPDATE users SET role = 'super_admin' WHERE role = '' OR role IS NULL");
        foreach ($rows as $u) {
            error_log("_repairBlankRoles: no administrator remained — restored super_admin "
                    . "for user #{$u['id']} ({$u['username']})");
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
/** Modules every signed-in member of staff gets, whatever their role. */
function universalModules(): array {
    // Meetings is company-wide: anyone can be invited to one, be given a
    // deliverable in it, or need to see what they agreed to. Gating it by role
    // would mean a mechanic invited to a safety briefing could not open it.
    return ['meetings', 'callcenter'];
}

function canAccess(string $module): bool {
    _fixRolePermissions();
    $user = authUser();
    if (!$user) return false;

    // The visitors-book kiosk sits in reception, logged in and unattended, in
    // front of whoever walks through the door. It is deliberately outside every
    // other grant — including the company-wide ones and any per-user database
    // grant below — so the account can reach the sign-in form and nothing else.
    if ($user['role'] === 'visitor_book') return $module === 'visitor_book';

    if ($user['role'] === 'admin' || $user['role'] === 'super_admin') return true;
    if (in_array($module, universalModules(), true)) return true;
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
            'crm','payments','invoices','quotations','sales','installments','expenses','imports','trade_in','meetings',
            'showroom_transfers',
        ],

        // ── Finance roles ─────────────────────────────────────────────────────
        'finance_manager'   => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat','lpo','payroll','attendance','trade_in',
            'inventory','suppliers','parts_requests','imports','meetings',
        ],
        'accountant'        => [
            'payments','invoices','quotations','expenses','reports','clients','sales',
            'installments','car_costs','cars','chat','trade_in','meetings',
        ],
        'cashier'           => [
            'payments','invoices','installments','clients','chat','sales',
        ],

        // ── Sales roles ────────────────────────────────────────────────────────
        'sales_manager'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','reports','expenses','assessments','showroom',
            'showroom_transfers','key_handovers','dispatch','team','imports','trade_in','meetings',
        ],
        'sales_officer'     => [
            'cars','clients','service_bookings','quotations','invoices','payments',
            'quick_assessments','sales','crm','installments','car_costs','car_documents',
            'inspections','chat','showroom','showroom_transfers','key_handovers','dispatch','team','imports','trade_in','meetings',
        ],
        'sales_person'      => [
            'cars','clients','service_bookings','quick_assessments','quotations','invoices',
            'payments','sales','crm','installments','car_documents','inspections','chat','showroom',
            'showroom_transfers','key_handovers','dispatch','team','trade_in','meetings',
        ],
        'customer_relations' => [
            // trade_in is read-only here (absent from canWrite below): CR agents
            // sell consignment vehicles through leads, so they need to see the
            // deal terms — owner, commission, agreement dates — but the deal
            // itself stays owned by sales/management.
            // showroom_transfers: CR agents raise a transfer when a customer wants a
            // vehicle moved to another showroom. Approving and receiving it is not
            // theirs — that is gated separately in the module itself.
            'clients','crm','chat','cars','trade_in','meetings','showroom_transfers',
        ],

        // ── Supervisor role ────────────────────────────────────────────────────
        'supervisor'        => [
            'cars','service_bookings','quick_assessments','quotations','invoices','reports','crm','payments','clients','chat','trade_in','meetings','showroom_transfers',
        ],
        'receptionist'      => [
            'clients','service_bookings','quick_assessments','cars','chat','showroom',
            'showroom_transfers','key_handovers','dispatch','team','meetings',
        ],

        // ── Workshop / Operational roles ───────────────────────────────────────
        'workshop_manager'  => [
            'cars','mechanics','drivers','assessments','jobs','parts_requests','issues',
            'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
            'inspections','attendance','payroll','chat','reports',
            'showroom_transfers','key_handovers','dispatch','team','imports','meetings',
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
            'car_documents','chat','meetings',
        ],
        'procurement_officer' => [
            'inventory','suppliers','lpo','parts_requests','cars','issues',
            'car_documents','chat','reports','meetings',
        ],

        // ── HR roles ───────────────────────────────────────────────────────────
        // Deliberately narrow. HR previously also carried 'expenses' (a finance
        // ledger), 'mechanics' and 'drivers' (fleet-operations records — HR
        // reaches the same people through the employee directory, which is
        // scoped to employment data rather than job assignment). Those were
        // removed so the portal only exposes what HR is accountable for.
        'hr_manager'        => [
            'hr','attendance','payroll','team','reports','chat','meetings',
        ],

        // ── Legacy ─────────────────────────────────────────────────────────────
        'manager'           => [
            'cars','mechanics','drivers','intake','assessments','jobs','quotations','invoices',
            'lpo','inventory','suppliers','reports','parts_requests','clients','service_bookings',
            'issues','chat','car_documents','crm','car_costs','installments','expenses',
            'inspections','attendance','payroll','hr','quick_assessments','sales',
            'showroom_transfers','key_handovers','dispatch','team','imports','meetings',
        ],
    ];
    return in_array($module, $map[$user['role']] ?? []);
}

// Create/edit permission per module (non-destructive writes)
function canWrite(string $module): bool {
    // Mirrors the containment in canAccess(): the kiosk may record a visit and
    // nothing else. Checked before hasRole(), which returns true for admin and
    // super_admin on every module.
    if (authRole() === 'visitor_book') return $module === 'visitor_book';

    if (hasRole('admin')) return true;
    // Anyone may schedule a meeting. What they can do to a meeting they are not
    // running is a separate, per-meeting question answered by meetingCanEdit().
    // Everyone can place a call; configuring the service and recording
    // top-ups is a management job, so 'callcenter' write is not universal.
    if (in_array($module, universalModules(), true) && $module !== 'callcenter') return true;
    $perms = getUserPermissions();
    if (isset($perms[$module]) && $perms[$module][1]) {
        return true;
    }
    $map = [
        // cars: the general manager could see the inventory but not correct anything
        // in it — a price, a mileage, a description — which meant every small fix
        // went through a super admin. Editing is write access; DELETING stays with
        // canEditDelete(), which is admin only, so nothing can be removed here.
        'general_manager'   => ['quotations','invoices','sales','imports','trade_in','meetings','callcenter',
                                'showroom_transfers','cars'],
        // showroom_transfers: a supervisor approves and receives them, which needs
        // write rights on the module even though everything else here is read-only.
        'supervisor'        => ['quick_assessments','meetings','showroom_transfers'],
        'finance_manager'   => ['payments','invoices','quotations','expenses','sales','installments','payroll','lpo','imports','trade_in','meetings'],
        'accountant'        => ['payments','invoices','quotations','expenses','sales','installments','trade_in','meetings'],
        'cashier'           => ['payments','installments'],
        'sales_manager'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments','expenses','dispatch','team','imports','trade_in','meetings','callcenter'],
        'sales_officer'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments','dispatch','team','trade_in','meetings'],
        'sales_person'      => ['service_bookings','quick_assessments','clients','payments','sales','crm','installments','dispatch','team','trade_in','meetings'],
        'customer_relations' => ['clients','crm','cars','meetings','callcenter','showroom_transfers'],
        'receptionist'      => ['clients','service_bookings','quick_assessments','team','meetings'],
        'workshop_manager'  => ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments','lpo','inventory','suppliers','car_documents','car_costs','inspections','attendance','payroll','dispatch','team','imports','meetings'],
        'mechanic'          => ['assessments','parts_requests','team'],
        'driver'            => ['team'],
        'inventory_manager' => ['inventory','suppliers','lpo','parts_requests','meetings'],
        'procurement_officer' => ['lpo','suppliers','inventory','parts_requests','meetings'],
        'hr_manager'        => ['hr','attendance','payroll','team','meetings','callcenter'],
        'manager'           => ['cars','jobs','assessments','mechanics','drivers','inventory','parts_requests','intake','issues','lpo','quotations','invoices','clients','service_bookings','car_documents','car_costs','installments','expenses','inspections','attendance','payroll','quick_assessments','sales','crm','imports','meetings','callcenter'],
    ];
    $role = authRole();
    return in_array($module, $map[$role] ?? []);
}

/**
 * Who may approve a stock transfer, send it on its way, or sign for it at the
 * other end.
 *
 * Deliberately narrower than canWrite('showroom_transfers'). Customer relations
 * raise a transfer because they are the ones the customer asks, but a vehicle
 * leaving one showroom and arriving at another is a custody change: the person
 * who asks for it must not also be the person who says it happened.
 */
function canApproveTransfer(): bool
{
    return isSuperAdmin() || hasRole(['admin', 'general_manager', 'supervisor']);
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
