<?php
require_once __DIR__ . '/../config/app.php';
unset($_SESSION['_client']);
header('Location: ' . BASE_URL . '/client/login.php');
exit;
