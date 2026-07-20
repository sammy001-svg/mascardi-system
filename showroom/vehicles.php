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
$pageTitle   = $filterMake ? $filterMake . ' Vehicles' : 'Available Vehicles';
$metaDesc    = "Browse {$totalStock} quality vehicles at {$companyName}. Transparent pricing, flexible financing.";

// URL for the current query minus the given params (used by filter chips)
function sv_url_without(array $drop): string {
    $q = $_GET;
    foreach ($drop as $k) unset($q[$k]);
    $s = http_build_query($q);
    return BASE_URL . '/showroom/vehicles.php' . ($s ? '?' . $s : '');
}

// Active filter chips: [label, params-to-drop]
$chips = [];
if ($search)        $chips[] = ['"' . $search . '"',                                        ['q']];
if ($filterMake)    $chips[] = [$filterMake,                                                ['make']];
if ($filterBody)    $chips[] = [$filterBody,                                                ['body']];
if ($filterFuel)    $chips[] = [ucfirst($filterFuel),                                       ['fuel']];
if ($filterTrans)   $chips[] = [ucfirst($filterTrans),                                      ['trans']];
if ($filterMin || $filterMax) {
    $chips[] = ['KES ' . ($filterMin ? number_format($filterMin) : '0') . ' – ' . ($filterMax ? number_format($filterMax) : 'any'), ['min','max']];
}
if ($filterYearMin || $filterYearMax) {
    $chips[] = ['Year ' . ($filterYearMin ?: 'any') . ' – ' . ($filterYearMax ?: 'any'),    ['year_min','year_max']];
}
if ($filterMileMax) $chips[] = ['≤ ' . number_format($filterMileMax) . ' km',               ['mile_max']];

$moreFiltersActive = $filterMin || $filterMax || $filterYearMin || $filterYearMax || $filterMileMax;

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     PAGE HEAD
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);padding:64px 0 40px">
    <div class="lx-wrap">
        <div class="lx-label" style="margin-bottom:14px">Inventory</div>
        <h1 class="lx-h2" style="font-size:clamp(32px,4.6vw,52px)">
            <?= $filterMake ? htmlspecialchars($filterMake) . ' vehicles' : 'Available vehicles' ?>
        </h1>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FILTER BAR
═══════════════════════════════════════════════════════════════ -->
<section class="sv-filterbar-wrap">
    <div class="lx-wrap">
        <form method="GET" action="<?= BASE_URL ?>/showroom/vehicles.php" id="svFilterForm">
            <div class="sv-filterbar">
                <div class="sv-search">
                    <i class="fa fa-magnifying-glass"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search make, model, colour…">
                </div>

                <select name="make" class="sv-select" onchange="this.form.submit()">
                    <option value="">Make</option>
                    <?php foreach ($makes as $mk): ?>
                    <option value="<?= htmlspecialchars($mk) ?>" <?= $filterMake === $mk ? 'selected' : '' ?>><?= htmlspecialchars($mk) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="body" class="sv-select" onchange="this.form.submit()">
                    <option value="">Body Type</option>
                    <?php foreach (['SUV','Saloon','Pick-Up','Hatchback','Van','Truck','Coupe','Bus','Minibus','Other'] as $bt): ?>
                    <option value="<?= $bt ?>" <?= $filterBody === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="fuel" class="sv-select" onchange="this.form.submit()">
                    <option value="">Fuel</option>
                    <?php foreach (['petrol','diesel','hybrid','electric'] as $fu): ?>
                    <option value="<?= $fu ?>" <?= $filterFuel === $fu ? 'selected' : '' ?>><?= ucfirst($fu) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="trans" class="sv-select" onchange="this.form.submit()">
                    <option value="">Transmission</option>
                    <?php foreach (['automatic','manual','cvt'] as $tr): ?>
                    <option value="<?= $tr ?>" <?= $filterTrans === $tr ? 'selected' : '' ?>><?= ucfirst($tr) ?></option>
                    <?php endforeach; ?>
                </select>

                <button type="button" class="sv-more-btn<?= $moreFiltersActive ? ' active' : '' ?>" id="svMoreBtn">
                    More Filters <i class="fa fa-chevron-down" style="font-size:9px"></i>
                </button>

                <div class="sv-sort">
                    <span>Sort</span>
                    <select name="sort" onchange="this.form.submit()">
                        <option value="featured"   <?= $sort==='featured'   ?'selected':'' ?>>Featured First</option>
                        <option value="newest"     <?= $sort==='newest'     ?'selected':'' ?>>Newest Arrivals</option>
                        <option value="price_asc"  <?= $sort==='price_asc'  ?'selected':'' ?>>Price: Low to High</option>
                        <option value="price_desc" <?= $sort==='price_desc' ?'selected':'' ?>>Price: High to Low</option>
                        <option value="year_desc"  <?= $sort==='year_desc'  ?'selected':'' ?>>Year: Newest First</option>
                    </select>
                </div>
            </div>

            <!-- Expandable range filters -->
            <div class="sv-more-panel<?= $moreFiltersActive ? ' open' : '' ?>" id="svMorePanel">
                <div class="sv-more-grid">
                    <div>
                        <label class="lx-flabel">Price Range (KES)</label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="number" name="min" value="<?= $filterMin ?: '' ?>" placeholder="Min" class="lx-input">
                            <span style="color:var(--ink-3);font-size:12px">–</span>
                            <input type="number" name="max" value="<?= $filterMax ?: '' ?>" placeholder="Max" class="lx-input">
                        </div>
                    </div>
                    <div>
                        <label class="lx-flabel">Year</label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="number" name="year_min" value="<?= $filterYearMin ?: '' ?>" placeholder="<?= $yearRange['min_yr'] ?>"
                                   min="<?= $yearRange['min_yr'] ?>" max="<?= $yearRange['max_yr'] ?>" class="lx-input">
                            <span style="color:var(--ink-3);font-size:12px">–</span>
                            <input type="number" name="year_max" value="<?= $filterYearMax ?: '' ?>" placeholder="<?= $yearRange['max_yr'] ?>"
                                   min="<?= $yearRange['min_yr'] ?>" max="<?= $yearRange['max_yr'] ?>" class="lx-input">
                        </div>
                    </div>
                    <div>
                        <label class="lx-flabel">Max Mileage (km)</label>
                        <input type="number" name="mile_max" value="<?= $filterMileMax ?: '' ?>" placeholder="e.g. 80000" min="0" class="lx-input">
                    </div>
                    <div style="display:flex;align-items:flex-end">
                        <button type="submit" class="btn-lx" style="width:100%;padding:12px 20px">Apply</button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Count + active chips -->
        <div class="sv-meta-row">
            <span style="font-size:13px;color:var(--ink-2)">
                <strong style="color:var(--ink);font-weight:600"><?= $filteredCount ?></strong>
                vehicle<?= $filteredCount !== 1 ? 's' : '' ?> <?= $isFiltered ? 'found' : 'available' ?>
            </span>
            <?php if ($chips): ?>
            <div class="sv-chips">
                <?php foreach ($chips as [$lbl, $drop]): ?>
                <a href="<?= htmlspecialchars(sv_url_without($drop)) ?>" class="sv-chip">
                    <?= htmlspecialchars($lbl) ?> <i class="fa fa-xmark"></i>
                </a>
                <?php endforeach; ?>
                <a href="<?= BASE_URL ?>/showroom/vehicles.php" class="sv-chip sv-chip-clear">Clear all</a>
            </div>
            <?php endif; ?>
            <button id="favFilterBtn" onclick="toggleFavFilter()" style="margin-left:auto;background:none;border:1px solid var(--line);border-radius:var(--r);padding:6px 14px;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink);cursor:pointer;display:flex;align-items:center;gap:6px;font-family:inherit">
                <i class="fa fa-heart" style="font-size:11px"></i> Saved
                <span id="favCount" style="display:none;background:var(--ink);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px">0</span>
            </button>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     VEHICLE GRID
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);padding:40px 0 110px">
    <div class="lx-wrap">

        <?php if (!$filteredCars): ?>
        <!-- Empty state -->
        <div style="border:1px solid var(--line);border-radius:var(--r);padding:88px 24px;text-align:center">
            <div class="lx-label" style="margin-bottom:14px">No Results</div>
            <h3 style="font-size:22px;font-weight:400;margin:0 0 10px">No vehicles found</h3>
            <p style="color:var(--ink-2);font-size:14px;margin:0 0 26px">Try adjusting your filters or clearing your search.</p>
            <a href="<?= BASE_URL ?>/showroom/vehicles.php" class="btn-lx">View All Vehicles</a>
        </div>
        <?php else: ?>

        <div class="sv-grid">
            <?php foreach ($filteredCars as $car):
                $img = $car['primary_image'] ? thumbUrl('cars', $car['primary_image']) : null;
                $waMsg = urlencode("Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} (KES " . number_format((float)$car['asking_price']) . ") listed on your showroom.");
                $isNew      = strtotime($car['created_at']) > strtotime('-30 days');
                $isReserved = ($car['status'] ?? '') === 'reserved';
                $hasOffer   = !$isReserved && !empty($car['offer_price']) && $car['offer_price'] > 0;
                $askPrice   = (float)($car['asking_price'] ?? 0);
                $showPrice  = $hasOffer ? (float)$car['offer_price'] : $askPrice;
                $saveAmt    = ($hasOffer && $askPrice > $car['offer_price']) ? $askPrice - (float)$car['offer_price'] : 0;

                // Meta line: Year · Colour · Fuel  (Lucid: year · paint · wheels)
                $metaBits = array_filter([
                    $car['year'] ?: null,
                    $car['color'] ? htmlspecialchars($car['color']) : null,
                    $car['fuel_type'] ? ucfirst($car['fuel_type']) : null,
                ]);
            ?>
            <div class="sv-card<?= $isReserved ? ' sv-card-reserved' : '' ?>" data-car-id="<?= $car['id'] ?>" data-car-name="<?= htmlspecialchars($car['year'].' '.$car['make'].' '.$car['model']) ?>">

                <!-- Image -->
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="sv-card-img">
                    <?php if ($isReserved): ?>
                    <span class="sv-chip-avail sv-chip-dark">Reserved</span>
                    <?php elseif ($car['featured']): ?>
                    <span class="sv-chip-avail">Available Today</span>
                    <?php elseif ($isNew): ?>
                    <span class="sv-chip-avail">New Arrival</span>
                    <?php else: ?>
                    <span class="sv-chip-avail">Available</span>
                    <?php endif; ?>
                    <?php if ($car['image_count'] > 1): ?>
                    <span class="sv-photos"><i class="fa fa-camera"></i> <?= $car['image_count'] ?></span>
                    <?php endif; ?>
                    <?php if ($img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($car['make'].' '.$car['model']) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                    <div class="lx-noimg"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>
                </a>

                <!-- Body -->
                <div class="sv-card-body">
                    <h3 class="sv-card-title">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>">
                            <?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>
                        </a>
                    </h3>
                    <div class="sv-card-meta"><?= implode(' &middot; ', $metaBits) ?: '&nbsp;' ?></div>

                    <div class="sv-card-price">
                        <?php if ($isReserved): ?>
                            <span class="p">Reserved</span>
                            <?php if ($askPrice > 0): ?><span class="sub">KES <?= number_format($askPrice) ?></span><?php endif; ?>
                        <?php elseif ($showPrice > 0): ?>
                            <span class="p">KES <?= number_format($showPrice) ?></span>
                            <?php if ($saveAmt > 0): ?>
                            <span class="sub"><del>KES <?= number_format($askPrice) ?></del> &nbsp;Save KES <?= number_format($saveAmt) ?></span>
                            <?php else: ?>
                            <span class="sub">Financing available</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="p" style="font-weight:400;color:var(--ink-2)">Price on request</span>
                            <span class="sub">Contact us for details</span>
                        <?php endif; ?>
                    </div>

                    <!-- Icon stat row (Lucid: Range / Power / Drive) -->
                    <div class="sv-card-stats">
                        <div>
                            <i class="fa fa-road"></i>
                            <div class="v"><?= $car['mileage'] ? number_format($car['mileage']) . ' km' : '—' ?></div>
                            <div class="l">Mileage</div>
                        </div>
                        <div>
                            <i class="fa fa-bolt"></i>
                            <div class="v"><?= $car['engine_cc'] ? number_format($car['engine_cc']) . ' cc' : '—' ?></div>
                            <div class="l">Engine</div>
                        </div>
                        <div>
                            <i class="fa fa-gears"></i>
                            <div class="v"><?= $car['transmission'] ? ucfirst($car['transmission']) : '—' ?></div>
                            <div class="l">Drive</div>
                        </div>
                    </div>

                    <!-- CTAs -->
                    <div class="sv-card-actions">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="sv-btn-main">View Details</a>
                        <?php if (!$isReserved && $__waClean): ?>
                        <a href="https://wa.me/<?= $__waClean ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="sv-btn-sq" title="Enquire on WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                        <button class="fav-btn sv-btn-sq" data-id="<?= $car['id'] ?>"
                                onclick="toggleFav(<?= $car['id'] ?>,this)" title="Save to favorites">
                            <i class="fa-regular fa-heart"></i>
                        </button>
                        <button class="cmp-btn sv-btn-sq" data-id="<?= $car['id'] ?>"
                                onclick="toggleCompare(<?= $car['id'] ?>,this)" title="Add to compare">
                            <i class="fa fa-scale-balanced"></i>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</section>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
/* ── Filter bar ─────────────────────────────────────────────── */
.sv-filterbar-wrap { background: var(--white); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 18px 0; position: sticky; top: var(--nav-h); z-index: 100; }
.sv-filterbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sv-search {
    display: flex; align-items: center; gap: 9px;
    border: 1px solid var(--line); border-radius: var(--r);
    padding: 0 14px; height: 42px; flex: 1 1 220px; min-width: 200px;
    transition: border-color .25s var(--ease); background: var(--white);
}
.sv-search:focus-within { border-color: var(--ink); }
.sv-search i { font-size: 12px; color: var(--ink-3); }
.sv-search input { border: none; outline: none; font-family: inherit; font-size: 13.5px; color: var(--ink); width: 100%; background: transparent; }
.sv-select {
    height: 42px; border: 1px solid var(--line); border-radius: var(--r);
    padding: 0 12px; font-family: inherit; font-size: 12px; font-weight: 500;
    letter-spacing: .04em; color: var(--ink); background: var(--white);
    cursor: pointer; outline: none; transition: border-color .25s var(--ease);
}
.sv-select:hover, .sv-select:focus { border-color: var(--ink); }
.sv-more-btn {
    height: 42px; border: 1px solid var(--line); border-radius: var(--r);
    padding: 0 16px; font-family: inherit; font-size: 11px; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase; color: var(--ink);
    background: var(--white); cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
    transition: all .25s var(--ease);
}
.sv-more-btn:hover, .sv-more-btn.active { border-color: var(--ink); }
.sv-more-btn.active { background: var(--ink); color: #fff; }
.sv-sort { margin-left: auto; display: flex; align-items: center; gap: 9px; }
.sv-sort span { font-size: 10.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: var(--ink-3); }
.sv-sort select {
    height: 42px; border: none; border-bottom: 1px solid var(--line);
    font-family: inherit; font-size: 13px; font-weight: 500; color: var(--ink);
    background: transparent; cursor: pointer; outline: none; padding: 0 4px;
}
.sv-sort select:hover { border-bottom-color: var(--ink); }

.sv-more-panel { display: none; border-top: 1px solid var(--line); margin-top: 16px; padding-top: 18px; }
.sv-more-panel.open { display: block; }
.sv-more-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
@media (max-width: 991px) { .sv-more-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 576px) { .sv-more-grid { grid-template-columns: 1fr; } }

.sv-meta-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 16px; }
.sv-chips { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sv-chip {
    display: inline-flex; align-items: center; gap: 8px;
    border: 1px solid var(--ink); border-radius: 20px;
    padding: 5px 13px; font-size: 12px; font-weight: 500; color: var(--ink);
    transition: all .25s var(--ease);
}
.sv-chip i { font-size: 10px; color: var(--ink-3); }
.sv-chip:hover { background: var(--ink); color: #fff; }
.sv-chip:hover i { color: #fff; }
.sv-chip-clear { border-color: var(--line); color: var(--ink-3); }

/* ── Vehicle cards (Lucid available-vehicles anatomy) ───────── */
.sv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
@media (max-width: 1100px) { .sv-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px)  { .sv-grid { grid-template-columns: 1fr; } }

.sv-card {
    background: var(--white); border: 1px solid var(--line); border-radius: var(--r);
    overflow: hidden; display: flex; flex-direction: column;
    transition: box-shadow .35s var(--ease), border-color .35s var(--ease), transform .35s var(--ease);
}
.sv-card:hover { box-shadow: 0 24px 56px rgba(0,0,0,.10); border-color: #cfcfcd; transform: translateY(-3px); }
.sv-card-reserved { opacity: .8; }

.sv-card-img { display: block; position: relative; aspect-ratio: 16/10; overflow: hidden; background: linear-gradient(180deg, #f7f7f5 0%, #ececea 100%); flex-shrink: 0; }
.sv-card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s var(--ease); }
.sv-card:hover .sv-card-img img { transform: scale(1.04); }

.sv-chip-avail {
    position: absolute; top: 16px; left: 16px; z-index: 1;
    background: var(--white); color: var(--ink); border: 1px solid var(--line);
    font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 5px 12px; border-radius: 20px;
}
.sv-chip-dark { background: var(--ink); color: #fff; border-color: var(--ink); }
.sv-photos { position: absolute; top: 16px; right: 16px; z-index: 1; background: rgba(10,10,10,.55); color: #fff; font-size: 10.5px; font-weight: 500; padding: 4px 10px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px; }
.sv-photos i { font-size: 10px; }

.sv-card-body { padding: 24px 26px 26px; flex: 1; display: flex; flex-direction: column; }
.sv-card-title { font-size: 20px; font-weight: 500; letter-spacing: -.01em; margin: 0 0 5px; }
.sv-card-title a { color: var(--ink); }
.sv-card-title a:hover { color: var(--ink-2); }
.sv-card-meta { font-size: 12.5px; color: var(--ink-3); margin-bottom: 18px; }

.sv-card-price { margin-bottom: 20px; }
.sv-card-price .p { display: block; font-size: 21px; font-weight: 600; letter-spacing: -.01em; color: var(--ink); }
.sv-card-price .sub { display: block; font-size: 12px; color: var(--ink-3); margin-top: 3px; }
.sv-card-price .sub del { color: var(--ink-3); }

.sv-card-stats {
    display: grid; grid-template-columns: 1fr 1fr 1fr;
    border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
    padding: 16px 0; margin-bottom: 20px; margin-top: auto;
}
.sv-card-stats > div { text-align: center; border-right: 1px solid var(--line); padding: 0 8px; }
.sv-card-stats > div:last-child { border-right: none; }
.sv-card-stats i { font-size: 15px; color: var(--ink); display: block; margin-bottom: 8px; }
.sv-card-stats .v { font-size: 13px; font-weight: 500; color: var(--ink); white-space: nowrap; }
.sv-card-stats .l { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .14em; color: var(--ink-3); margin-top: 3px; }

.sv-card-actions { display: flex; gap: 8px; }
.sv-btn-main {
    flex: 1; background: var(--ink); color: #fff; border-radius: var(--r);
    padding: 12px 14px; font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    text-align: center; display: flex; align-items: center; justify-content: center;
    transition: background .25s var(--ease);
}
.sv-btn-main:hover { background: #000; color: #fff; }
.sv-btn-sq {
    width: 42px; height: 42px; background: var(--white); color: var(--ink-2);
    border: 1px solid var(--line); border-radius: var(--r);
    display: flex; align-items: center; justify-content: center; font-size: 15px;
    cursor: pointer; transition: all .25s var(--ease); flex-shrink: 0; text-decoration: none;
}
.sv-btn-sq:hover { border-color: var(--ink); color: var(--ink); }
.sv-btn-sq.active-fav, .sv-btn-sq.active-cmp { background: var(--ink); color: #fff; border-color: var(--ink); }

@media (max-width: 768px) {
    .sv-sort { margin-left: 0; width: 100%; }
    .sv-filterbar-wrap { position: static; }
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

/* ── More Filters toggle ────────────────────────────────────────────────── */
document.getElementById('svMoreBtn').addEventListener('click', function () {
    document.getElementById('svMorePanel').classList.toggle('open');
});

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
    document.querySelectorAll('.sv-card').forEach(function(c) {
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
        var card = document.querySelector('.sv-card[data-car-id="'+id+'"]');
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
