<?php
/**
 * The customer door moved to the main sign-in page, where it sits beside the
 * staff one behind the same welcome animation. This file stays because links
 * to it are already out there — in emails we have sent, and in bookmarks.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!empty($_SESSION['_client'])) {
    header('Location: ' . BASE_URL . '/client/index.php');
    exit;
}

header("Location: " . BASE_URL . "/login.php?door=client", true, 302);
exit;
