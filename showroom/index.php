<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

// Inline migrations — silent no-op if columns already exist
try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}

// ── Stock overview for hero stats + featured blocks ───────────────────────────
$allCars = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.body_type,
           c.transmission, c.fuel_type, c.asking_price, c.offer_price, c.mileage,
           c.engine_cc, c.featured, c.created_at, c.status,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image
    FROM cars c
    WHERE c.car_type='inventory' AND c.show_on_website = 1
      AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))
    ORDER BY c.featured DESC, c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalStock  = count($allCars);
$featuredAll = array_values(array_filter($allCars, fn($c) => $c['featured']));

$catCounts = [];
foreach ($allCars as $c) {
    $bt = $c['body_type'] ?: 'Other';
    $catCounts[$bt] = ($catCounts[$bt] ?? 0) + 1;
}

$companyName = getSetting('company_name', 'Mascardi Car Yard');
$__waClean   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
$pageTitle   = 'Quality Vehicles';
$metaDesc    = "Browse {$totalStock} quality vehicles at {$companyName}. Transparent pricing, flexible financing. Find your dream car today.";

// 3D showcase carousel — ONLY vehicles that have a photo. Featured cars first,
// topped up with other photographed inventory when there aren't enough.
$withImg = fn($c) => !empty($c['primary_image']);
$carouselCars = array_values(array_filter($featuredAll, $withImg));
if (count($carouselCars) < 5) {
    $haveIds = array_column($carouselCars, 'id');
    foreach ($allCars as $c) {
        if (count($carouselCars) >= 8) break;
        if ($withImg($c) && !in_array($c['id'], $haveIds)) $carouselCars[] = $c;
    }
}
$carouselCars = array_slice($carouselCars, 0, 8);

$vehiclesUrl = BASE_URL . '/showroom/vehicles.php';

// Nav renders transparently over the video hero
$navOverlay = true;

include __DIR__ . '/header.php';
?>

<!-- ═══════════════════════════════════════════════════════════
     HERO — full-bleed autoplay video
═══════════════════════════════════════════════════════════════ -->
<section id="hero" class="lx-hero">
    <div class="lx-video-cover">
        <iframe src="https://www.youtube.com/embed/x7U4nFENdjU?autoplay=1&amp;mute=1&amp;controls=0&amp;loop=1&amp;playlist=x7U4nFENdjU&amp;playsinline=1&amp;rel=0&amp;iv_load_policy=3&amp;disablekb=1"
                title="Mascardi hero video" frameborder="0"
                allow="autoplay; encrypted-media; picture-in-picture" tabindex="-1"></iframe>
    </div>
    <div class="lx-hero-shade"></div>

    <div class="lx-hero-content">
        <div class="lx-label rv" style="color:rgba(255,255,255,.65);margin-bottom:18px"><?= $totalStock ?> Vehicles Available Now</div>
        <h1 class="rv rv-d1">Extraordinary cars.<br>Effortless ownership.</h1>
        <p class="rv rv-d2">Quality imported vehicles, transparent pricing and flexible financing — hand-inspected and ready for the road.</p>
        <div class="lx-hero-ctas rv rv-d3">
            <a href="<?= $vehiclesUrl ?>" class="btn-lx-light">Explore Vehicles</a>
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
                [$totalStock,       '+',       'Vehicles in Stock'],
                [count($catCounts), '',        'Body Types'],
                [100,               '%',       'Inspected & Verified'],
                [null,              'Finance', 'Flexible Plans Available'],
            ] as [$num, $suffix, $lbl]): ?>
            <div class="lx-stat rv">
                <?php if ($num !== null): ?>
                <div class="v" data-count="<?= $num ?>" data-suffix="<?= htmlspecialchars($suffix) ?>">0<?= htmlspecialchars($suffix) ?></div>
                <?php else: ?>
                <div class="v"><?= htmlspecialchars($suffix) ?></div>
                <?php endif; ?>
                <div class="l"><?= $lbl ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     3D SHOWCASE CAROUSEL — flagship vehicles (photos only)
═══════════════════════════════════════════════════════════════ -->
<?php if ($carouselCars): ?>
<section style="background:var(--paper);padding:96px 0;overflow:hidden">
    <div class="lx-wrap">
        <div class="rv" style="text-align:center;margin-bottom:56px">
            <div class="lx-label" style="margin-bottom:14px">Featured</div>
            <h2 class="lx-h2">Handpicked from our showroom</h2>
        </div>
    </div>

    <div class="c3d-wrap rv rv-d1">
        <div class="c3d-viewport" id="c3dViewport">
            <?php foreach ($carouselCars as $cc):
                $ccImg     = thumbUrl('cars', $cc['primary_image']);
                $ccIsOffer = !empty($cc['offer_price']) && $cc['offer_price'] > 0;
                $ccPrice   = $ccIsOffer ? (float)$cc['offer_price'] : (float)($cc['asking_price'] ?? 0);
                $ccSpecs   = array_filter([
                    $cc['mileage']      ? number_format($cc['mileage']) . ' km' : null,
                    $cc['transmission'] ? ucfirst($cc['transmission'])          : null,
                    $cc['fuel_type']    ? ucfirst($cc['fuel_type'])             : null,
                ]);
            ?>
            <a class="c3d-card" href="<?= BASE_URL ?>/showroom/view.php?id=<?= $cc['id'] ?>">
                <div class="img">
                    <img src="<?= htmlspecialchars($ccImg) ?>" alt="<?= htmlspecialchars($cc['make'].' '.$cc['model']) ?>" loading="lazy" decoding="async">
                </div>
                <div class="c3d-body">
                    <div class="lx-label" style="margin-bottom:6px"><?= $cc['year'] ?><?= $cc['body_type'] ? ' · ' . htmlspecialchars($cc['body_type']) : '' ?></div>
                    <h3><?= htmlspecialchars($cc['make'] . ' ' . $cc['model']) ?></h3>
                    <div class="c3d-price">
                        <?php if ($ccPrice > 0): ?>
                            <?php if ($ccIsOffer): ?><span class="offer-tag">Offer</span><?php endif; ?>
                            KES <?= number_format($ccPrice) ?>
                            <?php if ($ccIsOffer && !empty($cc['asking_price'])): ?>
                            <del>KES <?= number_format((float)$cc['asking_price']) ?></del>
                            <?php endif; ?>
                        <?php else: ?>
                            Price on request
                        <?php endif; ?>
                    </div>
                    <?php if ($ccSpecs): ?>
                    <div class="c3d-specs"><?= implode(' &middot; ', $ccSpecs) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>

            <?php if (count($carouselCars) > 1): ?>
            <button type="button" class="c3d-nav c3d-prev" id="c3dPrev" aria-label="Previous vehicle"><i class="fa fa-chevron-left"></i></button>
            <button type="button" class="c3d-nav c3d-next" id="c3dNext" aria-label="Next vehicle"><i class="fa fa-chevron-right"></i></button>
            <?php endif; ?>
        </div>
        <div class="c3d-dots" id="c3dDots"></div>
    </div>

    <div class="lx-wrap rv" style="text-align:center;margin-top:40px">
        <a href="<?= $vehiclesUrl ?>" class="btn-lx-ghost-dark">View All Vehicles</a>
    </div>
</section>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     BRAND STATEMENT
═══════════════════════════════════════════════════════════════ -->
<section id="story" style="background:var(--white);padding:110px 0;text-align:center">
    <div class="lx-wrap" style="max-width:900px">
        <h2 class="lx-h2 rv" style="font-size:clamp(30px,4.6vw,54px)">
            Sourced worldwide.<br>
            Inspected in Nairobi.<br>
            Ready for your road.
        </h2>
        <p class="rv rv-d1" style="font-size:16px;color:var(--ink-2);line-height:1.8;max-width:560px;margin:28px auto 0">
            Every vehicle at <?= htmlspecialchars($companyName) ?> passes a thorough multi-point
            inspection before it reaches the showroom floor. What you see is exactly what you get —
            no hidden fees, no surprises.
        </p>
    </div>

    <div class="lx-wrap" style="margin-top:64px">
        <div class="lx-promo-grid">
            <a href="<?= $vehiclesUrl ?>?sort=price_asc" class="lx-promo rv">
                <div class="lx-label" style="margin-bottom:10px">Current Offers</div>
                <div class="t">Explore vehicles on offer</div>
                <span class="arr"><i class="fa fa-arrow-right"></i></span>
            </a>
            <a href="<?= BASE_URL ?>/showroom/contact.php" class="lx-promo rv rv-d1">
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
        <iframe src="https://www.youtube.com/embed/P3JHd_cHHCM?autoplay=1&amp;mute=1&amp;controls=0&amp;loop=1&amp;playlist=P3JHd_cHHCM&amp;playsinline=1&amp;rel=0&amp;iv_load_policy=3&amp;disablekb=1"
                title="Mascardi brand film" frameborder="0"
                allow="autoplay; encrypted-media; picture-in-picture" tabindex="-1"></iframe>
    </div>
    <div class="lx-film-shade"></div>
    <div class="lx-film-caption">
        <div class="lx-label rv" style="color:rgba(255,255,255,.65);margin-bottom:14px">The Mascardi Experience</div>
        <h2 class="rv rv-d1" style="font-size:clamp(26px,3.6vw,44px);font-weight:300;color:#fff;margin:0 0 26px;line-height:1.15">
            A new standard for<br>car buying in Kenya
        </h2>
        <a href="<?= $vehiclesUrl ?>" class="btn-lx-light rv rv-d2" style="display:inline-flex">Browse the Collection</a>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     WHY MASCARDI
═══════════════════════════════════════════════════════════════ -->
<section style="background:var(--white);padding:100px 0">
    <div class="lx-wrap">
        <div class="rv" style="text-align:center;margin-bottom:64px">
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
            <div class="lx-value rv">
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
            <div class="col-lg-5 rv">
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
            <div class="col-lg-7 rv rv-d1">
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
        <h2 class="rv" style="font-size:clamp(26px,3.6vw,44px);font-weight:300;color:#fff;margin:0 0 14px">Ready to find your car?</h2>
        <p class="rv rv-d1" style="font-size:15px;color:rgba(255,255,255,.55);margin:0 0 36px">Talk to our team today. We're here to help.</p>
        <div class="rv rv-d2" style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap">
            <?php if ($__waClean): ?>
            <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener" class="btn-lx-light">
                <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
            </a>
            <?php endif; ?>
            <a href="<?= $vehiclesUrl ?>" class="btn-lx-ghost">Browse Vehicles</a>
        </div>
    </div>
</section>

<!-- ── Styles ──────────────────────────────────────────────────────────────── -->
<style>
/* ── Cropped, chrome-free YouTube cover video ─────────────────
   The iframe is guaranteed larger than the viewport in both axes
   (100vw × 56.25vw, min 177.78vh × 100vh), centered and slightly
   scaled so YouTube UI/watermark falls outside the visible crop.
   pointer-events off = no hover chrome ever appears. */
.lx-video-cover { position: absolute; inset: 0; overflow: hidden; background: var(--black); pointer-events: none; }
.lx-video-cover iframe {
    position: absolute; top: 50%; left: 50%;
    width: 100vw; height: 56.25vw;         /* 16:9 of viewport width  */
    min-width: 177.78vh; min-height: 100vh; /* 16:9 of viewport height */
    transform: translate(-50%, -50%) scale(1.15);
    border: 0;
}

/* Hero */
.lx-hero { position: relative; min-height: 100vh; display: flex; align-items: flex-end; overflow: hidden; background: var(--black); }
.lx-hero-shade {
    position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(to top, rgba(4,4,4,.78) 0%, rgba(4,4,4,.22) 45%, rgba(4,4,4,.34) 100%);
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

/* ── Scroll-reveal (elements with .rv fade-rise when they enter view) ── */
.rv { opacity: 0; transform: translateY(30px); transition: opacity .9s var(--ease), transform .9s var(--ease); }
.rv.in { opacity: 1; transform: none; }
.rv-d1 { transition-delay: .12s; }
.rv-d2 { transition-delay: .24s; }
.rv-d3 { transition-delay: .36s; }
/* Staggered rows */
.lx-stats  .lx-stat.rv:nth-child(2)  { transition-delay: .08s; }
.lx-stats  .lx-stat.rv:nth-child(3)  { transition-delay: .16s; }
.lx-stats  .lx-stat.rv:nth-child(4)  { transition-delay: .24s; }
.lx-values .lx-value.rv:nth-child(2) { transition-delay: .08s; }
.lx-values .lx-value.rv:nth-child(3) { transition-delay: .16s; }
.lx-values .lx-value.rv:nth-child(4) { transition-delay: .24s; }
.lx-values .lx-value.rv:nth-child(5) { transition-delay: .32s; }
.lx-values .lx-value.rv:nth-child(6) { transition-delay: .40s; }

/* ── 3D showcase carousel ─────────────────────────────────────── */
.c3d-wrap { position: relative; }
.c3d-viewport {
    position: relative;
    height: calc(min(430px, 82vw) * 0.625 + 172px);
    perspective: 1700px;
}
.c3d-card {
    position: absolute; left: 50%; top: 0;
    width: min(430px, 82vw);
    margin-left: calc(min(430px, 82vw) / -2);
    background: var(--white); border: 1px solid var(--line); border-radius: var(--r);
    overflow: hidden; display: block; text-decoration: none;
    transform-style: preserve-3d; backface-visibility: hidden;
    transition: transform .7s var(--ease), opacity .7s var(--ease), box-shadow .7s var(--ease);
    will-change: transform, opacity;
}
.c3d-card .img { aspect-ratio: 16/10; overflow: hidden; background: var(--paper); }
.c3d-card .img img { width: 100%; height: 100%; object-fit: cover; }
.c3d-body { padding: 20px 26px 24px; }
.c3d-body h3 { font-size: 21px; font-weight: 500; letter-spacing: -.01em; color: var(--ink); margin: 0 0 8px; }
.c3d-price { font-size: 16px; font-weight: 600; color: var(--ink); margin-bottom: 6px; }
.c3d-price del { font-size: 12.5px; color: var(--ink-3); font-weight: 400; margin-left: 8px; }
.c3d-specs { font-size: 12.5px; color: var(--ink-3); }
.c3d-card.is-center { box-shadow: 0 34px 80px rgba(0,0,0,.18); }
.c3d-card:not(.is-center) .c3d-body { pointer-events: none; }

.c3d-nav {
    position: absolute; top: 50%; transform: translateY(-50%); z-index: 200;
    width: 46px; height: 46px; border: 1px solid var(--line); border-radius: var(--r);
    background: rgba(255,255,255,.95); color: var(--ink); font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: border-color .25s var(--ease), background .25s var(--ease);
}
.c3d-nav:hover { border-color: var(--ink); background: #fff; }
.c3d-prev { left: max(16px, calc(50% - min(430px, 82vw) / 2 - 260px)); }
.c3d-next { right: max(16px, calc(50% - min(430px, 82vw) / 2 - 260px)); }

.c3d-dots { display: flex; gap: 8px; justify-content: center; margin-top: 30px; }
.c3d-dot {
    width: 26px; height: 2px; padding: 0; border: none; cursor: pointer;
    background: var(--line); transition: background .3s var(--ease);
}
.c3d-dot.on { background: var(--ink); }

.offer-tag {
    display: inline-block; font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    background: var(--ink); color: #fff;
    padding: 3px 9px; border-radius: var(--r); vertical-align: 2px; margin-right: 7px;
}
@media (prefers-reduced-motion: reduce) {
    .rv { opacity: 1; transform: none; transition: none; }
    .c3d-card { transition: opacity .3s ease; }
}

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
.lx-film { position: relative; height: min(86vh, 780px); min-height: 480px; overflow: hidden; display: flex; align-items: center; background: var(--black); }
.lx-film-shade { position: absolute; inset: 0; background: rgba(4,4,4,.42); pointer-events: none; }
.lx-film-caption { position: relative; z-index: 2; width: 100%; max-width: 1320px; margin: 0 auto; padding: 0 28px; }

/* Values */
.lx-values { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0; border-top: 1px solid var(--line); border-left: 1px solid var(--line); }
@media (max-width: 991px) { .lx-values { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px) { .lx-values { grid-template-columns: 1fr; } }
.lx-value { padding: 40px 36px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); }
.lx-value i { font-size: 20px; color: var(--ink); margin-bottom: 18px; display: block; }
.lx-value .t { font-size: 16px; font-weight: 500; margin-bottom: 10px; color: var(--ink); }
.lx-value p { font-size: 13.5px; color: var(--ink-2); line-height: 1.7; margin: 0; }

/* Service list */
.lx-service-list { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border-top: 1px solid var(--line); max-width: 420px; }
.lx-service-list div { font-size: 13.5px; color: var(--ink); padding: 13px 4px; border-bottom: 1px solid var(--line); }

@media (max-width: 768px) {
    .lx-hero-content { padding-bottom: 80px; }
    .c3d-nav { top: auto; bottom: -8px; transform: none; }
    .c3d-prev { left: calc(50% - 60px); }
    .c3d-next { right: calc(50% - 60px); }
    .c3d-dots { margin-top: 66px; }
}
</style>

<!-- ── Home page interactions: 3D carousel, scroll reveal, stat count-up ── -->
<script>
(function () {
    'use strict';
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── 3D showcase carousel ─────────────────────────────────── */
    (function () {
        var vp = document.getElementById('c3dViewport');
        if (!vp) return;
        var cards = [].slice.call(vp.querySelectorAll('.c3d-card'));
        var n = cards.length;
        if (!n) return;
        var dotsWrap = document.getElementById('c3dDots');
        var cur = 0, timer = null;

        var dots = [];
        if (dotsWrap && n > 1) {
            cards.forEach(function (_, i) {
                var b = document.createElement('button');
                b.className = 'c3d-dot';
                b.setAttribute('aria-label', 'Go to vehicle ' + (i + 1));
                b.addEventListener('click', function () { go(i); });
                dotsWrap.appendChild(b);
                dots.push(b);
            });
        }

        function layout() {
            cards.forEach(function (card, i) {
                // shortest signed distance from the current card (wraps around)
                var d = (i - cur + n) % n;
                if (d > n / 2) d -= n;
                var ad = Math.abs(d), x, z, ry, s, o;
                if      (ad === 0) { x = 0;       z = 0;    ry = 0;       s = 1;   o = 1;   }
                else if (ad === 1) { x = d * 58;  z = -190; ry = -d * 26; s = .85; o = .8;  }
                else if (ad === 2) { x = d * 98;  z = -360; ry = -d * 36; s = .7;  o = .3;  }
                else               { x = d * 120; z = -480; ry = -d * 40; s = .6;  o = 0;   }
                card.style.transform = 'translateX(' + x + '%) translateZ(' + z + 'px) rotateY(' + ry + 'deg) scale(' + s + ')';
                card.style.opacity = o;
                card.style.zIndex = String(100 - ad);
                card.style.pointerEvents = ad > 2 ? 'none' : 'auto';
                card.classList.toggle('is-center', ad === 0);
            });
            dots.forEach(function (dd, i) { dd.classList.toggle('on', i === cur); });
        }
        function go(i)  { cur = (i + n) % n; layout(); restart(); }
        function next() { go(cur + 1); }
        function prev() { go(cur - 1); }

        // Clicking a side card centres it; only the centre card follows its link
        cards.forEach(function (card, i) {
            card.addEventListener('click', function (e) {
                if (i !== cur) { e.preventDefault(); go(i); }
            });
        });

        var bPrev = document.getElementById('c3dPrev');
        var bNext = document.getElementById('c3dNext');
        if (bPrev) bPrev.addEventListener('click', prev);
        if (bNext) bNext.addEventListener('click', next);

        function restart() {
            if (timer) clearInterval(timer);
            timer = null;
            if (!reduced && n > 1) timer = setInterval(next, 4500);
        }
        vp.addEventListener('mouseenter', function () { if (timer) { clearInterval(timer); timer = null; } });
        vp.addEventListener('mouseleave', restart);

        // Swipe
        var sx = 0;
        vp.addEventListener('touchstart', function (e) { sx = e.changedTouches[0].screenX; }, { passive: true });
        vp.addEventListener('touchend', function (e) {
            var dx = e.changedTouches[0].screenX - sx;
            if (Math.abs(dx) > 40) (dx < 0 ? next : prev)();
        }, { passive: true });

        layout();
        restart();
    }());

    /* ── Scroll reveal ────────────────────────────────────────── */
    (function () {
        var els = [].slice.call(document.querySelectorAll('.rv'));
        if (!els.length) return;
        if (reduced || !('IntersectionObserver' in window)) {
            els.forEach(function (e) { e.classList.add('in'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
            });
        }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
        els.forEach(function (e) { io.observe(e); });
    }());

    /* ── Stat count-up ────────────────────────────────────────── */
    (function () {
        var els = [].slice.call(document.querySelectorAll('[data-count]'));
        if (!els.length) return;
        function animate(el) {
            var target = parseInt(el.getAttribute('data-count'), 10) || 0;
            var suffix = el.getAttribute('data-suffix') || '';
            if (reduced) { el.textContent = target.toLocaleString() + suffix; return; }
            var t0 = null, dur = 1400;
            function tick(ts) {
                if (!t0) t0 = ts;
                var p = Math.min(1, (ts - t0) / dur);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = Math.round(target * eased).toLocaleString() + suffix;
                if (p < 1) requestAnimationFrame(tick);
            }
            requestAnimationFrame(tick);
        }
        if (!('IntersectionObserver' in window)) {
            els.forEach(animate);
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { animate(en.target); io.unobserve(en.target); }
            });
        }, { threshold: .4 });
        els.forEach(function (e) { io.observe(e); });
    }());
}());
</script>

<?php include __DIR__ . '/footer.php'; ?>
