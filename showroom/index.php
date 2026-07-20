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

    <div class="fcar rv rv-d1">
        <div class="fcar-viewport" id="fcarViewport">
            <div class="fcar-track" id="fcarTrack">
                <?php foreach ($carouselCars as $fb):
                    $fbImg   = thumbUrl('cars', $fb['primary_image']);
                    $fbPrice = (!empty($fb['offer_price']) && $fb['offer_price'] > 0) ? (float)$fb['offer_price'] : (float)($fb['asking_price'] ?? 0);
                    $fbIsOffer = !empty($fb['offer_price']) && $fb['offer_price'] > 0;
                    $fbWa    = urlencode("Hi, I'm interested in the {$fb['year']} {$fb['make']} {$fb['model']}.");
                ?>
                <div class="fcar-slide">
                    <div class="lx-feature">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $fb['id'] ?>" class="lx-feature-img">
                            <img src="<?= htmlspecialchars($fbImg) ?>" alt="<?= htmlspecialchars($fb['make'].' '.$fb['model']) ?>" loading="lazy" decoding="async">
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
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($carouselCars) > 1): ?>
            <button type="button" class="fcar-nav fcar-prev" id="fcarPrev" aria-label="Previous vehicles"><i class="fa fa-chevron-left"></i></button>
            <button type="button" class="fcar-nav fcar-next" id="fcarNext" aria-label="Next vehicles"><i class="fa fa-chevron-right"></i></button>
            <?php endif; ?>
        </div>
        <div class="fcar-dots" id="fcarDots"></div>
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

/* ── Featured carousel — original large feature cards on a sliding track ── */
.fcar { position: relative; max-width: 1320px; margin: 0 auto; padding: 0 28px; }
.fcar-viewport { position: relative; overflow: hidden; }
.fcar-track {
    display: flex; gap: 32px;
    transition: transform .75s var(--ease);
    will-change: transform;
}
.fcar-slide { flex: 0 0 calc((100% - 32px) / 2); min-width: 0; }
@media (max-width: 991px) { .fcar-slide { flex: 0 0 100%; } }

.lx-feature { background: var(--white); border: 1px solid var(--line); border-radius: var(--r); overflow: hidden; display: flex; flex-direction: column; height: 100%; }
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

.fcar-nav {
    position: absolute; top: 50%; transform: translateY(-50%); z-index: 20;
    width: 46px; height: 46px; border: 1px solid var(--line); border-radius: var(--r);
    background: rgba(255,255,255,.96); color: var(--ink); font-size: 14px;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: border-color .25s var(--ease), background .25s var(--ease), opacity .25s var(--ease);
}
.fcar-nav:hover { border-color: var(--ink); background: #fff; }
.fcar-prev { left: 12px; }
.fcar-next { right: 12px; }

.fcar-dots { display: flex; gap: 8px; justify-content: center; margin-top: 30px; }
.fcar-dot {
    width: 26px; height: 2px; padding: 0; border: none; cursor: pointer;
    background: var(--line); transition: background .3s var(--ease);
}
.fcar-dot.on { background: var(--ink); }

.offer-tag {
    display: inline-block; font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    background: var(--ink); color: #fff;
    padding: 3px 9px; border-radius: var(--r); vertical-align: 2px; margin-right: 7px;
}
@media (prefers-reduced-motion: reduce) {
    .rv { opacity: 1; transform: none; transition: none; }
    .fcar-track { transition: none; }
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
    .lx-feature-body { padding: 26px 24px 30px; }
    .lx-feature-specs > div { padding: 0 16px; }
}
</style>

<!-- ── Home page interactions: 3D carousel, scroll reveal, stat count-up ── -->
<script>
(function () {
    'use strict';
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ── Featured carousel — sliding track, auto-playing ──────── */
    (function () {
        var vp    = document.getElementById('fcarViewport');
        var track = document.getElementById('fcarTrack');
        if (!vp || !track) return;
        var slides = [].slice.call(track.querySelectorAll('.fcar-slide'));
        var n = slides.length;
        if (!n) return;
        var dotsWrap = document.getElementById('fcarDots');
        var idx = 0, timer = null, dots = [];

        function perView() { return window.innerWidth >= 992 ? 2 : 1; }
        function maxIdx()  { return Math.max(0, n - perView()); }

        function buildDots() {
            if (!dotsWrap) return;
            dotsWrap.innerHTML = '';
            dots = [];
            if (maxIdx() === 0) return;
            for (var i = 0; i <= maxIdx(); i++) {
                (function (i) {
                    var b = document.createElement('button');
                    b.className = 'fcar-dot';
                    b.setAttribute('aria-label', 'Go to slide ' + (i + 1));
                    b.addEventListener('click', function () { go(i); });
                    dotsWrap.appendChild(b);
                    dots.push(b);
                }(i));
            }
        }

        function layout() {
            if (idx > maxIdx()) idx = maxIdx();
            track.style.transform = 'translateX(-' + slides[idx].offsetLeft + 'px)';
            dots.forEach(function (d, i) { d.classList.toggle('on', i === idx); });
        }
        function go(i)  { idx = Math.max(0, Math.min(i, maxIdx())); layout(); restart(); }
        function next() { go(idx >= maxIdx() ? 0 : idx + 1); }   // wrap back to start
        function prev() { go(idx <= 0 ? maxIdx() : idx - 1); }

        var bPrev = document.getElementById('fcarPrev');
        var bNext = document.getElementById('fcarNext');
        if (bPrev) bPrev.addEventListener('click', prev);
        if (bNext) bNext.addEventListener('click', next);

        function restart() {
            if (timer) clearInterval(timer);
            timer = null;
            if (!reduced && maxIdx() > 0) timer = setInterval(next, 5000);
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

        var pv = perView();
        window.addEventListener('resize', function () {
            if (perView() !== pv) { pv = perView(); buildDots(); }
            layout();
        });

        buildDots();
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
