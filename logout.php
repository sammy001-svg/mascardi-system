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

// A visitors-book desk holds an exclusive claim on its location. Release it here
// rather than leaving the branch locked for the whole heartbeat TTL — closing the
// book on one tablet should let another take that location straight away.
if (($_SESSION['auth_user']['role'] ?? '') === 'visitor_book') {
    try {
        require_once __DIR__ . '/modules/visitors/_bootstrap.php';
        visitorDeskRelease(getDB());
    } catch (\Throwable $e) { error_log('logout: desk release: ' . $e->getMessage()); }
}

session_destroy();
header('Location: ' . BASE_URL . '/login.php');
exit;
