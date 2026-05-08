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
        </head><body class="bg-light"><div class="container py-5 text-center">
        <h3 class="text-danger"><i class="fa fa-ban me-2"></i>Access Denied</h3>
        <p class="text-muted">You need <strong>' . htmlspecialchars($label) . '</strong> access to view this page.</p>
        <a href="' . BASE_URL . '/index.php" class="btn btn-primary">Back to Dashboard</a>
        </div></body></html>');
    }
}

// Module access map (non-admin roles)
function canAccess(string $module): bool {
    $user = authUser();
    if (!$user) return false;
    if ($user['role'] === 'admin') return true;
    $map = [
        'manager'  => ['cars','drivers','mechanics','intake','assessments','jobs','quotations','invoices','lpo','inventory','suppliers','reports'],
        'mechanic' => ['cars','jobs','assessments','inventory'],
        'driver'   => ['cars','intake'],
    ];
    return in_array($module, $map[$user['role']] ?? []);
}
