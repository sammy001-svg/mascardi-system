<?php
/**
 * Mascardi REST API v1 — Entry Point / Router
 *
 * Base URL: /api/v1/{resource}
 *
 * Authentication: Bearer token in Authorization header
 *   Authorization: Bearer <your_api_token>
 *
 * Generate a token: Admin → Users → Edit User → "Generate API Token"
 *
 * Available endpoints:
 *   GET  /api/v1/cars           List all cars (with filters)
 *   GET  /api/v1/cars/{id}      Get single car
 *   GET  /api/v1/clients        List all clients
 *   GET  /api/v1/clients/{id}   Get single client
 *   GET  /api/v1/invoices       List invoices (filter by status)
 *   GET  /api/v1/invoices/{id}  Get single invoice with items
 *   GET  /api/v1/jobs           List workshop jobs
 *   GET  /api/v1/jobs/{id}      Get single job
 *   GET  /api/v1/payments       List payments
 *   GET  /api/v1/stats          Dashboard statistics
 */

require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/auth.php';

// CORS headers for cross-origin API clients
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-CSRF-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse route
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$scriptDir  = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$path       = '/' . ltrim(str_replace($scriptDir, '', $requestUri), '/');

// e.g.  /api/v1/cars/42  →  ['cars', '42']
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

$resource = $segments[0] ?? '';
$id       = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;

// Authenticate via Bearer token
$apiUser = apiAuthenticate();
if (!$apiUser) {
    apiError(401, 'Unauthorized. Provide a valid Bearer token.');
}

// Route to resource handler
switch ($resource) {
    case 'cars':       require __DIR__ . '/resources/cars.php';     break;
    case 'clients':    require __DIR__ . '/resources/clients.php';  break;
    case 'invoices':   require __DIR__ . '/resources/invoices.php'; break;
    case 'jobs':       require __DIR__ . '/resources/jobs.php';     break;
    case 'payments':   require __DIR__ . '/resources/payments.php'; break;
    case 'stats':      require __DIR__ . '/resources/stats.php';    break;
    default:
        apiError(404, "Unknown resource '{$resource}'. See /api/v1/ documentation.");
}
