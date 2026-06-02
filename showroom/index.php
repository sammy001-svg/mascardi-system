<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

// ── Active filters ────────────────────────────────────────────────────────────
$filterMake  = trim($_GET['make']  ?? '');
$filterBody  = trim($_GET['body']  ?? '');
$filterFuel  = trim($_GET['fuel']  ?? '');
$filterTrans = trim($_GET['trans'] ?? '');
$filterMin   = (int)($_GET['min']  ?? 0);
$filterMax   = (int)($_GET['max']  ?? 0);
$sort        = $_GET['sort'] ?? 'featured';
$search      = trim($_GET['q']     ?? '');

// ── All inventory cars (stats + category counts) ──────────────────────────────
$allCars = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.mileage,
           c.engine_cc, c.featured, c.notes, c.created_at,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c
    WHERE c.car_type='inventory'
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

// ── Make list for filter ──────────────────────────────────────────────────────
$makes = $db->query("
    SELECT DISTINCT make FROM cars
    WHERE car_type='inventory' AND make != ''
    ORDER BY make
")->fetchAll(PDO::FETCH_COLUMN);

// ── Filtered inventory ────────────────────────────────────────────────────────
$where  = ["c.car_type='inventory'"];
$params = [];
if ($filterMake)  { $where[] = 'c.make = ?';          $params[] = $filterMake; }
if ($filterBody)  { $where[] = 'c.body_type = ?';     $params[] = $filterBody; }
if ($filterFuel)  { $where[] = 'c.fuel_type = ?';     $params[] = $filterFuel; }
if ($filterTrans) { $where[] = 'c.transmission = ?';  $params[] = $filterTrans; }
// Price filter only applies to cars that have a price set
if ($filterMin)   { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price >= ?'; $params[] = $filterMin; }
if ($filterMax)   { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price <= ?'; $params[] = $filterMax; }
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
           c.transmission, c.fuel_type, c.asking_price, c.mileage,
           c.engine_cc, c.featured, c.notes, c.created_at,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $orderBy
");
$stmt->execute($params);
$filteredCars = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredCount = count($filteredCars);

$isFiltered = $filterMake || $filterBody || $filterFuel || $filterTrans || $filterMin || $filterMax || $search;
$companyName = getSetting('company_name', 'Mascardi Car Yard');
$__waClean   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
$pageTitle   = 'Quality Vehicles';
$metaDesc    = "Browse {$totalStock} quality vehicles at {$companyName}. Transparent pricing, flexible financing. Find your dream car today.";

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════════ -->
<section id="hero" style="
    background:
        linear-gradient(105deg,
            rgba(10,16,35,0.97)  0%,
            rgba(10,16,35,0.88) 38%,
            rgba(10,16,35,0.55) 62%,
            rgba(10,16,35,0.25) 100%
        ),
        url('<?= BASE_URL ?>/assets/images/hero.webp') center center / cover no-repeat;
    min-height: 88vh;
    display: flex; align-items: center;
    position: relative; overflow: hidden;
    padding: 80px 0;
">
    <!-- Subtle grid overlay on text side only -->
    <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:60px 60px;pointer-events:none"></div>

    <div class="container-xl" style="position:relative;z-index:1">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <div style="display:inline-flex;align-items:center;gap:8px;background:rgba(37,99,235,.2);border:1px solid rgba(59,130,246,.3);border-radius:20px;padding:6px 16px;margin-bottom:24px;font-size:12.5px;color:#93c5fd;font-weight:600;letter-spacing:.5px">
                    <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block"></span>
                    <?= $totalStock ?> Vehicles Currently Available
                </div>
                <h1 style="font-size:clamp(36px,6vw,62px);font-weight:900;color:#fff;letter-spacing:-2px;line-height:1.07;margin:0 0 20px">
                    Find Your<br>
                    <span style="background:linear-gradient(135deg,#60a5fa,#818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">
                        Perfect Car
                    </span>
                </h1>
                <p style="font-size:17px;color:rgba(255,255,255,.6);line-height:1.7;margin:0 0 36px;max-width:480px">
                    Quality imported vehicles with transparent pricing and flexible financing options. Your dream car is waiting.
                </p>

                <!-- Search bar -->
                <form method="GET" action="#inventory" style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:10px;display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search make, model, body type..."
                           style="flex:1;min-width:180px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#fff;font-size:14px;font-family:inherit;outline:none"
                           onfocus="this.style.borderColor='rgba(96,165,250,.5)'"
                           onblur="this.style.borderColor='rgba(255,255,255,.1)'">
                    <select name="body" style="background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#fff;font-size:14px;font-family:inherit;outline:none;cursor:pointer">
                        <option value="" style="background:#1e3a8a">All Types</option>
                        <?php foreach (array_keys($catCounts) as $bt): ?>
                        <option value="<?= htmlspecialchars($bt) ?>" <?= $filterBody === $bt ? 'selected' : '' ?> style="background:#1e3a8a"><?= htmlspecialchars($bt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                            style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:10px;padding:12px 24px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px;white-space:nowrap;transition:box-shadow .15s"
                            onmouseover="this.style.boxShadow='0 4px 20px rgba(37,99,235,.5)'"
                            onmouseout="this.style.boxShadow='none'">
                        <i class="fa fa-search"></i> Search Cars
                    </button>
                </form>

                <!-- Quick stats -->
                <div style="display:flex;gap:32px;margin-top:32px;flex-wrap:wrap">
                    <?php
                    $heroStats = [
                        [$totalStock,                               'Vehicles in Stock'],
                        [count($featuredAll),                        'Featured Picks'],
                        [count($catCounts),                          'Categories'],
                    ];
                    foreach ($heroStats as [$val, $lbl]): ?>
                    <div>
                        <div style="font-size:28px;font-weight:900;color:#fff;letter-spacing:-1px"><?= $val ?>+</div>
                        <div style="font-size:12px;color:rgba(255,255,255,.4);font-weight:600;text-transform:uppercase;letter-spacing:.5px"><?= $lbl ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Hero image / featured car card -->
            <div class="col-lg-6 d-none d-lg-block">
                <?php if ($featuredAll): $fc = $featuredAll[0]; $fcImg = $fc['primary_image'] ? BASE_URL . '/uploads/cars/' . $fc['primary_image'] : null; ?>
                <div style="position:relative">
                    <!-- Main featured car card -->
                    <div style="background:rgba(255,255,255,.06);backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,.1);border-radius:24px;overflow:hidden;box-shadow:0 32px 80px rgba(0,0,0,.4)">
                        <?php if ($fcImg): ?>
                        <img src="<?= htmlspecialchars($fcImg) ?>" alt="<?= htmlspecialchars($fc['make'].' '.$fc['model']) ?>"
                             style="width:100%;height:280px;object-fit:cover">
                        <?php else: ?>
                        <div style="width:100%;height:280px;background:linear-gradient(135deg,rgba(37,99,235,.2),rgba(29,78,216,.1));display:flex;align-items:center;justify-content:center">
                            <i class="fa fa-car-side" style="font-size:80px;color:rgba(255,255,255,.1)"></i>
                        </div>
                        <?php endif; ?>
                        <div style="padding:24px">
                            <div style="display:flex;justify-content:space-between;align-items:start">
                                <div>
                                    <div style="font-size:11px;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px"><?= $fc['year'] ?> &bull; <?= ucfirst($fc['transmission'] ?? '') ?></div>
                                    <div style="font-size:22px;font-weight:800;color:#fff;letter-spacing:-.5px"><?= htmlspecialchars($fc['make'].' '.$fc['model']) ?></div>
                                </div>
                                <div style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:4px 10px;font-size:11px;font-weight:700;color:#f59e0b;white-space:nowrap">
                                    ⭐ Featured
                                </div>
                            </div>
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px">
                                <div style="font-size:26px;font-weight:900;color:#60a5fa;letter-spacing:-1px">
                                    KES <?= number_format((float)$fc['asking_price']) ?>
                                </div>
                                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fc['id'] ?>"
                                   style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:10px;padding:10px 20px;font-size:13px;font-weight:700;text-decoration:none;transition:box-shadow .15s"
                                   onmouseover="this.style.boxShadow='0 4px 20px rgba(37,99,235,.5)'"
                                   onmouseout="this.style.boxShadow='none'">
                                    View Details →
                                </a>
                            </div>
                        </div>
                    </div>
                    <!-- Floating badge -->
                    <div style="position:absolute;top:-16px;left:24px;background:#22c55e;color:#fff;border-radius:20px;padding:6px 14px;font-size:12px;font-weight:700;box-shadow:0 4px 14px rgba(34,197,94,.4)">
                        ✓ Available Now
                    </div>
                </div>
                <?php else: ?>
                <!-- No featured car — show decorative stat cards -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
                    <?php foreach ([['fa-car','Fleet','Quality Imports'],['fa-shield-check','Certified','All Verified'],['fa-wallet','Finance','Flexible Plans'],['fa-headset','Support','24/7 Service']] as [$ico,$t,$s]): ?>
                    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:24px;text-align:center">
                        <i class="fa <?= $ico ?>" style="font-size:28px;color:#60a5fa;margin-bottom:12px;display:block"></i>
                        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:4px"><?= $t ?></div>
                        <div style="font-size:12px;color:rgba(255,255,255,.4)"><?= $s ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Scroll indicator -->
    <div style="position:absolute;bottom:32px;left:50%;transform:translateX(-50%);display:flex;flex-direction:column;align-items:center;gap:6px;animation:bounce 2s infinite">
        <div style="width:1px;height:40px;background:linear-gradient(to bottom,rgba(255,255,255,0),rgba(255,255,255,.3))"></div>
        <i class="fa fa-chevron-down" style="color:rgba(255,255,255,.3);font-size:12px"></i>
    </div>
</section>

<style>
@keyframes bounce { 0%,100%{transform:translateX(-50%) translateY(0)} 50%{transform:translateX(-50%) translateY(8px)} }
</style>

<!-- ═══════════════════════════════════════════════════════════
     TRUST BAR
═══════════════════════════════════════════════════════════════ -->
<section style="background:#fff;border-bottom:1px solid #f1f5f9;padding:0">
    <div class="container-xl">
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:0">
            <?php
            $trustItems = [
                ['fa-car',            $totalStock . '+ Vehicles',     'In Stock Right Now'],
                ['fa-shield-halved',  'Quality Assured',               'Every Car Verified'],
                ['fa-credit-card',    'Finance Available',             'Flexible Payment Plans'],
                ['fa-rotate',         'Trade-In Welcome',              'Fair Market Value'],
                ['fa-headset',        '24/7 Support',                  'Always Here to Help'],
            ];
            foreach ($trustItems as [$ico, $title, $sub]): ?>
            <div style="flex:1;min-width:160px;max-width:220px;padding:28px 20px;text-align:center;border-right:1px solid #f1f5f9">
                <i class="fa <?= $ico ?>" style="font-size:24px;color:#2563eb;margin-bottom:10px;display:block"></i>
                <div style="font-size:14px;font-weight:800;color:#0f172a;margin-bottom:3px"><?= $title ?></div>
                <div style="font-size:12px;color:#94a3b8;font-weight:500"><?= $sub ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     CATEGORIES
═══════════════════════════════════════════════════════════════ -->
<section id="categories" style="background:#f8fafc;padding:80px 0">
    <div class="container-xl">
        <div style="text-align:center;margin-bottom:52px">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#2563eb;margin-bottom:10px">Browse by Type</div>
            <h2 style="font-size:clamp(28px,4vw,42px);font-weight:900;color:#0f172a;letter-spacing:-1px;margin:0 0 14px">Explore by Category</h2>
            <p style="font-size:16px;color:#64748b;max-width:520px;margin:0 auto;line-height:1.6">
                Choose from our wide selection of vehicle categories. Every car is quality-checked and ready for the road.
            </p>
        </div>

        <?php
        $catIconMap = [
            'SUV'      => ['fa-truck-monster',  '#2563eb', '#dbeafe'],
            'Saloon'   => ['fa-car',            '#7c3aed', '#f3e8ff'],
            'Pick-Up'  => ['fa-truck-pickup',   '#d97706', '#fef3c7'],
            'Van'      => ['fa-van-shuttle',    '#0891b2', '#e0f2fe'],
            'Truck'    => ['fa-truck',          '#64748b', '#f1f5f9'],
            'Hatchback'=> ['fa-car-side',       '#16a34a', '#dcfce7'],
            'Coupe'    => ['fa-car-side',       '#e11d48', '#ffe4e6'],
            'Bus'      => ['fa-bus',            '#0f172a', '#f1f5f9'],
            'Minibus'  => ['fa-bus-simple',     '#7c3aed', '#f3e8ff'],
            'Other'    => ['fa-car',            '#64748b', '#f8fafc'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px">
            <?php foreach ($catCounts as $cat => $count):
                [$ico, $color, $bg] = $catIconMap[$cat] ?? ['fa-car', '#64748b', '#f1f5f9'];
                $isActive = $filterBody === $cat;
            ?>
            <a href="?body=<?= urlencode($cat) ?>#inventory"
               style="background:<?= $isActive ? $color : '#fff' ?>;border:2px solid <?= $isActive ? $color : '#e2e8f0' ?>;border-radius:16px;padding:24px 16px;text-align:center;text-decoration:none;transition:all .2s;display:block;cursor:pointer"
               onmouseover="if(!<?= $isActive ? 'true' : 'false' ?>){this.style.borderColor='<?= $color ?>';this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 32px rgba(0,0,0,.1)'}"
               onmouseout="if(!<?= $isActive ? 'true' : 'false' ?>){this.style.borderColor='#e2e8f0';this.style.transform='';this.style.boxShadow=''}">
                <div style="width:56px;height:56px;border-radius:14px;background:<?= $isActive ? 'rgba(255,255,255,.2)' : $bg ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:22px;color:<?= $isActive ? '#fff' : $color ?>">
                    <i class="fa <?= $ico ?>"></i>
                </div>
                <div style="font-size:15px;font-weight:800;color:<?= $isActive ? '#fff' : '#0f172a' ?>;margin-bottom:4px"><?= htmlspecialchars($cat) ?></div>
                <div style="font-size:13px;color:<?= $isActive ? 'rgba(255,255,255,.7)' : '#94a3b8' ?>;font-weight:600"><?= $count ?> <?= $count === 1 ? 'car' : 'cars' ?></div>
            </a>
            <?php endforeach; ?>

            <?php if (!$catCounts): ?>
            <div style="grid-column:1/-1;text-align:center;padding:40px;color:#94a3b8">
                <i class="fa fa-car" style="font-size:40px;display:block;margin-bottom:12px"></i>
                No vehicles listed yet.
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FEATURED VEHICLES (only shown if featured cars exist)
═══════════════════════════════════════════════════════════════ -->
<?php if (!empty($featuredAll)): ?>
<section style="background:#fff;padding:80px 0">
    <div class="container-xl">
        <div style="text-align:center;margin-bottom:52px">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#f59e0b;margin-bottom:10px">
                <i class="fa fa-star me-1"></i>Staff Picks
            </div>
            <h2 style="font-size:clamp(28px,4vw,42px);font-weight:900;color:#0f172a;letter-spacing:-1px;margin:0 0 14px">Featured Vehicles</h2>
            <p style="font-size:16px;color:#64748b;max-width:500px;margin:0 auto">Handpicked by our experts — the finest vehicles in our current inventory.</p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px">
            <?php foreach (array_slice($featuredAll, 0, 3) as $fc):
                $fcImg = $fc['primary_image'] ? BASE_URL . '/uploads/cars/' . $fc['primary_image'] : null;
                $waMsg = urlencode("Hi, I'm interested in the {$fc['year']} {$fc['make']} {$fc['model']} (KES " . number_format((float)$fc['asking_price']) . ").");
            ?>
            <div class="featured-card">
                <!-- Image -->
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fc['id'] ?>" class="featured-img-wrap">
                    <?php if ($fcImg): ?>
                    <img src="<?= htmlspecialchars($fcImg) ?>" alt="<?= htmlspecialchars($fc['make'].' '.$fc['model']) ?>">
                    <?php else: ?>
                    <div class="featured-no-img"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>
                    <div class="featured-badge"><i class="fa fa-star me-1"></i>Featured</div>
                    <?php if ($fc['image_count'] > 1): ?>
                    <div class="featured-count"><i class="fa fa-images me-1"></i><?= $fc['image_count'] ?></div>
                    <?php endif; ?>
                </a>
                <!-- Body -->
                <div class="featured-body">
                    <div class="featured-meta">
                        <?= $fc['year'] ?>
                        <?php if ($fc['body_type']): ?> &bull; <?= $fc['body_type'] ?><?php endif; ?>
                        <?php if ($fc['fuel_type']): ?> &bull; <?= ucfirst($fc['fuel_type']) ?><?php endif; ?>
                    </div>
                    <h3 class="featured-title">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fc['id'] ?>">
                            <?= htmlspecialchars($fc['make'] . ' ' . $fc['model']) ?>
                        </a>
                    </h3>
                    <div class="featured-specs">
                        <?php if ($fc['transmission']): ?><span><i class="fa fa-gears me-1"></i><?= ucfirst($fc['transmission']) ?></span><?php endif; ?>
                        <?php if ($fc['mileage']):     ?><span><i class="fa fa-gauge me-1"></i><?= number_format($fc['mileage']) ?> km</span><?php endif; ?>
                        <?php if ($fc['engine_cc']):   ?><span><i class="fa fa-cog me-1"></i><?= number_format($fc['engine_cc']) ?> cc</span><?php endif; ?>
                        <?php if ($fc['color']):       ?><span><i class="fa fa-palette me-1"></i><?= htmlspecialchars($fc['color']) ?></span><?php endif; ?>
                    </div>
                    <div class="featured-price">
                        <?php if (!empty($fc['asking_price']) && $fc['asking_price'] > 0): ?>
                            KES <?= number_format((float)$fc['asking_price']) ?>
                        <?php else: ?>
                            <span style="font-size:15px;font-weight:700;color:#64748b">Contact for Price</span>
                        <?php endif; ?>
                    </div>
                    <div class="featured-actions">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fc['id'] ?>" class="btn-view">View Details <i class="fa fa-arrow-right ms-1"></i></a>
                        <?php if ($__waClean): ?>
                        <a href="https://wa.me/<?= $__waClean ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="btn-whatsapp-sm">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
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
     FULL INVENTORY
═══════════════════════════════════════════════════════════════ -->
<section id="inventory" style="background:#f8fafc;padding:80px 0">
    <div class="container-xl">

        <!-- Section header -->
        <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;margin-bottom:40px">
            <div>
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#2563eb;margin-bottom:10px">Complete Inventory</div>
                <h2 style="font-size:clamp(26px,4vw,38px);font-weight:900;color:#0f172a;letter-spacing:-1px;margin:0">
                    All Available Vehicles
                </h2>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <span style="font-size:14px;color:#64748b">
                    <strong style="color:#0f172a"><?= $filteredCount ?></strong> vehicle<?= $filteredCount !== 1 ? 's' : '' ?>
                    <?= $isFiltered ? 'found' : 'available' ?>
                </span>
                <?php if ($isFiltered): ?>
                <a href="#inventory" style="font-size:13px;color:#dc2626;font-weight:600;text-decoration:none;border:1px solid #fca5a5;border-radius:7px;padding:4px 12px;background:#fef2f2">
                    <i class="fa fa-xmark me-1"></i>Clear filters
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:260px 1fr;gap:28px;align-items:start">

            <!-- ── Sidebar Filters ─────────────────────── -->
            <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;overflow:hidden;position:sticky;top:90px;box-shadow:0 4px 20px rgba(0,0,0,.05)">
                <div style="padding:20px 22px;border-bottom:1px solid #f1f5f9;font-weight:800;font-size:15px;color:#0f172a;display:flex;align-items:center;justify-content:space-between">
                    <span><i class="fa fa-sliders me-2 text-primary"></i>Filter</span>
                    <?php if ($isFiltered): ?>
                    <a href="#inventory" style="font-size:12px;color:#94a3b8;font-weight:600;text-decoration:none">Reset</a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="#inventory" style="padding:20px 22px;display:flex;flex-direction:column;gap:20px">
                    <?php if ($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                    <?php if ($sort !== 'featured'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>

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
                        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;display:block;margin-bottom:8px"><?= $label ?></label>
                        <select name="<?= $name ?>" style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 12px;font-size:13.5px;font-family:inherit;color:#0f172a;background:#fff;cursor:pointer;outline:none;transition:border-color .15s"
                                onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'"
                                onchange="this.form.submit()">
                            <option value=""><?= $placeholder ?></option>
                            <?php foreach ($options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $current === $opt ? 'selected' : '' ?>>
                                <?= $ucfirst ? ucfirst($opt) : htmlspecialchars($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>

                    <!-- Price range -->
                    <div>
                        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;display:block;margin-bottom:8px">
                            Price Range (KES)
                        </label>
                        <div style="display:flex;gap:8px;align-items:center">
                            <input type="number" name="min" value="<?= $filterMin ?: '' ?>" placeholder="Min"
                                   style="flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 10px;font-size:13px;font-family:inherit;outline:none;width:0;min-width:0"
                                   onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                            <span style="color:#94a3b8;flex-shrink:0;font-size:12px">–</span>
                            <input type="number" name="max" value="<?= $filterMax ?: '' ?>" placeholder="Max"
                                   style="flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 10px;font-size:13px;font-family:inherit;outline:none;width:0;min-width:0"
                                   onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                        </div>
                    </div>

                    <button type="submit" style="width:100%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:12px;padding:12px;font-size:14px;font-weight:700;cursor:pointer;transition:box-shadow .15s;font-family:inherit"
                            onmouseover="this.style.boxShadow='0 4px 16px rgba(37,99,235,.4)'"
                            onmouseout="this.style.boxShadow='none'">
                        <i class="fa fa-search me-1"></i> Apply Filters
                    </button>
                </form>
            </div>

            <!-- ── Car Grid ─────────────────────────────── -->
            <div>
                <!-- Sort + count bar -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
                    <div style="font-size:13.5px;color:#64748b">
                        Showing <strong style="color:#0f172a"><?= $filteredCount ?></strong> of <strong style="color:#0f172a"><?= $totalStock ?></strong> vehicles
                    </div>
                    <form method="GET" action="#inventory" style="display:flex;align-items:center;gap:8px">
                        <?php foreach (['make','body','fuel','trans','q'] as $k): ?>
                        <?php if (isset($_GET[$k]) && $_GET[$k] !== ''): ?>
                        <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($_GET[$k]) ?>">
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if ($filterMin): ?><input type="hidden" name="min" value="<?= $filterMin ?>"><?php endif; ?>
                        <?php if ($filterMax): ?><input type="hidden" name="max" value="<?= $filterMax ?>"><?php endif; ?>
                        <span style="font-size:13px;color:#64748b;white-space:nowrap">Sort by:</span>
                        <select name="sort" style="border:1.5px solid #e2e8f0;border-radius:9px;padding:7px 12px;font-size:13px;font-family:inherit;outline:none;cursor:pointer;background:#fff;color:#0f172a"
                                onchange="this.form.submit()">
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
                <div style="background:#fff;border-radius:20px;border:1px solid #e2e8f0;padding:60px 24px;text-align:center">
                    <div style="font-size:56px;margin-bottom:16px">🔍</div>
                    <h3 style="font-size:20px;font-weight:800;color:#0f172a;margin-bottom:8px">No vehicles found</h3>
                    <p style="color:#64748b;margin-bottom:20px">Try adjusting your filters or clearing your search.</p>
                    <a href="#inventory" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border-radius:10px;padding:11px 24px;font-weight:700;text-decoration:none;font-size:14px">
                        View All Vehicles
                    </a>
                </div>
                <?php else: ?>

                <!-- Car grid -->
                <div class="inv-grid">
                    <?php foreach ($filteredCars as $car):
                        $img = $car['primary_image'] ? BASE_URL . '/uploads/cars/' . $car['primary_image'] : null;
                        $waMsg = urlencode("Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} (KES " . number_format((float)$car['asking_price']) . ") listed on your showroom.");
                        $isNew = strtotime($car['created_at']) > strtotime('-30 days');
                    ?>
                    <div class="inv-card">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="inv-img-wrap">
                            <?php if ($car['featured']): ?>
                            <span class="inv-badge-featured"><i class="fa fa-star me-1"></i>Featured</span>
                            <?php elseif ($isNew): ?>
                            <span class="inv-badge-new">New</span>
                            <?php endif; ?>
                            <?php if ($car['image_count'] > 1): ?>
                            <span class="inv-badge-photos"><i class="fa fa-images me-1"></i><?= $car['image_count'] ?></span>
                            <?php endif; ?>
                            <?php if ($img): ?>
                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($car['make'].' '.$car['model']) ?>">
                            <?php else: ?>
                            <div class="inv-no-img"><i class="fa fa-car-side"></i></div>
                            <?php endif; ?>
                        </a>
                        <div class="inv-body">
                            <div class="inv-meta">
                                <?= $car['year'] ?>
                                <?php if ($car['transmission']): ?> &bull; <?= ucfirst($car['transmission']) ?><?php endif; ?>
                                <?php if ($car['fuel_type']): ?> &bull; <?= ucfirst($car['fuel_type']) ?><?php endif; ?>
                            </div>
                            <h3 class="inv-title">
                                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>">
                                    <?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>
                                </a>
                            </h3>
                            <div class="inv-specs">
                                <?php if ($car['body_type']): ?><span><?= htmlspecialchars($car['body_type']) ?></span><?php endif; ?>
                                <?php if ($car['mileage']):   ?><span><?= number_format($car['mileage']) ?> km</span><?php endif; ?>
                                <?php if ($car['color']):     ?><span><?= htmlspecialchars($car['color']) ?></span><?php endif; ?>
                            </div>
                            <div class="inv-price">
                                <?php if (!empty($car['asking_price']) && $car['asking_price'] > 0): ?>
                                    KES <?= number_format((float)$car['asking_price']) ?>
                                <?php else: ?>
                                    <span style="font-size:14px;font-weight:700;color:#64748b">Contact for Price</span>
                                <?php endif; ?>
                            </div>
                            <div class="inv-actions">
                                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="inv-btn-view">
                                    View Details
                                </a>
                                <?php if ($__waClean): ?>
                                <a href="https://wa.me/<?= $__waClean ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="inv-btn-wa" title="Enquire on WhatsApp">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </a>
                                <?php endif; ?>
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
     WHY CHOOSE US
═══════════════════════════════════════════════════════════════ -->
<section id="why-us" style="background:var(--navy);padding:88px 0;position:relative;overflow:hidden">
    <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:50px 50px;pointer-events:none"></div>
    <div class="container-xl" style="position:relative;z-index:1">
        <div style="text-align:center;margin-bottom:60px">
            <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#60a5fa;margin-bottom:10px">Our Commitment</div>
            <h2 style="font-size:clamp(28px,4vw,42px);font-weight:900;color:#fff;letter-spacing:-1px;margin:0 0 14px">Why Choose Us?</h2>
            <p style="font-size:16px;color:rgba(255,255,255,.5);max-width:520px;margin:0 auto;line-height:1.6">We're committed to making your car buying experience simple, transparent, and exceptional.</p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:24px">
            <?php
            $whyUs = [
                ['fa-shield-halved', '#22c55e', 'Quality Assured',       'Every vehicle undergoes a thorough inspection before listing. What you see is exactly what you get.'],
                ['fa-eye',           '#3b82f6', 'Transparent Pricing',   'No hidden fees, no surprises. Our asking price is our final price. Full cost breakdown available.'],
                ['fa-credit-card',   '#f59e0b', 'Flexible Financing',    'We work with leading financiers to offer flexible payment plans tailored to your budget.'],
                ['fa-rotate',        '#a78bfa', 'Trade-In Welcome',      'Have a vehicle to trade in? Get a fair market value assessment and upgrade to your dream car.'],
                ['fa-headset',       '#fb923c', 'Expert Guidance',       'Our knowledgeable team is here to guide you through every step of the purchase process.'],
                ['fa-truck',         '#34d399', 'Nationwide Delivery',   'We can arrange delivery of your vehicle to any location across the country.'],
            ];
            foreach ($whyUs as [$ico, $color, $title, $desc]): ?>
            <div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:20px;padding:28px 24px;transition:background .2s,transform .2s"
                 onmouseover="this.style.background='rgba(255,255,255,.07)';this.style.transform='translateY(-4px)'"
                 onmouseout="this.style.background='rgba(255,255,255,.04)';this.style.transform=''">
                <div style="width:52px;height:52px;border-radius:14px;background:<?= $color ?>20;display:flex;align-items:center;justify-content:center;margin-bottom:18px;font-size:22px;color:<?= $color ?>">
                    <i class="fa <?= $ico ?>"></i>
                </div>
                <div style="font-size:17px;font-weight:800;color:#fff;margin-bottom:10px;letter-spacing:-.3px"><?= $title ?></div>
                <p style="font-size:14px;color:rgba(255,255,255,.45);line-height:1.65;margin:0"><?= $desc ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     INQUIRY CTA BAND
═══════════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#2563eb,#7c3aed);padding:64px 0">
    <div class="container-xl">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:28px">
            <div>
                <h2 style="font-size:clamp(24px,4vw,36px);font-weight:900;color:#fff;letter-spacing:-1px;margin:0 0 10px">Ready to find your car?</h2>
                <p style="font-size:16px;color:rgba(255,255,255,.65);margin:0">Talk to our team today. We're here to help.</p>
            </div>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
                <?php if ($__waClean): ?>
                <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                   style="background:#25d366;color:#fff;padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:9px;transition:all .15s;box-shadow:0 4px 20px rgba(0,0,0,.2)"
                   onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform=''">
                    <i class="fa-brands fa-whatsapp" style="font-size:20px"></i> Chat on WhatsApp
                </a>
                <?php endif; ?>
                <a href="#inventory"
                   style="background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.3);padding:14px 28px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:9px;transition:all .15s"
                   onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
                    <i class="fa fa-car"></i> Browse Vehicles
                </a>
            </div>
        </div>
    </div>
</section>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
/* Featured cards */
.featured-card { background:#fff; border-radius:20px; overflow:hidden; border:1px solid #e2e8f0; box-shadow:0 4px 20px rgba(0,0,0,.06); transition:transform .2s, box-shadow .2s; display:flex; flex-direction:column; }
.featured-card:hover { transform:translateY(-6px); box-shadow:0 20px 48px rgba(0,0,0,.12); }
.featured-img-wrap { display:block; position:relative; aspect-ratio:16/10; overflow:hidden; background:#f1f5f9; }
.featured-img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .4s ease; }
.featured-card:hover .featured-img-wrap img { transform:scale(1.04); }
.featured-no-img { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:56px; color:#cbd5e1; }
.featured-badge { position:absolute; top:12px; left:12px; background:#f59e0b; color:#fff; font-size:11.5px; font-weight:700; padding:4px 12px; border-radius:20px; }
.featured-count { position:absolute; top:12px; right:12px; background:rgba(0,0,0,.5); color:#fff; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; }
.featured-body { padding:20px 22px 22px; flex:1; display:flex; flex-direction:column; }
.featured-meta { font-size:11.5px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
.featured-title { font-size:20px; font-weight:800; margin:0 0 10px; letter-spacing:-.4px; }
.featured-title a { color:#0f172a; }
.featured-title a:hover { color:#2563eb; text-decoration:none; }
.featured-specs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
.featured-specs span { font-size:12px; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0; border-radius:7px; padding:3px 10px; }
.featured-price { font-size:22px; font-weight:900; color:#2563eb; letter-spacing:-.5px; margin-top:auto; margin-bottom:16px; }
.featured-actions { display:flex; gap:9px; }
.btn-view { flex:1; background:#0f172a; color:#fff; border-radius:10px; padding:11px 16px; font-size:13.5px; font-weight:700; text-align:center; display:flex; align-items:center; justify-content:center; transition:background .15s; text-decoration:none; }
.btn-view:hover { background:#1e293b; color:#fff; text-decoration:none; }
.btn-whatsapp-sm { width:44px; height:44px; background:#dcfce7; color:#16a34a; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; text-decoration:none; transition:background .15s; flex-shrink:0; }
.btn-whatsapp-sm:hover { background:#25d366; color:#fff; }

/* Inventory grid */
.inv-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:20px; }
.inv-card { background:#fff; border-radius:18px; overflow:hidden; border:1px solid #e2e8f0; box-shadow:0 2px 12px rgba(0,0,0,.05); transition:transform .2s, box-shadow .2s; display:flex; flex-direction:column; }
.inv-card:hover { transform:translateY(-5px); box-shadow:0 16px 40px rgba(0,0,0,.10); }
.inv-img-wrap { display:block; position:relative; aspect-ratio:16/10; overflow:hidden; background:#f1f5f9; flex-shrink:0; }
.inv-img-wrap img { width:100%; height:100%; object-fit:cover; transition:transform .4s ease; }
.inv-card:hover .inv-img-wrap img { transform:scale(1.04); }
.inv-no-img { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-size:44px; color:#cbd5e1; }
.inv-badge-featured { position:absolute; top:10px; left:10px; background:#f59e0b; color:#fff; font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; }
.inv-badge-new { position:absolute; top:10px; left:10px; background:#22c55e; color:#fff; font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; }
.inv-badge-photos { position:absolute; top:10px; right:10px; background:rgba(0,0,0,.5); color:#fff; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; }
.inv-body { padding:16px 18px 18px; flex:1; display:flex; flex-direction:column; }
.inv-meta { font-size:11px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.inv-title { font-size:17px; font-weight:800; margin:0 0 8px; letter-spacing:-.3px; }
.inv-title a { color:#0f172a; }
.inv-title a:hover { color:#2563eb; text-decoration:none; }
.inv-specs { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:10px; }
.inv-specs span { font-size:11px; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:2px 8px; }
.inv-price { font-size:19px; font-weight:900; color:#2563eb; letter-spacing:-.4px; margin-top:auto; margin-bottom:13px; }
.inv-actions { display:flex; gap:8px; }
.inv-btn-view { flex:1; background:#0f172a; color:#fff; border-radius:9px; padding:9px 12px; font-size:13px; font-weight:700; text-align:center; display:flex; align-items:center; justify-content:center; transition:background .15s; text-decoration:none; }
.inv-btn-view:hover { background:#1e293b; color:#fff; text-decoration:none; }
.inv-btn-wa { width:38px; height:38px; background:#dcfce7; color:#16a34a; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:18px; text-decoration:none; transition:background .15s; flex-shrink:0; }
.inv-btn-wa:hover { background:#25d366; color:#fff; }

/* Responsive */
@media (max-width: 1024px) {
    #inventory > .container-xl > div:last-child { grid-template-columns: 1fr; }
    #inventory > .container-xl > div:last-child > div:first-child { position:static; }
}
@media (max-width: 768px) {
    .inv-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .inv-grid { grid-template-columns: 1fr; }
}
</style>

<?php include __DIR__ . '/footer.php'; ?>
