<?php
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Inline migrations — silent no-op if columns already exist
try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/showroom/'); exit; }

$stmt = $db->prepare("
    SELECT c.*, IFNULL(pl.name, l.name) AS location_name
    FROM cars c
    LEFT JOIN locations l  ON l.id  = c.location_id
    LEFT JOIN locations pl ON pl.id = l.parent_id
    WHERE c.id = ? AND c.car_type = 'inventory' AND c.show_on_website = 1
      AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))
");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$car) { header('Location: ' . BASE_URL . '/showroom/'); exit; }

// Images
$images = $db->prepare("SELECT * FROM car_images WHERE car_id=? ORDER BY is_primary DESC, id ASC");
$images->execute([$id]);
$images = $images->fetchAll(PDO::FETCH_ASSOC);
$primaryImg = $images ? thumbUrl('cars', $images[0]['file_path']) : null;

// Similar vehicles (same make or body type, excluding this car)
$similar = $db->prepare("
    SELECT c.id, c.make, c.model, c.year, c.asking_price, c.offer_price, c.body_type, c.transmission, c.fuel_type,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image
    FROM cars c
    WHERE c.car_type='inventory' AND c.show_on_website = 1 AND c.id != ?
      AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))
      AND (c.make = ? OR c.body_type = ?)
    ORDER BY c.featured DESC, c.created_at DESC LIMIT 3
");
$similar->execute([$id, $car['make'], $car['body_type']]);
$similar = $similar->fetchAll(PDO::FETCH_ASSOC);

$companyName   = getSetting('company_name',    'Mascardi Car Yard');
$companyPhone  = getSetting('company_phone',   '');
$whatsappPhone = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', $companyPhone));
$isReserved = ($car['status'] ?? '') === 'reserved';
$hasOffer = !empty($car['offer_price']) && $car['offer_price'] > 0;
$hasPrice = !empty($car['asking_price']) && $car['asking_price'] > 0;
$displayPrice = $hasOffer ? $car['offer_price'] : ($hasPrice ? $car['asking_price'] : null);
$priceStr = $displayPrice ? 'KES ' . number_format((float)$displayPrice) : 'Contact for Price';
$saveAmt  = ($hasOffer && $hasPrice && $car['asking_price'] > $car['offer_price'])
          ? (float)$car['asking_price'] - (float)$car['offer_price'] : 0;

$carTitle = $car['year'] . ' ' . $car['make'] . ' ' . $car['model'];
$waMsg    = urlencode("Hi, I'm interested in the {$carTitle}" . ($displayPrice ? " priced at {$priceStr}" : '') . ". Could you share more details? " . BASE_URL . "/showroom/view.php?id={$id}");

$pageTitle = $carTitle;
$metaDesc  = "Buy this {$carTitle} at {$companyName}." . ($displayPrice ? " {$priceStr}." : '') . " Finance available.";
if ($primaryImg) $ogImage = $primaryImg;

include __DIR__ . '/header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     TOP: back link + gallery (left) + summary panel (right)
══════════════════════════════════════════════════════════════ -->
<div style="background:var(--white)">
    <div class="lx-wrap" style="padding-top:28px;padding-bottom:72px">

        <a href="<?= BASE_URL ?>/showroom/vehicles.php" class="dv-back">
            <i class="fa fa-arrow-left"></i> Back to vehicles
        </a>

        <div class="dv-layout">

            <!-- ── LEFT: Gallery ─────────────────────────────── -->
            <div>
                <?php if ($images): ?>
                <div class="dv-gallery">
                    <div class="dv-main" id="vgMain" onclick="openLightbox(currentIdx)">
                        <img id="vgMainImg"
                             src="<?= htmlspecialchars($primaryImg) ?>"
                             alt="<?= htmlspecialchars($carTitle) ?>"
                             fetchpriority="high" decoding="async">
                        <?php if ($isReserved): ?>
                        <span class="dv-chip dv-chip-dark">Reserved</span>
                        <?php elseif ($car['featured']): ?>
                        <span class="dv-chip">Available Today</span>
                        <?php else: ?>
                        <span class="dv-chip">Available</span>
                        <?php endif; ?>
                        <?php if (count($images) > 1): ?>
                        <button class="dv-arrow dv-arrow-l" onclick="event.stopPropagation();changePhoto(-1)" aria-label="Previous photo"><i class="fa fa-chevron-left"></i></button>
                        <button class="dv-arrow dv-arrow-r" onclick="event.stopPropagation();changePhoto(1)" aria-label="Next photo"><i class="fa fa-chevron-right"></i></button>
                        <div class="dv-counter" id="vgCounter">1 / <?= count($images) ?></div>
                        <?php endif; ?>
                        <div class="dv-zoom"><i class="fa fa-expand"></i></div>
                    </div>
                    <?php if (count($images) > 1): ?>
                    <div class="dv-thumbs" id="vgThumbs">
                        <?php foreach ($images as $i => $img): ?>
                        <button class="dv-thumb <?= $i === 0 ? 'active' : '' ?>"
                                onclick="selectPhoto(<?= $i ?>)"
                                data-src="<?= BASE_URL . '/uploads/cars/' . htmlspecialchars($img['file_path']) ?>">
                            <img src="<?= thumbUrl('cars', $img['file_path']) ?>" alt="Photo <?= $i+1 ?>" loading="lazy" decoding="async">
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="dv-noimg">
                    <i class="fa fa-car-side"></i>
                    <div>No photos available yet</div>
                    <p>Contact us for a viewing appointment</p>
                </div>
                <?php endif; ?>

                <!-- Icon spec strip (Lucid: Range / Power / 0-60 / Drive) -->
                <div class="dv-stats">
                    <div>
                        <i class="fa fa-road"></i>
                        <div class="v"><?= $car['mileage'] ? number_format($car['mileage']) : '—' ?></div>
                        <div class="l"><?= $car['mileage'] ? 'km · Mileage' : 'Mileage' ?></div>
                    </div>
                    <div>
                        <i class="fa fa-bolt"></i>
                        <div class="v"><?= $car['engine_cc'] ? number_format($car['engine_cc']) : '—' ?></div>
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

                <!-- Accordions (Lucid: Included Options / Warranty / Delivery) -->
                <div class="dv-accordions">

                    <details class="dv-acc" open>
                        <summary>Vehicle Specifications <i class="fa fa-plus"></i></summary>
                        <div class="dv-acc-body">
                            <?php
                            $specRows = [
                                ['Make',          $car['make']],
                                ['Model',         $car['model']],
                                ['Year',          $car['year']],
                                ['Body Type',     $car['body_type']],
                                ['Exterior Colour', $car['color']],
                                ['Fuel Type',     $car['fuel_type'] ? ucfirst($car['fuel_type']) : null],
                                ['Transmission',  $car['transmission'] ? ucfirst($car['transmission']) : null],
                                ['Engine',        $car['engine_cc'] ? number_format($car['engine_cc']) . ' cc' : null],
                                ['Mileage',       $car['mileage'] ? number_format($car['mileage']) . ' km' : null],
                                ['Reg. Number',   $car['registration_number']],
                                ['Location',      $car['location_name'] ?? null],
                            ];
                            foreach ($specRows as [$lbl, $val]):
                                if (!$val) continue;
                            ?>
                            <div class="dv-spec-row">
                                <span><?= $lbl ?></span>
                                <strong><?= htmlspecialchars((string)$val) ?></strong>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>

                    <?php if (!empty($car['notes'])): ?>
                    <details class="dv-acc">
                        <summary>About This Vehicle <i class="fa fa-plus"></i></summary>
                        <div class="dv-acc-body">
                            <p class="dv-notes"><?= nl2br(htmlspecialchars($car['notes'])) ?></p>
                        </div>
                    </details>
                    <?php endif; ?>

                    <details class="dv-acc">
                        <summary>Purchase &amp; Ownership <i class="fa fa-plus"></i></summary>
                        <div class="dv-acc-body">
                            <?php foreach ([
                                ['fa-shield-halved', 'Inspected & Verified', 'This vehicle has passed our multi-point inspection. What you see is exactly what you get.'],
                                ['fa-credit-card',   'Flexible Financing',   'We work with leading financiers to offer payment plans tailored to your budget.'],
                                ['fa-rotate',        'Trade-In Welcome',     'Get a fair market value assessment on your current vehicle and offset the price.'],
                                ['fa-truck',         'Nationwide Delivery',  'We arrange safe delivery of your vehicle to any location across the country.'],
                            ] as [$ico, $t, $d]): ?>
                            <div class="dv-own-row">
                                <i class="fa <?= $ico ?>"></i>
                                <div>
                                    <div class="t"><?= $t ?></div>
                                    <p><?= $d ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>

                </div>
            </div>

            <!-- ── RIGHT: Sticky summary / purchase panel ────── -->
            <div>
                <div class="dv-panel">

                    <?php if ($isReserved): ?>
                    <div class="dv-avail"><span class="dot dot-dark"></span>Reserved</div>
                    <?php else: ?>
                    <div class="dv-avail"><span class="dot"></span><?= $car['featured'] ? 'Available Today' : 'Available' ?></div>
                    <?php endif; ?>

                    <h1 class="dv-title"><?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?></h1>
                    <div class="dv-sub">
                        <?= $car['year'] ?><?= $car['color'] ? ' · ' . htmlspecialchars($car['color']) : '' ?><?= $car['body_type'] ? ' · ' . htmlspecialchars($car['body_type']) : '' ?>
                    </div>

                    <div class="dv-price">
                        <?php if ($isReserved): ?>
                            <div class="amt" style="font-size:24px;font-weight:400;color:var(--ink-2)">Reserved</div>
                            <?php if ($hasPrice): ?><div class="note">Listed at KES <?= number_format((float)$car['asking_price']) ?> — contact us to join the waitlist</div><?php endif; ?>
                        <?php elseif ($displayPrice): ?>
                            <div class="amt"><?= $priceStr ?></div>
                            <?php if ($saveAmt > 0): ?>
                            <div class="note"><del>KES <?= number_format((float)$car['asking_price']) ?></del> &nbsp;·&nbsp; Save KES <?= number_format($saveAmt) ?></div>
                            <?php else: ?>
                            <div class="note">Financing available · Trade-in welcome</div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="amt" style="font-size:24px">Price on request</div>
                            <div class="note">Send an enquiry or WhatsApp us</div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($car['location_name'])): ?>
                    <div class="dv-loc"><i class="fa fa-location-dot"></i>Available at <?= htmlspecialchars($car['location_name']) ?></div>
                    <?php endif; ?>

                    <div class="dv-ctas">
                        <?php if ($whatsappPhone): ?>
                        <a href="https://wa.me/<?= $whatsappPhone ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener" class="btn-lx" style="width:100%">
                            <i class="fa-brands fa-whatsapp"></i> <?= $isReserved ? 'Join Waitlist' : 'Enquire on WhatsApp' ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($companyPhone): ?>
                        <a href="tel:<?= htmlspecialchars($companyPhone) ?>" class="btn-lx-ghost-dark" style="width:100%">
                            <i class="fa fa-phone"></i> <?= htmlspecialchars($companyPhone) ?>
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Enquiry form -->
                    <div class="dv-form">
                        <div class="lx-label" style="color:var(--ink);margin-bottom:16px">Send an Enquiry</div>

                        <div id="inquirySuccess" style="display:none;border:1px solid var(--line);border-radius:var(--r);padding:20px;text-align:center;margin-bottom:16px">
                            <i class="fa fa-circle-check" style="font-size:24px;color:var(--ink);display:block;margin-bottom:10px"></i>
                            <div style="font-weight:600;font-size:14px">Message Sent</div>
                            <div style="font-size:13px;color:var(--ink-2);margin-top:4px">We'll get back to you shortly.</div>
                        </div>

                        <form id="inquiryForm" novalidate>
                            <input type="hidden" name="car_id" value="<?= $id ?>">
                            <div class="dv-field">
                                <label class="lx-flabel">Full Name *</label>
                                <input type="text" name="name" placeholder="e.g. John Kamau" required class="lx-input">
                            </div>
                            <div class="dv-field">
                                <label class="lx-flabel">Phone Number</label>
                                <input type="tel" name="phone" placeholder="+254 7XX XXX XXX" class="lx-input">
                            </div>
                            <div class="dv-field">
                                <label class="lx-flabel">Email Address</label>
                                <input type="email" name="email" placeholder="you@example.com" class="lx-input">
                            </div>
                            <div class="dv-field">
                                <label class="lx-flabel">Message</label>
                                <textarea name="message" rows="3" class="lx-input" style="resize:vertical"><?= "I'm interested in the {$carTitle}. Please contact me with more details." ?></textarea>
                            </div>
                            <button type="submit" id="inquiryBtn" class="btn-lx" style="width:100%">
                                <span id="inquiryBtnText">Send Enquiry</span>
                                <span id="inquiryBtnLoading" style="display:none"><i class="fa fa-spinner fa-spin me-2"></i>Sending…</span>
                            </button>
                            <div id="inquiryError" style="display:none;border:1px solid var(--line);border-left:3px solid var(--ink);border-radius:var(--r);padding:12px;margin-top:10px;font-size:13px;color:var(--ink)"></div>
                        </form>
                    </div>

                    <!-- Share -->
                    <div class="dv-share">
                        <span class="lx-label">Share</span>
                        <div style="display:flex;gap:8px">
                            <?php if ($whatsappPhone): ?>
                            <a href="https://wa.me/?text=<?= urlencode("Check out this {$carTitle} at {$companyName} " . BASE_URL . "/showroom/view.php?id={$id}") ?>"
                               target="_blank" rel="noopener" class="dv-share-btn" title="Share on WhatsApp">
                                <i class="fa-brands fa-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                            <button onclick="copyLink()" class="dv-share-btn" title="Copy link" id="copyBtn">
                                <i class="fa fa-link"></i>
                            </button>
                            <a href="mailto:?subject=<?= urlencode("Check out this {$carTitle}") ?>&body=<?= urlencode("I found this vehicle at {$companyName}: " . BASE_URL . "/showroom/view.php?id={$id}") ?>"
                               class="dv-share-btn" title="Share by Email">
                                <i class="fa fa-envelope"></i>
                            </a>
                        </div>
                    </div>

                </div>
            </div>

        </div><!-- /dv-layout -->

        <!-- ── Similar Vehicles ──────────────────────────────── -->
        <?php if ($similar): ?>
        <div class="dv-similar">
            <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:14px;margin-bottom:36px">
                <div>
                    <div class="lx-label" style="margin-bottom:12px">You may also like</div>
                    <h2 class="lx-h2" style="font-size:clamp(24px,3vw,34px)">Similar vehicles</h2>
                </div>
                <a href="<?= BASE_URL ?>/showroom/vehicles.php" style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:600;color:var(--ink)">View All <i class="fa fa-arrow-right" style="font-size:10px"></i></a>
            </div>
            <div class="dv-sim-grid">
                <?php foreach ($similar as $sv):
                    $svImg = $sv['primary_image'] ? thumbUrl('cars', $sv['primary_image']) : null;
                    $svOffer = !empty($sv['offer_price']) && $sv['offer_price'] > 0;
                    $svPrice = $svOffer ? (float)$sv['offer_price'] : (float)($sv['asking_price'] ?? 0);
                ?>
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $sv['id'] ?>" class="dv-sim-card">
                    <div class="img">
                        <?php if ($svImg): ?>
                        <img src="<?= htmlspecialchars($svImg) ?>" alt="<?= htmlspecialchars($sv['make'].' '.$sv['model']) ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="lx-noimg"><i class="fa fa-car-side"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="body">
                        <div class="meta"><?= $sv['year'] ?><?= $sv['body_type'] ? ' · '.htmlspecialchars($sv['body_type']) : '' ?></div>
                        <div class="name"><?= htmlspecialchars($sv['make'].' '.$sv['model']) ?></div>
                        <div class="price">
                            <?php if ($svPrice > 0): ?>
                            KES <?= number_format($svPrice) ?>
                            <?php if ($svOffer && !empty($sv['asking_price'])): ?><del>KES <?= number_format((float)$sv['asking_price']) ?></del><?php endif; ?>
                            <?php else: ?>
                            <span style="color:var(--ink-2);font-weight:400">Price on request</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     LIGHTBOX
══════════════════════════════════════════════════════════════ -->
<?php if ($images): ?>
<div id="lightbox" onclick="closeLightbox()" style="
    display:none;position:fixed;inset:0;background:rgba(4,4,4,.96);
    z-index:99999;align-items:center;justify-content:center;
    flex-direction:column;gap:16px;padding:20px;
">
    <button onclick="event.stopPropagation();closeLightbox()" style="position:absolute;top:20px;right:20px;background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:44px;height:44px;border-radius:2px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center">
        <i class="fa fa-xmark"></i>
    </button>
    <button onclick="event.stopPropagation();changePhoto(-1,true)" style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:2px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
        <i class="fa fa-chevron-left"></i>
    </button>
    <button onclick="event.stopPropagation();changePhoto(1,true)" style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:none;border:1px solid rgba(255,255,255,.3);color:#fff;width:48px;height:48px;border-radius:2px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
        <i class="fa fa-chevron-right"></i>
    </button>
    <img id="lightboxImg" src="" alt="" onclick="event.stopPropagation()"
         style="max-width:90vw;max-height:80vh;object-fit:contain">
    <div id="lightboxCounter" style="color:rgba(255,255,255,.55);font-size:12px;font-weight:600;letter-spacing:.14em"></div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════════════════ -->
<style>
.dv-back {
    display: inline-flex; align-items: center; gap: 9px;
    font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    color: var(--ink-2); margin-bottom: 26px; transition: color .25s var(--ease);
}
.dv-back:hover { color: var(--ink); }
.dv-back i { font-size: 11px; }

.dv-layout { display: grid; grid-template-columns: 1fr 420px; gap: 44px; align-items: start; }
@media (max-width: 1080px) { .dv-layout { grid-template-columns: 1fr; } }

/* ── Gallery ──────────────────────────────────────────────── */
.dv-gallery { margin-bottom: 0; }
.dv-main {
    position: relative; aspect-ratio: 16/10; overflow: hidden; cursor: zoom-in;
    background: linear-gradient(180deg, #f7f7f5 0%, #ececea 100%);
    border: 1px solid var(--line); border-radius: var(--r);
}
.dv-main img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dv-chip {
    position: absolute; top: 18px; left: 18px; z-index: 2;
    background: var(--white); color: var(--ink); border: 1px solid var(--line);
    font-size: 9.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 6px 14px; border-radius: 20px;
}
.dv-chip-dark { background: var(--ink); color: #fff; border-color: var(--ink); }
.dv-arrow {
    position: absolute; top: 50%; transform: translateY(-50%); z-index: 2;
    width: 44px; height: 44px; border-radius: var(--r);
    background: rgba(255,255,255,.92); border: 1px solid var(--line);
    color: var(--ink); font-size: 14px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .25s var(--ease);
}
.dv-arrow:hover { background: #fff; }
.dv-arrow-l { left: 16px; }
.dv-arrow-r { right: 16px; }
.dv-counter {
    position: absolute; bottom: 16px; right: 18px; z-index: 2;
    background: rgba(10,10,10,.6); color: #fff;
    font-size: 11px; font-weight: 500; letter-spacing: .08em;
    padding: 4px 12px; border-radius: 20px;
}
.dv-zoom {
    position: absolute; bottom: 16px; left: 18px; z-index: 2;
    color: var(--ink-2); background: rgba(255,255,255,.85);
    width: 34px; height: 34px; border-radius: var(--r);
    display: flex; align-items: center; justify-content: center; font-size: 13px;
    opacity: 0; transition: opacity .25s var(--ease);
}
.dv-main:hover .dv-zoom { opacity: 1; }
.dv-thumbs { display: flex; gap: 8px; padding-top: 10px; overflow-x: auto; scrollbar-width: thin; }
.dv-thumb {
    flex-shrink: 0; width: 92px; height: 60px; border: 1px solid var(--line); border-radius: var(--r);
    overflow: hidden; cursor: pointer; padding: 0; background: none;
    opacity: .55; transition: opacity .25s var(--ease), border-color .25s var(--ease);
}
.dv-thumb.active { border-color: var(--ink); opacity: 1; }
.dv-thumb:hover { opacity: 1; }
.dv-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.dv-noimg {
    aspect-ratio: 16/10; border: 1px solid var(--line); border-radius: var(--r);
    background: var(--paper);
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    color: var(--line); font-size: 56px; gap: 12px;
}
.dv-noimg div { font-size: 15px; color: var(--ink-2); font-weight: 500; }
.dv-noimg p   { font-size: 13px; color: var(--ink-3); margin: 0; }

/* ── Icon stat strip ──────────────────────────────────────── */
.dv-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    border: 1px solid var(--line); border-radius: var(--r);
    margin-top: 28px; background: var(--white);
}
.dv-stats > div { text-align: center; padding: 26px 12px; border-right: 1px solid var(--line); }
.dv-stats > div:last-child { border-right: none; }
.dv-stats i { font-size: 17px; color: var(--ink); display: block; margin-bottom: 12px; }
.dv-stats .v { font-size: 21px; font-weight: 300; letter-spacing: -.01em; color: var(--ink); }
.dv-stats .l { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .14em; color: var(--ink-3); margin-top: 4px; }
@media (max-width: 640px) {
    .dv-stats { grid-template-columns: 1fr 1fr; }
    .dv-stats > div:nth-child(2) { border-right: none; }
    .dv-stats > div:nth-child(-n+2) { border-bottom: 1px solid var(--line); }
}

/* ── Accordions ───────────────────────────────────────────── */
.dv-accordions { margin-top: 28px; border-top: 1px solid var(--line); }
.dv-acc { border-bottom: 1px solid var(--line); }
.dv-acc summary {
    list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between;
    padding: 22px 4px; font-size: 15px; font-weight: 500; color: var(--ink);
    letter-spacing: -.01em; transition: color .25s var(--ease);
}
.dv-acc summary::-webkit-details-marker { display: none; }
.dv-acc summary:hover { color: var(--ink-2); }
.dv-acc summary i { font-size: 12px; color: var(--ink-3); transition: transform .3s var(--ease); }
.dv-acc[open] summary i { transform: rotate(45deg); }
.dv-acc-body { padding: 2px 4px 26px; }

.dv-spec-row {
    display: flex; align-items: baseline; justify-content: space-between; gap: 20px;
    padding: 11px 0; border-bottom: 1px solid var(--paper); font-size: 13.5px;
}
.dv-spec-row:last-child { border-bottom: none; }
.dv-spec-row span { color: var(--ink-3); }
.dv-spec-row strong { color: var(--ink); font-weight: 500; text-align: right; }

.dv-notes { color: var(--ink-2); line-height: 1.8; font-size: 14px; margin: 0; }

.dv-own-row { display: flex; gap: 18px; padding: 14px 0; }
.dv-own-row i { font-size: 16px; color: var(--ink); flex-shrink: 0; margin-top: 3px; width: 20px; text-align: center; }
.dv-own-row .t { font-size: 14px; font-weight: 500; color: var(--ink); margin-bottom: 4px; }
.dv-own-row p { font-size: 13px; color: var(--ink-2); line-height: 1.65; margin: 0; }

/* ── Summary panel ────────────────────────────────────────── */
.dv-panel {
    position: sticky; top: calc(var(--nav-h) + 24px);
    border: 1px solid var(--line); border-radius: var(--r);
    padding: 32px 30px; background: var(--white);
}
@media (max-width: 1080px) { .dv-panel { position: static; } }
.dv-avail { display: flex; align-items: center; gap: 9px; font-size: 10.5px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: var(--ink-2); margin-bottom: 16px; }
.dv-avail .dot { width: 7px; height: 7px; border-radius: 50%; background: #2e7d32; }
.dv-avail .dot-dark { background: var(--ink); }
.dv-title { font-size: 30px; font-weight: 400; letter-spacing: -.01em; color: var(--ink); line-height: 1.15; margin: 0 0 8px; }
.dv-sub { font-size: 13px; color: var(--ink-3); margin-bottom: 24px; }
.dv-price { padding: 20px 0; border-top: 1px solid var(--line); }
.dv-price .amt { font-size: 30px; font-weight: 600; letter-spacing: -.01em; color: var(--ink); }
.dv-price .note { font-size: 12.5px; color: var(--ink-3); margin-top: 6px; }
.dv-price .note del { color: var(--ink-3); }
.dv-loc { display: flex; align-items: center; gap: 9px; font-size: 13px; color: var(--ink-2); padding-bottom: 20px; }
.dv-loc i { font-size: 12px; color: var(--ink-3); }
.dv-ctas { display: flex; flex-direction: column; gap: 10px; padding-bottom: 26px; border-bottom: 1px solid var(--line); }
.dv-form { padding-top: 24px; }
.dv-field { margin-bottom: 14px; }
.dv-share { display: flex; align-items: center; justify-content: space-between; margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--line); }
.dv-share-btn {
    width: 38px; height: 38px; border: 1px solid var(--line); border-radius: var(--r);
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--ink-2); font-size: 14px; background: none; cursor: pointer;
    transition: all .25s var(--ease);
}
.dv-share-btn:hover { border-color: var(--ink); color: var(--ink); }

/* ── Similar vehicles ─────────────────────────────────────── */
.dv-similar { margin-top: 88px; padding-top: 56px; border-top: 1px solid var(--line); }
.dv-sim-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 28px; }
@media (max-width: 991px) { .dv-sim-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 640px) { .dv-sim-grid { grid-template-columns: 1fr; } }
.dv-sim-card {
    display: flex; flex-direction: column; border: 1px solid var(--line); border-radius: var(--r);
    overflow: hidden; background: var(--white);
    transition: box-shadow .35s var(--ease), transform .35s var(--ease);
}
.dv-sim-card:hover { box-shadow: 0 22px 48px rgba(0,0,0,.09); transform: translateY(-3px); }
.dv-sim-card .img { aspect-ratio: 16/10; overflow: hidden; background: var(--paper); }
.dv-sim-card .img img { width: 100%; height: 100%; object-fit: cover; transition: transform .8s var(--ease); }
.dv-sim-card:hover .img img { transform: scale(1.04); }
.dv-sim-card .body { padding: 20px 22px 22px; }
.dv-sim-card .meta { font-size: 10.5px; color: var(--ink-3); font-weight: 600; text-transform: uppercase; letter-spacing: .16em; margin-bottom: 5px; }
.dv-sim-card .name { font-size: 17px; font-weight: 500; color: var(--ink); margin-bottom: 8px; letter-spacing: -.01em; }
.dv-sim-card .price { font-size: 15px; font-weight: 600; color: var(--ink); }
.dv-sim-card .price del { font-size: 12px; color: var(--ink-3); font-weight: 400; margin-left: 7px; }
</style>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════ -->
<script>
var photos = <?= json_encode(array_map(fn($img) => BASE_URL . '/uploads/cars/' . $img['file_path'], $images ?: [])) ?>;
var currentIdx = 0;

function selectPhoto(idx) {
    if (idx < 0) idx = photos.length - 1;
    if (idx >= photos.length) idx = 0;
    currentIdx = idx;
    var mainImg = document.getElementById('vgMainImg');
    if (mainImg) {
        mainImg.style.opacity = '0';
        setTimeout(function () {
            mainImg.src = photos[idx];
            mainImg.style.opacity = '1';
        }, 120);
    }
    var counter = document.getElementById('vgCounter');
    if (counter) counter.textContent = (idx + 1) + ' / ' + photos.length;
    document.querySelectorAll('.dv-thumb').forEach(function (t, i) {
        t.classList.toggle('active', i === idx);
    });
}

function changePhoto(dir, inLightbox) {
    var newIdx = currentIdx + dir;
    if (newIdx < 0) newIdx = photos.length - 1;
    if (newIdx >= photos.length) newIdx = 0;
    selectPhoto(newIdx);
    if (inLightbox) updateLightbox(newIdx);
}

// Smooth fade on main image
document.getElementById('vgMainImg') && (document.getElementById('vgMainImg').style.transition = 'opacity .12s ease');

// Lightbox
function openLightbox(idx) {
    var lb = document.getElementById('lightbox');
    if (!lb || !photos.length) return;
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    updateLightbox(idx);
}
function updateLightbox(idx) {
    document.getElementById('lightboxImg').src = photos[idx];
    var ctr = document.getElementById('lightboxCounter');
    if (ctr) ctr.textContent = (idx + 1) + ' / ' + photos.length;
}
function closeLightbox() {
    var lb = document.getElementById('lightbox');
    if (lb) lb.style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
    var lb = document.getElementById('lightbox');
    if (!lb || lb.style.display === 'none') return;
    if (e.key === 'ArrowLeft')  changePhoto(-1, true);
    if (e.key === 'ArrowRight') changePhoto(1,  true);
    if (e.key === 'Escape')     closeLightbox();
});

// Swipe support
(function () {
    var main = document.getElementById('vgMain');
    if (!main) return;
    var startX = 0;
    main.addEventListener('touchstart', function (e) { startX = e.changedTouches[0].screenX; }, { passive: true });
    main.addEventListener('touchend', function (e) {
        var dx = e.changedTouches[0].screenX - startX;
        if (Math.abs(dx) > 40) changePhoto(dx < 0 ? 1 : -1);
    });
}());

// Share — copy link
function copyLink() {
    navigator.clipboard.writeText(window.location.href).then(function () {
        var btn = document.getElementById('copyBtn');
        if (!btn) return;
        btn.innerHTML = '<i class="fa fa-check"></i>';
        setTimeout(function () {
            btn.innerHTML = '<i class="fa fa-link"></i>';
        }, 2000);
    });
}

// Inquiry form
document.getElementById('inquiryForm') && document.getElementById('inquiryForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var form = this;
    var btn  = document.getElementById('inquiryBtn');
    if (!form.querySelector('[name="name"]').value.trim()) {
        form.querySelector('[name="name"]').focus();
        return;
    }
    document.getElementById('inquiryBtnText').style.display    = 'none';
    document.getElementById('inquiryBtnLoading').style.display = '';
    document.getElementById('inquiryError').style.display      = 'none';
    btn.disabled = true;

    fetch('<?= BASE_URL ?>/showroom/inquiry.php', { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res.success) {
                form.style.display = 'none';
                document.getElementById('inquirySuccess').style.display = '';
                document.getElementById('inquirySuccess').scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                document.getElementById('inquiryError').textContent = res.error || 'Something went wrong. Please try again.';
                document.getElementById('inquiryError').style.display = '';
            }
        })
        .catch(function () {
            document.getElementById('inquiryError').textContent = 'Network error. Please try again.';
            document.getElementById('inquiryError').style.display = '';
        })
        .finally(function () {
            document.getElementById('inquiryBtnText').style.display    = '';
            document.getElementById('inquiryBtnLoading').style.display = 'none';
            btn.disabled = false;
        });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
