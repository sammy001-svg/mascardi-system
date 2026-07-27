<?php
/**
 * XML Sitemap — public resource, no auth required.
 * Lists static showroom pages plus every vehicle currently visible on the
 * public website. Referenced from robots.txt.
 */
ob_start();
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
ob_end_clean();

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = rtrim(BASE_URL, '/');
$db   = getDB();

$staticPages = [
    ['loc' => $base . '/showroom/',                  'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $base . '/showroom/vehicles.php',       'priority' => '0.9', 'changefreq' => 'daily'],
    ['loc' => $base . '/showroom/compare.php',        'priority' => '0.4', 'changefreq' => 'weekly'],
    ['loc' => $base . '/showroom/contact.php',        'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => $base . '/showroom/book-service.php',   'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => $base . '/showroom/inquiry.php',        'priority' => '0.4', 'changefreq' => 'monthly'],
];

try {
    $cars = $db->query("
        SELECT id, updated_at
        FROM cars
        WHERE car_type IN ('inventory','sale_on_behalf') AND show_on_website = 1
          AND (status IS NULL OR status NOT IN ('delivered','sold'))
        ORDER BY updated_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $cars = [];
}

// Vehicle makes power the /showroom/vehicles.php?make=... landing pages
try {
    $makes = $db->query("
        SELECT DISTINCT make FROM cars
        WHERE car_type IN ('inventory','sale_on_behalf') AND show_on_website = 1 AND make != ''
          AND (status IS NULL OR status NOT IN ('delivered','sold'))
        ORDER BY make
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $makes = [];
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($staticPages as $p): ?>
    <url>
        <loc><?= htmlspecialchars($p['loc']) ?></loc>
        <changefreq><?= $p['changefreq'] ?></changefreq>
        <priority><?= $p['priority'] ?></priority>
    </url>
<?php endforeach; ?>
<?php foreach ($makes as $mk): ?>
    <url>
        <loc><?= htmlspecialchars($base . '/showroom/vehicles.php?make=' . urlencode($mk)) ?></loc>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
<?php endforeach; ?>
<?php foreach ($cars as $c): ?>
    <url>
        <loc><?= htmlspecialchars($base . '/showroom/view.php?id=' . (int)$c['id']) ?></loc>
        <?php if (!empty($c['updated_at'])): ?>
        <lastmod><?= date('Y-m-d', strtotime($c['updated_at'])) ?></lastmod>
        <?php endif; ?>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
<?php endforeach; ?>
</urlset>
