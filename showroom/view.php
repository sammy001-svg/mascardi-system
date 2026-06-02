<?php
require_once __DIR__ . '/../config/app.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/showroom/'); exit; }

$stmt = $db->prepare("
    SELECT c.*, l.name AS location_name
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE c.id = ? AND c.car_type = 'inventory' AND c.asking_price > 0
");
$stmt->execute([$id]);
$car = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$car) { header('Location: ' . BASE_URL . '/showroom/'); exit; }

// Images
$images = $db->prepare("SELECT * FROM car_images WHERE car_id=? ORDER BY is_primary DESC, id ASC");
$images->execute([$id]);
$images = $images->fetchAll(PDO::FETCH_ASSOC);

$companyName   = getSetting('company_name',    'Mascardi Car Yard');
$whatsappPhone = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));

$pageTitle = $car['year'] . ' ' . $car['make'] . ' ' . $car['model'];
$metaDesc  = "Buy this {$car['year']} {$car['make']} {$car['model']} at {$companyName}. KES " . number_format((float)$car['asking_price']) . ".";
$ogImage   = $images ? BASE_URL . '/uploads/cars/' . htmlspecialchars($images[0]['file_path']) : null;

$waMsg = urlencode("Hi, I'm interested in the {$car['year']} {$car['make']} {$car['model']} (KES " . number_format((float)$car['asking_price']) . "). Could you send more details? " . BASE_URL . "/showroom/view.php?id={$id}");

include __DIR__ . '/header.php';
?>

<!-- Breadcrumb -->
<nav style="font-size:13px;color:#94a3b8;margin-bottom:20px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>/showroom/" style="color:#64748b">Showroom</a>
    <i class="fa fa-chevron-right" style="font-size:10px;color:#cbd5e1"></i>
    <span style="color:#0f172a;font-weight:600"><?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?></span>
</nav>

<div class="row g-4">

    <!-- ── Left: Gallery + Specs ────────────────────────────────── -->
    <div class="col-lg-7">

        <!-- Gallery -->
        <?php if ($images): ?>
        <div class="gallery-wrap mb-3">
            <div class="gallery-main" id="galleryMain">
                <img src="<?= BASE_URL . '/uploads/cars/' . htmlspecialchars($images[0]['file_path']) ?>"
                     id="mainImg" alt="<?= htmlspecialchars($car['make'] . ' ' . $car['model']) ?>">
                <?php if ($car['featured']): ?>
                <span class="gallery-badge-featured"><i class="fa fa-star me-1"></i>Featured</span>
                <?php endif; ?>
            </div>
            <?php if (count($images) > 1): ?>
            <div class="gallery-thumbs">
                <?php foreach ($images as $i => $img): ?>
                <button class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>"
                        onclick="setGalleryImg(this, '<?= BASE_URL . '/uploads/cars/' . htmlspecialchars($img['file_path']) ?>')">
                    <img src="<?= BASE_URL . '/uploads/cars/' . htmlspecialchars($img['file_path']) ?>"
                         alt="Photo <?= $i+1 ?>">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="gallery-no-img mb-3"><i class="fa fa-car"></i><div>No photos yet</div></div>
        <?php endif; ?>

        <!-- Specs card -->
        <div class="card border-0 shadow-sm" style="border-radius:16px">
            <div class="card-body p-0">
                <div style="padding:18px 24px;border-bottom:1px solid #f1f5f9;font-weight:700;font-size:15px">
                    <i class="fa fa-list-check me-2 text-primary"></i>Vehicle Specifications
                </div>
                <div class="spec-grid">
                    <?php
                    $specs = [
                        ['Make',         $car['make']],
                        ['Model',        $car['model']],
                        ['Year',         $car['year']],
                        ['Body Type',    $car['body_type']],
                        ['Color',        $car['color']],
                        ['Transmission', ucfirst($car['transmission'] ?? '')],
                        ['Fuel Type',    ucfirst($car['fuel_type'] ?? '')],
                        ['Engine',       $car['engine_cc'] ? number_format($car['engine_cc']) . ' cc' : null],
                        ['Mileage',      $car['mileage'] ? number_format($car['mileage']) . ' km' : null],
                        ['Location',     $car['location_name'] ?? null],
                    ];
                    foreach ($specs as [$label, $val]):
                        if (!$val) continue;
                    ?>
                    <div class="spec-row">
                        <span class="spec-label"><?= $label ?></span>
                        <span class="spec-val"><?= htmlspecialchars($val) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($car['notes']): ?>
        <div class="card border-0 shadow-sm mt-3" style="border-radius:16px">
            <div class="card-body">
                <h6 style="font-weight:700;margin-bottom:10px"><i class="fa fa-align-left me-2 text-primary"></i>Description</h6>
                <p style="color:#475569;line-height:1.7;margin:0;font-size:14px"><?= nl2br(htmlspecialchars($car['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Right: Price + Inquiry ───────────────────────────────── -->
    <div class="col-lg-5">
        <div style="position:sticky;top:80px">

            <!-- Price card -->
            <div class="card border-0 shadow-sm mb-3" style="border-radius:16px;overflow:hidden">
                <div style="background:linear-gradient(120deg,#1e3a8a,#2563eb);padding:24px;color:#fff">
                    <div style="font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;opacity:.65;margin-bottom:6px">Asking Price</div>
                    <div style="font-size:36px;font-weight:800;letter-spacing:-1px">
                        KES <?= number_format((float)$car['asking_price']) ?>
                    </div>
                    <div style="opacity:.6;font-size:13px;margin-top:4px">Finance available &bull; Contact us for details</div>
                </div>
                <div class="card-body pb-3">
                    <div class="d-flex gap-2 flex-wrap">
                        <span style="background:#f0fdf4;color:#16a34a;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700">
                            <i class="fa fa-check-circle me-1"></i>Available
                        </span>
                        <?php if ($car['featured']): ?>
                        <span style="background:#fef3c7;color:#b45309;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:700">
                            <i class="fa fa-star me-1"></i>Featured
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- WhatsApp CTA -->
            <?php if ($whatsappPhone): ?>
            <a href="https://wa.me/<?= $whatsappPhone ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener"
               class="d-flex align-items-center justify-content-center gap-2 mb-3"
               style="background:#25d366;color:#fff;padding:14px;border-radius:12px;font-size:15px;font-weight:700;text-decoration:none;transition:background .15s"
               onmouseover="this.style.background='#128c7e'" onmouseout="this.style.background='#25d366'">
                <i class="fa-brands fa-whatsapp" style="font-size:20px"></i> Chat on WhatsApp
            </a>
            <?php endif; ?>

            <!-- Inquiry form -->
            <div class="card border-0 shadow-sm" style="border-radius:16px">
                <div class="card-body">
                    <h6 style="font-weight:700;margin-bottom:16px"><i class="fa fa-paper-plane me-2 text-primary"></i>Send an Enquiry</h6>
                    <div id="inquirySuccess" class="alert alert-success" style="display:none;border-radius:10px">
                        <i class="fa fa-check-circle me-1"></i> <strong>Message sent!</strong> We'll be in touch shortly.
                    </div>
                    <form id="inquiryForm" novalidate>
                        <input type="hidden" name="car_id" value="<?= $id ?>">
                        <div class="mb-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600">Your Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="John Kamau" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="+254 7XX XXX XXX">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="you@example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" style="font-size:12.5px;font-weight:600">Message</label>
                            <textarea name="message" class="form-control" rows="3"
                                      placeholder="I'm interested in this vehicle. When can I view it?"><?= "I'm interested in the {$car['year']} {$car['make']} {$car['model']}. Please contact me with more information." ?></textarea>
                        </div>
                        <button type="submit" id="inquiryBtn" class="btn btn-primary w-100" style="border-radius:10px;padding:12px;font-weight:700">
                            <span id="inquiryBtnText"><i class="fa fa-paper-plane me-1"></i> Send Enquiry</span>
                            <span id="inquiryBtnLoading" style="display:none"><i class="fa fa-spinner fa-spin me-1"></i> Sending...</span>
                        </button>
                        <div id="inquiryError" class="alert alert-danger mt-2" style="display:none;border-radius:10px;font-size:13px"></div>
                    </form>
                </div>
            </div>

        </div><!-- /sticky -->
    </div>
</div>

<style>
/* Gallery */
.gallery-wrap { border-radius: 16px; overflow: hidden; background: #f1f5f9; }
.gallery-main { position: relative; aspect-ratio: 16/10; overflow: hidden; background: #f1f5f9; }
.gallery-main img { width: 100%; height: 100%; object-fit: cover; transition: transform .35s ease; cursor: zoom-in; }
.gallery-main img:active { transform: scale(1.06); }
.gallery-badge-featured {
    position: absolute; top: 12px; left: 12px;
    background: #f59e0b; color: #fff;
    font-size: 12px; font-weight: 700;
    padding: 4px 12px; border-radius: 20px;
}
.gallery-thumbs { display: flex; gap: 6px; padding: 8px; overflow-x: auto; background: #f8fafc; }
.gallery-thumb {
    flex-shrink: 0; width: 72px; height: 52px;
    border: 2px solid transparent; border-radius: 8px;
    overflow: hidden; cursor: pointer; padding: 0;
    background: none; transition: border-color .15s;
}
.gallery-thumb.active { border-color: #2563eb; }
.gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }
.gallery-no-img {
    aspect-ratio: 16/10; background: #f1f5f9; border-radius: 16px;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    font-size: 60px; color: #cbd5e1; gap: 12px;
}
.gallery-no-img div { font-size: 14px; color: #94a3b8; }

/* Specs */
.spec-grid { padding: 4px 0; }
.spec-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 11px 24px; border-bottom: 1px solid #f8fafc;
    font-size: 13.5px;
}
.spec-row:last-child { border-bottom: none; }
.spec-label { color: #64748b; font-weight: 500; }
.spec-val { font-weight: 700; color: #0f172a; text-align: right; }
</style>

<script>
function setGalleryImg(btn, src) {
    document.getElementById('mainImg').src = src;
    document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
}

// Inquiry form submission
document.getElementById('inquiryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var form = this;
    var btn  = document.getElementById('inquiryBtn');
    var name = form.querySelector('[name="name"]').value.trim();
    if (!name) { form.querySelector('[name="name"]').focus(); return; }

    document.getElementById('inquiryBtnText').style.display = 'none';
    document.getElementById('inquiryBtnLoading').style.display = '';
    document.getElementById('inquiryError').style.display = 'none';
    btn.disabled = true;

    var data = new FormData(form);
    fetch('<?= BASE_URL ?>/showroom/inquiry.php', { method: 'POST', body: data })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                form.style.display = 'none';
                document.getElementById('inquirySuccess').style.display = '';
            } else {
                document.getElementById('inquiryError').textContent = res.error || 'Something went wrong. Please try again.';
                document.getElementById('inquiryError').style.display = '';
            }
        })
        .catch(function() {
            document.getElementById('inquiryError').textContent = 'Network error. Please try again.';
            document.getElementById('inquiryError').style.display = '';
        })
        .finally(function() {
            document.getElementById('inquiryBtnText').style.display = '';
            document.getElementById('inquiryBtnLoading').style.display = 'none';
            btn.disabled = false;
        });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
