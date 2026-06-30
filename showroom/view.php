<?php
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// Inline migrations — silent no-op if columns already exist
try { $db->exec("ALTER TABLE cars ADD COLUMN offer_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN show_on_website TINYINT(1) NOT NULL DEFAULT 1"); } catch (\Throwable $_) {}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/showroom/'); exit; }

$stmt = $db->prepare("
    SELECT c.*, l.name AS location_name
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE c.id = ? AND c.car_type = 'inventory' AND c.show_on_website = 1
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
      AND (c.make = ? OR c.body_type = ?)
    ORDER BY c.featured DESC, c.created_at DESC LIMIT 3
");
$similar->execute([$id, $car['make'], $car['body_type']]);
$similar = $similar->fetchAll(PDO::FETCH_ASSOC);

$companyName   = getSetting('company_name',    'Mascardi Car Yard');
$companyPhone  = getSetting('company_phone',   '');
$whatsappPhone = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', $companyPhone));
$hasOffer = !empty($car['offer_price']) && $car['offer_price'] > 0;
$hasPrice = !empty($car['asking_price']) && $car['asking_price'] > 0;
$displayPrice = $hasOffer ? $car['offer_price'] : ($hasPrice ? $car['asking_price'] : null);
$priceStr = $displayPrice ? 'KES ' . number_format((float)$displayPrice) : 'Contact for Price';

$carTitle = $car['year'] . ' ' . $car['make'] . ' ' . $car['model'];
$waMsg    = urlencode("Hi, I'm interested in the {$carTitle}" . ($displayPrice ? " priced at {$priceStr}" : '') . ". Could you share more details? " . BASE_URL . "/showroom/view.php?id={$id}");

$pageTitle = $carTitle;
$metaDesc  = "Buy this {$carTitle} at {$companyName}." . ($displayPrice ? " {$priceStr}." : '') . " Finance available.";
if ($primaryImg) $ogImage = $primaryImg;

include __DIR__ . '/header.php';
?>

<!-- ══════════════════════════════════════════════════════════
     VEHICLE HERO — full-width image with overlay details
══════════════════════════════════════════════════════════════ -->
<div class="vh-hero" id="hero-top">
    <!-- Background image -->
    <?php if ($primaryImg): ?>
    <div class="vh-bg" style="background-image:url('<?= htmlspecialchars($primaryImg) ?>')"></div>
    <?php else: ?>
    <div class="vh-bg" style="background:linear-gradient(135deg,#0f172a,#1e3a8a)"></div>
    <?php endif; ?>
    <div class="vh-overlay"></div>

    <div class="container-xl vh-content">
        <!-- Breadcrumb -->
        <nav class="vh-breadcrumb">
            <a href="<?= BASE_URL ?>/showroom/">Showroom</a>
            <i class="fa fa-chevron-right"></i>
            <a href="<?= BASE_URL ?>/showroom/?make=<?= urlencode($car['make']) ?>"><?= htmlspecialchars($car['make']) ?></a>
            <i class="fa fa-chevron-right"></i>
            <span><?= htmlspecialchars($car['model']) ?></span>
        </nav>

        <!-- Badges -->
        <div class="vh-badges">
            <?php if ($car['featured']): ?>
            <span class="vh-badge vh-badge-gold"><i class="fa fa-star me-1"></i>Featured</span>
            <?php endif; ?>
            <span class="vh-badge vh-badge-green"><i class="fa fa-circle-check me-1"></i>Available</span>
            <?php if ($car['body_type']): ?>
            <span class="vh-badge vh-badge-dark"><?= htmlspecialchars($car['body_type']) ?></span>
            <?php endif; ?>
        </div>

        <!-- Title -->
        <h1 class="vh-title"><?= htmlspecialchars($carTitle) ?></h1>

        <!-- Price -->
        <div class="vh-price">
            <?php if ($hasOffer): ?>
            <span class="vh-price-label">Sale Price</span>
            <span class="vh-price-value"><?= $priceStr ?></span>
            <?php if ($hasPrice): ?>
            <del class="vh-price-note" style="font-size:16px;opacity:.6">KES <?= number_format((float)$car['asking_price']) ?></del>
            <?php endif; ?>
            <span class="vh-price-note">Finance available</span>
            <?php elseif ($hasPrice): ?>
            <span class="vh-price-label">Asking Price</span>
            <span class="vh-price-value"><?= $priceStr ?></span>
            <span class="vh-price-note">Finance available</span>
            <?php else: ?>
            <span class="vh-price-value" style="font-size:28px">Contact for Price</span>
            <?php endif; ?>
        </div>

        <!-- Quick spec pills -->
        <div class="vh-pills">
            <?php if ($car['mileage']): ?>
            <div class="vh-pill"><i class="fa fa-gauge-high"></i><?= number_format($car['mileage']) ?> km</div>
            <?php endif; ?>
            <?php if ($car['fuel_type']): ?>
            <div class="vh-pill"><i class="fa fa-gas-pump"></i><?= ucfirst($car['fuel_type']) ?></div>
            <?php endif; ?>
            <?php if ($car['transmission']): ?>
            <div class="vh-pill"><i class="fa fa-gears"></i><?= ucfirst($car['transmission']) ?></div>
            <?php endif; ?>
            <?php if ($car['engine_cc']): ?>
            <div class="vh-pill"><i class="fa fa-cog"></i><?= number_format($car['engine_cc']) ?> cc</div>
            <?php endif; ?>
            <?php if ($car['color']): ?>
            <div class="vh-pill"><i class="fa fa-palette"></i><?= htmlspecialchars($car['color']) ?></div>
            <?php endif; ?>
            <?php if ($car['location_name']): ?>
            <div class="vh-pill"><i class="fa fa-location-dot"></i><?= htmlspecialchars($car['location_name']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Scroll hint -->
        <div class="vh-scroll-hint">
            <span>Scroll to explore</span>
            <i class="fa fa-chevron-down"></i>
        </div>
    </div>

    <!-- Photo count -->
    <?php if ($images): ?>
    <div class="vh-photo-count">
        <i class="fa fa-images me-1"></i><?= count($images) ?> Photo<?= count($images) !== 1 ? 's' : '' ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════════════ -->
<div class="container-xl" style="padding-top:48px;padding-bottom:80px">
    <div class="row g-4 align-items-start">

        <!-- ── LEFT COLUMN ───────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Gallery -->
            <?php if ($images): ?>
            <div class="vg-wrap">
                <!-- Main image -->
                <div class="vg-main" id="vgMain" onclick="openLightbox(currentIdx)">
                    <img id="vgMainImg"
                         src="<?= htmlspecialchars($primaryImg) ?>"
                         alt="<?= htmlspecialchars($carTitle) ?>"
                         fetchpriority="high" decoding="async">
                    <?php if ($car['featured']): ?>
                    <div class="vg-featured-badge"><i class="fa fa-star me-1"></i>Featured</div>
                    <?php endif; ?>
                    <div class="vg-zoom-hint"><i class="fa fa-expand me-1"></i>Click to zoom</div>
                    <!-- Arrows -->
                    <?php if (count($images) > 1): ?>
                    <button class="vg-arrow vg-arrow-left"  onclick="event.stopPropagation();changePhoto(-1)"><i class="fa fa-chevron-left"></i></button>
                    <button class="vg-arrow vg-arrow-right" onclick="event.stopPropagation();changePhoto(1)"><i class="fa fa-chevron-right"></i></button>
                    <!-- Counter -->
                    <div class="vg-counter" id="vgCounter">1 / <?= count($images) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Thumbnails -->
                <?php if (count($images) > 1): ?>
                <div class="vg-thumbs" id="vgThumbs">
                    <?php foreach ($images as $i => $img): ?>
                    <button class="vg-thumb <?= $i === 0 ? 'active' : '' ?>"
                            onclick="selectPhoto(<?= $i ?>)"
                            data-src="<?= BASE_URL . '/uploads/cars/' . htmlspecialchars($img['file_path']) ?>">
                        <img src="<?= thumbUrl('cars', $img['file_path']) ?>" alt="Photo <?= $i+1 ?>" loading="lazy" decoding="async">
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="vg-no-img">
                <i class="fa fa-car-side"></i>
                <div>No photos available yet</div>
                <p>Contact us for a viewing appointment</p>
            </div>
            <?php endif; ?>

            <!-- Specification Grid -->
            <div class="vs-section">
                <div class="vs-heading">
                    <i class="fa fa-list-check"></i>
                    <span>Vehicle Specifications</span>
                </div>
                <?php
                $specGroups = [
                    'Identity' => [
                        ['fa-industry',    'Make',         $car['make']],
                        ['fa-car',         'Model',        $car['model']],
                        ['fa-calendar',    'Year',         $car['year']],
                        ['fa-car-side',    'Body Type',    $car['body_type']],
                    ],
                    'Performance' => [
                        ['fa-gas-pump',    'Fuel Type',    ucfirst($car['fuel_type'] ?? '')],
                        ['fa-gears',       'Transmission', ucfirst($car['transmission'] ?? '')],
                        ['fa-cog',         'Engine',       $car['engine_cc'] ? number_format($car['engine_cc']).' cc' : null],
                        ['fa-gauge-high',  'Mileage',      $car['mileage'] ? number_format($car['mileage']).' km' : null],
                    ],
                    'Details' => [
                        ['fa-palette',     'Colour',       $car['color']],
                        ['fa-hashtag',     'Reg. Number',  $car['registration_number']],
                        ['fa-location-dot','Location',     $car['location_name'] ?? null],
                    ],
                ];
                ?>
                <?php foreach ($specGroups as $groupName => $specs):
                    $hasAny = array_filter($specs, fn($s) => !empty($s[2]));
                    if (!$hasAny) continue;
                ?>
                <div class="vs-group-label"><?= $groupName ?></div>
                <div class="vs-grid">
                    <?php foreach ($specs as [$ico, $label, $val]):
                        if (!$val) continue;
                    ?>
                    <div class="vs-item">
                        <div class="vs-item-icon"><i class="fa <?= $ico ?>"></i></div>
                        <div class="vs-item-label"><?= $label ?></div>
                        <div class="vs-item-val"><?= htmlspecialchars((string)$val) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Description -->
            <?php if (!empty($car['notes'])): ?>
            <div class="vd-section">
                <div class="vs-heading">
                    <i class="fa fa-align-left"></i>
                    <span>About This Vehicle</span>
                </div>
                <p class="vd-text"><?= nl2br(htmlspecialchars($car['notes'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- Trust badges -->
            <div class="vt-grid">
                <?php foreach ([
                    ['fa-shield-halved', '#22c55e', 'Inspected',         'Thoroughly checked'],
                    ['fa-credit-card',   '#2563eb', 'Finance Ready',     'Flexible plans'],
                    ['fa-rotate',        '#f59e0b', 'Trade-In Welcome',  'Fair valuation'],
                    ['fa-headset',       '#7c3aed', 'Expert Support',    'We guide you'],
                ] as [$ico, $col, $t, $s]): ?>
                <div class="vt-card">
                    <div class="vt-icon" style="background:<?= $col ?>18;color:<?= $col ?>">
                        <i class="fa <?= $ico ?>"></i>
                    </div>
                    <div class="vt-title"><?= $t ?></div>
                    <div class="vt-sub"><?= $s ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── RIGHT COLUMN (sticky sidebar) ─────────────── -->
        <div class="col-lg-4">
            <div class="vi-sidebar">

                <!-- Price card -->
                <div class="vi-price-card">
                    <div class="vi-price-bg">
                        <?php if ($hasOffer): ?>
                        <div class="vi-price-lbl">
                            <span style="background:#dc2626;color:#fff;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;margin-right:8px">SALE</span>Sale Price
                        </div>
                        <div class="vi-price-amt"><?= $priceStr ?></div>
                        <?php if ($hasPrice): ?>
                        <div class="vi-price-note"><del>KES <?= number_format((float)$car['asking_price']) ?></del> &bull; Finance available</div>
                        <?php else: ?>
                        <div class="vi-price-note">Finance available &bull; Negotiable</div>
                        <?php endif; ?>
                        <?php elseif ($hasPrice): ?>
                        <div class="vi-price-lbl">Asking Price</div>
                        <div class="vi-price-amt"><?= $priceStr ?></div>
                        <div class="vi-price-note">Finance available &bull; Negotiable</div>
                        <?php else: ?>
                        <div class="vi-price-lbl">Price</div>
                        <div class="vi-price-amt" style="font-size:26px">Contact for Price</div>
                        <div class="vi-price-note">Send an enquiry or WhatsApp us</div>
                        <?php endif; ?>
                    </div>
                    <div class="vi-price-badges">
                        <span class="vi-badge-avail"><i class="fa fa-circle-check me-1"></i>Available</span>
                        <?php if ($car['featured']): ?>
                        <span class="vi-badge-feat"><i class="fa fa-star me-1"></i>Featured</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CTA buttons -->
                <div class="vi-ctas">
                    <?php if ($whatsappPhone): ?>
                    <a href="https://wa.me/<?= $whatsappPhone ?>?text=<?= $waMsg ?>"
                       target="_blank" rel="noopener" class="vi-btn-wa">
                        <i class="fa-brands fa-whatsapp"></i>
                        <div>
                            <div style="font-size:14px;font-weight:800">Chat on WhatsApp</div>
                            <div style="font-size:11px;opacity:.8">Instant response</div>
                        </div>
                    </a>
                    <?php endif; ?>
                    <?php if ($companyPhone): ?>
                    <a href="tel:<?= htmlspecialchars($companyPhone) ?>" class="vi-btn-call">
                        <i class="fa fa-phone"></i>
                        <div>
                            <div style="font-size:13px;font-weight:700"><?= htmlspecialchars($companyPhone) ?></div>
                            <div style="font-size:11px;color:#94a3b8">Call us directly</div>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Enquiry form -->
                <div class="vi-form-wrap">
                    <div class="vi-form-heading">
                        <i class="fa fa-paper-plane"></i> Send an Enquiry
                    </div>

                    <div id="inquirySuccess" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center;margin-bottom:16px">
                        <i class="fa fa-circle-check" style="font-size:28px;color:#16a34a;display:block;margin-bottom:8px"></i>
                        <div style="font-weight:800;color:#14532d;font-size:15px">Message Sent!</div>
                        <div style="font-size:13px;color:#166534;margin-top:4px">We'll get back to you shortly.</div>
                    </div>

                    <form id="inquiryForm" novalidate>
                        <input type="hidden" name="car_id" value="<?= $id ?>">

                        <div class="vi-field">
                            <label>Full Name <span>*</span></label>
                            <div class="vi-field-wrap">
                                <i class="fa fa-user"></i>
                                <input type="text" name="name" placeholder="e.g. John Kamau" required>
                            </div>
                        </div>
                        <div class="vi-field">
                            <label>Phone Number</label>
                            <div class="vi-field-wrap">
                                <i class="fa fa-phone"></i>
                                <input type="tel" name="phone" placeholder="+254 7XX XXX XXX">
                            </div>
                        </div>
                        <div class="vi-field">
                            <label>Email Address</label>
                            <div class="vi-field-wrap">
                                <i class="fa fa-envelope"></i>
                                <input type="email" name="email" placeholder="you@example.com">
                            </div>
                        </div>
                        <div class="vi-field">
                            <label>Message</label>
                            <textarea name="message" rows="3" placeholder="I'm interested in this vehicle. When can I view it?"><?= "I'm interested in the {$carTitle}. Please contact me with more details." ?></textarea>
                        </div>

                        <button type="submit" id="inquiryBtn" class="vi-submit">
                            <span id="inquiryBtnText"><i class="fa fa-paper-plane me-2"></i>Send Enquiry</span>
                            <span id="inquiryBtnLoading" style="display:none"><i class="fa fa-spinner fa-spin me-2"></i>Sending…</span>
                        </button>
                        <div id="inquiryError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px;margin-top:10px;font-size:13px;color:#991b1b"></div>
                    </form>
                </div>

                <!-- Share -->
                <div style="text-align:center;padding:16px 0;border-top:1px solid #f1f5f9;margin-top:4px">
                    <div style="font-size:12px;color:#94a3b8;font-weight:600;margin-bottom:10px">SHARE THIS VEHICLE</div>
                    <div style="display:flex;justify-content:center;gap:10px">
                        <?php if ($whatsappPhone): ?>
                        <a href="https://wa.me/?text=<?= urlencode("Check out this {$carTitle} at {$companyName} " . BASE_URL . "/showroom/view.php?id={$id}") ?>"
                           target="_blank" rel="noopener" class="share-btn" style="background:#25d366" title="Share on WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                        <button onclick="copyLink()" class="share-btn" style="background:#2563eb" title="Copy link" id="copyBtn">
                            <i class="fa fa-link"></i>
                        </button>
                        <a href="mailto:?subject=<?= urlencode("Check out this {$carTitle}") ?>&body=<?= urlencode("I found this vehicle at {$companyName}: " . BASE_URL . "/showroom/view.php?id={$id}") ?>"
                           class="share-btn" style="background:#64748b" title="Share by Email">
                            <i class="fa fa-envelope"></i>
                        </a>
                    </div>
                </div>

            </div>
        </div>

    </div><!-- /row -->

    <!-- ── Similar Vehicles ──────────────────────────────── -->
    <?php if ($similar): ?>
    <div class="vsim-section">
        <div class="vs-heading" style="margin-bottom:28px">
            <i class="fa fa-car-side"></i>
            <span>Similar Vehicles</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px">
            <?php foreach ($similar as $sv):
                $svImg = $sv['primary_image'] ? thumbUrl('cars', $sv['primary_image']) : null;
            ?>
            <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $sv['id'] ?>" class="vsim-card">
                <div class="vsim-img">
                    <?php if ($svImg): ?>
                    <img src="<?= htmlspecialchars($svImg) ?>" alt="<?= htmlspecialchars($sv['make'].' '.$sv['model']) ?>" loading="lazy" decoding="async">
                    <?php else: ?>
                    <div style="height:100%;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:40px"><i class="fa fa-car-side"></i></div>
                    <?php endif; ?>
                </div>
                <div class="vsim-body">
                    <div class="vsim-meta"><?= $sv['year'] ?><?= $sv['body_type'] ? ' &bull; '.$sv['body_type'] : '' ?></div>
                    <div class="vsim-name"><?= htmlspecialchars($sv['make'].' '.$sv['model']) ?></div>
                    <div class="vsim-price">
                        <?php if (!empty($sv['offer_price']) && $sv['offer_price'] > 0): ?>
                        <span style="font-size:10px;background:#dc2626;color:#fff;padding:1px 7px;border-radius:10px;vertical-align:middle;margin-right:4px;font-weight:700">SALE</span>
                        KES <?= number_format((float)$sv['offer_price']) ?>
                        <?php if (!empty($sv['asking_price']) && $sv['asking_price'] > 0): ?>
                        <del style="font-size:12px;color:#94a3b8;font-weight:500;margin-left:4px">KES <?= number_format((float)$sv['asking_price']) ?></del>
                        <?php endif; ?>
                        <?php elseif (!empty($sv['asking_price']) && $sv['asking_price'] > 0): ?>
                        KES <?= number_format((float)$sv['asking_price']) ?>
                        <?php else: ?>
                        <span style="color:#94a3b8;font-size:13px">Contact for Price</span>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /container -->

<!-- ══════════════════════════════════════════════════════════
     LIGHTBOX
══════════════════════════════════════════════════════════════ -->
<?php if ($images): ?>
<div id="lightbox" onclick="closeLightbox()" style="
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.95);
    z-index:99999;align-items:center;justify-content:center;
    flex-direction:column;gap:16px;padding:20px;
    -webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);
">
    <button onclick="event.stopPropagation();closeLightbox()" style="position:absolute;top:20px;right:20px;background:rgba(255,255,255,.1);border:none;color:#fff;width:44px;height:44px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
        <i class="fa fa-xmark"></i>
    </button>
    <button onclick="event.stopPropagation();changePhoto(-1,true)" style="position:absolute;left:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.1);border:none;color:#fff;width:50px;height:50px;border-radius:50%;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">
        <i class="fa fa-chevron-left"></i>
    </button>
    <button onclick="event.stopPropagation();changePhoto(1,true)" style="position:absolute;right:20px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.1);border:none;color:#fff;width:50px;height:50px;border-radius:50%;font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s" onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.1)'">
        <i class="fa fa-chevron-right"></i>
    </button>
    <img id="lightboxImg" src="" alt="" onclick="event.stopPropagation()"
         style="max-width:90vw;max-height:80vh;object-fit:contain;border-radius:12px;box-shadow:0 32px 80px rgba(0,0,0,.6)">
    <div id="lightboxCounter" style="color:rgba(255,255,255,.5);font-size:14px;font-weight:600"></div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     STYLES
══════════════════════════════════════════════════════════════ -->
<style>
/* ── Hero ──────────────────────────────────────────────────── */
.vh-hero {
    position: relative;
    min-height: 80vh;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
}
.vh-bg {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    transform: scale(1.04);
    transition: transform 8s ease;
}
.vh-hero:hover .vh-bg { transform: scale(1); }
.vh-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(0,0,0,.15)  0%,
        rgba(0,0,0,.35) 40%,
        rgba(0,0,0,.88) 100%
    );
}
.vh-content {
    position: relative;
    z-index: 2;
    padding-bottom: 52px;
    padding-top: 100px;
    width: 100%;
}
.vh-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    font-size: 13px;
    flex-wrap: wrap;
}
.vh-breadcrumb a { color: rgba(255,255,255,.55); text-decoration: none; transition: color .15s; }
.vh-breadcrumb a:hover { color: #fff; }
.vh-breadcrumb i { font-size: 9px; color: rgba(255,255,255,.3); }
.vh-breadcrumb span { color: rgba(255,255,255,.85); font-weight: 600; }

.vh-badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.vh-badge { font-size: 12px; font-weight: 700; padding: 5px 14px; border-radius: 20px; }
.vh-badge-gold  { background: rgba(245,158,11,.2); border: 1px solid rgba(245,158,11,.4); color: #fcd34d; }
.vh-badge-green { background: rgba(34,197,94,.2);  border: 1px solid rgba(34,197,94,.4);  color: #86efac; }
.vh-badge-dark  { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: rgba(255,255,255,.75); }

.vh-title { font-size: clamp(28px, 5vw, 52px); font-weight: 900; color: #fff; letter-spacing: -1.5px; line-height: 1.1; margin: 0 0 20px; max-width: 700px; }

.vh-price { display: flex; align-items: baseline; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.vh-price-label { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,.45); }
.vh-price-value { font-size: 38px; font-weight: 900; color: #fff; letter-spacing: -1px; }
.vh-price-note { font-size: 13px; color: rgba(255,255,255,.4); }

.vh-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 28px; }
.vh-pill {
    display: flex; align-items: center; gap: 7px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.15);
    backdrop-filter: blur(8px);
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 13px; font-weight: 600; color: rgba(255,255,255,.85);
}
.vh-pill i { font-size: 13px; color: #60a5fa; }

.vh-scroll-hint { display: flex; flex-direction: column; align-items: flex-start; gap: 5px; font-size: 11px; color: rgba(255,255,255,.3); font-weight: 600; letter-spacing: 1px; text-transform: uppercase; }
.vh-scroll-hint i { animation: bounce 2s infinite; }
@keyframes bounce { 0%,100%{transform:translateY(0)} 50%{transform:translateY(5px)} }

.vh-photo-count {
    position: absolute; bottom: 20px; right: 24px; z-index: 3;
    background: rgba(0,0,0,.6); color: rgba(255,255,255,.8);
    border-radius: 8px; padding: 6px 14px; font-size: 13px; font-weight: 600;
    backdrop-filter: blur(8px);
}

/* ── Gallery ──────────────────────────────────────────────── */
.vg-wrap { border-radius: 20px; overflow: hidden; background: #f1f5f9; box-shadow: 0 8px 32px rgba(0,0,0,.10); margin-bottom: 32px; }
.vg-main {
    position: relative;
    aspect-ratio: 16/10;
    overflow: hidden;
    background: #0f172a;
    cursor: pointer;
}
.vg-main img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform .4s ease;
    display: block;
}
.vg-main:hover img { transform: scale(1.02); }
.vg-featured-badge { position: absolute; top: 16px; left: 16px; background: #f59e0b; color: #fff; font-size: 12px; font-weight: 700; padding: 5px 14px; border-radius: 20px; }
.vg-zoom-hint { position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,.5); color: rgba(255,255,255,.8); font-size: 12px; padding: 5px 14px; border-radius: 20px; opacity: 0; transition: opacity .25s; white-space: nowrap; backdrop-filter: blur(4px); pointer-events: none; }
.vg-main:hover .vg-zoom-hint { opacity: 1; }
.vg-arrow {
    position: absolute; top: 50%; transform: translateY(-50%);
    width: 44px; height: 44px; border-radius: 50%;
    background: rgba(0,0,0,.45); border: 1px solid rgba(255,255,255,.2);
    color: #fff; font-size: 16px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s; backdrop-filter: blur(4px);
    z-index: 2;
}
.vg-arrow:hover { background: rgba(37,99,235,.8); }
.vg-arrow-left  { left: 14px; }
.vg-arrow-right { right: 14px; }
.vg-counter { position: absolute; bottom: 14px; right: 16px; background: rgba(0,0,0,.55); color: rgba(255,255,255,.85); font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 20px; backdrop-filter: blur(4px); }
.vg-thumbs { display: flex; gap: 6px; padding: 10px; overflow-x: auto; background: #f8fafc; scrollbar-width: thin; }
.vg-thumb { flex-shrink: 0; width: 88px; height: 62px; border: 2.5px solid transparent; border-radius: 10px; overflow: hidden; cursor: pointer; padding: 0; background: none; transition: border-color .15s, opacity .15s; opacity: .65; }
.vg-thumb.active { border-color: #2563eb; opacity: 1; }
.vg-thumb:hover { opacity: 1; }
.vg-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vg-no-img {
    aspect-ratio: 16/10; background: #f1f5f9; border-radius: 20px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    color: #cbd5e1; font-size: 64px; gap: 14px; margin-bottom: 32px;
}
.vg-no-img div { font-size: 16px; color: #94a3b8; font-weight: 600; }
.vg-no-img p   { font-size: 13px; color: #cbd5e1; margin: 0; }

/* ── Spec grid ─────────────────────────────────────────────── */
.vs-section, .vd-section { margin-bottom: 32px; }
.vs-heading {
    display: flex; align-items: center; gap: 10px;
    font-size: 18px; font-weight: 800; color: #0f172a;
    letter-spacing: -.4px; margin-bottom: 20px;
    padding-bottom: 14px; border-bottom: 2px solid #f1f5f9;
}
.vs-heading i { color: #2563eb; }
.vs-group-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #94a3b8; margin-bottom: 10px; margin-top: 4px; }
.vs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }
.vs-item {
    background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 14px;
    padding: 16px 14px; text-align: center;
    transition: border-color .15s, box-shadow .15s;
}
.vs-item:hover { border-color: #e2e8f0; box-shadow: 0 4px 14px rgba(0,0,0,.06); }
.vs-item-icon { font-size: 20px; color: #2563eb; margin-bottom: 8px; }
.vs-item-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; margin-bottom: 4px; }
.vs-item-val { font-size: 15px; font-weight: 800; color: #0f172a; }

/* ── Description ───────────────────────────────────────────── */
.vd-text { color: #475569; line-height: 1.8; font-size: 15px; margin: 0; }

/* ── Trust grid ────────────────────────────────────────────── */
.vt-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 8px; }
.vt-card { background: #fff; border: 1px solid #f1f5f9; border-radius: 14px; padding: 18px 12px; text-align: center; }
.vt-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 0 auto 10px; }
.vt-title { font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
.vt-sub { font-size: 11px; color: #94a3b8; font-weight: 500; }

/* ── Sidebar ───────────────────────────────────────────────── */
.vi-sidebar { position: sticky; top: 88px; display: flex; flex-direction: column; gap: 14px; }

.vi-price-card { border-radius: 20px; overflow: hidden; box-shadow: 0 8px 32px rgba(0,0,0,.12); }
.vi-price-bg {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2563eb 100%);
    padding: 28px 24px; color: #fff;
}
.vi-price-lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,.45); margin-bottom: 6px; }
.vi-price-amt { font-size: 32px; font-weight: 900; letter-spacing: -1px; color: #fff; }
.vi-price-note { font-size: 13px; color: rgba(255,255,255,.45); margin-top: 6px; }
.vi-price-badges { background: #fff; padding: 14px 20px; display: flex; gap: 8px; }
.vi-badge-avail { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; }
.vi-badge-feat  { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 5px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; }

.vi-ctas { display: flex; flex-direction: column; gap: 10px; }
.vi-btn-wa {
    display: flex; align-items: center; gap: 14px;
    background: #25d366; color: #fff;
    border-radius: 14px; padding: 16px 20px;
    text-decoration: none; transition: all .15s;
    box-shadow: 0 4px 20px rgba(37,211,102,.3);
}
.vi-btn-wa i { font-size: 26px; flex-shrink: 0; }
.vi-btn-wa:hover { background: #128c7e; color: #fff; text-decoration: none; transform: translateY(-2px); box-shadow: 0 8px 28px rgba(37,211,102,.4); }
.vi-btn-call {
    display: flex; align-items: center; gap: 14px;
    background: #fff; border: 1.5px solid #e2e8f0;
    border-radius: 14px; padding: 14px 20px;
    text-decoration: none; transition: all .15s; color: #0f172a;
}
.vi-btn-call i { font-size: 20px; color: #2563eb; flex-shrink: 0; }
.vi-btn-call:hover { border-color: #2563eb; background: #eff6ff; color: #0f172a; text-decoration: none; }

.vi-form-wrap { background: #fff; border: 1px solid #f1f5f9; border-radius: 20px; padding: 24px; box-shadow: 0 4px 20px rgba(0,0,0,.05); }
.vi-form-heading { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; }
.vi-form-heading i { color: #2563eb; }
.vi-field { margin-bottom: 14px; }
.vi-field label { display: block; font-size: 12px; font-weight: 700; color: #374151; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .5px; }
.vi-field label span { color: #dc2626; }
.vi-field-wrap { position: relative; }
.vi-field-wrap i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; }
.vi-field input, .vi-field textarea {
    width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 10px 12px 10px 38px; font-size: 14px; font-family: inherit;
    color: #0f172a; background: #f8fafc; outline: none;
    transition: border-color .15s, background .15s;
}
.vi-field textarea { padding-left: 12px; resize: vertical; }
.vi-field input:focus, .vi-field textarea:focus { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.vi-submit {
    width: 100%; background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff; border: none; border-radius: 12px; padding: 14px;
    font-size: 15px; font-weight: 800; cursor: pointer; font-family: inherit;
    transition: box-shadow .15s, transform .1s;
    letter-spacing: .3px;
}
.vi-submit:hover { box-shadow: 0 8px 24px rgba(37,99,235,.45); transform: translateY(-1px); }
.vi-submit:disabled { opacity: .7; transform: none; }

/* Share buttons */
.share-btn { width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 15px; text-decoration: none; border: none; cursor: pointer; transition: transform .15s, box-shadow .15s; }
.share-btn:hover { transform: scale(1.15); box-shadow: 0 4px 14px rgba(0,0,0,.2); color: #fff; }

/* ── Similar vehicles ──────────────────────────────────────── */
.vsim-section { margin-top: 60px; padding-top: 48px; border-top: 2px solid #f1f5f9; }
.vsim-card { display: flex; flex-direction: column; border-radius: 16px; overflow: hidden; border: 1px solid #e2e8f0; background: #fff; text-decoration: none; box-shadow: 0 2px 12px rgba(0,0,0,.05); transition: transform .2s, box-shadow .2s; }
.vsim-card:hover { transform: translateY(-5px); box-shadow: 0 12px 32px rgba(0,0,0,.10); text-decoration: none; }
.vsim-img { height: 160px; overflow: hidden; background: #f1f5f9; }
.vsim-img img { width: 100%; height: 100%; object-fit: cover; transition: transform .4s ease; }
.vsim-card:hover .vsim-img img { transform: scale(1.05); }
.vsim-body { padding: 16px 18px; }
.vsim-meta { font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
.vsim-name { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 6px; letter-spacing: -.3px; }
.vsim-price { font-size: 17px; font-weight: 900; color: #2563eb; letter-spacing: -.4px; }

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 991px) {
    .vi-sidebar { position: static; }
    .vt-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 576px) {
    .vh-hero { min-height: 65vh; }
    .vh-title { font-size: 26px; }
    .vh-price-value { font-size: 28px; }
    .vt-grid { grid-template-columns: repeat(2, 1fr); }
    .vs-grid  { grid-template-columns: repeat(2, 1fr); }
}
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
    document.querySelectorAll('.vg-thumb').forEach(function (t, i) {
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
        btn.style.background = '#22c55e';
        setTimeout(function () {
            btn.innerHTML = '<i class="fa fa-link"></i>';
            btn.style.background = '#2563eb';
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
