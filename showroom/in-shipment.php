<?php
/**
 * In Shipment — vehicles currently on the water / in transit.
 *
 * This is the ONLY public page where status = 'in_transit' stock appears. Every
 * other showroom surface (homepage, vehicles listing, search, filters, compare,
 * similar-vehicles, nav make counts, sitemap) explicitly excludes 'in_transit',
 * and showroom/view.php redirects here if such a car is opened directly. Cards
 * are intentionally self-contained — they do not link to a detail page, so this
 * stock cannot leak into the normal browsing flow.
 */
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

$companyName   = getSetting('company_name',  'Mascardi Car Yard');
$companyPhone  = getSetting('company_phone', '');
$whatsappPhone = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', $companyPhone));

$filterMake = trim($_GET['make'] ?? '');

$where  = ["c.car_type IN ('inventory','sale_on_behalf')", "c.show_on_website = 1", "c.status = 'in_transit'"];
$params = [];
if ($filterMake) { $where[] = 'c.make = ?'; $params[] = $filterMake; }

try {
    $stmt = $db->prepare("
        SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
               c.transmission, c.fuel_type, c.asking_price, c.offer_price,
               c.mileage, c.engine_cc, c.description,
               (SELECT file_path FROM car_images WHERE car_id = c.id AND is_primary = 1 LIMIT 1) AS primary_image
        FROM cars c
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC
    ");
    $stmt->execute($params);
    $cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $cars = []; }

try {
    $makes = $db->query("
        SELECT DISTINCT make FROM cars
        WHERE car_type IN ('inventory','sale_on_behalf') AND show_on_website = 1
          AND status = 'in_transit' AND make IS NOT NULL AND make != ''
        ORDER BY make
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $_) { $makes = []; }

$total = count($cars);

$pageTitle    = 'Cars In Shipment';
$metaDesc     = "{$total} vehicle" . ($total === 1 ? '' : 's') . " currently in shipment to {$companyName}. "
              . "Reserve an incoming unit before it lands.";
$canonicalUrl = rtrim(BASE_URL, '/') . '/showroom/in-shipment.php';

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     PAGE HEAD
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);padding:64px 0 34px">
    <div class="lx-wrap">
        <div class="lx-label" style="margin-bottom:14px">
            <i class="fa fa-ship me-1"></i>Incoming Stock
        </div>
        <h1 class="lx-h2" style="font-size:clamp(32px,4.6vw,52px)">Cars in shipment</h1>
        <p style="max-width:620px;margin:18px 0 0;color:var(--ink-2);line-height:1.75;font-size:15px">
            These vehicles are on their way to us and are not yet available for viewing at the yard.
            Reserve one now and we will contact you the moment it arrives and clears.
        </p>
    </div>
</section>

<?php if ($makes): ?>
<!-- Make filter -->
<section style="background:var(--white);padding:0 0 26px">
    <div class="lx-wrap">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a href="<?= BASE_URL ?>/showroom/in-shipment.php" class="ish-chip <?= $filterMake === '' ? 'ish-chip-on' : '' ?>">
                All <span><?= $total ?></span>
            </a>
            <?php foreach ($makes as $mk): ?>
            <a href="?make=<?= urlencode($mk) ?>" class="ish-chip <?= $filterMake === $mk ? 'ish-chip-on' : '' ?>">
                <?= htmlspecialchars($mk) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     GRID
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--paper);padding:44px 0 84px">
    <div class="lx-wrap">

        <?php if (!$cars): ?>
        <div style="background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:64px 32px;text-align:center">
            <i class="fa fa-ship" style="font-size:38px;color:var(--line);display:block;margin-bottom:18px"></i>
            <h2 style="font-size:19px;font-weight:500;margin:0 0 8px">
                <?= $filterMake ? 'No ' . htmlspecialchars($filterMake) . ' units in shipment' : 'Nothing in shipment right now' ?>
            </h2>
            <p style="color:var(--ink-2);font-size:14px;margin:0 0 22px">
                Browse what is available at the yard today, or tell us what you are looking for.
            </p>
            <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>/showroom/vehicles.php" class="btn-lx">View Available Vehicles</a>
                <a href="<?= BASE_URL ?>/showroom/contact.php" class="btn-lx-ghost-dark">Request a Vehicle</a>
            </div>
        </div>

        <?php else: ?>
        <div style="margin-bottom:22px;font-size:13px;color:var(--ink-2)">
            <?= $total ?> vehicle<?= $total === 1 ? '' : 's' ?> in shipment<?= $filterMake ? ' · ' . htmlspecialchars($filterMake) : '' ?>
        </div>

        <div class="ish-grid">
            <?php foreach ($cars as $c):
                $title = trim($c['year'] . ' ' . $c['make'] . ' ' . $c['model']);
                $price = (!empty($c['offer_price']) && $c['offer_price'] > 0)
                       ? (float)$c['offer_price']
                       : ((!empty($c['asking_price']) && $c['asking_price'] > 0) ? (float)$c['asking_price'] : null);
                $bits = array_filter([
                    $c['body_type'] ?: null,
                    $c['transmission'] ? ucfirst($c['transmission']) : null,
                    $c['fuel_type'] ? ucfirst($c['fuel_type']) : null,
                    !empty($c['engine_cc']) ? number_format((int)$c['engine_cc']) . 'cc' : null,
                ]);
                $wa = urlencode(
                    "Hi, I'm interested in the {$title} that is currently in shipment"
                    . ($price ? ' (' . 'KES ' . number_format($price) . ')' : '')
                    . ". Could you share the expected arrival and reservation terms?"
                );
            ?>
            <article class="ish-card">
                <div class="ish-img">
                    <?php if ($c['primary_image']): ?>
                    <img src="<?= htmlspecialchars(thumbUrl('cars', $c['primary_image'])) ?>"
                         alt="<?= htmlspecialchars($title) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                    <div class="lx-noimg"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>
                    <span class="ish-badge"><i class="fa fa-ship"></i> In Shipment</span>
                </div>

                <div class="ish-body">
                    <h2 class="ish-title"><?= htmlspecialchars($title) ?></h2>
                    <?php if ($bits): ?>
                    <div class="ish-meta"><?= htmlspecialchars(implode(' · ', $bits)) ?></div>
                    <?php endif; ?>

                    <div class="ish-price">
                        <?= $price ? 'KES ' . number_format($price) : 'Price on arrival' ?>
                    </div>

                    <?php if (!empty($c['description'])): ?>
                    <p class="ish-desc"><?= htmlspecialchars(mb_strimwidth(trim($c['description']), 0, 130, '…')) ?></p>
                    <?php endif; ?>

                    <div class="ish-actions">
                        <?php if ($whatsappPhone): ?>
                        <a href="https://wa.me/<?= $whatsappPhone ?>?text=<?= $wa ?>" target="_blank" rel="noopener" class="ish-btn">
                            <i class="fa-brands fa-whatsapp"></i> Reserve this unit
                        </a>
                        <?php else: ?>
                        <a href="<?= BASE_URL ?>/showroom/contact.php" class="ish-btn">Enquire</a>
                        <?php endif; ?>
                        <?php if ($companyPhone): ?>
                        <a href="tel:<?= htmlspecialchars($companyPhone) ?>" class="ish-btn-sq" title="Call us">
                            <i class="fa fa-phone"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<style>
.ish-chip {
    display: inline-flex; align-items: center; gap: 7px;
    border: 1px solid var(--line); border-radius: var(--r);
    padding: 8px 16px; font-size: 12px; font-weight: 600;
    letter-spacing: .1em; text-transform: uppercase; color: var(--ink);
    transition: all .25s var(--ease);
}
.ish-chip span { font-size: 10px; color: var(--ink-3); }
.ish-chip:hover { border-color: var(--ink); color: var(--ink); }
.ish-chip-on { background: var(--ink); color: #fff; border-color: var(--ink); }
.ish-chip-on span { color: rgba(255,255,255,.6); }

.ish-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
@media (max-width: 1100px) { .ish-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px)  { .ish-grid { grid-template-columns: 1fr; } }

.ish-card {
    background: var(--white); border: 1px solid var(--line); border-radius: var(--r);
    overflow: hidden; display: flex; flex-direction: column;
    transition: box-shadow .35s var(--ease), border-color .35s var(--ease), transform .35s var(--ease);
}
.ish-card:hover { box-shadow: 0 24px 56px rgba(0,0,0,.10); border-color: #cfcfcd; transform: translateY(-3px); }

.ish-img { position: relative; aspect-ratio: 4/3; background: var(--paper); overflow: hidden; }
.ish-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
.ish-badge {
    position: absolute; top: 12px; left: 12px;
    background: rgba(17,17,17,.9); color: #fff;
    font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase;
    padding: 6px 11px; border-radius: var(--r);
    display: inline-flex; align-items: center; gap: 6px;
}

.ish-body { padding: 20px 20px 22px; display: flex; flex-direction: column; flex: 1; }
.ish-title { font-size: 16px; font-weight: 500; margin: 0 0 6px; letter-spacing: -.01em; }
.ish-meta  { font-size: 12.5px; color: var(--ink-3); margin-bottom: 12px; }
.ish-price { font-size: 18px; font-weight: 600; color: var(--ink); margin-bottom: 10px; }
.ish-desc  { font-size: 13px; color: var(--ink-2); line-height: 1.65; margin: 0 0 16px; }
.ish-actions { display: flex; gap: 8px; margin-top: auto; }
.ish-btn {
    flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    background: var(--ink); color: #fff; border: 1px solid var(--ink); border-radius: var(--r);
    padding: 12px 16px; font-size: 11.5px; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase; transition: background .25s var(--ease);
}
.ish-btn:hover { background: #000; color: #fff; }
.ish-btn-sq {
    width: 44px; display: inline-flex; align-items: center; justify-content: center;
    border: 1px solid var(--line); border-radius: var(--r); color: var(--ink);
    transition: border-color .25s var(--ease), color .25s var(--ease);
}
.ish-btn-sq:hover { border-color: var(--ink); }
</style>

<?php include __DIR__ . '/footer.php'; ?>
