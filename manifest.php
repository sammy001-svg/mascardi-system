<?php
/**
 * PWA Web App Manifest — public resource, no auth required.
 * Output buffering ensures no stray byte corrupts the JSON response.
 */
ob_start();
require_once __DIR__ . '/config/app.php';
ob_end_clean(); // discard any accidental output from config/session setup

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate'); // always fresh so icon changes are picked up
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
        // Static PNGs (generated + cached by pwa-icon.php on first request)
        // Chrome/Android require genuine PNG icons for beforeinstallprompt to fire
        [
            'src'     => file_exists(BASE_PATH . '/assets/images/icons/icon-192.png')
                            ? $base . '/assets/images/icons/icon-192.png'
                            : $base . '/pwa-icon.php?s=192&v=4',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => file_exists(BASE_PATH . '/assets/images/icons/icon-192.png')
                            ? $base . '/assets/images/icons/icon-192.png'
                            : $base . '/pwa-icon.php?s=192&v=4',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src'     => file_exists(BASE_PATH . '/assets/images/icons/icon-512.png')
                            ? $base . '/assets/images/icons/icon-512.png'
                            : $base . '/pwa-icon.php?s=512&v=4',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src'     => file_exists(BASE_PATH . '/assets/images/icons/icon-512.png')
                            ? $base . '/assets/images/icons/icon-512.png'
                            : $base . '/pwa-icon.php?s=512&v=4',
            'sizes'   => '512x512',
            'type'    => 'image/png',
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
