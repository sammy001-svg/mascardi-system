<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

// Clear remember-me token
if (!empty($_COOKIE['rm_tok'])) {
    try {
        $hash = hash('sha256', $_COOKIE['rm_tok']);
        getDB()->prepare("DELETE FROM remember_tokens WHERE token_hash = ?")->execute([$hash]);
    } catch (Exception $e) {}
    setcookie('rm_tok', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
}

session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
