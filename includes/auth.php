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

// Module access map (non-admin roles)
function canAccess(string $module): bool {
    $user = authUser();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $map = [
        'manager'  => ['cars','drivers','mechanics','intake','assessments','jobs','quotations','invoices','lpo','inventory','suppliers','reports','parts_requests'],
        'mechanic' => ['cars','jobs','assessments','parts_requests'],
        'driver'   => ['assessments'],
    ];
    return in_array($module, $map[$user['role']] ?? []);
}

// Only admins may edit or delete existing records
function canEditDelete(): bool {
    return hasRole('admin');
}
