<?php
require_once __DIR__ . '/../config/app.php';

$db = getDB();

// ── Filters ──────────────────────────────────────────────────────────────
$filterMake  = trim($_GET['make']  ?? '');
$filterBody  = trim($_GET['body']  ?? '');
$filterFuel  = trim($_GET['fuel']  ?? '');
$filterTrans = trim($_GET['trans'] ?? '');
$filterMin   = (int)($_GET['min']  ?? 0);
$filterMax   = (int)($_GET['max']  ?? 0);
$sort        = $_GET['sort'] ?? 'featured';
$search      = trim($_GET['q'] ?? '');

// ── Build query ───────────────────────────────────────────────────────────
$where  = ["c.car_type = 'inventory'", "c.asking_price IS NOT NULL", "c.asking_price > 0"];
$params = [];

if ($filterMake)  { $where[] = 'c.make = ?';         $params[] = $filterMake; }
if ($filterBody)  { $where[] = 'c.body_type = ?';    $params[] = $filterBody; }
if ($filterFuel)  { $where[] = 'c.fuel_type = ?';    $params[] = $filterFuel; }
if ($filterTrans) { $where[] = 'c.transmission = ?'; $params[] = $filterTrans; }
if ($filterMin)   { $where[] = 'c.asking_price >= ?';$params[] = $filterMin; }
if ($filterMax)   { $where[] = 'c.asking_price <= ?';$params[] = $filterMax; }
if ($search) {
    $where[] = '(c.make LIKE ? OR c.model LIKE ? OR c.body_type LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$orderBy = match($sort) {
    'price_asc'  => 'c.asking_price ASC',
    'price_desc' => 'c.asking_price DESC',
    'year_desc'  => 'c.year DESC',
    'newest'     => 'c.created_at DESC',
    default      => 'c.featured DESC, c.created_at DESC',
};

$sql = "SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
               c.transmission, c.fuel_type, c.asking_price, c.mileage,
               c.engine_cc, c.featured, c.notes,
               (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
               (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
        FROM cars c
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $orderBy";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($cars);

// ── Filter options ────────────────────────────────────────────────────────
$makes = $db->query("SELECT DISTINCT make FROM cars WHERE car_type='inventory' AND asking_price>0 ORDER BY make")->fetchAll(PDO::FETCH_COLUMN);
$bodyTypes = ['SUV','Saloon','Pick-Up','Hatchback','Van','Truck','Coupe','Bus','Minibus','Other'];

// ── Meta ──────────────────────────────────────────────────────────────────
$companyName = getSetting('company_name', 'Mascardi Car Yard');
$pageTitle   = 'Car Showroom';
$metaDesc    = "Browse {$total} quality vehicles available at {$companyName}. Finance available.";

include __DIR__ . '/header.php';
?>

<!-- Hero -->
<div style="background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 55%,#2563eb 100%);color:#fff;padding:48px 24px;margin:-32px -12px 32px;border-radius:0 0 20px 20px" class="d-md-block">
    <div class="container-lg">
        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:10px">
                    <?= htmlspecialchars($companyName) ?>
                </div>
                <h1 style="font-size:clamp(26px,5vw,42px);font-weight:800;letter-spacing:-1px;margin:0 0 12px;line-height:1.15">
                    Find Your Perfect Car
                </h1>
                <p style="font-size:16px;color:rgba(255,255,255,.65);margin:0 0 24px">
                    <?= $total ?> <?= $total === 1 ? 'vehicle' : 'vehicles' ?> available &mdash; finance options available
                </p>
                <!-- Quick search -->
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="q" class="form-control" placeholder="Search make, model, body type..."
                           value="<?= htmlspecialchars($search) ?>"
                           style="border-radius:10px;border:none;font-size:14px;padding:12px 16px;max-width:340px">
                    <button type="submit" class="btn" style="background:#2563eb;color:#fff;border-radius:10px;padding:12px 20px;font-weight:700;border:2px solid rgba(255,255,255,.3)">
                        <i class="fa fa-search"></i>
                    </button>
                </form>
            </div>
            <div class="col-lg-6 d-none d-lg-flex justify-content-end gap-4">
                <?php
                $stats = [
                    ['icon' => 'fa-car', 'val' => $total, 'lbl' => 'In Stock'],
                    ['icon' => 'fa-star', 'val' => count(array_filter($cars, fn($c) => $c['featured'])), 'lbl' => 'Featured'],
                ];
                foreach ($stats as $s): ?>
                <div style="text-align:center">
                    <div style="font-size:36px;font-weight:800;color:#fff"><?= $s['val'] ?></div>
                    <div style="font-size:12px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1px"><?= $s['lbl'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">

    <!-- ── Sidebar Filters ─────────────────────────────────────── -->
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm" style="border-radius:14px;position:sticky;top:80px">
            <div class="card-body p-0">
                <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:14px;display:flex;align-items:center;justify-content:space-between">
                    <span><i class="fa fa-sliders me-2 text-primary"></i>Filters</span>
                    <?php if ($filterMake || $filterBody || $filterFuel || $filterTrans || $filterMin || $filterMax || $search): ?>
                    <a href="?" style="font-size:12px;color:#94a3b8">Clear all</a>
                    <?php endif; ?>
                </div>
                <form method="GET" action="" style="padding:16px 20px">
                    <?php if ($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8">Make</label>
                        <select name="make" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Makes</option>
                            <?php foreach ($makes as $m): ?>
                            <option value="<?= htmlspecialchars($m) ?>" <?= $filterMake === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8">Body Type</label>
                        <select name="body" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach ($bodyTypes as $bt): ?>
                            <option value="<?= $bt ?>" <?= $filterBody === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8">Fuel Type</label>
                        <select name="fuel" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach (['petrol','diesel','hybrid','electric'] as $f): ?>
                            <option value="<?= $f ?>" <?= $filterFuel === $f ? 'selected' : '' ?>><?= ucfirst($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8">Transmission</label>
                        <select name="trans" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">All</option>
                            <option value="automatic" <?= $filterTrans === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                            <option value="manual"    <?= $filterTrans === 'manual'    ? 'selected' : '' ?>>Manual</option>
                            <option value="cvt"       <?= $filterTrans === 'cvt'       ? 'selected' : '' ?>>CVT</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8">Price Range (KES)</label>
                        <input type="number" name="min" class="form-control form-control-sm mb-1" placeholder="Min" value="<?= $filterMin ?: '' ?>">
                        <input type="number" name="max" class="form-control form-control-sm" placeholder="Max" value="<?= $filterMax ?: '' ?>">
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm w-100">Apply Filters</button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Car Grid ─────────────────────────────────────────────── -->
    <div class="col-lg-9">

        <!-- Sort bar -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div style="font-size:13.5px;color:#64748b">
                <strong style="color:#0f172a"><?= $total ?></strong> vehicle<?= $total !== 1 ? 's' : '' ?> found
            </div>
            <form method="GET" action="" class="d-flex align-items-center gap-2">
                <?php foreach (['make','body','fuel','trans','min','max','q'] as $k): ?>
                <?php if ($${'filter'.ucfirst($k)} ?? ($k==='q'?$search:'')): ?>
                <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($${'filter'.ucfirst($k)} ?? $search) ?>">
                <?php endif; ?>
                <?php endforeach; ?>
                <label class="text-muted" style="font-size:12.5px;white-space:nowrap">Sort by:</label>
                <select name="sort" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto">
                    <option value="featured"   <?= $sort === 'featured'   ? 'selected' : '' ?>>Featured First</option>
                    <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest</option>
                    <option value="price_asc"  <?= $sort === 'price_asc'  ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="year_desc"  <?= $sort === 'year_desc'  ? 'selected' : '' ?>>Year: Newest</option>
                </select>
            </form>
        </div>

        <?php if (!$cars): ?>
        <!-- Empty state -->
        <div class="text-center py-5">
            <div style="font-size:60px;margin-bottom:16px">🚗</div>
            <h5 style="font-weight:700;color:#0f172a">No vehicles found</h5>
            <p style="color:#64748b">Try adjusting your filters or <a href="?">view all cars</a>.</p>
        </div>
        <?php else: ?>
        <div class="row g-3">
        <?php foreach ($cars as $car):
            $imgSrc = $car['primary_image']
                ? BASE_URL . '/uploads/cars/' . htmlspecialchars($car['primary_image'])
                : null;
            $whatsappPhone = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
            $waMsg = urlencode("Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} listed on your showroom. Could you share more details?");
        ?>
        <div class="col-sm-6 col-xl-4">
            <div class="car-card">
                <!-- Image -->
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="car-card-img-wrap">
                    <?php if ($car['featured']): ?>
                    <span class="car-badge-featured"><i class="fa fa-star me-1"></i>Featured</span>
                    <?php endif; ?>
                    <?php if ($car['image_count'] > 1): ?>
                    <span class="car-badge-photos"><i class="fa fa-images me-1"></i><?= $car['image_count'] ?></span>
                    <?php endif; ?>
                    <?php if ($imgSrc): ?>
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>">
                    <?php else: ?>
                    <div class="car-no-img"><i class="fa fa-car"></i></div>
                    <?php endif; ?>
                </a>

                <!-- Info -->
                <div class="car-card-body">
                    <div class="car-meta">
                        <?= $car['year'] ?>
                        <?php if ($car['fuel_type']): ?> &bull; <?= ucfirst($car['fuel_type']) ?><?php endif; ?>
                        <?php if ($car['transmission']): ?> &bull; <?= ucfirst($car['transmission']) ?><?php endif; ?>
                    </div>
                    <h3 class="car-title">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>">
                            <?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>
                        </a>
                    </h3>
                    <div class="car-specs">
                        <?php if ($car['body_type']): ?>
                        <span><i class="fa fa-car me-1"></i><?= htmlspecialchars($car['body_type']) ?></span>
                        <?php endif; ?>
                        <?php if ($car['mileage']): ?>
                        <span><i class="fa fa-gauge me-1"></i><?= number_format($car['mileage']) ?> km</span>
                        <?php endif; ?>
                        <?php if ($car['engine_cc']): ?>
                        <span><i class="fa fa-cog me-1"></i><?= number_format($car['engine_cc']) ?> cc</span>
                        <?php endif; ?>
                        <?php if ($car['color']): ?>
                        <span><i class="fa fa-palette me-1"></i><?= htmlspecialchars($car['color']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="car-price">KES <?= number_format((float)$car['asking_price']) ?></div>
                    <div class="car-actions">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="btn-card-view">
                            View Details <i class="fa fa-arrow-right ms-1"></i>
                        </a>
                        <?php if ($whatsappPhone): ?>
                        <a href="https://wa.me/<?= $whatsappPhone ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="btn-card-wa" title="Enquire on WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ── Car card ── */
.car-card {
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    transition: box-shadow .18s, transform .18s;
    display: flex;
    flex-direction: column;
    height: 100%;
}
.car-card:hover { box-shadow: 0 12px 32px rgba(0,0,0,.11); transform: translateY(-3px); }

.car-card-img-wrap {
    display: block;
    position: relative;
    aspect-ratio: 16/10;
    overflow: hidden;
    background: #f1f5f9;
    flex-shrink: 0;
}
.car-card-img-wrap img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform .4s ease;
}
.car-card:hover .car-card-img-wrap img { transform: scale(1.04); }

.car-no-img {
    width: 100%; height: 100%;
    display: flex; align-items: center; justify-content: center;
    font-size: 48px; color: #cbd5e1;
}

.car-badge-featured {
    position: absolute; top: 10px; left: 10px;
    background: #f59e0b; color: #fff;
    font-size: 11px; font-weight: 700;
    padding: 3px 9px; border-radius: 20px;
    z-index: 1;
}
.car-badge-photos {
    position: absolute; top: 10px; right: 10px;
    background: rgba(0,0,0,.55); color: #fff;
    font-size: 11px; font-weight: 600;
    padding: 3px 9px; border-radius: 20px;
    z-index: 1;
}

.car-card-body { padding: 16px 18px 18px; flex: 1; display: flex; flex-direction: column; }

.car-meta { font-size: 11.5px; color: #94a3b8; font-weight: 600; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .4px; }
.car-title { font-size: 17px; font-weight: 800; margin: 0 0 8px; letter-spacing: -.3px; }
.car-title a { color: #0f172a; }
.car-title a:hover { color: #2563eb; text-decoration: none; }

.car-specs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.car-specs span { font-size: 11.5px; color: #64748b; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 3px 8px; }

.car-price { font-size: 20px; font-weight: 800; color: #2563eb; letter-spacing: -.5px; margin-bottom: 14px; margin-top: auto; }

.car-actions { display: flex; gap: 8px; }
.btn-card-view {
    flex: 1; background: #0f172a; color: #fff;
    border-radius: 10px; padding: 10px 14px;
    font-size: 13px; font-weight: 700; text-align: center;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
    text-decoration: none;
}
.btn-card-view:hover { background: #1e293b; color: #fff; text-decoration: none; }
.btn-card-wa {
    width: 42px; height: 42px;
    background: #dcfce7; color: #16a34a;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; text-decoration: none;
    transition: background .15s;
    flex-shrink: 0;
}
.btn-card-wa:hover { background: #25d366; color: #fff; }
</style>

<?php include __DIR__ . '/footer.php'; ?>
