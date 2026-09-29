<?php
function clientAuth(): ?array {
    return $_SESSION['_client'] ?? null;
}

function requireClientLogin(): void {
    if (!clientAuth()) {
        // Straight to the customer side of the main door, rather than through
        // the forwarder in client/login.php.
        header('Location: ' . BASE_URL . '/login.php?door=client');
        exit;
    }
}
