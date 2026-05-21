<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
portalLogout();
header('Location: ' . BASE_URL . '/portal/login.php');
exit;
