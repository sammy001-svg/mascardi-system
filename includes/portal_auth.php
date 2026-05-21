<?php
function requirePortalLogin(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    if (empty($_SESSION['portal_client']['id'])) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . BASE_URL . '/portal/login.php' . ($redirect ? '?redirect=' . $redirect : ''));
        exit;
    }
    // 2-hour portal session timeout
    if (isset($_SESSION['portal_last_activity']) && (time() - $_SESSION['portal_last_activity']) > 7200) {
        portalLogout();
        header('Location: ' . BASE_URL . '/portal/login.php?timeout=1');
        exit;
    }
    $_SESSION['portal_last_activity'] = time();
}

function portalClient(): array {
    return $_SESSION['portal_client'] ?? [];
}

function portalLogin(array $client): void {
    session_regenerate_id(true);
    $_SESSION['portal_client'] = [
        'id'    => (int)$client['id'],
        'name'  => $client['name'],
        'email' => $client['email'],
    ];
    $_SESSION['portal_last_activity'] = time();
}

function portalLogout(): void {
    unset($_SESSION['portal_client'], $_SESSION['portal_last_activity']);
}
