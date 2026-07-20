<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

// Inline migrations — silent no-op if columns already exist
try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}

// ── Active filters ────────────────────────────────────────────────────────────
$filterMake    = trim($_GET['make']     ?? '');
$filterBody    = trim($_GET['body']     ?? '');
$filterFuel    = trim($_GET['fuel']     ?? '');
$filterTrans   = trim($_GET['trans']   ?? '');
$filterMin     = (int)($_GET['min']    ?? 0);
$filterMax     = (int)($_GET['max']    ?? 0);
$filterYearMin = (int)($_GET['year_min'] ?? 0);
$filterYearMax = (int)($_GET['year_max'] ?? 0);
$filterMileMax = (int)($_GET['mile_max'] ?? 0);
$sort          = $_GET['sort'] ?? 'featured';
$search        = trim($_GET['q']        ?? '');

// ── All inventory cars (stats + category counts) ──────────────────────────────
$allCars = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.offer_price, c.mileage,
           c.engine_cc, c.featured, c.notes, c.created_at, c.status,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c
    WHERE c.car_type='inventory' AND c.show_on_website = 1
      AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))
    ORDER BY c.featured DESC, c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalStock  = count($allCars);
$featuredAll = array_values(array_filter($allCars, fn($c) => $c['featured']));

// ── Category counts ───────────────────────────────────────────────────────────
$catCounts = [];
foreach ($allCars as $c) {
    $bt = $c['body_type'] ?: 'Other';
    $catCounts[$bt] = ($catCounts[$bt] ?? 0) + 1;
}
arsort($catCounts);

// ── Make list + year range for filters ───────────────────────────────────────
$makes = $db->query("
    SELECT DISTINCT make FROM cars
    WHERE car_type='inventory' AND make != ''
    ORDER BY make
")->fetchAll(PDO::FETCH_COLUMN);

$yearRange = $db->query("
    SELECT MIN(year) AS min_yr, MAX(year) AS max_yr
    FROM cars WHERE car_type='inventory' AND show_on_website=1 AND year > 0
")->fetch(PDO::FETCH_ASSOC) ?: ['min_yr' => 2000, 'max_yr' => date('Y')];

// ── Filtered inventory ────────────────────────────────────────────────────────
$where  = ["c.car_type='inventory'", "c.show_on_website = 1", "(c.status IS NULL OR c.status NOT IN ('delivered','sold'))"];
$params = [];
if ($filterMake)  { $where[] = 'c.make = ?';          $params[] = $filterMake; }
if ($filterBody)  { $where[] = 'c.body_type = ?';     $params[] = $filterBody; }
if ($filterFuel)  { $where[] = 'c.fuel_type = ?';     $params[] = $filterFuel; }
if ($filterTrans) { $where[] = 'c.transmission = ?';  $params[] = $filterTrans; }
// Price filter only applies to cars that have a price set
if ($filterMin)     { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price >= ?'; $params[] = $filterMin; }
if ($filterMax)     { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price <= ?'; $params[] = $filterMax; }
if ($filterYearMin) { $where[] = 'c.year >= ?'; $params[] = $filterYearMin; }
if ($filterYearMax) { $where[] = 'c.year <= ?'; $params[] = $filterYearMax; }
if ($filterMileMax) { $where[] = 'c.mileage IS NOT NULL AND c.mileage <= ?'; $params[] = $filterMileMax; }
if ($search) {
    $where[] = '(c.make LIKE ? OR c.model LIKE ? OR c.body_type LIKE ? OR c.color LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

// Price sorts put NULL/0 prices at the end (cars with price first)
$orderBy = match($sort) {
    'price_asc'  => '(c.asking_price IS NULL OR c.asking_price = 0) ASC, c.asking_price ASC',
    'price_desc' => '(c.asking_price IS NULL OR c.asking_price = 0) ASC, c.asking_price DESC',
    'year_desc'  => 'c.year DESC, c.created_at DESC',
    'newest'     => 'c.created_at DESC',
    default      => 'c.featured DESC, c.created_at DESC',
};

$stmt = $db->prepare("
    SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.offer_price, c.mileage,
           c.engine_cc, c.featured, c.notes, c.created_at, c.status,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy
");
$stmt->execute($params);
$filteredCars = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredCount = count($filteredCars);

$isFiltered = $filterMake || $filterBody || $filterFuel || $filterTrans || $filterMin || $filterMax
           || $filterYearMin || $filterYearMax || $filterMileMax || $search;
$companyName = getSetting('company_name', 'Mascardi Car Yard');
$__waClean   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
$pageTitle   = 'Quality Vehicles';
$metaDesc    = "Browse {$totalStock} quality vehicles at {$companyName}. Transparent pricing, flexible financing. Find your dream car today.";

// Feature blocks — top two featured cars (fallback: newest two)
$featureBlocks = array_slice($featuredAll ?: $allCars, 0, 2);

// Nav renders transparently over the video hero
$navOverlay = true;

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     HERO — full-bleed autoplay video
═══════════════════════════════════════════════════════════════ -->
<section id="hero" class="lx-hero">
    <div class="lx-video-cover">
        <iframe src="https://www.youtube-nocookie.com/embed/x7U4nFENdjU?autoplay=1&amp;mute=1&amp;controls=0&amp;loop=1&amp;playlist=x7U4nFENdjU&amp;playsinline=1&amp;rel=0&amp;iv_load_policy=3&amp;disablekb=1&amp;fs=0"
                title="" allow="autoplay; encrypted-media" tabindex="-1"
                referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="lx-hero-shade"></div>

    <div class="lx-hero-content">
        <div class="lx-label" style="color:rgba(255,255,255,.65);margin-bottom:18px"><?= $totalStock ?> Vehicles Available Now</div>
        <h1>Extraordinary cars.<br>Effortless ownership.</h1>
        <p>Quality imported vehicles, transparent pricing and flexible financing — hand-inspected and ready for the road.</p>
        <div class="lx-hero-ctas">
            <a href="#inventory" class="btn-lx-light">Explore Inventory</a>
            <?php if ($__waClean): ?>
            <a href="https://wa.me/<?= $__waClean ?>?text=<?= urlencode('Hi, I\'d like to book a test drive.') ?>"
               target="_blank" rel="noopener" class="btn-lx-ghost">Book a Test Drive</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="lx-hero-scroll" aria-hidden="true">
        <span></span>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     STAT STRIP
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);border-bottom:1px solid var(--line)">
    <div class="lx-wrap">
        <div class="lx-stats">
            <?php foreach ([
                [$totalStock . '+',        'Vehicles in Stock'],
                [count($catCounts),        'Body Types'],
                ['100%',                   'Inspected & Verified'],
                ['Finance',                'Flexible Plans Available'],
            ] as [$val, $lbl]): ?>
            <div class="lx-stat">
                <div class="v"><?= $val ?></div>
                <div class="l"><?= $lbl ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     DUAL FEATURE BLOCKS — flagship vehicles
═══════════════════════════════════════════════════════════════ -->
<?php if ($featureBlocks): ?>
<section style="background:var(--paper);padding:96px 0">
    <div class="lx-wrap">
        <div style="text-align:center;margin-bottom:64px">
            <div class="lx-label" style="margin-bottom:14px">Featured</div>
            <h2 class="lx-h2">Handpicked from our showroom</h2>
        </div>

        <div class="lx-feature-grid">
            <?php foreach ($featureBlocks as $fb):
                $fbImg   = $fb['primary_image'] ? thumbUrl('cars', $fb['primary_image']) : null;
                $fbPrice = (!empty($fb['offer_price']) && $fb['offer_price'] > 0) ? (float)$fb['offer_price'] : (float)($fb['asking_price'] ?? 0);
                $fbIsOffer = !empty($fb['offer_price']) && $fb['offer_price'] > 0;
                $fbWa    = urlencode("Hi, I'm interested in the {$fb['year']} {$fb['make']} {$fb['model']}.");
            ?>
            <div class="lx-feature">
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fb['id'] ?>" class="lx-feature-img">
                    <?php if ($fbImg): ?>
                    <img src="<?= htmlspecialchars($fbImg) ?>" alt="<?= htmlspecialchars($fb['make'].' '.$fb['model']) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                    <div class="lx-noimg"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>
                </a>
                <div class="lx-feature-body">
                    <div class="lx-label" style="margin-bottom:8px"><?= $fb['year'] ?><?= $fb['body_type'] ? ' · ' . htmlspecialchars($fb['body_type']) : '' ?></div>
                    <h3><?= htmlspecialchars($fb['make'] . ' ' . $fb['model']) ?></h3>
                    <div class="lx-feature-price">
                        <?php if ($fbPrice > 0): ?>
                            <?= $fbIsOffer ? '<span class="offer-tag">Offer</span>' : 'From' ?>
                            KES <?= number_format($fbPrice) ?>
                            <?php if ($fbIsOffer && !empty($fb['asking_price'])): ?>
                            <del>KES <?= number_format((float)$fb['asking_price']) ?></del>
                            <?php endif; ?>
                        <?php else: ?>
                            Price on request
                        <?php endif; ?>
                    </div>
                    <div class="lx-feature-specs">
                        <?php if ($fb['mileage']): ?>
                        <div><div class="sv"><?= number_format($fb['mileage']) ?></div><div class="sl">km</div></div>
                        <?php endif; ?>
                        <?php if ($fb['engine_cc']): ?>
                        <div><div class="sv"><?= number_format($fb['engine_cc']) ?></div><div class="sl">cc</div></div>
                        <?php endif; ?>
                        <?php if ($fb['transmission']): ?>
                        <div><div class="sv"><?= ucfirst($fb['transmission']) ?></div><div class="sl">Transmission</div></div>
                        <?php endif; ?>
                        <?php if ($fb['fuel_type']): ?>
                        <div><div class="sv"><?= ucfirst($fb['fuel_type']) ?></div><div class="sl">Fuel</div></div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fb['id'] ?>" class="btn-lx">View Details</a>
                        <?php if ($__waClean): ?>
                        <a href="https://wa.me/<?= $__waClean ?>?text=<?= $fbWa ?>" target="_blank" rel="noopener" class="btn-lx-ghost-dark">Enquire</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     BRAND STATEMENT
═══════════════════════════════════════════════════════════════ -->
<section id="story" style="background:var(--white);padding:110px 0;text-align:center">
    <div class="lx-wrap" style="max-width:900px">
        <h2 class="lx-h2" style="font-size:clamp(30px,4.6vw,54px)">
            Sourced worldwide.<br>
            Inspected in Nairobi.<br>
            <span style="color:var(--bronze)">Ready for your road.</span>
        </h2>
        <p style="font-size:16px;color:var(--ink-2);line-height:1.8;max-width:560px;margin:28px auto 0">
            Every vehicle at <?= htmlspecialchars($companyName) ?> passes a thorough multi-point
            inspection before it reaches the showroom floor. What you see is exactly what you get —
            no hidden fees, no surprises.
        </p>
    </div>

    <div class="lx-wrap" style="margin-top:64px">
        <div class="lx-promo-grid">
            <a href="<?= BASE_URL ?>/showroom/?sort=price_asc#inventory" class="lx-promo">
                <div class="lx-label" style="margin-bottom:10px">Current Offers</div>
                <div class="t">Explore vehicles on offer</div>
                <span class="arr"><i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="<?= BASE_URL ?>/showroom/contact.php" class="lx-promo">
                <div class="lx-label" style="margin-bottom:10px">Visit Us</div>
                <div class="t">Find our showroom</div>
                <span class="arr"><i class="fa fa-arrow-right"></i></span>
            </a>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     SECOND VIDEO — brand film
═══════════════════════════════════════════════════════════════ -->
<section class="lx-film">
    <div class="lx-video-cover">
        <iframe src="https://www.youtube-nocookie.com/embed/P3JHd_cHHCM?autoplay=1&amp;mute=1&amp;controls=0&amp;loop=1&amp;playlist=P3JHd_cHHCM&amp;playsinline=1&amp;rel=0&amp;iv_load_policy=3&amp;disablekb=1&amp;fs=0"
                title="" allow="autoplay; encrypted-media" tabindex="-1" loading="lazy"
                referrerpolicy="no-referrer"></iframe>
    </div>
    <div class="lx-film-shade"></div>
    <div class="lx-film-caption">
        <div class="lx-label" style="color:rgba(255,255,255,.65);margin-bottom:14px">The Mascardi Experience</div>
        <h2 style="font-size:clamp(26px,3.6vw,44px);font-weight:300;color:#fff;margin:0 0 26px;line-height:1.15">
            A new standard for<br>car buying in Kenya
        </h2>
        <a href="#inventory" class="btn-lx-light">Browse the Collection</a>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FULL INVENTORY
═══════════════════════════════════════════════════════════════ -->
<section id="inventory" style="background:var(--paper);padding:96px 0">
    <div class="lx-wrap">

        <!-- Section header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-bottom:44px">
            <div>
                <div class="lx-label" style="margin-bottom:12px">Inventory</div>
                <h2 class="lx-h2">All available vehicles</h2>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                <span style="font-size:13px;color:var(--ink-2)">
                    <strong style="color:var(--ink);font-weight:600"><?= $filteredCount ?></strong> vehicle<?= $filteredCount !== 1 ? 's' : '' ?>
                    <?= $isFiltered ? 'found' : 'available' ?>
                </span>
                <?php if ($isFiltered): ?>
                <a href="<?= BASE_URL ?>/showroom/#inventory" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:600;color:var(--ink);border:1px solid var(--ink);border-radius:var(--r);padding:7px 14px">
                    Clear Filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="lx-inv-layout">

            <!-- ── Sidebar Filters ─────────────────────── -->
            <div class="lx-filter">
                <div class="lx-filter-head">
                    <span class="lx-label" style="color:var(--ink)">Filter</span>
                    <?php if ($isFiltered): ?>
                    <a href="<?= BASE_URL ?>/showroom/#inventory" style="font-size:11px;color:var(--ink-3);letter-spacing:.1em;text-transform:uppercase">Reset</a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="#inventory" style="padding:22px;display:flex;flex-direction:column;gap:22px">
                    <?php if ($sort !== 'featured'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>

                    <div>
                        <label class="lx-flabel">Search</label>
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Make, model, colour…" class="lx-input">
                    </div>

                    <?php
                    $filterGroups = [
                        ['Make', 'make', $makes, 'All Makes', $filterMake],
                        ['Body Type', 'body', ['SUV','Saloon','Pick-Up','Hatchback','Van','Truck','Coupe','Bus','Minibus','Other'], 'All Types', $filterBody],
                        ['Fuel Type', 'fuel', ['petrol','diesel','hybrid','electric'], 'All Fuel Types', $filterFuel, true],
                        ['Transmission', 'trans', ['automatic','manual','cvt'], 'All Types', $filterTrans, true],
                    ];
                    foreach ($filterGroups as $fg):
                    [$label, $name, $options, $placeholder, $current] = $fg;
                    $ucfirst = $fg[5] ?? false;
                    ?>
                    <div>
                        <label class="lx-flabel"><?= $label ?></label>
                        <select name="<?= $name ?>" class="lx-input" onchange="this.form.submit()">
                            <option value=""><?= $placeholder ?></option>
                            <?php foreach ($options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $current === $opt ? 'selected' : '' ?>>
                                <?= $ucfirst ? ucfirst($opt) : htmlspecialchars($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>

                    <div>
                        <label class="lx-flabel">Price Range (KES)</label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="number" name="min" value="<?= $filterMin ?: '' ?>" placeholder="Min" class="lx-input" style="width:0;min-width:0;flex:1">
                            <span style="color:var(--ink-3);font-size:12px">–</span>
                            <input type="number" name="max" value="<?= $filterMax ?: '' ?>" placeholder="Max" class="lx-input" style="width:0;min-width:0;flex:1">
                        </div>
                    </div>

                    <div>
                        <label class="lx-flabel">Year</label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="number" name="year_min" value="<?= $filterYearMin ?: '' ?>" placeholder="<?= $yearRange['min_yr'] ?>"
                                   min="<?= $yearRange['min_yr'] ?>" max="<?= $yearRange['max_yr'] ?>" class="lx-input" style="width:0;min-width:0;flex:1">
                            <span style="color:var(--ink-3);font-size:12px">–</span>
                            <input type="number" name="year_max" value="<?= $filterYearMax ?: '' ?>" placeholder="<?= $yearRange['max_yr'] ?>"
                                   min="<?= $yearRange['min_yr'] ?>" max="<?= $yearRange['max_yr'] ?>" class="lx-input" style="width:0;min-width:0;flex:1">
                        </div>
                    </div>

                    <div>
                        <label class="lx-flabel">Max Mileage (km)</label>
                        <input type="number" name="mile_max" value="<?= $filterMileMax ?: '' ?>" placeholder="e.g. 80000" min="0" class="lx-input">
                    </div>

                    <button type="submit" class="btn-lx" style="width:100%">Apply Filters</button>
                </form>
            </div>

            <!-- ── Car Grid ─────────────────────────────── -->
            <div>
                <!-- Sort + count bar -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;flex-wrap:wrap;gap:12px">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                        <div style="font-size:13px;color:var(--ink-2)">
                            Showing <strong style="color:var(--ink);font-weight:600"><?= $filteredCount ?></strong> of <strong style="color:var(--ink);font-weight:600"><?= $totalStock ?></strong>
                        </div>
                        <button id="favFilterBtn" onclick="toggleFavFilter()"
                                style="background:none;border:1px solid var(--line);border-radius:var(--r);padding:6px 14px;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink);cursor:pointer;display:flex;align-items:center;gap:6px;font-family:inherit">
                            <i class="fa fa-heart" style="font-size:11px"></i> Saved
                            <span id="favCount" style="display:none;background:var(--ink);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px">0</span>
                        </button>
                    </div>
                    <form method="GET" action="#inventory" style="display:flex;align-items:center;gap:10px">
                        <?php foreach (['make','body','fuel','trans','q'] as $k): ?>
                        <?php if (isset($_GET[$k]) && $_GET[$k] !== ''): ?>
                        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k]) ?>">
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($filterMin):     ?><input type="hidden" name="min"      value="<?= $filterMin ?>"><?php endif; ?>
                        <?php if ($filterMax):     ?><input type="hidden" name="max"      value="<?= $filterMax ?>"><?php endif; ?>
                        <?php if ($filterYearMin): ?><input type="hidden" name="year_min" value="<?= $filterYearMin ?>"><?php endif; ?>
                        <?php if ($filterYearMax): ?><input type="hidden" name="year_max" value="<?= $filterYearMax ?>"><?php endif; ?>
                        <?php if ($filterMileMax): ?><input type="hidden" name="mile_max" value="<?= $filterMileMax ?>"><?php endif; ?>
                        <span class="lx-flabel" style="margin:0;white-space:nowrap">Sort</span>
                        <select name="sort" class="lx-input" style="width:auto;padding:8px 12px" onchange="this.form.submit()">
                            <option value="featured"   <?= $sort==='featured'   ?'selected':'' ?>>Featured First</option>
                            <option value="newest"     <?= $sort==='newest'     ?'selected':'' ?>>Newest Arrivals</option>
                            <option value="price_asc"  <?= $sort==='price_asc'  ?'selected':'' ?>>Price: Low to High</option>
                            <option value="price_desc" <?= $sort==='price_desc' ?'selected':'' ?>>Price: High to Low</option>
                            <option value="year_desc"  <?= $sort==='year_desc'  ?'selected':'' ?>>Year: Newest First</option>
                        </select>
                    </form>
                </div>

                <?php if (!$filteredCars): ?>
                <!-- Empty state -->
                <div style="background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:72px 24px;text-align:center">
                    <div class="lx-label" style="margin-bottom:14px">No Results</div>
                    <h3 style="font-size:22px;font-weight:400;margin:0 0 10px">No vehicles found</h3>
                    <p style="color:var(--ink-2);font-size:14px;margin:0 0 26px">Try adjusting your filters or clearing your search.</p>
                    <a href="<?= BASE_URL ?>/showroom/#inventory" class="btn-lx">View All Vehicles</a>
                </div>
                <?php else: ?>

                <!-- Car grid -->
                <div class="inv-grid">
                    <?php foreach ($filteredCars as $car):
                        $img = $car['primary_image'] ? thumbUrl('cars', $car['primary_image']) : null;
                        $waMsg = urlencode("Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} (KES " . number_format((float)$car['asking_price']) . ") listed on your showroom.");
                        $isNew      = strtotime($car['created_at']) > strtotime('-30 days');
                        $isReserved = ($car['status'] ?? '') === 'reserved';
                    ?>
                    <div class="inv-card<?= $isReserved ? ' inv-card-reserved' : '' ?>" data-car-id="<?= $car['id'] ?>" data-car-name="<?= htmlspecialchars($car['year'].' '.$car['make'].' '.$car['model']) ?>">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="inv-img-wrap">
                            <?php if ($isReserved): ?>
                            <span class="inv-badge">Reserved</span>
                            <?php elseif ($car['featured']): ?>
                            <span class="inv-badge inv-badge-bronze">Featured</span>
                            <?php elseif ($isNew): ?>
                            <span class="inv-badge">New Arrival</span>
                            <?php endif; ?>
                            <?php if ($car['image_count'] > 1): ?>
                            <span class="inv-photos"><i class="fa fa-images"></i> <?= $car['image_count'] ?></span>
                            <?php endif; ?>
                            <?php if ($img): ?>
                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($car['make'].' '.$car['model']) ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                            <div class="lx-noimg"><i class="fa fa-car-side"></i></div>
                            <?php endif; ?>
                        </a>
                        <div class="inv-body">
                            <div class="inv-meta"><?= $car['year'] ?><?= $car['body_type'] ? ' · ' . htmlspecialchars($car['body_type']) : '' ?></div>
                            <h3 class="inv-title">
                                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>">
                                    <?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>
                                </a>
                            </h3>
                            <div class="inv-specs">
                                <?php if ($car['mileage']):      ?><span><i class="fa fa-gauge-high"></i><?= number_format($car['mileage']) ?> km</span><?php endif; ?>
                                <?php if ($car['transmission']): ?><span><i class="fa fa-gears"></i><?= ucfirst($car['transmission']) ?></span><?php endif; ?>
                                <?php if ($car['fuel_type']):    ?><span><i class="fa fa-gas-pump"></i><?= ucfirst($car['fuel_type']) ?></span><?php endif; ?>
                                <?php if ($car['engine_cc']):    ?><span><i class="fa fa-car-side"></i><?= number_format($car['engine_cc']) ?> cc</span><?php endif; ?>
                            </div>
                            <div class="inv-price">
                                <?php if ($isReserved): ?>
                                    <span class="reserved-tag">Reserved</span>
                                    <?php if (!empty($car['asking_price']) && $car['asking_price'] > 0): ?>
                                    <span style="font-size:13px;color:var(--ink-3);font-weight:400;margin-left:8px">KES <?= number_format((float)$car['asking_price']) ?></span>
                                    <?php endif; ?>
                                <?php elseif (!empty($car['offer_price']) && $car['offer_price'] > 0): ?>
                                    <span class="offer-tag">Offer</span>
                                    KES <?= number_format((float)$car['offer_price']) ?>
                                    <?php if (!empty($car['asking_price']) && $car['asking_price'] > 0): ?>
                                    <del>KES <?= number_format((float)$car['asking_price']) ?></del>
                                    <?php endif; ?>
                                <?php elseif (!empty($car['asking_price']) && $car['asking_price'] > 0): ?>
                                    KES <?= number_format((float)$car['asking_price']) ?>
                                <?php else: ?>
                                    <span style="font-weight:400;color:var(--ink-2)">Price on request</span>
                                <?php endif; ?>
                            </div>
                            <div class="inv-actions">
                                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="inv-btn-view">View Details</a>
                                <?php if (!$isReserved && $__waClean): ?>
                                <a href="https://wa.me/<?= $__waClean ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="inv-btn-icon" title="Enquire on WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                                <?php endif; ?>
                                <button class="fav-btn inv-btn-icon" data-id="<?= $car['id'] ?>"
                                        onclick="toggleFav(<?= $car['id'] ?>,this)" title="Save to favorites">
                                    <i class="fa-regular fa-heart"></i>
                                </button>
                                <button class="cmp-btn inv-btn-icon" data-id="<?= $car['id'] ?>"
                                        onclick="toggleCompare(<?= $car['id'] ?>,this)" title="Add to compare">
                                    <i class="fa fa-scale-balanced"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </div><!-- /car grid col -->
        </div><!-- /grid layout -->
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     WHY MASCARDI
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);padding:100px 0;border-top:1px solid var(--line)">
    <div class="lx-wrap">
        <div style="text-align:center;margin-bottom:64px">
            <div class="lx-label" style="margin-bottom:14px">Our Commitment</div>
            <h2 class="lx-h2">Why choose <?= htmlspecialchars($companyName) ?></h2>
        </div>
        <div class="lx-values">
            <?php foreach ([
                ['fa-shield-halved', 'Quality Assured',     'Every vehicle undergoes a thorough inspection before listing. What you see is exactly what you get.'],
                ['fa-eye',           'Transparent Pricing', 'No hidden fees, no surprises. Our asking price is our final price, with a full cost breakdown available.'],
                ['fa-credit-card',   'Flexible Financing',  'We work with leading financiers to offer payment plans tailored to your budget.'],
                ['fa-rotate',        'Trade-In Welcome',    'Get a fair market value assessment on your current vehicle and upgrade with ease.'],
                ['fa-headset',       'Expert Guidance',     'Our team guides you through every step of the purchase, from viewing to logbook transfer.'],
                ['fa-truck',         'Nationwide Delivery', 'We arrange safe delivery of your vehicle to any location across the country.'],
            ] as [$ico, $title, $desc]): ?>
            <div class="lx-value">
                <i class="fa <?= $ico ?>"></i>
                <div class="t"><?= $title ?></div>
                <p><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     BOOK A SERVICE
═══════════════════════════════════════════════════════════════ -->
<section id="book-service" style="background:var(--paper);padding:96px 0;border-top:1px solid var(--line)">
    <div class="lx-wrap">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <div class="lx-label" style="margin-bottom:14px">Ownership</div>
                <h2 class="lx-h2" style="margin-bottom:18px">Book your vehicle<br>service online</h2>
                <p style="font-size:15px;color:var(--ink-2);line-height:1.75;margin:0 0 36px;max-width:420px">
                    No need to call. Choose a date, describe the issue, and we'll confirm your
                    workshop slot — fast.
                </p>
                <div class="lx-service-list">
                    <?php foreach (['Engine Service','Major Service','Diagnostics','Paint Job','Body Work','Buffing'] as $svc): ?>
                    <div><?= $svc ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-lg-7">
                <div style="background:var(--white);border:1px solid var(--line);border-radius:var(--r);padding:40px 36px">
                    <div class="lx-label" style="margin-bottom:6px">Quick Booking</div>
                    <p style="font-size:13px;color:var(--ink-3);margin:0 0 26px">Fill in your details and we'll get back to you right away.</p>

                    <form method="GET" action="<?= BASE_URL ?>/showroom/book-service.php" style="display:grid;gap:18px">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
                            <div>
                                <label class="lx-flabel">Full Name *</label>
                                <input type="text" name="name" placeholder="Your full name" required class="lx-input">
                            </div>
                            <div>
                                <label class="lx-flabel">Phone *</label>
                                <input type="tel" name="phone" placeholder="e.g. 0712 345 678" required class="lx-input">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
                            <div>
                                <label class="lx-flabel">Service Needed *</label>
                                <select name="service" required class="lx-input">
                                    <option value="">Select service…</option>
                                    <option>Engine Service</option>
                                    <option>Major Service</option>
                                    <option>Diagnostics</option>
                                    <option>Paint Job</option>
                                    <option>Body Work</option>
                                    <option>Buffing</option>
                                </select>
                            </div>
                            <div>
                                <label class="lx-flabel">Preferred Date</label>
                                <input type="date" name="date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" class="lx-input">
                            </div>
                        </div>
                        <div>
                            <label class="lx-flabel">Car Reg. (optional)</label>
                            <input type="text" name="reg" placeholder="e.g. KDA 000Q" class="lx-input"
                                   style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <button type="submit" class="btn-lx" style="width:100%">Continue &amp; Confirm Booking</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     CTA BAND
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--black);padding:88px 0;text-align:center">
    <div class="lx-wrap">
        <h2 style="font-size:clamp(26px,3.6vw,44px);font-weight:300;color:#fff;margin:0 0 14px">Ready to find your car?</h2>
        <p style="font-size:15px;color:rgba(255,255,255,.55);margin:0 0 36px">Talk to our team today. We're here to help.</p>
        <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
            <?php if ($__waClean): ?>
            <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener" class="btn-lx-light">
                <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
            </a>
            <?php endif; ?>
            <a href="#inventory" class="btn-lx-ghost">Browse Vehicles</a>
        </div>
    </div>
</section>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
.lx-wrap { max-width: 1320px; margin: 0 auto; padding: 0 28px; }

/* ── Cropped, chrome-free YouTube cover video ─────────────────
   The iframe is oversized + scaled so YouTube UI/watermark falls
   outside the visible crop; pointer-events off = no hover chrome. */
.lx-video-cover { position: absolute; inset: 0; overflow: hidden; background: var(--black); pointer-events: none; }
.lx-video-cover iframe {
    position: absolute; top: 50%; left: 50%;
    width:  max(100%, calc(100vh * 1.7778), 177.78vh);
    height: max(100%, 56.25vw);
    min-width: 100%; min-height: 100%;
    transform: translate(-50%, -50%) scale(1.3);
    border: 0;
}

/* Hero */
.lx-hero { position: relative; min-height: 100vh; display: flex; align-items: flex-end; overflow: hidden; }
.lx-hero-shade {
    position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(to top, rgba(6,6,6,.78) 0%, rgba(6,6,6,.25) 45%, rgba(6,6,6,.35) 100%);
}
.lx-hero-content {
    position: relative; z-index: 2;
    width: 100%; max-width: 1320px; margin: 0 auto;
    padding: 0 28px 110px;
}
.lx-hero-content h1 {
    font-size: clamp(36px, 5.4vw, 68px); font-weight: 300; letter-spacing: -.01em;
    color: #fff; line-height: 1.08; margin: 0 0 20px;
}
.lx-hero-content p { font-size: 16px; color: rgba(255,255,255,.72); max-width: 460px; line-height: 1.7; margin: 0 0 36px; }
.lx-hero-ctas { display: flex; gap: 14px; flex-wrap: wrap; }
.lx-hero-scroll { position: absolute; bottom: 34px; right: 44px; z-index: 2; }
.lx-hero-scroll span {
    display: block; width: 1px; height: 56px;
    background: linear-gradient(to bottom, rgba(255,255,255,0), rgba(255,255,255,.7));
    animation: lxScroll 2.2s var(--ease) infinite;
}
@keyframes lxScroll { 0% { transform: scaleY(0); transform-origin: top; } 45% { transform: scaleY(1); transform-origin: top; } 55% { transform: scaleY(1); transform-origin: bottom; } 100% { transform: scaleY(0); transform-origin: bottom; } }

/* Stat strip */
.lx-stats { display: grid; grid-template-columns: repeat(4, 1fr); }
.lx-stat { padding: 40px 24px; text-align: center; border-right: 1px solid var(--line); }
.lx-stat:last-child { border-right: none; }
.lx-stat .v { font-size: 30px; font-weight: 300; letter-spacing: -.01em; color: var(--ink); margin-bottom: 6px; }
.lx-stat .l { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .16em; color: var(--ink-3); }
@media (max-width: 768px) {
    .lx-stats { grid-template-columns: 1fr 1fr; }
    .lx-stat:nth-child(2) { border-right: none; }
    .lx-stat { border-bottom: 1px solid var(--line); }
    .lx-stat:nth-child(n+3) { border-bottom: none; }
}

/* Dual feature blocks */
.lx-feature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 32px; }
@media (max-width: 991px) { .lx-feature-grid { grid-template-columns: 1fr; } }
.lx-feature { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; display: flex; flex-direction: column; }
.lx-feature-img { display: block; position: relative; aspect-ratio: 16/9; overflow: hidden; background: var(--paper); }
.lx-feature-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s var(--ease); }
.lx-feature:hover .lx-feature-img img { transform: scale(1.03); }
.lx-feature-body { padding: 34px 36px 38px; flex: 1; display: flex; flex-direction: column; }
.lx-feature-body h3 { font-size: 27px; font-weight: 400; letter-spacing: -.01em; margin: 0 0 8px; }
.lx-feature-price { font-size: 15px; font-weight: 500; color: var(--ink); margin-bottom: 26px; }
.lx-feature-price del { color: var(--ink-3); font-weight: 400; margin-left: 8px; font-size: 13px; }
.lx-feature-specs { display: flex; gap: 0; margin-bottom: 30px; flex-wrap: wrap; }
.lx-feature-specs > div { padding: 0 26px; border-right: 1px solid var(--line); }
.lx-feature-specs > div:first-child { padding-left: 0; }
.lx-feature-specs > div:last-child { border-right: none; }
.lx-feature-specs .sv { font-size: 20px; font-weight: 300; color: var(--ink); }
.lx-feature-specs .sl { font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .14em; color: var(--ink-3); margin-top: 3px; }

/* Promo cards */
.lx-promo-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
@media (max-width: 768px) { .lx-promo-grid { grid-template-columns: 1fr; } }
.lx-promo {
    position: relative; display: block; text-align: left;
    background: var(--paper); border: 1px solid var(--line); border-radius: var(--r);
    padding: 40px 44px; transition: border-color .3s var(--ease);
}
.lx-promo:hover { border-color: var(--ink); }
.lx-promo .t { font-size: 21px; font-weight: 400; color: var(--ink); }
.lx-promo .arr { position: absolute; right: 36px; top: 50%; transform: translateY(-50%); color: var(--ink-3); font-size: 15px; transition: transform .3s var(--ease), color .3s var(--ease); }
.lx-promo:hover .arr { transform: translateY(-50%) translateX(6px); color: var(--ink); }

/* Brand film */
.lx-film { position: relative; height: min(86vh, 780px); min-height: 480px; overflow: hidden; display: flex; align-items: center; }
.lx-film-shade { position: absolute; inset: 0; background: rgba(6,6,6,.42); pointer-events: none; }
.lx-film-caption { position: relative; z-index: 2; width: 100%; max-width: 1320px; margin: 0 auto; padding: 0 28px; }

/* No-image placeholder */
.lx-noimg { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 52px; color: var(--line); background: var(--paper); }

/* Inventory layout */
.lx-inv-layout { display: grid; grid-template-columns: 280px 1fr; gap: 32px; align-items: start; }
@media (max-width: 1024px) { .lx-inv-layout { grid-template-columns: 1fr; } .lx-filter { position: static !important; } }
.lx-filter { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); position: sticky; top: calc(var(--nav-h) + 20px); }
.lx-filter-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 22px; border-bottom: 1px solid var(--line); }
.lx-flabel { display: block; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .16em; color: var(--ink-3); margin-bottom: 8px; }
.lx-input {
    width: 100%; border: 1px solid var(--line); border-radius: var(--r);
    padding: 10px 13px; font-size: 13.5px; font-family: inherit; color: var(--ink);
    background: var(--white); outline: none; transition: border-color .25s var(--ease);
}
.lx-input:focus { border-color: var(--ink); }
select.lx-input { cursor: pointer; }

/* Inventory cards */
.inv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
.inv-card { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .35s var(--ease), border-color .35s var(--ease); }
.inv-card:hover { box-shadow: 0 22px 48px rgba(0,0,0,.09); border-color: #d5d1ca; }
.inv-img-wrap { display: block; position: relative; aspect-ratio: 16/10; overflow: hidden; background: var(--paper); flex-shrink: 0; }
.inv-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s var(--ease); }
.inv-card:hover .inv-img-wrap img { transform: scale(1.04); }
.inv-badge {
    position: absolute; top: 14px; left: 14px; z-index: 1;
    background: rgba(12,12,12,.82); color: #fff;
    font-size: 10px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 5px 12px; border-radius: var(--r);
}
.inv-badge-bronze { background: var(--bronze); }
.inv-photos { position: absolute; top: 14px; right: 14px; z-index: 1; background: rgba(12,12,12,.55); color: #fff; font-size: 10.5px; font-weight: 500; padding: 3px 9px; border-radius: var(--r); }
.inv-card-reserved { opacity: .82; }
.inv-body { padding: 22px 24px 24px; flex: 1; display: flex; flex-direction: column; }
.inv-meta { font-size: 10.5px; color: var(--ink-3); font-weight: 600; text-transform: uppercase; letter-spacing: .16em; margin-bottom: 6px; }
.inv-title { font-size: 18px; font-weight: 500; letter-spacing: -.01em; margin: 0 0 14px; }
.inv-title a { color: var(--ink); }
.inv-title a:hover { color: var(--bronze); }
.inv-specs { display: flex; flex-wrap: wrap; gap: 12px 16px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
.inv-specs span { font-size: 12px; color: var(--ink-2); display: inline-flex; align-items: center; gap: 7px; }
.inv-specs i { font-size: 11px; color: var(--ink-3); }
.inv-price { font-size: 17px; font-weight: 600; letter-spacing: -.01em; color: var(--ink); margin-top: auto; margin-bottom: 16px; }
.inv-price del { font-size: 12.5px; color: var(--ink-3); font-weight: 400; margin-left: 8px; }
.offer-tag, .reserved-tag {
    display: inline-block; font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 3px 9px; border-radius: var(--r); vertical-align: 2px; margin-right: 7px;
}
.offer-tag { background: var(--bronze); color: #fff; }
.reserved-tag { background: var(--ink); color: #fff; }
.inv-actions { display: flex; gap: 8px; }
.inv-btn-view {
    flex: 1; background: var(--ink); color: #fff; border-radius: var(--r);
    padding: 11px 14px; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    text-align: center; display: flex; align-items: center; justify-content: center;
    transition: background .25s var(--ease);
}
.inv-btn-view:hover { background: #000; color: #fff; }
.inv-btn-icon {
    width: 40px; height: 40px; background: var(--white); color: var(--ink-2);
    border: 1px solid var(--line); border-radius: var(--r);
    display: flex; align-items: center; justify-content: center; font-size: 15px;
    cursor: pointer; transition: all .25s var(--ease); flex-shrink: 0; text-decoration: none;
}
.inv-btn-icon:hover { border-color: var(--ink); color: var(--ink); }
.inv-btn-icon.active-fav { background: var(--ink); color: #fff; border-color: var(--ink); }
.inv-btn-icon.active-cmp { background: var(--bronze); color: #fff; border-color: var(--bronze); }

/* Values */
.lx-values { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border-top: 1px solid var(--line); border-left: 1px solid var(--line); }
@media (max-width: 991px) { .lx-values { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px) { .lx-values { grid-template-columns: 1fr; } }
.lx-value { padding: 40px 36px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.lx-value i { font-size: 20px; color: var(--bronze); margin-bottom: 18px; display: block; }
.lx-value .t { font-size: 16px; font-weight: 500; margin-bottom: 10px; color: var(--ink); }
.lx-value p { font-size: 13.5px; color: var(--ink-2); line-height: 1.7; margin: 0; }

/* Service list */
.lx-service-list { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-top: 1px solid var(--line); max-width: 420px; }
.lx-service-list div { font-size: 13.5px; color: var(--ink); padding: 13px 4px; border-bottom: 1px solid var(--line); }

@media (max-width: 768px) {
    .inv-grid { grid-template-columns: 1fr 1fr; gap: 16px; }
    .lx-hero-content { padding-bottom: 80px; }
    .lx-feature-body { padding: 26px 24px 30px; }
    .lx-feature-specs > div { padding: 0 16px; }
}
@media (max-width: 520px) {
    .inv-grid { grid-template-columns: 1fr; }
}
</style>

<!-- ── Compare sticky bar ─────────────────────────────────────────────────── -->
<div id="compareBar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:var(--black);border-top:1px solid rgba(255,255,255,.1);padding:14px 24px;z-index:1050">
    <div style="max-width:1320px;margin:0 auto;display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <span class="lx-label" style="color:rgba(255,255,255,.5);white-space:nowrap">Compare</span>
        <div id="compareSlots" style="display:flex;gap:8px;flex:1;flex-wrap:wrap"></div>
        <a id="compareBtn" href="#"
           style="background:#fff;color:var(--ink);padding:10px 22px;border-radius:var(--r);font-size:11px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;text-decoration:none;white-space:nowrap">
            Compare Cars
        </a>
        <button onclick="clearCompare()"
                style="background:none;color:rgba(255,255,255,.55);border:1px solid rgba(255,255,255,.25);border-radius:var(--r);padding:9px 16px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;white-space:nowrap;font-family:inherit">
            Clear
        </button>
    </div>
</div>

<script>
var BASE_URL_JS = '<?= BASE_URL ?>';

/* ── Favorites ─────────────────────────────────────────────────────────── */
var FAV_KEY = 'msc_favs';
function getFavs() { try { return JSON.parse(localStorage.getItem(FAV_KEY)||'[]'); } catch(e){return[];} }
function saveFavs(ids) { localStorage.setItem(FAV_KEY,JSON.stringify(ids)); }

function toggleFav(id, btn) {
    var favs = getFavs(), idx = favs.indexOf(id);
    if (idx >= 0) favs.splice(idx,1); else favs.push(id);
    saveFavs(favs);
    syncFavUI();
    if (showingFavs) applyFavFilter();
}

function syncFavUI() {
    var favs = getFavs();
    document.querySelectorAll('.fav-btn').forEach(function(b) {
        var id = parseInt(b.dataset.id);
        var saved = favs.indexOf(id) >= 0;
        b.querySelector('i').className = saved ? 'fa fa-heart' : 'fa-regular fa-heart';
        b.classList.toggle('active-fav', saved);
    });
    var cnt = favs.length, el = document.getElementById('favCount');
    if (el) { el.textContent = cnt; el.style.display = cnt ? '' : 'none'; }
}

var showingFavs = false;
function toggleFavFilter() {
    showingFavs = !showingFavs;
    applyFavFilter();
    var btn = document.getElementById('favFilterBtn');
    if (btn) {
        btn.style.background  = showingFavs ? 'var(--ink)' : '';
        btn.style.color       = showingFavs ? '#fff' : '';
        btn.style.borderColor = showingFavs ? 'var(--ink)' : '';
    }
}
function applyFavFilter() {
    var favs = getFavs();
    document.querySelectorAll('.inv-card').forEach(function(c) {
        c.style.display = showingFavs && favs.indexOf(parseInt(c.dataset.carId)) < 0 ? 'none' : '';
    });
}

/* ── Compare ────────────────────────────────────────────────────────────── */
var compareIds = [], compareNames = {};

function toggleCompare(id, btn) {
    var idx = compareIds.indexOf(id);
    if (idx >= 0) {
        compareIds.splice(idx,1);
        delete compareNames[id];
    } else {
        if (compareIds.length >= 3) { alert('You can compare up to 3 cars at a time.'); return; }
        compareIds.push(id);
        var card = document.querySelector('.inv-card[data-car-id="'+id+'"]');
        compareNames[id] = card ? card.dataset.carName : 'Car '+id;
    }
    syncCompareUI();
}

function syncCompareUI() {
    document.querySelectorAll('.cmp-btn').forEach(function(b) {
        var active = compareIds.indexOf(parseInt(b.dataset.id)) >= 0;
        b.querySelector('i').className = active ? 'fa fa-check' : 'fa fa-scale-balanced';
        b.classList.toggle('active-cmp', active);
    });

    var bar = document.getElementById('compareBar');
    var slots = document.getElementById('compareSlots');
    var btn = document.getElementById('compareBtn');
    if (!compareIds.length) { bar.style.display = 'none'; return; }
    bar.style.display = '';

    slots.innerHTML = compareIds.map(function(id) {
        return '<div style="background:rgba(255,255,255,.1);color:#eee;border-radius:2px;padding:5px 11px;font-size:12.5px;font-weight:500;display:flex;align-items:center;gap:8px">'
            + '<span>' + (compareNames[id]||'Car '+id) + '</span>'
            + '<button onclick="toggleCompare('+id+',this)" style="background:none;border:none;color:rgba(255,255,255,.5);cursor:pointer;font-size:14px;padding:0;line-height:1">&times;</button>'
            + '</div>';
    }).join('');

    var qs = compareIds.map(function(id){return'ids[]='+id;}).join('&');
    btn.href = compareIds.length >= 2 ? BASE_URL_JS+'/showroom/compare.php?'+qs : '#';
    btn.style.opacity = compareIds.length >= 2 ? '1' : '0.5';
    btn.onclick = compareIds.length < 2 ? function(e){e.preventDefault();alert('Select at least 2 cars to compare.');} : null;
    btn.textContent = compareIds.length >= 2 ? 'Compare '+compareIds.length+' Cars →' : 'Select '+(2-compareIds.length)+' more…';
}

function clearCompare() {
    compareIds = []; compareNames = {};
    syncCompareUI();
}

document.addEventListener('DOMContentLoaded', function() {
    syncFavUI();
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
