<?php
/**
 * Superseded by modules/hr/index.php, which covers all staff types rather than
 * mechanics and drivers only. Kept as a redirect so bookmarks and any links
 * still pointing here land on the current dashboard.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
redirect(BASE_URL . '/modules/hr/index.php');
