<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

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

// ── Stock stats ───────────────────────────────────────────────────────────────
$totalStock = (int)$db->query("
    SELECT COUNT(*) FROM cars
    WHERE car_type='inventory' AND show_on_website = 1
      AND (status IS NULL OR status NOT IN ('delivered','sold'))
")->fetchColumn();

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
$pageTitle   = $filterMake ? $filterMake . ' Vehicles' : 'All Vehicles';
$metaDesc    = "Browse {$totalStock} quality vehicles at {$companyName}. Transparent pricing, flexible financing.";

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     PAGE HEAD
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);border-bottom:1px solid var(--line);padding:72px 0 56px">
    <div class="lx-wrap">
        <div class="lx-label" style="margin-bottom:14px">Inventory</div>
        <h1 class="lx-h2" style="font-size:clamp(32px,4.6vw,54px)">
            <?= $filterMake ? htmlspecialchars($filterMake) . ' vehicles' : 'All vehicles' ?>
        </h1>
        <p style="font-size:15px;color:var(--ink-2);margin:16px 0 0;max-width:520px;line-height:1.7">
            <?= $totalStock ?> quality vehicles in stock — every one inspected, verified and ready for the road.
        </p>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     INVENTORY BROWSER
═══════════════════════════════════════════════════════════════ -->
<section id="inventory" style="background:var(--paper);padding:56px 0 96px">
    <div class="lx-wrap">

        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;margin-bottom:32px">
            <span style="font-size:13px;color:var(--ink-2)">
                <strong style="color:var(--ink);font-weight:600"><?= $filteredCount ?></strong> vehicle<?= $filteredCount !== 1 ? 's' : '' ?>
                <?= $isFiltered ? 'found' : 'available' ?>
            </span>
            <?php if ($isFiltered): ?>
            <a href="<?= BASE_URL ?>/showroom/vehicles.php" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:600;color:var(--ink);border:1px solid var(--ink);border-radius:var(--r);padding:7px 14px">
                Clear Filters
            </a>
            <?php endif; ?>
        </div>

        <div class="lx-inv-layout">

            <!-- ── Sidebar Filters ─────────────────────── -->
            <div class="lx-filter">
                <div class="lx-filter-head">
                    <span class="lx-label" style="color:var(--ink)">Filter</span>
                    <?php if ($isFiltered): ?>
                    <a href="<?= BASE_URL ?>/showroom/vehicles.php" style="font-size:11px;color:var(--ink-3);letter-spacing:.1em;text-transform:uppercase">Reset</a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="<?= BASE_URL ?>/showroom/vehicles.php" style="padding:22px;display:flex;flex-direction:column;gap:22px">
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
                <!-- Sort + saved bar -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;flex-wrap:wrap;gap:12px">
                    <button id="favFilterBtn" onclick="toggleFavFilter()"
                            style="background:none;border:1px solid var(--line);border-radius:var(--r);padding:6px 14px;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink);cursor:pointer;display:flex;align-items:center;gap:6px;font-family:inherit">
                        <i class="fa fa-heart" style="font-size:11px"></i> Saved
                        <span id="favCount" style="display:none;background:var(--ink);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px">0</span>
                    </button>
                    <form method="GET" action="<?= BASE_URL ?>/showroom/vehicles.php" style="display:flex;align-items:center;gap:10px">
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
                    <a href="<?= BASE_URL ?>/showroom/vehicles.php" class="btn-lx">View All Vehicles</a>
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
                            <span class="inv-badge">Featured</span>
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
                                    <span class="inv-tag">Reserved</span>
                                    <?php if (!empty($car['asking_price']) && $car['asking_price'] > 0): ?>
                                    <span style="font-size:13px;color:var(--ink-3);font-weight:400;margin-left:8px">KES <?= number_format((float)$car['asking_price']) ?></span>
                                    <?php endif; ?>
                                <?php elseif (!empty($car['offer_price']) && $car['offer_price'] > 0): ?>
                                    <span class="inv-tag">Offer</span>
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

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
.lx-inv-layout { display: grid; grid-template-columns: 280px 1fr; gap: 32px; align-items: start; }
@media (max-width: 1024px) { .lx-inv-layout { grid-template-columns: 1fr; } .lx-filter { position: static !important; } }
.lx-filter { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); position: sticky; top: calc(var(--nav-h) + 20px); }
.lx-filter-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 22px; border-bottom: 1px solid var(--line); }

.inv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
.inv-card { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; display: flex; flex-direction: column; transition: box-shadow .35s var(--ease), border-color .35s var(--ease); }
.inv-card:hover { box-shadow: 0 22px 48px rgba(0,0,0,.09); border-color: #cfcfcd; }
.inv-img-wrap { display: block; position: relative; aspect-ratio: 16/10; overflow: hidden; background: var(--paper); flex-shrink: 0; }
.inv-img-wrap img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s var(--ease); }
.inv-card:hover .inv-img-wrap img { transform: scale(1.04); }
.inv-badge {
    position: absolute; top: 14px; left: 14px; z-index: 1;
    background: rgba(10,10,10,.85); color: #fff;
    font-size: 10px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 5px 12px; border-radius: var(--r);
}
.inv-photos { position: absolute; top: 14px; right: 14px; z-index: 1; background: rgba(10,10,10,.55); color: #fff; font-size: 10.5px; font-weight: 500; padding: 3px 9px; border-radius: var(--r); }
.inv-card-reserved { opacity: .82; }
.inv-body { padding: 22px 24px 24px; flex: 1; display: flex; flex-direction: column; }
.inv-meta { font-size: 10.5px; color: var(--ink-3); font-weight: 600; text-transform: uppercase; letter-spacing: .16em; margin-bottom: 6px; }
.inv-title { font-size: 18px; font-weight: 500; letter-spacing: -.01em; margin: 0 0 14px; }
.inv-title a { color: var(--ink); }
.inv-title a:hover { color: var(--ink-2); }
.inv-specs { display: flex; flex-wrap: wrap; gap: 12px 16px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--line); }
.inv-specs span { font-size: 12px; color: var(--ink-2); display: inline-flex; align-items: center; gap: 7px; }
.inv-specs i { font-size: 11px; color: var(--ink-3); }
.inv-price { font-size: 17px; font-weight: 600; letter-spacing: -.01em; color: var(--ink); margin-top: auto; margin-bottom: 16px; }
.inv-price del { font-size: 12.5px; color: var(--ink-3); font-weight: 400; margin-left: 8px; }
.inv-tag {
    display: inline-block; font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    background: var(--ink); color: #fff;
    padding: 3px 9px; border-radius: var(--r); vertical-align: 2px; margin-right: 7px;
}
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
.inv-btn-icon.active-fav, .inv-btn-icon.active-cmp { background: var(--ink); color: #fff; border-color: var(--ink); }

@media (max-width: 768px) {
    .inv-grid { grid-template-columns: 1fr 1fr; gap: 16px; }
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
