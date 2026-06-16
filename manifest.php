<?php
/**
 * PWA Web App Manifest
 * Served as JSON with dynamic BASE_URL so the app works in any subdirectory.
 * Browsers fetch this before the user logs in — no auth required.
 */
require_once __DIR__ . '/config/app.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');

// Attempt to load company name from DB; fall back to constant gracefully
$appName      = defined('APP_NAME') ? APP_NAME : 'Mascardi Car Yard';
$appShortName = 'Mascardi';

try {
    $db = getDB();
    $row = $db->query("SELECT setting_value FROM settings WHERE setting_key='company_name' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['setting_value']) {
        $appName = $row['setting_value'];
        // Use first word as short name if name is long
        $parts = explode(' ', $appName);
        $appShortName = $parts[0];
    }
} catch (Exception $e) {
    // DB unavailable — use defaults
}

$base = rtrim(BASE_URL, '/');

$manifest = [
    'name'             => $appName,
    'short_name'       => $appShortName,
    'description'      => 'Car Yard Management — fleet, workshop, sales, inventory and finance in one place.',
    'start_url'        => $base . '/index.php?pwa=1',
    'scope'            => $base . '/',
    'id'               => $base . '/',
    'display'          => 'standalone',
    'display_override' => ['standalone', 'minimal-ui', 'browser'],
    'orientation'      => 'any',
    'theme_color'      => '#2563eb',
    'background_color' => '#f1f5f9',
    'lang'             => 'en',
    'dir'              => 'ltr',
    'categories'       => ['business', 'productivity'],

    'icons' => [
        // PNG icons are required by Chrome/Android for the beforeinstallprompt to fire
        [
            'src'     => $base . '/pwa-icon.php?s=192',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => $base . '/pwa-icon.php?s=512',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        // SVG fallbacks for browsers that support them
        [
            'src'     => $base . '/assets/images/icons/icon.svg',
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'any',
        ],
        [
            'src'     => $base . '/assets/images/icons/maskable.svg',
            'sizes'   => 'any',
            'type'    => 'image/svg+xml',
            'purpose' => 'maskable',
        ],
    ],

    'screenshots' => [],

    'shortcuts' => [
        [
            'name'        => 'Dashboard',
            'short_name'  => 'Dash',
            'description' => 'Go to the main dashboard',
            'url'         => $base . '/index.php?pwa=1',
            'icons'       => [['src' => $base . '/assets/images/icons/icon.svg', 'sizes' => 'any']],
        ],
        [
            'name'        => 'All Cars',
            'short_name'  => 'Cars',
            'description' => 'View the vehicle fleet',
            'url'         => $base . '/modules/cars/index.php',
            'icons'       => [['src' => $base . '/assets/images/icons/icon.svg', 'sizes' => 'any']],
        ],
        [
            'name'        => 'Job Cards',
            'short_name'  => 'Jobs',
            'description' => 'View workshop job cards',
            'url'         => $base . '/modules/jobs/index.php',
            'icons'       => [['src' => $base . '/assets/images/icons/icon.svg', 'sizes' => 'any']],
        ],
        [
            'name'        => 'Clients',
            'short_name'  => 'Clients',
            'description' => 'View client records',
            'url'         => $base . '/modules/clients/index.php',
            'icons'       => [['src' => $base . '/assets/images/icons/icon.svg', 'sizes' => 'any']],
        ],
    ],

    'prefer_related_applications' => false,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
