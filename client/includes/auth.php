<?php
function clientAuth(): ?array {
    return $_SESSION['_client'] ?? null;
}

function requireClientLogin(): void {
    if (!clientAuth()) {
        header('Location: ' . BASE_URL . '/client/login.php');
        exit;
    }
}
