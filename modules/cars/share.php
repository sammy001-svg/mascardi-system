<?php
/**
 * Public car detail page — no login required.
 *
 * Clients receive this link from staff ("Share link" button on the internal
 * car view). It shows make/model, all photos with a scrollable gallery, price,
 * key specs, features, and a WhatsApp/phone enquiry button.
 *
 * This is not the website. A client opening it should see one vehicle,
 * presented properly, and a way to reach the person selling it — not a shop
 * front with navigation inviting them to go and browse. Nothing here links back
 * into the public showroom, deliberately: the link was sent to somebody about a
 * particular car, and every door out of it is a door away from that
 * conversation.
 *
 * Security:
 *  • Only inventory cars (car_type = 'inventory' | 'sale_on_behalf') are exposed.
 *  • Sold/delivered cars are not shown — they get a polite "unavailable" page.
 *  • No internal fields (chassis, entry number, costs, import details, billing) appear.
 *  • The link is the access control, so it is a token rather than a row id.
 *    ?id=7 is a count: anyone handed one link could reach every other car by
 *    changing the number, including stock kept off the public site on purpose.
 *    ?t=<token> cannot be counted through. The old form still resolves, because
 *    links sent to clients before this are out in the world and should not break.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/_bootstrap.php';

$db    = getDB();
$token = trim((string)($_GET['t'] ?? ''));
$id    = (int)($_GET['id'] ?? 0);

if ($token !== '' && preg_match('/^[a-f0-9]{16,32}$/', $token)) {
    $st = $db->prepare("SELECT id FROM cars WHERE share_token = ? LIMIT 1");
    $st->execute([$token]);
    $id = (int)($st->fetchColumn() ?: 0);
}

// Pull the car — inventory stock only, not sold/delivered.
$stmt = $db->prepare("
    SELECT c.make, c.model, c.year, c.color, c.body_type, c.fuel_type, c.transmission,
           c.engine_cc, c.mileage, c.registration_number, c.asking_price, c.offer_price,
           c.description, c.notes, c.features, c.status, c.car_type,
           IFNULL(pl.name, l.name) AS location_name
    FROM cars c
    LEFT JOIN locations l  ON l.id  = c.location_id
    LEFT JOIN locations pl ON pl.id = l.parent_id
    WHERE c.id = ?
      AND c.car_type IN ('inventory', 'sale_on_behalf')
      AND c.status NOT IN ('sold', 'delivered')
");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$id) { $car = null; }

if (!$car) {
    // Car not found, or sold/delivered, or a client vehicle (not shareable).
    // Whoever opened this was sent it by somebody; send them back to that
    // person rather than to a catalogue they did not ask for.
    http_response_code(404);
    $companyName = getSetting('company_name', 'Mascardi Luxury Cars');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vehicle Unavailable — <?= htmlspecialchars($companyName) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
<div class="text-center p-5" style="max-width:420px">
    <div style="font-size:60px;margin-bottom:14px">🚗</div>
    <h4 class="fw-semibold mb-2">This vehicle is no longer available</h4>
    <p class="text-muted mb-4">It may have been sold, or the link may have expired.
       Whoever sent this to you will know what else is in.</p>
    <?php $__ph = getSetting('company_phone', ''); if ($__ph !== ''): ?>
    <a href="tel:<?= htmlspecialchars($__ph) ?>" class="btn btn-dark">
        <i class="fa fa-phone me-2"></i>Call <?= htmlspecialchars($companyName) ?>
    </a>
    <?php endif; ?>
</div>
</body>
</html>
    <?php
    exit;
}

// Images
$images = $db->prepare("SELECT file_path, caption, is_primary FROM car_images WHERE car_id=? ORDER BY is_primary DESC, id ASC");
$images->execute([$id]);
$images = $images->fetchAll(PDO::FETCH_ASSOC);

// Pricing
$hasOffer   = !empty($car['offer_price'])   && (float)$car['offer_price']   > 0;
$hasPrice   = !empty($car['asking_price'])  && (float)$car['asking_price']  > 0;
$dispPrice  = $hasOffer ? (float)$car['offer_price'] : ($hasPrice ? (float)$car['asking_price'] : null);
$saveAmt    = ($hasOffer && $hasPrice && $car['asking_price'] > $car['offer_price'])
            ? (float)$car['asking_price'] - (float)$car['offer_price'] : 0;
$priceStr   = $dispPrice ? 'KES ' . number_format($dispPrice) : 'Contact for price';

$carTitle      = trim($car['year'] . ' ' . $car['make'] . ' ' . $car['model']);
$companyName   = getSetting('company_name',   'Mascardi Luxury Cars');
$companyPhone  = getSetting('company_phone',  '');
$whatsappNum   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', $companyPhone));
$isReserved    = ($car['status'] ?? '') === 'reserved';
$inTransit     = ($car['status'] ?? '') === 'in_transit';

$primaryImg    = $images ? BASE_URL . '/uploads/cars/' . $images[0]['file_path'] : null;
$logoInfo      = companyLogo();

// The link this page calls itself, for og: tags and the forward buttons. The
// token form, so a client who passes it on passes on the private link rather
// than a number somebody can count from.
$shareTok      = carShareToken($db, $id);
$shareUrl      = rtrim(BASE_URL, '/') . '/modules/cars/share.php?'
               . ($shareTok !== '' ? 't=' . $shareTok : 'id=' . $id);
$waMsg         = urlencode("Hi, I'm interested in the {$carTitle}" . ($dispPrice ? " priced at {$priceStr}" : '') . ". Could you share more details? {$shareUrl}");

$featureList   = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($car['features'] ?? '')))));
$publicDesc    = trim($car['description'] ?? '') ?: trim($car['notes'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($carTitle) ?> — <?= htmlspecialchars($companyName) ?></title>
<meta property="og:title"       content="<?= htmlspecialchars($carTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($priceStr . ' · ' . $companyName) ?>">
<?php if ($primaryImg): ?>
<meta property="og:image"       content="<?= htmlspecialchars($primaryImg) ?>">
<?php endif; ?>
<meta property="og:url"         content="<?= htmlspecialchars($shareUrl) ?>">
<meta property="og:type"        content="website">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --ink:#0f172a;--ink-2:#475569;--ink-3:#94a3b8;
  --line:#e2e8f0;--paper:#f8fafc;--white:#fff;
  --accent:#111827;--green:#16a34a;
  --r:10px;--ease:cubic-bezier(.4,0,.2,1);
}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;background:var(--paper);color:var(--ink);line-height:1.6;min-height:100vh}
img{max-width:100%;height:auto;display:block}
a{color:inherit;text-decoration:none}

/* ── Layout ── */
.sp-wrap{max-width:1140px;margin:0 auto;padding:0 20px}

/* ── Top bar ── */
.sp-nav{
  background:var(--white);border-bottom:1px solid var(--line);
  padding:14px 0;position:sticky;top:0;z-index:100;
}
.sp-nav-inner{display:flex;align-items:center;justify-content:space-between;gap:16px}
.sp-logo{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px;color:var(--ink)}
.sp-logo img{height:36px;width:auto;object-fit:contain}
.sp-nav-badge{
  font-size:10.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;
  color:var(--ink-3);padding:4px 12px;border:1px solid var(--line);border-radius:20px;
}

/* ── Hero ── */
.sp-hero{padding:32px 0 0}
.sp-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:36px;align-items:start}
/* Placed by hand rather than by source order: the photographs and the detail
   below them are two grid children now, so that a narrow screen can put the
   price between them without the markup being written twice. */
.sp-gal{grid-column:1;grid-row:1}
.sp-detail{grid-column:1;grid-row:2;margin-top:-12px}
.sp-panel-col{grid-column:2;grid-row:1/span 2}

@media(max-width:960px){
  /* One column, and the order a person actually wants: see the car, see what
     it costs and how to ask about it, and only then the specification. The
     price used to sit under the whole accordion, which on a phone is a long
     way past the point where somebody has decided. */
  .sp-grid{grid-template-columns:1fr;gap:22px}
  .sp-gal,.sp-detail,.sp-panel-col{grid-column:1;grid-row:auto}
  .sp-gal{order:1}
  .sp-panel-col{order:2}
  .sp-detail{order:3;margin-top:0}
}

/* ── Gallery ── */
.sp-gal-main{
  position:relative;aspect-ratio:16/10;overflow:hidden;
  background:#f1f5f9;border:1px solid var(--line);border-radius:var(--r);cursor:zoom-in;
}
.sp-gal-main img{width:100%;height:100%;object-fit:cover;transition:opacity .2s}
.sp-arrow{
  position:absolute;top:50%;transform:translateY(-50%);
  width:42px;height:42px;border-radius:8px;background:rgba(255,255,255,.92);
  border:1px solid var(--line);color:var(--ink);font-size:14px;cursor:pointer;
  display:flex;align-items:center;justify-content:center;transition:background .2s;z-index:2;
}
.sp-arrow:hover{background:#fff}
.sp-al{left:12px}.sp-ar{right:12px}
.sp-cnt{
  position:absolute;bottom:12px;right:14px;background:rgba(10,10,10,.6);color:#fff;
  font-size:11px;font-weight:500;padding:3px 11px;border-radius:20px;letter-spacing:.06em;z-index:2;
}
.sp-zoom{
  position:absolute;bottom:12px;left:14px;background:rgba(255,255,255,.85);
  width:32px;height:32px;border-radius:7px;display:flex;align-items:center;justify-content:center;
  font-size:12px;color:#555;opacity:0;transition:opacity .2s;z-index:2;
}
.sp-gal-main:hover .sp-zoom{opacity:1}
.sp-thumbs{
  display:flex;gap:6px;padding:8px 0;overflow-x:auto;scrollbar-width:thin;
}
.sp-thumb{
  flex-shrink:0;width:82px;height:54px;border:2px solid var(--line);border-radius:7px;
  overflow:hidden;cursor:pointer;padding:0;background:none;transition:border-color .15s;
}
.sp-thumb.active,.sp-thumb:hover{border-color:var(--ink)}
.sp-thumb img{width:100%;height:100%;object-fit:cover}
.sp-noimg{
  aspect-ratio:16/10;border:1px solid var(--line);border-radius:var(--r);
  background:var(--paper);display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:10px;color:var(--line);font-size:52px;
}
.sp-noimg div{font-size:14px;color:var(--ink-3);font-weight:500}

/* ── Stats strip ── */
.sp-stats{
  display:grid;grid-template-columns:repeat(4,1fr);
  border:1px solid var(--line);border-radius:var(--r);margin-top:20px;background:var(--white);
}
.sp-stats>div{text-align:center;padding:20px 10px;border-right:1px solid var(--line)}
.sp-stats>div:last-child{border-right:none}
.sp-stats i{font-size:16px;color:var(--ink);display:block;margin-bottom:10px}
.sp-stats .v{font-size:18px;font-weight:300;letter-spacing:-.01em;color:var(--ink)}
.sp-stats .l{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.13em;color:var(--ink-3);margin-top:4px}
@media(max-width:560px){
  .sp-stats{grid-template-columns:1fr 1fr}
  .sp-stats>div:nth-child(2){border-right:none}
  .sp-stats>div:nth-child(-n+2){border-bottom:1px solid var(--line)}
}

/* ── Accordion specs ── */
.sp-accs{margin-top:24px;border-top:1px solid var(--line)}
.sp-acc{border-bottom:1px solid var(--line)}
.sp-acc summary{
  list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;
  padding:18px 2px;font-size:14.5px;font-weight:500;color:var(--ink);transition:color .2s;
}
.sp-acc summary::-webkit-details-marker{display:none}
.sp-acc summary:hover{color:var(--ink-2)}
.sp-acc summary i{font-size:11px;color:var(--ink-3);transition:transform .3s var(--ease)}
.sp-acc[open] summary i{transform:rotate(45deg)}
.sp-acc-body{padding:2px 2px 22px}
.sp-spec{
  display:flex;justify-content:space-between;align-items:baseline;gap:16px;
  padding:9px 0;border-bottom:1px solid var(--paper);font-size:13.5px;
}
.sp-spec:last-child{border-bottom:none}
.sp-spec span{color:var(--ink-3)}
.sp-spec strong{color:var(--ink);font-weight:500;text-align:right}
.sp-feats{list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:4px 20px}
.sp-feats li{display:flex;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid var(--paper);font-size:13.5px;color:var(--ink-2)}
.sp-feats li i{color:var(--ink);font-size:11px;flex-shrink:0}
@media(max-width:560px){.sp-feats{grid-template-columns:1fr}}
.sp-desc{color:var(--ink-2);line-height:1.8;font-size:14px}

/* ── Sticky right panel ── */
.sp-panel{
  position:sticky;top:calc(60px + 20px);
  border:1px solid var(--line);border-radius:var(--r);
  padding:28px 26px;background:var(--white);
}
@media(max-width:960px){.sp-panel{position:static;margin-top:20px}}
.sp-avail{
  display:flex;align-items:center;gap:8px;
  font-size:10px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;
  color:var(--ink-3);margin-bottom:14px;
}
.sp-dot{width:7px;height:7px;border-radius:50%;background:var(--green);flex-shrink:0}
.sp-dot-dark{background:var(--ink)}
.sp-title{font-size:26px;font-weight:400;letter-spacing:-.01em;line-height:1.2;margin-bottom:6px}
.sp-sub{font-size:13px;color:var(--ink-3);margin-bottom:22px}
.sp-price-box{padding:18px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin-bottom:20px}
.sp-price-amt{font-size:28px;font-weight:700;letter-spacing:-.01em;color:var(--ink)}
.sp-price-note{font-size:12.5px;color:var(--ink-3);margin-top:5px}
.sp-price-note del{color:var(--ink-3)}
.sp-loc{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ink-2);margin-bottom:18px}
.sp-loc i{font-size:12px;color:var(--ink-3)}
.sp-btn{
  display:flex;align-items:center;justify-content:center;gap:10px;
  width:100%;padding:14px;border-radius:8px;font-size:14px;font-weight:600;
  cursor:pointer;border:none;transition:opacity .2s;text-decoration:none;
}
.sp-btn-wa{background:#25D366;color:#fff;margin-bottom:10px}
.sp-btn-wa:hover{opacity:.9;color:#fff}
.sp-btn-ph{background:var(--ink);color:#fff;margin-bottom:10px}
.sp-btn-ph:hover{opacity:.85;color:#fff}
.sp-btn-copy{background:var(--paper);color:var(--ink);border:1px solid var(--line)}
.sp-btn-copy:hover{background:var(--line)}

/* ── Share row ── */
.sp-share{margin-top:18px;padding-top:18px;border-top:1px solid var(--line);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.sp-share-label{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-3)}
.sp-share-btn{
  width:34px;height:34px;border-radius:7px;border:1px solid var(--line);
  background:none;color:var(--ink-2);font-size:14px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s;
}
.sp-share-btn:hover{background:var(--paper)}

/* ── Footer ── */
.sp-foot{background:var(--white);border-top:1px solid var(--line);padding:24px 0;margin-top:56px;text-align:center;font-size:12.5px;color:var(--ink-3)}
.sp-foot a{color:var(--ink-2)}

/* ── On a phone ───────────────────────────────────────────────
   This link is nearly always opened from WhatsApp, on a phone, by somebody who
   has been sent one car. That is the primary case, not the fallback. */
@media(max-width:720px){
  .sp-wrap{padding:0 16px}
  .sp-hero{padding:18px 0 0}
  .sp-panel{padding:20px 18px}
  .sp-title{font-size:23px}
  .sp-price-amt{font-size:26px}
  .sp-gal-main{aspect-ratio:4/3;border-radius:10px}
  .sp-accs{margin-top:18px}
  .sp-acc summary{padding:15px 2px;font-size:14px}
  /* A number that has been read is a number that can be dialled. */
  .sp-spec{font-size:13px;gap:12px}
}

/* The one thing a client needs within reach at any point on the page: what it
   costs, and how to ask about it. On desktop the panel is sticky and does this
   already; on a phone the panel scrolls away, so this takes over. */
.sp-bar{display:none}
@media(max-width:960px){
  .sp-bar{
    display:flex;align-items:center;gap:12px;
    position:fixed;left:0;right:0;bottom:0;z-index:200;
    padding:10px 16px calc(10px + env(safe-area-inset-bottom,0px));
    background:rgba(255,255,255,.97);
    -webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);
    border-top:1px solid var(--line);
    box-shadow:0 -4px 20px rgba(0,0,0,.07);
  }
  .sp-bar-price{min-width:0;flex:1}
  .sp-bar-amt{
    font-size:17px;font-weight:700;color:var(--ink);letter-spacing:-.01em;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2;
  }
  .sp-bar-lbl{
    font-size:10px;font-weight:600;letter-spacing:.11em;text-transform:uppercase;
    color:var(--ink-3);margin-top:2px;
  }
  .sp-bar-btn{
    flex:none;display:inline-flex;align-items:center;gap:8px;
    padding:12px 20px;border-radius:9px;font-size:14.5px;font-weight:600;
    text-decoration:none;color:#fff;background:#25d366;
  }
  .sp-bar-btn.is-phone{background:var(--ink)}
  .sp-bar-btn:hover{opacity:.92;color:#fff}
  /* So the bar never sits on top of the last line of the footer. */
  body{padding-bottom:78px}
}

/* ── Lightbox ── */
#spLb{display:none;position:fixed;inset:0;background:rgba(4,4,4,.97);z-index:99999;align-items:center;justify-content:center;flex-direction:column;gap:14px;padding:24px}
#spLb.open{display:flex!important}
</style>
</head>
<body>

<!-- Top nav -->
<nav class="sp-nav">
  <div class="sp-wrap sp-nav-inner">
    <?php // The company, stated rather than linked. This is not a shop front,
          // and a logo that navigates is an invitation to leave. ?>
    <span class="sp-logo">
      <?php if ($logoInfo['exists']): ?>
      <img src="<?= htmlspecialchars($logoInfo['url']) ?>" alt="<?= htmlspecialchars($companyName) ?>">
      <?php else: ?>
      <?= htmlspecialchars($companyName) ?>
      <?php endif; ?>
    </span>
    <span class="sp-nav-badge">Prepared for you</span>
  </div>
</nav>

<div class="sp-hero">
  <div class="sp-wrap">
    <div class="sp-grid">

      <!-- LEFT, upper: the photographs -->
      <div class="sp-gal">
        <?php if ($images): ?>
        <!-- Main image -->
        <div class="sp-gal-main" id="spMain" onclick="spOpen(spIdx)">
          <img id="spMainImg"
               src="<?= htmlspecialchars(BASE_URL . '/uploads/cars/' . $images[0]['file_path']) ?>"
               alt="<?= htmlspecialchars($carTitle) ?>"
               fetchpriority="high" decoding="async">
          <?php if (count($images) > 1): ?>
          <button class="sp-arrow sp-al" onclick="event.stopPropagation();spChange(-1)" aria-label="Previous"><i class="fa fa-chevron-left"></i></button>
          <button class="sp-arrow sp-ar" onclick="event.stopPropagation();spChange(1)"  aria-label="Next"><i class="fa fa-chevron-right"></i></button>
          <div class="sp-cnt" id="spCnt">1 / <?= count($images) ?></div>
          <?php endif; ?>
          <div class="sp-zoom"><i class="fa fa-expand"></i></div>
        </div>

        <?php if (count($images) > 1): ?>
        <!-- Scrollable thumbnails -->
        <div class="sp-thumbs" id="spThumbs">
          <?php foreach ($images as $i => $img): ?>
          <button class="sp-thumb <?= $i === 0 ? 'active' : '' ?>"
                  onclick="spSel(<?= $i ?>)"
                  style="border-color:<?= $i === 0 ? 'var(--ink)' : 'var(--line)' ?>">
            <img src="<?= htmlspecialchars(thumbUrl('cars', $img['file_path'])) ?>"
                 alt="Photo <?= $i+1 ?>"
                 loading="<?= $i < 5 ? 'eager' : 'lazy' ?>" decoding="async">
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="sp-noimg"><i class="fa fa-car-side"></i><div>No photos yet</div></div>
        <?php endif; ?>

      </div><!-- /gallery -->

      <!-- LEFT, lower: the detail. A separate grid child purely so that a phone
           can put the price and the enquiry button in front of it. -->
      <div class="sp-detail">
        <!-- Stats strip -->
        <div class="sp-stats">
          <div>
            <i class="fa fa-road"></i>
            <div class="v"><?= $car['mileage'] ? number_format((int)$car['mileage']) : '—' ?></div>
            <div class="l"><?= $car['mileage'] ? 'km · Mileage' : 'Mileage' ?></div>
          </div>
          <div>
            <i class="fa fa-bolt"></i>
            <div class="v"><?= $car['engine_cc'] ? number_format((int)$car['engine_cc']) : '—' ?></div>
            <div class="l"><?= $car['engine_cc'] ? 'cc · Engine' : 'Engine' ?></div>
          </div>
          <div>
            <i class="fa fa-gears"></i>
            <div class="v"><?= $car['transmission'] ? ucfirst($car['transmission']) : '—' ?></div>
            <div class="l">Drive</div>
          </div>
          <div>
            <i class="fa fa-gas-pump"></i>
            <div class="v"><?= $car['fuel_type'] ? ucfirst($car['fuel_type']) : '—' ?></div>
            <div class="l">Fuel</div>
          </div>
        </div>

        <!-- Accordions -->
        <div class="sp-accs">

          <details class="sp-acc" open>
            <summary>Vehicle Specifications <i class="fa fa-plus"></i></summary>
            <div class="sp-acc-body">
              <?php
              $specs = [
                ['Make',           $car['make']],
                ['Model',          $car['model']],
                ['Year',           $car['year']],
                ['Body Type',      $car['body_type']],
                ['Colour',         $car['color']],
                ['Fuel Type',      $car['fuel_type']   ? ucfirst($car['fuel_type'])   : null],
                ['Transmission',   $car['transmission']? ucfirst($car['transmission']): null],
                ['Engine',         $car['engine_cc']   ? number_format((int)$car['engine_cc']) . ' cc' : null],
                ['Mileage',        $car['mileage']     ? number_format((int)$car['mileage']) . ' km'   : null],
                ['Registration',   $car['registration_number'] ?: null],
                ['Location',       $car['location_name'] ?? null],
              ];
              foreach ($specs as [$lbl, $val]):
                if (!$val) continue;
              ?>
              <div class="sp-spec">
                <span><?= htmlspecialchars($lbl) ?></span>
                <strong><?= htmlspecialchars((string)$val) ?></strong>
              </div>
              <?php endforeach; ?>
            </div>
          </details>

          <?php if ($publicDesc): ?>
          <details class="sp-acc" open>
            <summary>About This Vehicle <i class="fa fa-plus"></i></summary>
            <div class="sp-acc-body">
              <p class="sp-desc"><?= nl2br(htmlspecialchars($publicDesc)) ?></p>
            </div>
          </details>
          <?php endif; ?>

          <?php if ($featureList): ?>
          <details class="sp-acc" open>
            <summary>Features &amp; Equipment <i class="fa fa-plus"></i></summary>
            <div class="sp-acc-body">
              <ul class="sp-feats">
                <?php foreach ($featureList as $feat): ?>
                <li><i class="fa fa-check"></i><?= htmlspecialchars($feat) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </details>
          <?php endif; ?>

        </div>
      </div><!-- /detail -->

      <!-- RIGHT: Sticky panel -->
      <div class="sp-panel-col">
        <div class="sp-panel">

          <?php if ($inTransit): ?>
          <div class="sp-avail"><span class="sp-dot sp-dot-dark"></span><i class="fa fa-ship"></i> In Shipment</div>
          <?php elseif ($isReserved): ?>
          <div class="sp-avail"><span class="sp-dot sp-dot-dark"></span>Reserved</div>
          <?php else: ?>
          <div class="sp-avail"><span class="sp-dot"></span>Available</div>
          <?php endif; ?>

          <h1 class="sp-title"><?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?></h1>
          <div class="sp-sub">
            <?= htmlspecialchars($car['year']) ?>
            <?= $car['color']     ? ' · ' . htmlspecialchars($car['color'])     : '' ?>
            <?= $car['body_type'] ? ' · ' . htmlspecialchars($car['body_type']) : '' ?>
          </div>

          <div class="sp-price-box">
            <?php if ($inTransit): ?>
              <?php if ($dispPrice): ?>
              <div class="sp-price-amt"><?= htmlspecialchars($priceStr) ?></div>
              <div class="sp-price-note">Reserve now — we'll contact you on arrival</div>
              <?php else: ?>
              <div class="sp-price-amt" style="font-size:22px">Price on arrival</div>
              <div class="sp-price-note">Ask us about reservation terms</div>
              <?php endif; ?>
            <?php elseif ($isReserved): ?>
              <div class="sp-price-amt" style="font-weight:400;font-size:22px;color:var(--ink-2)">Reserved</div>
              <?php if ($hasPrice): ?><div class="sp-price-note">Listed at KES <?= number_format((float)$car['asking_price']) ?></div><?php endif; ?>
            <?php elseif ($dispPrice): ?>
              <div class="sp-price-amt"><?= htmlspecialchars($priceStr) ?></div>
              <?php if ($saveAmt > 0): ?>
              <div class="sp-price-note"><del>KES <?= number_format((float)$car['asking_price']) ?></del> &nbsp;·&nbsp; Save KES <?= number_format($saveAmt) ?></div>
              <?php else: ?>
              <div class="sp-price-note">Financing available · Trade-in welcome</div>
              <?php endif; ?>
            <?php else: ?>
              <div class="sp-price-amt" style="font-size:22px">Price on request</div>
              <div class="sp-price-note">Send an enquiry or WhatsApp us</div>
            <?php endif; ?>
          </div>

          <?php if (!empty($car['location_name'])): ?>
          <div class="sp-loc"><i class="fa fa-location-dot"></i>Available at <?= htmlspecialchars($car['location_name']) ?></div>
          <?php endif; ?>

          <?php if ($whatsappNum): ?>
          <a href="https://wa.me/<?= $whatsappNum ?>?text=<?= $waMsg ?>"
             target="_blank" rel="noopener" class="sp-btn sp-btn-wa">
            <i class="fa-brands fa-whatsapp"></i>
            <?= $inTransit ? 'Reserve on WhatsApp' : ($isReserved ? 'Join Waitlist' : 'Enquire on WhatsApp') ?>
          </a>
          <?php endif; ?>
          <?php if ($companyPhone): ?>
          <a href="tel:<?= htmlspecialchars($companyPhone) ?>" class="sp-btn sp-btn-ph">
            <i class="fa fa-phone"></i> <?= htmlspecialchars($companyPhone) ?>
          </a>
          <?php endif; ?>
          <?php // A yard with an address but no phone would otherwise show a
                // vehicle and no way to answer about it. Email is the fallback,
                // with the car already named in the subject so the reply lands
                // on somebody who knows which one is meant. ?>
          <?php $sellEmail = getSetting('company_email', '');
                if ($sellEmail !== '' && !$whatsappNum): ?>
          <a href="mailto:<?= htmlspecialchars($sellEmail) ?>?subject=<?= urlencode('Enquiry: ' . $carTitle) ?>"
             class="sp-btn sp-btn-ph">
            <i class="fa fa-envelope"></i> Email us about this vehicle
          </a>
          <?php endif; ?>

          <!-- Copy link -->
          <button type="button" class="sp-btn sp-btn-copy" id="spCopyBtn"
                  onclick="(function(b){
                    var u='<?= addslashes($shareUrl) ?>';
                    navigator.clipboard ? navigator.clipboard.writeText(u).then(function(){
                        b.innerHTML='<i class=\'fa fa-check\'></i>&nbsp;Link copied!';
                        setTimeout(function(){b.innerHTML='<i class=\'fa fa-link\'></i>&nbsp;Copy link';},2500);
                    }) : (function(){var t=document.createElement('textarea');t.value=u;document.body.appendChild(t);t.select();document.execCommand('copy');document.body.removeChild(t);b.innerHTML='<i class=\'fa fa-check\'></i>&nbsp;Link copied!';setTimeout(function(){b.innerHTML='<i class=\'fa fa-link\'></i>&nbsp;Copy link';},2500);})();
                  })(this)">
            <i class="fa fa-link"></i>&nbsp;Copy link
          </button>

          <!-- Share row -->
          <div class="sp-share">
            <span class="sp-share-label">Share</span>
            <?php if ($whatsappNum): ?>
            <a href="https://wa.me/?text=<?= urlencode("Check out this {$carTitle} at {$companyName}: {$shareUrl}") ?>"
               target="_blank" rel="noopener" class="sp-share-btn" title="WhatsApp">
              <i class="fa-brands fa-whatsapp"></i>
            </a>
            <?php endif; ?>
            <a href="mailto:?subject=<?= urlencode("Check out this {$carTitle}") ?>&body=<?= urlencode("Hi, I found this vehicle at {$companyName}: {$shareUrl}") ?>"
               class="sp-share-btn" title="Email">
              <i class="fa fa-envelope"></i>
            </a>
            <button class="sp-share-btn" title="Copy link"
                    onclick="(function(b){var u='<?= addslashes($shareUrl) ?>';navigator.clipboard?navigator.clipboard.writeText(u):void 0;})(this)">
              <i class="fa fa-link"></i>
            </button>
          </div>

        </div><!-- /sp-panel -->
      </div><!-- /right -->

    </div><!-- /sp-grid -->
  </div><!-- /sp-wrap -->
</div><!-- /sp-hero -->

<?php // Fixed to the bottom on a phone only. One price, one action — whichever
      // way of reaching us is actually configured. If none is, it does not
      // render at all rather than showing a button that goes nowhere. ?>
<?php
$barHref = $barText = $barCls = $barIcon = '';
if ($whatsappNum) {
    $barHref = 'https://wa.me/' . $whatsappNum . '?text=' . $waMsg;
    $barText = $inTransit ? 'Reserve' : ($isReserved ? 'Waitlist' : 'Enquire');
    $barIcon = 'fa-brands fa-whatsapp';
} elseif ($companyPhone) {
    $barHref = 'tel:' . $companyPhone;
    $barText = 'Call us';
    $barIcon = 'fa fa-phone';
    $barCls  = ' is-phone';
} elseif ($sellEmail = getSetting('company_email', '')) {
    $barHref = 'mailto:' . $sellEmail . '?subject=' . urlencode('Enquiry: ' . $carTitle);
    $barText = 'Email us';
    $barIcon = 'fa fa-envelope';
    $barCls  = ' is-phone';
}
?>
<?php if ($barHref !== ''): ?>
<div class="sp-bar">
  <div class="sp-bar-price">
    <div class="sp-bar-amt"><?= htmlspecialchars($dispPrice ? $priceStr : 'Price on request') ?></div>
    <div class="sp-bar-lbl">
      <?= $inTransit ? 'In shipment' : ($isReserved ? 'Reserved' : 'Available') ?>
    </div>
  </div>
  <a class="sp-bar-btn<?= $barCls ?>" href="<?= htmlspecialchars($barHref) ?>"
     <?= str_starts_with($barHref, 'https://') ? 'target="_blank" rel="noopener"' : '' ?>>
    <i class="<?= $barIcon ?>"></i><?= htmlspecialchars($barText) ?>
  </a>
</div>
<?php endif; ?>

<footer class="sp-foot">
  <div class="sp-wrap">
    <div style="margin-bottom:6px">
      Sent to you by <strong><?= htmlspecialchars($companyName) ?></strong>.
      Questions about this vehicle? Just reply to whoever sent it, or reach us below.
    </div>
    <?php if ($companyPhone): ?>
    <a href="tel:<?= htmlspecialchars($companyPhone) ?>"><?= htmlspecialchars($companyPhone) ?></a>
    <?php endif; ?>
    <?php $__em = getSetting('company_email', ''); if ($__em !== ''): ?>
    &nbsp;·&nbsp; <a href="mailto:<?= htmlspecialchars($__em) ?>"><?= htmlspecialchars($__em) ?></a>
    <?php endif; ?>
    <?php $__ad = getSetting('company_address', ''); if ($__ad !== ''): ?>
    <div style="margin-top:6px;opacity:.75"><?= htmlspecialchars($__ad) ?></div>
    <?php endif; ?>
  </div>
</footer>

<!-- Lightbox -->
<div id="spLb" onclick="spClose()">
  <button onclick="event.stopPropagation();spClose()"
          style="position:absolute;top:20px;right:20px;background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:44px;height:44px;border-radius:8px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center">
    <i class="fa fa-xmark"></i>
  </button>
  <?php if (count($images) > 1): ?>
  <button onclick="event.stopPropagation();spChange(-1,true)"
          style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:8px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
    <i class="fa fa-chevron-left"></i>
  </button>
  <button onclick="event.stopPropagation();spChange(1,true)"
          style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:8px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
    <i class="fa fa-chevron-right"></i>
  </button>
  <?php endif; ?>
  <img id="spLbImg" src="" alt="" onclick="event.stopPropagation()"
       style="max-width:90vw;max-height:80vh;object-fit:contain">
  <div id="spLbCnt" style="color:rgba(255,255,255,.5);font-size:12px;font-weight:600;letter-spacing:.1em"></div>
</div>

<script>
var spPhotos = <?= json_encode(array_values(array_map(fn($img) => BASE_URL . '/uploads/cars/' . $img['file_path'], $images))) ?>;
var spIdx    = 0;
var spMI     = document.getElementById('spMainImg');
var spCnt    = document.getElementById('spCnt');
var spTbs    = document.querySelectorAll('.sp-thumb');
var spLb     = document.getElementById('spLb');
var spLbI    = document.getElementById('spLbImg');
var spLbC    = document.getElementById('spLbCnt');

function spSel(i) {
  spIdx = (i + spPhotos.length) % spPhotos.length;
  spMI.style.opacity = '0';
  setTimeout(function(){ spMI.src = spPhotos[spIdx]; spMI.style.opacity = '1'; }, 100);
  if (spCnt) spCnt.textContent = (spIdx+1) + ' / ' + spPhotos.length;
  spTbs.forEach(function(t, j) {
    t.style.borderColor = j === spIdx ? 'var(--ink)' : 'var(--line)';
    t.classList.toggle('active', j === spIdx);
  });
  if (spTbs[spIdx]) spTbs[spIdx].scrollIntoView({inline:'nearest',block:'nearest',behavior:'smooth'});
  if (spLb && spLb.classList.contains('open')) {
    spLbI.src = spPhotos[spIdx];
    if (spLbC) spLbC.textContent = (spIdx+1) + ' / ' + spPhotos.length;
  }
}
function spChange(d) { spSel(spIdx + d); }
function spOpen(i) {
  if (!spLb || !spPhotos.length) return;
  spSel(i);
  spLbI.src = spPhotos[spIdx];
  if (spLbC) spLbC.textContent = (spIdx+1) + ' / ' + spPhotos.length;
  spLb.style.display = 'flex';
  spLb.classList.add('open');
  document.body.style.overflow = 'hidden';
}
function spClose() {
  if (!spLb) return;
  spLb.style.display = 'none';
  spLb.classList.remove('open');
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) {
  if (!spLb || !spLb.classList.contains('open')) return;
  if (e.key === 'ArrowLeft')  spChange(-1);
  if (e.key === 'ArrowRight') spChange(1);
  if (e.key === 'Escape')     spClose();
});
// Swiping the photographs.
//
// Two things were wrong on a phone. Swiping worked on the main image but not
// inside the lightbox, which is the one place somebody actually expects it —
// a full-screen photo that will not move is the moment the page stops feeling
// finished. And a swipe on the main image also fired its click, so flicking to
// the next photo threw the viewer open on top of you.
(function(){
  if (spPhotos.length < 2) return;

  function swipeable(el, onSwipe) {
    if (!el) return;
    var sx = 0, sy = 0, moved = false;
    el.addEventListener('touchstart', function(e){
      sx = e.changedTouches[0].clientX;
      sy = e.changedTouches[0].clientY;
      moved = false;
    }, {passive:true});
    el.addEventListener('touchend', function(e){
      var dx = e.changedTouches[0].clientX - sx,
          dy = e.changedTouches[0].clientY - sy;
      // Sideways, and more sideways than up — otherwise a scroll down the page
      // that drifts a little counts as a swipe and the photo jumps.
      if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy)) {
        moved = true;
        onSwipe(dx < 0 ? 1 : -1);
      }
    }, {passive:true});
    // A swipe is not a tap. Swallow the click the browser sends afterwards.
    el.addEventListener('click', function(e){
      if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; }
    }, true);
  }

  // Both go through spChange, which already refreshes the lightbox when it is
  // the thing on screen — repeating that here would be a second copy of the
  // same three lines, waiting to disagree with the first.
  swipeable(document.getElementById('spMain'), spChange);
  swipeable(document.getElementById('spLb'),   spChange);
}());
</script>

</body>
</html>
