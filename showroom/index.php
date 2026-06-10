<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

// ── Active filters ─────────────────────────────────────────────────────────────
$filterMake  = trim($_GET['make']  ?? '');
$filterBody  = trim($_GET['body']  ?? '');
$filterFuel  = trim($_GET['fuel']  ?? '');
$filterTrans = trim($_GET['trans'] ?? '');
$filterMin   = (int)($_GET['min']  ?? 0);
$filterMax   = (int)($_GET['max']  ?? 0);
$sort        = $_GET['sort'] ?? 'featured';
$search      = trim($_GET['q']     ?? '');

// ── All inventory cars ─────────────────────────────────────────────────────────
$allCars = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.mileage,
           c.engine_cc, c.featured, c.notes, c.created_at,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c WHERE c.car_type='inventory'
    ORDER BY c.featured DESC, c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalStock  = count($allCars);
$featuredAll = array_values(array_filter($allCars, fn($c) => $c['featured']));

$catCounts = [];
foreach ($allCars as $c) {
    $bt = $c['body_type'] ?: 'Other';
    $catCounts[$bt] = ($catCounts[$bt] ?? 0) + 1;
}
arsort($catCounts);

$makes = $db->query("SELECT DISTINCT make FROM cars WHERE car_type='inventory' AND make!='' ORDER BY make")->fetchAll(PDO::FETCH_COLUMN);

// ── 360° showcase car ──────────────────────────────────────────────────────────
$showcase = null;
$showcaseImages = [];
if ($featuredAll) {
    $showcase = $featuredAll[0];
    $sImgs = $db->prepare("SELECT file_path FROM car_images WHERE car_id=? ORDER BY is_primary DESC, id ASC LIMIT 16");
    $sImgs->execute([$showcase['id']]);
    $showcaseImages = $sImgs->fetchAll(PDO::FETCH_COLUMN);
}

// ── Filtered inventory ─────────────────────────────────────────────────────────
$where  = ["c.car_type='inventory'"]; $params = [];
if ($filterMake)  { $where[] = 'c.make=?';                                             $params[] = $filterMake; }
if ($filterBody)  { $where[] = 'c.body_type=?';                                        $params[] = $filterBody; }
if ($filterFuel)  { $where[] = 'c.fuel_type=?';                                        $params[] = $filterFuel; }
if ($filterTrans) { $where[] = 'c.transmission=?';                                     $params[] = $filterTrans; }
if ($filterMin)   { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price>=?';     $params[] = $filterMin; }
if ($filterMax)   { $where[] = 'c.asking_price IS NOT NULL AND c.asking_price<=?';     $params[] = $filterMax; }
if ($search) {
    $where[] = '(c.make LIKE ? OR c.model LIKE ? OR c.body_type LIKE ? OR c.color LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
$orderBy = match($sort) {
    'price_asc'  => '(c.asking_price IS NULL OR c.asking_price=0) ASC, c.asking_price ASC',
    'price_desc' => '(c.asking_price IS NULL OR c.asking_price=0) ASC, c.asking_price DESC',
    'year_desc'  => 'c.year DESC, c.created_at DESC',
    'newest'     => 'c.created_at DESC',
    default      => 'c.featured DESC, c.created_at DESC',
};
$stmt = $db->prepare("
    SELECT c.id, c.make, c.model, c.year, c.color, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.mileage,
           c.engine_cc, c.featured, c.created_at,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image
    FROM cars c WHERE ".implode(' AND ',$where)." ORDER BY $orderBy
");
$stmt->execute($params);
$filteredCars  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredCount = count($filteredCars);
$isFiltered    = $filterMake||$filterBody||$filterFuel||$filterTrans||$filterMin||$filterMax||$search;

$companyName = getSetting('company_name', 'Mascardi Car Yard');
$__waClean   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
$pageTitle   = 'Luxury Car Showroom';
$metaDesc    = "Browse {$totalStock} premium vehicles at {$companyName}. Transparent pricing, flexible financing.";

// Body icon map for categories
$bodyIcons = [
    'SUV'=>'fa-car-side','Saloon'=>'fa-car','Pick-Up'=>'fa-truck-pickup','Van'=>'fa-van-shuttle',
    'Truck'=>'fa-truck','Hatchback'=>'fa-car-rear','Coupe'=>'fa-car-burst','Bus'=>'fa-bus',
    'Minibus'=>'fa-bus-simple','Other'=>'fa-car-on',
];

include __DIR__ . '/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   MASCARDI LUXURY LANDING — DARK EDITION
══════════════════════════════════════════════════════════ */
:root {
    --c-bg:      #030508;
    --c-bg2:     #070c14;
    --c-bg3:     #0d1628;
    --c-card:    #111827;
    --c-gold:    #c9a96e;
    --c-gold2:   #f0d08a;
    --c-blue:    #4f8ef7;
    --c-blue2:   #7eb8f7;
    --c-border:  rgba(255,255,255,0.07);
    --c-text:    #f1f5f9;
    --c-text2:   rgba(241,245,249,0.55);
    --c-text3:   rgba(241,245,249,0.28);
}
body { background: var(--c-bg) !important; color: var(--c-text) !important; }

/* ── Shared section utility ──────────────────────────────── */
.lp-section-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 2.5px; color: var(--c-gold); margin-bottom: 12px;
}
.lp-section-title {
    font-size: clamp(30px,4.5vw,50px); font-weight: 900;
    color: var(--c-text); letter-spacing: -2px; line-height: 1.07; margin: 0 0 18px;
}
.lp-section-sub {
    font-size: 15px; color: var(--c-text2); line-height: 1.8; max-width: 520px;
}

/* ── HERO ─────────────────────────────────────────────────── */
.lp-hero {
    min-height: 100vh; background: var(--c-bg);
    display: flex; align-items: center;
    position: relative; overflow: hidden; padding: 130px 0 100px;
}
#heroCanvas { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.hero-glow {
    position: absolute; border-radius: 50%; pointer-events: none;
    animation: ambientPulse 5s ease-in-out infinite;
}
.hero-glow-1 {
    width: 700px; height: 700px;
    background: radial-gradient(circle, rgba(79,142,247,.10) 0%, transparent 65%);
    top: -150px; left: -150px;
}
.hero-glow-2 {
    width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(201,169,110,.07) 0%, transparent 65%);
    bottom: -80px; right: -80px; animation-delay: -2.5s;
}
.hero-glow-3 {
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(79,142,247,.05) 0%, transparent 70%);
    top: 40%; left: 35%; animation-delay: -1.2s;
}
@keyframes ambientPulse {
    0%,100% { opacity:.7; transform:scale(1); }
    50%      { opacity:1; transform:scale(1.06); }
}

.hero-eyebrow {
    display: inline-flex; align-items: center; gap: 8px;
    background: rgba(79,142,247,.1); border: 1px solid rgba(79,142,247,.22);
    border-radius: 50px; padding: 6px 18px; margin-bottom: 28px;
    font-size: 11.5px; font-weight: 700; letter-spacing: .8px;
    text-transform: uppercase; color: var(--c-blue2);
}
.hero-pulse { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; flex-shrink: 0;
    box-shadow: 0 0 0 0 rgba(34,197,94,.4);
    animation: pulseDot 1.8s ease-in-out infinite;
}
@keyframes pulseDot {
    0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.5); }
    70%  { box-shadow: 0 0 0 8px rgba(34,197,94,0); }
    100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); }
}

.hero-title {
    font-size: clamp(40px, 6.5vw, 72px); font-weight: 900;
    color: #fff; letter-spacing: -3px; line-height: 1.0; margin: 0 0 24px;
}
.hero-title .grad {
    background: linear-gradient(135deg, var(--c-gold), var(--c-gold2), var(--c-gold));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    background-size: 200% auto; animation: goldShine 4s linear infinite;
}
@keyframes goldShine {
    0%   { background-position: 0% center; }
    100% { background-position: 200% center; }
}
.hero-divider { width: 60px; height: 2px; background: linear-gradient(90deg,var(--c-gold),transparent);
    border-radius: 2px; margin-bottom: 22px; }
.hero-sub {
    font-size: 17px; color: var(--c-text2); line-height: 1.78;
    max-width: 460px; margin: 0 0 38px;
}

/* Hero search */
.hero-search {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
    border-radius: 18px; padding: 10px; display: flex; gap: 8px; flex-wrap: wrap;
    max-width: 540px; backdrop-filter: blur(12px);
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
}
.hero-search input, .hero-search select {
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.09);
    border-radius: 11px; padding: 11px 14px; color: #fff; font-size: 14px;
    font-family: inherit; outline: none; transition: border-color .2s;
}
.hero-search input:focus, .hero-search select:focus { border-color: rgba(201,169,110,.45); }
.hero-search input { flex: 1; min-width: 150px; }
.hero-search input::placeholder { color: var(--c-text3); }
.hero-search select option { background: #0d1628; }
.hero-search-btn {
    background: linear-gradient(135deg, var(--c-gold), #b8873e);
    color: #0a0e1a; border: none; border-radius: 11px; padding: 11px 24px;
    font-size: 14px; font-weight: 800; cursor: pointer; letter-spacing: .3px;
    transition: box-shadow .2s, transform .15s; white-space: nowrap; flex-shrink: 0;
}
.hero-search-btn:hover { box-shadow: 0 6px 24px rgba(201,169,110,.45); transform: translateY(-1px); }

/* Hero stats */
.hero-stats { display: flex; gap: 40px; margin-top: 36px; flex-wrap: wrap; }
.hero-stat-n { font-size: 30px; font-weight: 900; color: #fff; letter-spacing: -1.5px; }
.hero-stat-l {
    font-size: 11px; color: var(--c-text3); font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px; margin-top: 2px;
}

/* Hero car card */
.hero-car-card {
    background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
    border-radius: 26px; overflow: hidden;
    box-shadow: 0 40px 100px rgba(0,0,0,.55), 0 0 0 1px rgba(201,169,110,.07);
    animation: floatCard 7s ease-in-out infinite;
}
@keyframes floatCard {
    0%,100% { transform: translateY(0px) rotate(0.3deg); }
    50%      { transform: translateY(-14px) rotate(-0.3deg); }
}
.hero-car-img-wrap { overflow: hidden; position: relative; }
.hero-car-img-wrap img { width: 100%; height: 280px; object-fit: cover; transition: transform .5s; }
.hero-car-card:hover .hero-car-img-wrap img { transform: scale(1.04); }
.hero-car-img-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to top, rgba(13,22,40,.9) 0%, transparent 55%);
    pointer-events: none;
}
.hero-car-no-img {
    width: 100%; height: 280px;
    background: linear-gradient(135deg, rgba(79,142,247,.08), rgba(201,169,110,.04));
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.06); font-size: 80px;
}
.hero-car-info { padding: 22px 26px 26px; }
.hero-car-tag {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(201,169,110,.1); border: 1px solid rgba(201,169,110,.22);
    border-radius: 8px; padding: 3px 11px; font-size: 11px; font-weight: 700;
    color: var(--c-gold); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px;
}
.hero-car-name { font-size: 22px; font-weight: 900; color: #fff; letter-spacing: -.6px; margin-bottom: 6px; }
.hero-car-meta { font-size: 12px; color: var(--c-text3); margin-bottom: 16px; }
.hero-car-bottom { display: flex; justify-content: space-between; align-items: center; }
.hero-car-price { font-size: 24px; font-weight: 900; color: var(--c-gold); letter-spacing: -1px; }
.btn-view-car {
    background: linear-gradient(135deg, var(--c-blue), #2563eb);
    color: #fff; border: none; border-radius: 10px; padding: 10px 20px;
    font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex;
    align-items: center; gap: 7px; transition: box-shadow .2s, transform .15s;
}
.btn-view-car:hover {
    box-shadow: 0 6px 22px rgba(79,142,247,.45); transform: translateY(-1px);
    color: #fff; text-decoration: none;
}

/* Scroll indicator */
.scroll-hint {
    position: absolute; bottom: 30px; left: 50%; transform: translateX(-50%);
    text-align: center; animation: fadeInUp 1.5s 2s both;
}
@keyframes fadeInUp { from { opacity:0; transform: translateX(-50%) translateY(12px); } to { opacity:1; transform: translateX(-50%) translateY(0); } }
.scroll-line {
    width: 1px; height: 44px; margin: 6px auto 0;
    background: linear-gradient(to bottom, rgba(255,255,255,.2), transparent);
    animation: scrollBounce 1.5s ease-in-out infinite;
}
@keyframes scrollBounce { 0%,100%{transform:scaleY(1)} 50%{transform:scaleY(0.7)} }

/* ── 360° SHOWCASE ───────────────────────────────────────── */
.showcase-section {
    background: var(--c-bg2); padding: 120px 0;
    position: relative; overflow: hidden;
}
.showcase-section::before {
    content: ''; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(
        0deg, transparent, transparent 60px, rgba(255,255,255,.012) 60px, rgba(255,255,255,.012) 61px
    ), repeating-linear-gradient(
        90deg, transparent, transparent 60px, rgba(255,255,255,.012) 60px, rgba(255,255,255,.012) 61px
    );
    pointer-events: none;
}

/* Viewer */
.viewer-outer {
    position: relative; display: flex; flex-direction: column; align-items: center;
    user-select: none; -webkit-user-select: none;
}
.viewer-stage {
    width: 100%; max-width: 680px; aspect-ratio: 16/9;
    background:
        radial-gradient(ellipse at 50% 75%, rgba(79,142,247,.09) 0%, transparent 55%),
        radial-gradient(ellipse at 50% 50%, rgba(13,22,40,.97) 0%, #030508 100%);
    border-radius: 22px; border: 1px solid rgba(255,255,255,.07);
    position: relative; overflow: hidden; cursor: grab;
    box-shadow: 0 40px 100px rgba(0,0,0,.6), 0 0 0 1px rgba(79,142,247,.05);
    transition: box-shadow .3s;
}
.viewer-stage:active { cursor: grabbing; }
.viewer-stage:hover { box-shadow: 0 40px 100px rgba(0,0,0,.6), 0 0 40px rgba(79,142,247,.06); }

/* Ground plane */
.viewer-ground {
    position: absolute; bottom: 22%; left: 5%; right: 5%; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(79,142,247,.3), rgba(201,169,110,.2), transparent);
}
.viewer-ground-reflect {
    position: absolute; bottom: 0; left: 0; right: 0; height: 28%;
    background: linear-gradient(to bottom, transparent, rgba(5,10,20,.7));
    pointer-events: none;
}

/* Car container */
.car-360-wrap {
    position: absolute; inset: 6% 4%;
    display: flex; align-items: center; justify-content: center;
    transform-style: preserve-3d; will-change: transform;
    transition: transform .06s linear;
}
.car-360-img {
    max-width: 92%; max-height: 88%; object-fit: contain; display: block;
    filter: drop-shadow(0 16px 40px rgba(0,0,0,.7)) drop-shadow(0 0 50px rgba(79,142,247,.12));
    transition: filter .4s;
    pointer-events: none;
}
.viewer-stage:hover .car-360-img {
    filter: drop-shadow(0 16px 40px rgba(0,0,0,.7)) drop-shadow(0 0 70px rgba(79,142,247,.18));
}

/* Door overlay */
.door-overlay {
    position: absolute; inset: 0; pointer-events: none;
    display: flex; align-items: center; justify-content: center;
}
.door-panel {
    position: absolute; top: 22%; left: 32%; width: 20%; height: 46%;
    background: rgba(79,142,247,.14);
    border: 1.5px solid rgba(79,142,247,.45);
    border-radius: 3px 3px 6px 6px;
    transform-origin: left center;
    transition: transform .85s cubic-bezier(.34,1.2,.64,1);
    box-shadow: inset 0 0 20px rgba(79,142,247,.1);
}
.door-panel.door-open { transform: perspective(700px) rotateY(-72deg) translateX(-3%); }
.door-interior {
    position: absolute; top: 22%; left: 33%; width: 18%; height: 46%;
    background: linear-gradient(160deg, #1a0c06 0%, #2a1510 30%, #1a1a2e 100%);
    border-radius: 2px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    opacity: 0; transition: opacity .45s .45s; pointer-events: none;
    box-shadow: inset 0 0 30px rgba(0,0,0,.8);
}
.door-interior.door-open { opacity: 1; }
.door-interior i { font-size: 18px; color: rgba(201,169,110,.6); margin-bottom: 5px; }
.door-interior span { font-size: 8px; color: rgba(201,169,110,.4); text-align: center; letter-spacing: 1px; text-transform: uppercase; }

/* Angle display */
.angle-display {
    position: absolute; top: 12px; right: 14px;
    font-size: 11px; font-weight: 700; color: rgba(255,255,255,.25);
    letter-spacing: 1px;
}

/* Rotation dots */
.rot-indicator {
    position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%);
    display: flex; gap: 5px; align-items: center;
}
.rot-dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: rgba(255,255,255,.12); transition: all .2s;
}
.rot-dot.active {
    background: var(--c-gold); width: 14px; border-radius: 3px;
}

/* Viewer controls */
.viewer-controls { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; justify-content: center; }
.vbtn {
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1);
    border-radius: 50px; padding: 9px 22px; font-size: 12.5px; font-weight: 600;
    color: rgba(255,255,255,.6); cursor: pointer; transition: all .2s; letter-spacing: .3px;
    font-family: inherit;
}
.vbtn:hover { background: rgba(255,255,255,.09); color: #fff; border-color: rgba(255,255,255,.22); }
.vbtn.vbtn-active {
    background: rgba(201,169,110,.14); border-color: rgba(201,169,110,.4); color: var(--c-gold);
}
.drag-hint { font-size: 11.5px; color: var(--c-text3); text-align: center; margin-top: 10px; letter-spacing: .5px; }

/* No image state */
.viewer-no-img {
    position: absolute; inset: 0; display: flex; flex-direction: column;
    align-items: center; justify-content: center; gap: 14px;
    color: rgba(255,255,255,.08);
}
.viewer-no-img i { font-size: 64px; }
.viewer-no-img span { font-size: 13px; letter-spacing: 2px; text-transform: uppercase; }

/* ── STATS BAND ───────────────────────────────────────────── */
.stats-band {
    background: var(--c-bg3); border-top: 1px solid var(--c-border);
    border-bottom: 1px solid var(--c-border); padding: 60px 0;
}
.stat-item { text-align: center; position: relative; }
.stat-item + .stat-item::before {
    content: ''; position: absolute; left: 0; top: 15%; height: 70%;
    width: 1px; background: var(--c-border);
}
.stat-n {
    font-size: clamp(36px,5vw,54px); font-weight: 900; color: #fff;
    letter-spacing: -2px; line-height: 1;
}
.stat-n em { color: var(--c-gold); font-style: normal; }
.stat-l { font-size: 11px; font-weight: 700; color: var(--c-text3); text-transform: uppercase; letter-spacing: 1.5px; margin-top: 6px; }

/* ── FEATURED VEHICLES ─────────────────────────────────────── */
.featured-section { background: var(--c-bg); padding: 120px 0; }

.car-card {
    background: var(--c-bg3); border: 1px solid var(--c-border);
    border-radius: 20px; overflow: hidden;
    transition: transform .35s cubic-bezier(.34,1.4,.64,1), box-shadow .35s, border-color .35s;
    display: block; text-decoration: none; color: inherit;
}
.car-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 32px 80px rgba(0,0,0,.45), 0 0 0 1px rgba(201,169,110,.18);
    border-color: rgba(201,169,110,.18); text-decoration: none; color: inherit;
}
.car-card-img-box { overflow: hidden; position: relative; }
.car-card-img-box img { width: 100%; height: 210px; object-fit: cover; transition: transform .45s; display: block; }
.car-card:hover .car-card-img-box img { transform: scale(1.05); }
.car-card-img-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to top, rgba(13,22,40,.85) 0%, transparent 55%);
    pointer-events: none;
}
.car-card-no-img {
    width: 100%; height: 210px;
    background: linear-gradient(135deg, rgba(79,142,247,.07), rgba(201,169,110,.04));
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.05); font-size: 56px;
}
.car-card-featured-badge {
    position: absolute; top: 14px; left: 14px;
    background: rgba(201,169,110,.15); border: 1px solid rgba(201,169,110,.3);
    border-radius: 8px; padding: 3px 10px; font-size: 10px; font-weight: 800;
    color: var(--c-gold); text-transform: uppercase; letter-spacing: .5px;
}
.car-card-body { padding: 20px 20px 22px; }
.car-card-year { font-size: 10.5px; font-weight: 700; color: var(--c-text3); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
.car-card-name { font-size: 18px; font-weight: 900; color: var(--c-text); letter-spacing: -.4px; margin-bottom: 10px; }
.car-card-specs { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.car-card-spec { font-size: 11.5px; color: var(--c-text3); display: flex; align-items: center; gap: 5px; }
.car-card-spec i { color: rgba(201,169,110,.55); font-size: 11px; }
.car-card-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 4px; }
.car-card-price { font-size: 20px; font-weight: 900; color: var(--c-gold); letter-spacing: -.8px; }
.car-card-price-sub { font-size: 10.5px; color: var(--c-text3); }
.car-card-arrow {
    width: 34px; height: 34px; border-radius: 50%;
    background: rgba(255,255,255,.05); border: 1px solid var(--c-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; color: var(--c-text3);
    transition: all .25s;
}
.car-card:hover .car-card-arrow {
    background: rgba(201,169,110,.15); border-color: rgba(201,169,110,.3); color: var(--c-gold);
}

/* ── CATEGORIES ─────────────────────────────────────────────── */
.categories-section { background: var(--c-bg2); padding: 100px 0; }
.cat-card {
    background: rgba(255,255,255,.03); border: 1px solid var(--c-border);
    border-radius: 18px; padding: 28px 20px; text-align: center;
    cursor: pointer; transition: all .28s; text-decoration: none; display: block; color: inherit;
}
.cat-card:hover {
    background: rgba(201,169,110,.07); border-color: rgba(201,169,110,.2);
    transform: translateY(-5px); text-decoration: none; color: inherit;
}
.cat-card i { font-size: 26px; color: var(--c-gold); margin-bottom: 10px; display: block; transition: transform .3s; }
.cat-card:hover i { transform: scale(1.15); }
.cat-card-name { font-size: 13.5px; font-weight: 800; color: var(--c-text); margin-bottom: 4px; }
.cat-card-count { font-size: 11px; color: var(--c-text3); }

/* ── INVENTORY ─────────────────────────────────────────────── */
.inventory-section { background: var(--c-bg); padding: 100px 0; }

.filter-panel {
    background: var(--c-bg3); border: 1px solid var(--c-border);
    border-radius: 18px; padding: 24px; position: sticky; top: 80px;
}
.filter-heading {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 1.5px; color: var(--c-text3); margin-bottom: 20px;
    padding-bottom: 14px; border-bottom: 1px solid var(--c-border);
}
.filter-group { margin-bottom: 18px; }
.filter-group-label { font-size: 11px; font-weight: 700; color: var(--c-text3); text-transform: uppercase; letter-spacing: .7px; margin-bottom: 8px; display: block; }
.fselect {
    width: 100%; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
    border-radius: 10px; padding: 10px 12px; color: var(--c-text); font-size: 13px;
    font-family: inherit; outline: none; transition: border-color .2s; cursor: pointer;
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='rgba(255,255,255,.3)' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
    padding-right: 32px;
}
.fselect:focus { border-color: rgba(201,169,110,.4); }
.fselect option { background: #0d1628; }

.finput {
    width: 100%; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.09);
    border-radius: 10px; padding: 10px 12px; color: var(--c-text); font-size: 13px;
    font-family: inherit; outline: none; transition: border-color .2s;
}
.finput:focus { border-color: rgba(201,169,110,.4); }
.finput::placeholder { color: var(--c-text3); }

.btn-apply-filter {
    width: 100%; background: linear-gradient(135deg, var(--c-gold), #b8873e);
    color: #0a0e1a; border: none; border-radius: 10px; padding: 12px;
    font-size: 14px; font-weight: 800; cursor: pointer; transition: box-shadow .2s; letter-spacing: .3px;
    font-family: inherit;
}
.btn-apply-filter:hover { box-shadow: 0 6px 24px rgba(201,169,110,.4); }

/* Sort bar */
.inv-sort-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 22px; }
.inv-count { font-size: 14px; color: var(--c-text2); }
.inv-count strong { color: var(--c-text); }
.fsort {
    background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.09);
    border-radius: 10px; padding: 9px 14px; color: var(--c-text); font-size: 13px;
    font-family: inherit; cursor: pointer; outline: none;
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='rgba(255,255,255,.3)' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center; padding-right: 32px;
}
.fsort option { background: #0d1628; }

.no-results-dark { text-align: center; padding: 80px 20px; color: var(--c-text3); }
.no-results-dark i { font-size: 48px; display: block; margin-bottom: 16px; }

/* ── WHY US ─────────────────────────────────────────────────── */
.whyus-section { background: var(--c-bg2); padding: 120px 0; position: relative; overflow: hidden; }

.feat-card {
    background: rgba(255,255,255,.025); border: 1px solid var(--c-border);
    border-radius: 20px; padding: 34px 28px;
    transition: transform .35s cubic-bezier(.34,1.4,.64,1), box-shadow .3s, border-color .3s, background .3s;
}
.feat-card:hover {
    transform: translateY(-7px); box-shadow: 0 24px 60px rgba(0,0,0,.3);
    border-color: rgba(201,169,110,.14); background: rgba(255,255,255,.04);
}
.feat-icon {
    width: 58px; height: 58px; border-radius: 14px;
    background: linear-gradient(135deg, rgba(201,169,110,.14), rgba(201,169,110,.04));
    border: 1px solid rgba(201,169,110,.18);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: var(--c-gold); margin-bottom: 20px; transition: box-shadow .3s;
}
.feat-card:hover .feat-icon { box-shadow: 0 8px 24px rgba(201,169,110,.2); }
.feat-title { font-size: 17px; font-weight: 800; color: var(--c-text); letter-spacing: -.3px; margin-bottom: 10px; }
.feat-text { font-size: 13.5px; color: rgba(241,245,249,.42); line-height: 1.78; }

/* ── BOOK SERVICE CTA ────────────────────────────────────────── */
.service-cta {
    background: linear-gradient(160deg, var(--c-bg2) 0%, var(--c-bg) 100%);
    border-top: 1px solid var(--c-border); padding: 110px 0; position: relative; overflow: hidden;
}
.service-cta::before {
    content: ''; position: absolute; width: 700px; height: 700px; border-radius: 50%;
    background: radial-gradient(circle, rgba(201,169,110,.06) 0%, transparent 60%);
    right: -150px; top: -150px; pointer-events: none;
}
.service-cta::after {
    content: ''; position: absolute; width: 400px; height: 400px; border-radius: 50%;
    background: radial-gradient(circle, rgba(79,142,247,.05) 0%, transparent 60%);
    left: -80px; bottom: -80px; pointer-events: none;
}

/* CTA Buttons */
.btn-gold {
    background: linear-gradient(135deg, var(--c-gold), #b8873e);
    color: #0a0e1a; border: none; border-radius: 12px; padding: 14px 32px;
    font-size: 15px; font-weight: 800; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: box-shadow .2s, transform .15s; letter-spacing: .3px; font-family: inherit;
}
.btn-gold:hover { box-shadow: 0 8px 30px rgba(201,169,110,.5); transform: translateY(-2px); text-decoration: none; color: #0a0e1a; }

.btn-ghost-white {
    background: rgba(255,255,255,.05); color: rgba(255,255,255,.7);
    border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 14px 32px;
    font-size: 15px; font-weight: 700; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all .2s; font-family: inherit;
}
.btn-ghost-white:hover { background: rgba(255,255,255,.1); color: #fff; border-color: rgba(255,255,255,.28); text-decoration: none; }

/* ── SCROLL REVEAL STATES ────────────────────────────────────── */
.gs-fade   { opacity: 0; }
.gs-up     { opacity: 0; transform: translateY(45px); }
.gs-left   { opacity: 0; transform: translateX(-45px); }
.gs-right  { opacity: 0; transform: translateX(45px); }
.gs-scale  { opacity: 0; transform: scale(0.88); }

/* ── FOOTER OVERRIDE ─────────────────────────────────────────── */
footer a { color: rgba(255,255,255,.5) !important; }
footer a:hover { color: #fff !important; }

/* ── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 991px) {
    .lp-hero { padding: 100px 0 80px; }
    .hero-car-card { display: none; }
}
@media (max-width: 767px) {
    .stat-item + .stat-item::before { display: none; }
    .hero-stats { gap: 24px; }
}
</style>

<!-- ═══════════════════════════════ HERO ════════════════════════ -->
<section class="lp-hero" id="hero">
    <canvas id="heroCanvas"></canvas>
    <div class="hero-glow hero-glow-1"></div>
    <div class="hero-glow hero-glow-2"></div>
    <div class="hero-glow hero-glow-3"></div>

    <div class="container-xl" style="position:relative;z-index:1">
        <div class="row align-items-center g-5">

            <!-- Text side -->
            <div class="col-lg-6">
                <div id="heroLeft">
                    <div class="hero-divider"></div>
                    <div class="hero-eyebrow">
                        <span class="hero-pulse"></span>
                        <?= $totalStock ?> Vehicles Available Now
                    </div>
                    <h1 class="hero-title">
                        Drive The<br>
                        <span class="grad">Extraordinary</span>
                    </h1>
                    <p class="hero-sub">
                        Curated luxury and performance vehicles with transparent pricing, flexible financing, and a buying experience that sets a new standard.
                    </p>
                    <form method="GET" action="#inventory" class="hero-search">
                        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search make, model, type...">
                        <select name="body" style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:11px;padding:11px 14px;color:#fff;font-size:14px;font-family:inherit;outline:none;flex-shrink:0">
                            <option value="" style="background:#0d1628">All Types</option>
                            <?php foreach (array_keys($catCounts) as $bt): ?>
                            <option value="<?= e($bt) ?>" <?= $filterBody===$bt?'selected':'' ?> style="background:#0d1628"><?= e($bt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="hero-search-btn"><i class="fa fa-search"></i> Search</button>
                    </form>
                    <div class="hero-stats">
                        <div>
                            <div class="hero-stat-n" data-count="<?= $totalStock ?>"><?= $totalStock ?>+</div>
                            <div class="hero-stat-l">Vehicles in Stock</div>
                        </div>
                        <div>
                            <div class="hero-stat-n" data-count="<?= count($featuredAll) ?>"><?= count($featuredAll) ?>+</div>
                            <div class="hero-stat-l">Featured Picks</div>
                        </div>
                        <div>
                            <div class="hero-stat-n" data-count="<?= count($catCounts) ?>"><?= count($catCounts) ?>+</div>
                            <div class="hero-stat-l">Categories</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Featured car card -->
            <div class="col-lg-6 d-none d-lg-block">
                <?php if ($featuredAll): $fc = $featuredAll[0];
                    $fcImg = $fc['primary_image'] ? BASE_URL.'/uploads/cars/'.$fc['primary_image'] : null; ?>
                <div class="hero-car-card" id="heroCard">
                    <div class="hero-car-img-wrap">
                        <?php if ($fcImg): ?>
                        <img src="<?= e($fcImg) ?>" alt="<?= e($fc['make'].' '.$fc['model']) ?>">
                        <?php else: ?>
                        <div class="hero-car-no-img"><i class="fa fa-car-side"></i></div>
                        <?php endif; ?>
                        <div class="hero-car-img-overlay"></div>
                    </div>
                    <div class="hero-car-info">
                        <div class="hero-car-tag"><i class="fa fa-star" style="font-size:9px"></i> Featured Vehicle</div>
                        <div class="hero-car-name"><?= e($fc['make'].' '.$fc['model']) ?></div>
                        <div class="hero-car-meta">
                            <?= $fc['year'] ?> &nbsp;·&nbsp;
                            <?= e(ucfirst($fc['transmission'] ?? 'Auto')) ?> &nbsp;·&nbsp;
                            <?= e(ucfirst($fc['fuel_type'] ?? '')) ?>
                            <?php if ($fc['mileage']): ?>&nbsp;·&nbsp; <?= number_format($fc['mileage']) ?> km<?php endif; ?>
                        </div>
                        <div class="hero-car-bottom">
                            <div>
                                <div class="hero-car-price">KES <?= number_format((float)$fc['asking_price']) ?></div>
                            </div>
                            <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fc['id'] ?>" class="btn-view-car">
                                View Details <i class="fa fa-arrow-right" style="font-size:11px"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Scroll hint -->
    <div class="scroll-hint">
        <div style="font-size:10px;color:rgba(255,255,255,.2);text-transform:uppercase;letter-spacing:2.5px">Scroll</div>
        <div class="scroll-line"></div>
    </div>
</section>

<!-- ═══════════════════════════ 360° SHOWCASE ═══════════════════ -->
<?php if ($showcase): ?>
<section class="showcase-section" id="showcase">
    <div class="container-xl">
        <div class="row g-5 align-items-center">

            <!-- Left: Info -->
            <div class="col-lg-5 gs-left">
                <div class="lp-section-label">Interactive Showcase</div>
                <h2 class="lp-section-title">Experience<br>Every Angle</h2>
                <p class="lp-section-sub">
                    Drag to rotate the vehicle 360°. Explore every curve, every detail. Open the door to reveal the interior experience.
                </p>
                <div style="margin-top:32px;display:flex;flex-direction:column;gap:14px">
                    <?php foreach ([
                        ['fa-arrows-rotate','Drag to Spin','Interact with the vehicle in full 360°'],
                        ['fa-door-open',     'Open the Door','Reveal the interior with a single click'],
                        ['fa-magnifying-glass','Full Details',  'Click View Details for full specs &amp; gallery'],
                    ] as [$ico,$ttl,$txt]): ?>
                    <div style="display:flex;align-items:flex-start;gap:14px">
                        <div style="width:40px;height:40px;border-radius:10px;background:rgba(201,169,110,.1);border:1px solid rgba(201,169,110,.18);display:flex;align-items:center;justify-content:center;color:var(--c-gold);font-size:15px;flex-shrink:0">
                            <i class="fa <?= $ico ?>"></i>
                        </div>
                        <div>
                            <div style="font-size:14px;font-weight:800;color:var(--c-text);margin-bottom:3px"><?= $ttl ?></div>
                            <div style="font-size:13px;color:var(--c-text3);line-height:1.6"><?= $txt ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="margin-top:32px;display:flex;gap:12px;flex-wrap:wrap">
                    <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $showcase['id'] ?>" class="btn-gold">
                        <i class="fa fa-eye"></i> View Full Details
                    </a>
                    <a href="#inventory" class="btn-ghost-white">
                        <i class="fa fa-th"></i> Browse All
                    </a>
                </div>
            </div>

            <!-- Right: Viewer -->
            <div class="col-lg-7 gs-right">
                <div class="viewer-outer">
                    <div class="viewer-stage" id="viewerStage">
                        <?php
                        $sImg0 = !empty($showcaseImages) ? BASE_URL.'/uploads/cars/'.$showcaseImages[0] : null;
                        ?>
                        <?php if ($sImg0): ?>
                        <div class="car-360-wrap" id="car360Wrap">
                            <img src="<?= e($sImg0) ?>" class="car-360-img" id="car360Img"
                                 alt="<?= e($showcase['make'].' '.$showcase['model']) ?>">
                        </div>
                        <div class="door-overlay">
                            <div class="door-panel" id="doorPanel"></div>
                            <div class="door-interior" id="doorInterior">
                                <i class="fa fa-couch"></i>
                                <span>Interior</span>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="viewer-no-img">
                            <i class="fa fa-car-side"></i>
                            <span><?= e($showcase['make'].' '.$showcase['model']) ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="viewer-ground"></div>
                        <div class="viewer-ground-reflect"></div>
                        <div class="angle-display" id="angleDsp">0°</div>
                        <div class="rot-indicator" id="rotIndicator">
                            <?php for ($i=0;$i<8;$i++): ?><div class="rot-dot<?= $i===0?' active':'' ?>"></div><?php endfor; ?>
                        </div>
                    </div>

                    <div class="viewer-controls">
                        <button class="vbtn" id="spinBtn"><i class="fa fa-rotate me-1"></i>Auto Spin</button>
                        <button class="vbtn" id="doorBtn"><i class="fa fa-door-open me-1"></i>Open Door</button>
                        <button class="vbtn" id="resetBtn"><i class="fa fa-redo me-1"></i>Reset</button>
                    </div>
                    <div class="drag-hint">
                        <?php if (!empty($showcaseImages) && count($showcaseImages)>1): ?>
                        Drag left or right to rotate — <?= count($showcaseImages) ?> views available
                        <?php else: ?>
                        Drag left or right to rotate the view
                        <?php endif; ?>
                    </div>

                    <!-- Showcase car name -->
                    <div style="margin-top:18px;text-align:center">
                        <div style="font-size:11px;color:var(--c-text3);text-transform:uppercase;letter-spacing:2px;margin-bottom:4px"><?= $showcase['year'] ?></div>
                        <div style="font-size:20px;font-weight:900;color:var(--c-text);letter-spacing:-.5px"><?= e($showcase['make'].' '.$showcase['model']) ?></div>
                        <?php if ($showcase['asking_price']): ?>
                        <div style="font-size:16px;font-weight:700;color:var(--c-gold);margin-top:4px">KES <?= number_format((float)$showcase['asking_price']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════ STATS BAND ══════════════════════ -->
<div class="stats-band">
    <div class="container-xl">
        <div class="row g-4">
            <?php foreach ([
                [$totalStock,        '+', 'Vehicles in Stock'],
                [count($featuredAll),'+', 'Featured Vehicles'],
                [count($catCounts),  '+', 'Body Categories'],
                [100,                '%', 'Quality Assured'],
            ] as [$n, $sfx, $lbl]): ?>
            <div class="col-6 col-md-3">
                <div class="stat-item gs-fade">
                    <div class="stat-n"><span class="count-up" data-target="<?= $n ?>"><?= $n ?></span><em><?= $sfx ?></em></div>
                    <div class="stat-l"><?= $lbl ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════ FEATURED ════════════════════════ -->
<?php if ($featuredAll): ?>
<section class="featured-section" id="featured">
    <div class="container-xl">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-5 gs-up">
            <div>
                <div class="lp-section-label">Handpicked Selection</div>
                <h2 class="lp-section-title" style="margin-bottom:0">Featured Vehicles</h2>
            </div>
            <a href="#inventory" class="btn-ghost-white" style="font-size:14px;padding:10px 24px">View All <i class="fa fa-arrow-right ms-1" style="font-size:11px"></i></a>
        </div>
        <div class="row g-4" id="featuredGrid">
            <?php foreach (array_slice($featuredAll,0,3) as $i=>$car):
                $img = $car['primary_image'] ? BASE_URL.'/uploads/cars/'.$car['primary_image'] : null; ?>
            <div class="col-md-6 col-lg-4 gs-up" style="transition-delay:<?= $i*0.1 ?>s">
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="car-card">
                    <div class="car-card-img-box">
                        <?php if ($img): ?>
                        <img src="<?= e($img) ?>" alt="<?= e($car['make'].' '.$car['model']) ?>">
                        <?php else: ?>
                        <div class="car-card-no-img"><i class="fa fa-car-side"></i></div>
                        <?php endif; ?>
                        <div class="car-card-img-overlay"></div>
                        <div class="car-card-featured-badge"><i class="fa fa-star" style="font-size:9px"></i> Featured</div>
                    </div>
                    <div class="car-card-body">
                        <div class="car-card-year"><?= $car['year'] ?> · <?= e(ucfirst($car['body_type'] ?? '')) ?></div>
                        <div class="car-card-name"><?= e($car['make'].' '.$car['model']) ?></div>
                        <div class="car-card-specs">
                            <?php if ($car['transmission']): ?><span class="car-card-spec"><i class="fa fa-gears"></i><?= e(ucfirst($car['transmission'])) ?></span><?php endif; ?>
                            <?php if ($car['fuel_type']): ?><span class="car-card-spec"><i class="fa fa-gas-pump"></i><?= e(ucfirst($car['fuel_type'])) ?></span><?php endif; ?>
                            <?php if ($car['mileage']): ?><span class="car-card-spec"><i class="fa fa-gauge-high"></i><?= number_format($car['mileage']) ?> km</span><?php endif; ?>
                            <?php if ($car['engine_cc']): ?><span class="car-card-spec"><i class="fa fa-bolt"></i><?= number_format($car['engine_cc']) ?>cc</span><?php endif; ?>
                        </div>
                        <div class="car-card-footer">
                            <div>
                                <?php if ($car['asking_price']): ?>
                                <div class="car-card-price">KES <?= number_format((float)$car['asking_price']) ?></div>
                                <div class="car-card-price-sub">Finance available</div>
                                <?php else: ?>
                                <div class="car-card-price" style="font-size:14px;color:var(--c-text3)">Price on Request</div>
                                <?php endif; ?>
                            </div>
                            <div class="car-card-arrow"><i class="fa fa-arrow-right"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════ CATEGORIES ══════════════════════ -->
<?php if ($catCounts): ?>
<section class="categories-section" id="categories">
    <div class="container-xl">
        <div class="text-center mb-5 gs-up">
            <div class="lp-section-label">Browse by Type</div>
            <h2 class="lp-section-title">Shop by Category</h2>
        </div>
        <div class="row g-3">
            <?php foreach ($catCounts as $bt => $cnt): $ico = $bodyIcons[$bt] ?? 'fa-car'; ?>
            <div class="col-6 col-sm-4 col-md-3 col-lg-2 gs-scale">
                <a href="<?= BASE_URL ?>/showroom/?body=<?= urlencode($bt) ?>#inventory" class="cat-card">
                    <i class="fa <?= $ico ?>"></i>
                    <div class="cat-card-name"><?= e($bt) ?></div>
                    <div class="cat-card-count"><?= $cnt ?> vehicle<?= $cnt==1?'':'s' ?></div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════ INVENTORY ═══════════════════════ -->
<section class="inventory-section" id="inventory">
    <div class="container-xl">
        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-5 gs-up">
            <div>
                <div class="lp-section-label">Full Stock</div>
                <h2 class="lp-section-title" style="margin-bottom:0">All Vehicles</h2>
            </div>
        </div>

        <div class="row g-4">
            <!-- Filters -->
            <div class="col-lg-3">
                <div class="filter-panel gs-left">
                    <div class="filter-heading"><i class="fa fa-sliders me-2"></i>Filter Vehicles</div>
                    <form method="GET" action="#inventory">
                        <div class="filter-group">
                            <label class="filter-group-label">Make / Brand</label>
                            <select name="make" class="fselect">
                                <option value="">All Makes</option>
                                <?php foreach ($makes as $mk): ?>
                                <option value="<?= e($mk) ?>" <?= $filterMake===$mk?'selected':'' ?>><?= e($mk) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-group-label">Body Type</label>
                            <select name="body" class="fselect">
                                <option value="">All Types</option>
                                <?php foreach (array_keys($catCounts) as $bt): ?>
                                <option value="<?= e($bt) ?>" <?= $filterBody===$bt?'selected':'' ?>><?= e($bt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-group-label">Fuel Type</label>
                            <select name="fuel" class="fselect">
                                <option value="">All Fuel Types</option>
                                <?php foreach (['Petrol','Diesel','Hybrid','Electric'] as $ft): ?>
                                <option value="<?= $ft ?>" <?= $filterFuel===$ft?'selected':'' ?>><?= $ft ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-group-label">Transmission</label>
                            <select name="trans" class="fselect">
                                <option value="">All</option>
                                <?php foreach (['Automatic','Manual'] as $tr): ?>
                                <option value="<?= $tr ?>" <?= $filterTrans===$tr?'selected':'' ?>><?= $tr ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-group-label">Min Price (KES)</label>
                            <input type="number" name="min" class="finput" value="<?= $filterMin ?: '' ?>" placeholder="e.g. 500000">
                        </div>
                        <div class="filter-group" style="margin-bottom:22px">
                            <label class="filter-group-label">Max Price (KES)</label>
                            <input type="number" name="max" class="finput" value="<?= $filterMax ?: '' ?>" placeholder="e.g. 5000000">
                        </div>
                        <button type="submit" class="btn-apply-filter"><i class="fa fa-search me-2"></i>Apply Filter</button>
                        <?php if ($isFiltered): ?>
                        <a href="<?= BASE_URL ?>/showroom/#inventory" class="btn-ghost-white" style="width:100%;margin-top:10px;justify-content:center;font-size:13px;padding:10px">
                            <i class="fa fa-times me-1"></i>Clear Filters
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Grid -->
            <div class="col-lg-9">
                <div class="inv-sort-bar gs-up">
                    <div class="inv-count">
                        <strong><?= $filteredCount ?></strong> vehicle<?= $filteredCount==1?'':'s' ?>
                        <?= $isFiltered ? 'found' : 'in stock' ?>
                    </div>
                    <form method="GET" id="sortForm">
                        <?php foreach (['make'=>$filterMake,'body'=>$filterBody,'fuel'=>$filterFuel,'trans'=>$filterTrans,'min'=>$filterMin,'max'=>$filterMax,'q'=>$search] as $k=>$v): ?>
                        <?php if ($v): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; ?>
                        <?php endforeach; ?>
                        <select name="sort" class="fsort" onchange="this.form.submit()">
                            <option value="featured"   <?= $sort==='featured'  ?'selected':'' ?>>Featured First</option>
                            <option value="newest"     <?= $sort==='newest'    ?'selected':'' ?>>Newest First</option>
                            <option value="price_asc"  <?= $sort==='price_asc' ?'selected':'' ?>>Price: Low → High</option>
                            <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>Price: High → Low</option>
                            <option value="year_desc"  <?= $sort==='year_desc' ?'selected':'' ?>>Year: Newest</option>
                        </select>
                    </form>
                </div>

                <?php if ($filteredCars): ?>
                <div class="row g-3" id="invGrid">
                    <?php foreach ($filteredCars as $i=>$car):
                        $img = $car['primary_image'] ? BASE_URL.'/uploads/cars/'.$car['primary_image'] : null; ?>
                    <div class="col-sm-6 col-xl-4 gs-up" style="transition-delay:<?= min($i,8)*0.06 ?>s">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" class="car-card">
                            <div class="car-card-img-box">
                                <?php if ($img): ?>
                                <img src="<?= e($img) ?>" alt="<?= e($car['make'].' '.$car['model']) ?>">
                                <?php else: ?>
                                <div class="car-card-no-img"><i class="fa fa-car-side"></i></div>
                                <?php endif; ?>
                                <div class="car-card-img-overlay"></div>
                                <?php if ($car['featured']): ?><div class="car-card-featured-badge" style="font-size:9px">⭐ Featured</div><?php endif; ?>
                            </div>
                            <div class="car-card-body">
                                <div class="car-card-year"><?= $car['year'] ?><?= $car['body_type']?' · '.e(ucfirst($car['body_type'])):'' ?></div>
                                <div class="car-card-name"><?= e($car['make'].' '.$car['model']) ?></div>
                                <div class="car-card-specs">
                                    <?php if ($car['transmission']): ?><span class="car-card-spec"><i class="fa fa-gears"></i><?= e(ucfirst($car['transmission'])) ?></span><?php endif; ?>
                                    <?php if ($car['fuel_type']): ?><span class="car-card-spec"><i class="fa fa-gas-pump"></i><?= e(ucfirst($car['fuel_type'])) ?></span><?php endif; ?>
                                    <?php if ($car['mileage']): ?><span class="car-card-spec"><i class="fa fa-gauge-high"></i><?= number_format($car['mileage']) ?> km</span><?php endif; ?>
                                </div>
                                <div class="car-card-footer">
                                    <div>
                                        <?php if ($car['asking_price']): ?>
                                        <div class="car-card-price">KES <?= number_format((float)$car['asking_price']) ?></div>
                                        <?php else: ?>
                                        <div class="car-card-price" style="font-size:13px;color:var(--c-text3)">Price on Request</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="car-card-arrow"><i class="fa fa-arrow-right"></i></div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="no-results-dark">
                    <i class="fa fa-car-burst"></i>
                    <div style="font-size:18px;font-weight:800;color:var(--c-text);margin-bottom:8px">No vehicles found</div>
                    <div>Try adjusting your filters or <a href="<?= BASE_URL ?>/showroom/#inventory" style="color:var(--c-gold)">clear all filters</a></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════════════ WHY US ══════════════════════════ -->
<section class="whyus-section" id="why-us">
    <div class="container-xl">
        <div class="text-center mb-5 gs-up">
            <div class="lp-section-label">Why Mascardi</div>
            <h2 class="lp-section-title">The Standard of Excellence</h2>
            <p class="lp-section-sub mx-auto" style="max-width:480px">
                Every vehicle. Every transaction. Every customer. Held to the highest standard.
            </p>
        </div>
        <div class="row g-4">
            <?php foreach ([
                ['fa-shield-halved',   'Quality Assured',        'Every vehicle undergoes thorough inspection and quality checks before it reaches you.'],
                ['fa-eye',             'Transparent Pricing',    'No hidden fees. No surprises. The price you see is the price you pay.'],
                ['fa-handshake',       'Flexible Financing',     'Tailored financing plans that fit your budget. Drive away today with manageable repayments.'],
                ['fa-rotate',         'Trade-In Welcome',       'Fair market-value trade-ins on your current vehicle. Quick, hassle-free valuations.'],
                ['fa-user-tie',       'Expert Guidance',        'Our experienced team guides you from first inquiry to driving away your perfect car.'],
                ['fa-truck-fast',     'Nationwide Delivery',    'Your vehicle delivered to your doorstep anywhere in the country, fully insured.'],
            ] as $i=>[$ico,$ttl,$txt]): ?>
            <div class="col-md-6 col-lg-4 gs-up" style="transition-delay:<?= $i*0.08 ?>s">
                <div class="feat-card">
                    <div class="feat-icon"><i class="fa <?= $ico ?>"></i></div>
                    <div class="feat-title"><?= $ttl ?></div>
                    <div class="feat-text"><?= $txt ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════ SERVICE CTA ═════════════════════ -->
<section class="service-cta" id="book">
    <div class="container-xl" style="position:relative;z-index:1">
        <div class="row align-items-center g-5">
            <div class="col-lg-7 gs-left">
                <div class="lp-section-label">Workshop Services</div>
                <h2 class="lp-section-title">Your Car Deserves<br>Expert Care</h2>
                <p class="lp-section-sub" style="max-width:480px">
                    From routine maintenance to full diagnostics — our certified technicians keep your vehicle at peak performance.
                </p>
                <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:36px">
                    <a href="<?= BASE_URL ?>/showroom/book-service.php" class="btn-gold">
                        <i class="fa fa-calendar-check"></i> Book a Service
                    </a>
                    <a href="<?= BASE_URL ?>/showroom/contact.php" class="btn-ghost-white">
                        <i class="fa fa-phone"></i> Contact Us
                    </a>
                    <?php if ($__waClean): ?>
                    <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                       style="background:#25d366;color:#fff;border:none;border-radius:12px;padding:14px 28px;font-size:15px;font-weight:700;display:inline-flex;align-items:center;gap:8px;text-decoration:none;transition:box-shadow .2s"
                       onmouseover="this.style.boxShadow='0 6px 24px rgba(37,211,102,.4)'"
                       onmouseout="this.style.boxShadow='none'">
                        <i class="fa-brands fa-whatsapp"></i> WhatsApp
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5 gs-right">
                <div style="background:rgba(255,255,255,.03);border:1px solid var(--c-border);border-radius:22px;padding:36px;backdrop-filter:blur(10px)">
                    <div style="font-size:13px;font-weight:700;color:var(--c-gold);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:18px">Our Services</div>
                    <?php foreach ([
                        ['fa-wrench',       'General Service & Maintenance'],
                        ['fa-car-burst',    'Diagnostics & Repairs'],
                        ['fa-tire',         'Tyre & Alignment'],
                        ['fa-temperature-high','Engine & Cooling System'],
                        ['fa-paint-roller', 'Body & Paint Restoration'],
                        ['fa-shield-check', 'Pre-Purchase Inspection'],
                    ] as [$ico,$svc]): ?>
                    <div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid rgba(255,255,255,.04)">
                        <i class="fa <?= $ico ?>" style="width:18px;color:var(--c-gold);font-size:13px;flex-shrink:0"></i>
                        <span style="font-size:14px;color:rgba(241,245,249,.65)"><?= $svc ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>

<!-- ═══════════════ SCRIPTS ═══════════════════════════════════════ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script>
(function() {
'use strict';

/* ── PARTICLE CANVAS ──────────────────────────────────────────── */
const canvas = document.getElementById('heroCanvas');
if (canvas) {
    const ctx = canvas.getContext('2d');
    let W, H, pts = [];

    function resize() {
        W = canvas.width  = canvas.offsetWidth;
        H = canvas.height = canvas.offsetHeight;
    }

    function Pt() {
        this.x  = Math.random() * W;
        this.y  = Math.random() * H;
        this.vx = (Math.random() - 0.5) * 0.28;
        this.vy = (Math.random() - 0.5) * 0.28;
        this.r  = Math.random() * 1.3 + 0.3;
        this.a  = Math.random() * 0.35 + 0.08;
        this.c  = Math.random() > 0.68 ? '201,169,110' : '79,142,247';
    }
    Pt.prototype.tick = function() {
        this.x += this.vx; this.y += this.vy;
        if (this.x < 0 || this.x > W) this.vx *= -1;
        if (this.y < 0 || this.y > H) this.vy *= -1;
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.r, 0, Math.PI*2);
        ctx.fillStyle = `rgba(${this.c},${this.a})`;
        ctx.fill();
    };

    function draw() {
        ctx.clearRect(0, 0, W, H);
        pts.forEach(p => p.tick());
        for (let i = 0; i < pts.length; i++) {
            for (let j = i+1; j < pts.length; j++) {
                const dx = pts[i].x - pts[j].x;
                const dy = pts[i].y - pts[j].y;
                const d  = Math.sqrt(dx*dx + dy*dy);
                if (d < 110) {
                    ctx.beginPath();
                    ctx.moveTo(pts[i].x, pts[i].y);
                    ctx.lineTo(pts[j].x, pts[j].y);
                    ctx.strokeStyle = `rgba(79,142,247,${0.05 * (1 - d/110)})`;
                    ctx.lineWidth = 0.5;
                    ctx.stroke();
                }
            }
        }
        requestAnimationFrame(draw);
    }

    resize();
    for (let i = 0; i < 70; i++) pts.push(new Pt());
    draw();
    window.addEventListener('resize', resize);
}

/* ── GSAP ANIMATIONS ──────────────────────────────────────────── */
gsap.registerPlugin(ScrollTrigger);

// Hero entrance
const tl = gsap.timeline({ delay: 0.15 });
tl.from('#heroLeft .hero-divider',  { scaleX: 0, transformOrigin: 'left', duration: 0.8, ease: 'power3.out' })
  .from('#heroLeft .hero-eyebrow',  { y: 20, opacity: 0, duration: 0.7, ease: 'power3.out' }, '-=0.3')
  .from('#heroLeft .hero-title',    { y: 50, opacity: 0, duration: 1.0, ease: 'power4.out' }, '-=0.4')
  .from('#heroLeft .hero-sub',      { y: 30, opacity: 0, duration: 0.8, ease: 'power3.out' }, '-=0.5')
  .from('#heroLeft .hero-search',   { y: 24, opacity: 0, duration: 0.7, ease: 'power3.out' }, '-=0.4')
  .from('#heroLeft .hero-stats > div',{ y: 16, opacity: 0, duration: 0.5, stagger: 0.12, ease: 'power2.out' }, '-=0.3')
  .from('#heroCard', { x: 60, opacity: 0, duration: 1.1, ease: 'power3.out' }, '-=0.9');

// Scroll-driven reveals
gsap.utils.toArray('.gs-up').forEach(el => {
    gsap.from(el, {
        y: 50, opacity: 0, duration: 0.9, ease: 'power3.out',
        scrollTrigger: { trigger: el, start: 'top 88%', once: true }
    });
});
gsap.utils.toArray('.gs-fade').forEach(el => {
    gsap.from(el, {
        opacity: 0, duration: 0.8, ease: 'power2.out',
        scrollTrigger: { trigger: el, start: 'top 88%', once: true }
    });
});
gsap.utils.toArray('.gs-left').forEach(el => {
    gsap.from(el, {
        x: -55, opacity: 0, duration: 1.0, ease: 'power3.out',
        scrollTrigger: { trigger: el, start: 'top 85%', once: true }
    });
});
gsap.utils.toArray('.gs-right').forEach(el => {
    gsap.from(el, {
        x: 55, opacity: 0, duration: 1.0, ease: 'power3.out',
        scrollTrigger: { trigger: el, start: 'top 85%', once: true }
    });
});
gsap.utils.toArray('.gs-scale').forEach((el, i) => {
    gsap.from(el, {
        scale: 0.85, opacity: 0, duration: 0.6, ease: 'back.out(1.4)',
        delay: (i % 6) * 0.06,
        scrollTrigger: { trigger: el, start: 'top 90%', once: true }
    });
});

// Count-up on stats band
document.querySelectorAll('.count-up').forEach(el => {
    const target = parseInt(el.dataset.target);
    ScrollTrigger.create({
        trigger: el, start: 'top 88%', once: true,
        onEnter: () => {
            let cur = 0;
            const step = target / 45;
            const t = setInterval(() => {
                cur = Math.min(cur + step, target);
                el.textContent = Math.round(cur);
                if (cur >= target) { el.textContent = target; clearInterval(t); }
            }, 30);
        }
    });
});

/* ── 360° VIEWER ──────────────────────────────────────────────── */
(function() {
    const stage     = document.getElementById('viewerStage');
    const wrap      = document.getElementById('car360Wrap');
    const img       = document.getElementById('car360Img');
    const doorPanel = document.getElementById('doorPanel');
    const doorInt   = document.getElementById('doorInterior');
    const angleDsp  = document.getElementById('angleDsp');
    const dots      = document.querySelectorAll('#rotIndicator .rot-dot');
    const spinBtn   = document.getElementById('spinBtn');
    const doorBtn   = document.getElementById('doorBtn');
    const resetBtn  = document.getElementById('resetBtn');

    if (!stage) return;

    // Image frames from PHP
    const frames = <?= json_encode(array_map(fn($f) => BASE_URL.'/uploads/cars/'.$f, $showcaseImages)) ?>;
    const totalFrames = Math.max(frames.length, 1);
    let curFrame = 0, accumDrag = 0;
    let isDrag = false, lastX = 0, dragDir = 0;
    let spinInt = null, isSpinning = false;
    let doorOpen = false;

    function setFrame(idx) {
        curFrame = ((idx % totalFrames) + totalFrames) % totalFrames;
        if (frames.length > 1 && img) {
            img.src = frames[curFrame];
        }
        const deg = Math.round((curFrame / totalFrames) * 360);
        if (angleDsp) angleDsp.textContent = deg + '°';
        const dotIdx = Math.round((curFrame / totalFrames) * 8) % 8;
        dots.forEach((d, i) => d.classList.toggle('active', i === dotIdx));
    }

    function applyTilt(delta) {
        if (!wrap) return;
        const tilt = Math.max(-6, Math.min(6, delta * 0.5));
        wrap.style.transform = `perspective(1100px) rotateY(${tilt}deg)`;
        clearTimeout(wrap._tilt);
        wrap._tilt = setTimeout(() => { wrap.style.transform = ''; }, 120);
    }

    // Mouse events
    stage.addEventListener('mousedown', e => { isDrag = true; lastX = e.clientX; e.preventDefault(); });
    document.addEventListener('mousemove', e => {
        if (!isDrag) return;
        const dx = e.clientX - lastX; lastX = e.clientX; dragDir = dx;
        accumDrag += dx;
        const nf = Math.floor(((accumDrag / 4) % totalFrames + totalFrames) % totalFrames);
        if (nf !== curFrame) setFrame(nf);
        applyTilt(dx);
    });
    document.addEventListener('mouseup', () => { isDrag = false; });

    // Touch events
    stage.addEventListener('touchstart', e => { isDrag = true; lastX = e.touches[0].clientX; }, { passive: true });
    document.addEventListener('touchmove', e => {
        if (!isDrag) return;
        const dx = e.touches[0].clientX - lastX; lastX = e.touches[0].clientX; dragDir = dx;
        accumDrag += dx;
        const nf = Math.floor(((accumDrag / 4) % totalFrames + totalFrames) % totalFrames);
        if (nf !== curFrame) setFrame(nf);
        applyTilt(dx);
    }, { passive: true });
    document.addEventListener('touchend', () => { isDrag = false; });

    // Auto spin
    if (spinBtn) spinBtn.addEventListener('click', () => {
        isSpinning = !isSpinning;
        if (isSpinning) {
            spinBtn.classList.add('vbtn-active');
            spinBtn.innerHTML = '<i class="fa fa-stop me-1"></i>Stop Spin';
            spinInt = setInterval(() => {
                accumDrag += 4;
                const nf = Math.floor(((accumDrag / 4) % totalFrames + totalFrames) % totalFrames);
                if (nf !== curFrame) setFrame(nf);
            }, 50);
        } else {
            clearInterval(spinInt);
            spinBtn.classList.remove('vbtn-active');
            spinBtn.innerHTML = '<i class="fa fa-rotate me-1"></i>Auto Spin';
        }
    });

    // Door open/close
    if (doorBtn) doorBtn.addEventListener('click', () => {
        doorOpen = !doorOpen;
        if (doorPanel) doorPanel.classList.toggle('door-open', doorOpen);
        if (doorInt)   doorInt.classList.toggle('door-open', doorOpen);
        doorBtn.classList.toggle('vbtn-active', doorOpen);
        doorBtn.innerHTML = doorOpen
            ? '<i class="fa fa-door-closed me-1"></i>Close Door'
            : '<i class="fa fa-door-open me-1"></i>Open Door';
    });

    // Reset
    if (resetBtn) resetBtn.addEventListener('click', () => {
        accumDrag = 0; setFrame(0);
        doorOpen = false;
        if (doorPanel) doorPanel.classList.remove('door-open');
        if (doorInt)   doorInt.classList.remove('door-open');
        if (doorBtn) { doorBtn.classList.remove('vbtn-active'); doorBtn.innerHTML = '<i class="fa fa-door-open me-1"></i>Open Door'; }
        if (isSpinning && spinBtn) spinBtn.click();
    });

    // Auto spin on enter viewport (once)
    if (frames.length > 1) {
        ScrollTrigger.create({
            trigger: stage, start: 'top 75%', once: true,
            onEnter: () => {
                let cnt = 0;
                const autoSpin = setInterval(() => {
                    accumDrag += 5;
                    const nf = Math.floor(((accumDrag / 4) % totalFrames + totalFrames) % totalFrames);
                    if (nf !== curFrame) setFrame(nf);
                    if (++cnt >= 60) clearInterval(autoSpin);
                }, 40);
            }
        });
    }
}());

})();
</script>
