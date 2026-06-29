<?php
/**
 * Web App Manifest — public resource, no auth required.
 *
 * Icons point to committed static PNG files so Chrome always fetches
 * a genuine, uncorrupted PNG (required for beforeinstallprompt).
 */
// Buffer any accidental output from config (session start, warnings, etc.)
ob_start();
require_once __DIR__ . '/config/app.php';
ob_end_clean();

// Derive base URL (safe for sub-directory deployments)
$base = rtrim(BASE_URL, '/');

// App name from DB, fall back to constant
$appName      = defined('APP_NAME') ? APP_NAME : 'Mascardi';
$appShortName = 'Mascardi';
try {
    $row = getDB()->query(
        "SELECT setting_value FROM settings WHERE setting_key='company_name' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['setting_value'])) {
        $appName      = $row['setting_value'];
        $appShortName = explode(' ', $appName)[0];
    }
} catch (Exception $e) { /* use defaults */ }

// Static PNG paths (committed to git, always exist)
$icon192 = $base . '/assets/images/icons/icon-192.png';
$icon512 = $base . '/assets/images/icons/icon-512.png';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');  // 1-hour cache — short enough to pick up changes
header('X-Content-Type-Options: nosniff');

echo json_encode([
    'name'             => $appName,
    'short_name'       => $appShortName,
    'description'      => 'Car Yard Management — fleet, workshop, sales and finance.',
    'start_url'        => $base . '/index.php',
    'scope'            => $base . '/',
    'id'               => $base . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait-primary',
    'theme_color'      => '#2563eb',
    'background_color' => '#1e3a8a',
    'lang'             => 'en',

    'icons' => [
        ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],

    'shortcuts' => [
        ['name' => 'Dashboard', 'url' => $base . '/index.php',
         'icons' => [['src' => $icon192, 'sizes' => '192x192']]],
        ['name' => 'Cars',      'url' => $base . '/modules/cars/index.php',
         'icons' => [['src' => $icon192, 'sizes' => '192x192']]],
        ['name' => 'Job Cards', 'url' => $base . '/modules/jobs/index.php',
         'icons' => [['src' => $icon192, 'sizes' => '192x192']]],
    ],

    'prefer_related_applications' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
