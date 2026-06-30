<?php
require_once __DIR__ . '/../includes/functions.php';
$db = getDB();

$ids = array_map('intval', (array)($_GET['ids'] ?? []));
$ids = array_values(array_unique(array_filter($ids)));

if (count($ids) < 2) {
    header('Location: ' . BASE_URL . '/showroom/');
    exit;
}
$ids = array_slice($ids, 0, 3);

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("
    SELECT c.*, l.name AS location_name,
           (SELECT file_path FROM car_images WHERE car_id=c.id AND is_primary=1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM car_images WHERE car_id=c.id) AS image_count
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE c.id IN ($placeholders) AND c.car_type='inventory' AND c.show_on_website=1
    ORDER BY FIELD(c.id,$placeholders)
");
$stmt->execute(array_merge($ids, $ids));
$cars = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($cars) < 2) {
    header('Location: ' . BASE_URL . '/showroom/');
    exit;
}

$companyName = getSetting('company_name', 'Mascardi Car Yard');
$waClean = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
$pageTitle = 'Compare Vehicles';
$metaDesc = 'Compare ' . implode(' vs ', array_map(fn($c) => $c['year'].' '.$c['make'].' '.$c['model'], $cars)) . ' at ' . $companyName;

include __DIR__ . '/header.php';
?>

<section style="background:#f8fafc;min-height:80vh;padding:60px 0">
    <div class="container-xl">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:40px">
            <div>
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#2563eb;margin-bottom:8px">
                    <i class="fa fa-scale-balanced me-1"></i>Side by Side
                </div>
                <h1 style="font-size:clamp(24px,4vw,36px);font-weight:900;color:#0f172a;letter-spacing:-1px;margin:0">
                    Compare Vehicles
                </h1>
            </div>
            <a href="<?= BASE_URL ?>/showroom/" style="background:#fff;border:1.5px solid #e2e8f0;color:#0f172a;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
                <i class="fa fa-arrow-left"></i> Back to Showroom
            </a>
        </div>

        <!-- Car photos row -->
        <div style="display:grid;grid-template-columns:200px repeat(<?= count($cars) ?>,1fr);gap:1px;background:#e2e8f0;border-radius:20px;overflow:hidden;margin-bottom:2px;box-shadow:0 4px 20px rgba(0,0,0,.07)">

            <!-- Label cell -->
            <div style="background:#fff;padding:20px 18px;display:flex;align-items:center">
                <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8">Vehicle</span>
            </div>

            <?php foreach ($cars as $car):
                $img = $car['primary_image'] ? thumbUrl('cars', $car['primary_image']) : null;
                $hasOffer = !empty($car['offer_price']) && $car['offer_price'] > 0;
                $hasPrice = !empty($car['asking_price']) && $car['asking_price'] > 0;
                $displayPrice = $hasOffer ? $car['offer_price'] : ($hasPrice ? $car['asking_price'] : null);
            ?>
            <div style="background:#fff;padding:20px">
                <?php if ($img): ?>
                <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>">
                    <img src="<?= e($img) ?>" alt="<?= e($car['make'].' '.$car['model']) ?>"
                         style="width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:12px;display:block" loading="lazy">
                </a>
                <?php else: ?>
                <div style="width:100%;aspect-ratio:16/9;background:#f1f5f9;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:40px;color:#cbd5e1">
                    <i class="fa fa-car-side"></i>
                </div>
                <?php endif; ?>
                <div style="margin-top:14px">
                    <div style="font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px">
                        <?= $car['year'] ?><?php if ($car['body_type']): ?> &bull; <?= e($car['body_type']) ?><?php endif; ?>
                    </div>
                    <div style="font-size:17px;font-weight:800;color:#0f172a;letter-spacing:-.3px;margin-bottom:6px">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>" style="color:inherit;text-decoration:none">
                            <?= e($car['make'].' '.$car['model']) ?>
                        </a>
                    </div>
                    <div style="font-size:20px;font-weight:900;color:#2563eb;letter-spacing:-.5px;margin-bottom:10px">
                        <?php if ($hasOffer): ?>
                            <span style="font-size:10px;background:#dc2626;color:#fff;padding:2px 7px;border-radius:10px;vertical-align:middle;margin-right:5px;font-weight:700">SALE</span>
                            KES <?= number_format((float)$car['offer_price']) ?>
                            <?php if ($hasPrice): ?>
                            <del style="font-size:13px;color:#94a3b8;font-weight:500;display:block">KES <?= number_format((float)$car['asking_price']) ?></del>
                            <?php endif; ?>
                        <?php elseif ($hasPrice): ?>
                            KES <?= number_format((float)$car['asking_price']) ?>
                        <?php else: ?>
                            <span style="font-size:14px;color:#64748b">Contact for Price</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $car['id'] ?>"
                           style="flex:1;background:#0f172a;color:#fff;border-radius:9px;padding:9px 14px;font-size:13px;font-weight:700;text-align:center;text-decoration:none">
                            View Details
                        </a>
                        <?php if ($waClean && $displayPrice): $waMsg = urlencode("Hi, I'm comparing and interested in the {$car['year']} {$car['make']} {$car['model']} at KES ".number_format((float)$displayPrice)."."); ?>
                        <a href="https://wa.me/<?= $waClean ?>?text=<?= $waMsg ?>" target="_blank" rel="noopener"
                           style="width:38px;height:38px;background:#dcfce7;color:#16a34a;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:18px;text-decoration:none;flex-shrink:0">
                            <i class="fa-brands fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Spec rows -->
        <?php
        $specs = [
            ['Year',         'year',         fn($v) => $v ?: '—'],
            ['Make',         'make',         fn($v) => $v ?: '—'],
            ['Model',        'model',        fn($v) => $v ?: '—'],
            ['Body Type',    'body_type',    fn($v) => $v ?: '—'],
            ['Transmission', 'transmission', fn($v) => $v ? ucfirst($v) : '—'],
            ['Fuel Type',    'fuel_type',    fn($v) => $v ? ucfirst($v) : '—'],
            ['Mileage',      'mileage',      fn($v) => $v ? number_format((int)$v).' km' : '—'],
            ['Engine',       'engine_cc',    fn($v) => $v ? number_format((int)$v).' cc' : '—'],
            ['Color',        'color',        fn($v) => $v ?: '—'],
            ['Location',     'location_name',fn($v) => $v ?: '—'],
            ['Status',       'status',       fn($v) => $v ? ucfirst(str_replace('_',' ',$v)) : '—'],
        ];

        foreach ($specs as $i => [$label, $field, $fmt]):
            $values = array_map(fn($c) => $c[$field] ?? '', $cars);
            $allSame = count(array_unique($values)) === 1;
            $rowBg   = $i % 2 === 0 ? '#fff' : '#f8fafc';
        ?>
        <div style="display:grid;grid-template-columns:200px repeat(<?= count($cars) ?>,1fr);gap:1px;background:#e2e8f0;margin-top:1px">

            <div style="background:<?= $rowBg ?>;padding:14px 18px;display:flex;align-items:center">
                <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px"><?= $label ?></span>
            </div>

            <?php foreach ($cars as $j => $car):
                $val = $fmt($car[$field] ?? '');
                $otherVals = array_map(fn($c) => $fmt($c[$field] ?? ''), array_filter($cars, fn($c) => $c['id'] !== $car['id']));
                $isWinner = !$allSame && $val !== '—';
            ?>
            <div style="background:<?= $rowBg ?>;padding:14px 16px;display:flex;align-items:center">
                <span style="font-size:14px;color:<?= $allSame ? '#0f172a' : ($isWinner ? '#0f172a' : '#64748b') ?>;font-weight:<?= $isWinner ? '700' : '500' ?>">
                    <?= e($val) ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <!-- Notes/description -->
        <?php $hasNotes = array_filter($cars, fn($c) => !empty($c['notes'])); if ($hasNotes): ?>
        <div style="display:grid;grid-template-columns:200px repeat(<?= count($cars) ?>,1fr);gap:1px;background:#e2e8f0;margin-top:1px;border-radius:0 0 20px 20px;overflow:hidden">
            <div style="background:#fff;padding:14px 18px;display:flex;align-items:flex-start;padding-top:18px">
                <span style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px">Description</span>
            </div>
            <?php foreach ($cars as $car): ?>
            <div style="background:#fff;padding:14px 16px">
                <p style="font-size:13px;color:#64748b;line-height:1.6;margin:0"><?= nl2br(e($car['notes'] ?: '—')) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="background:#e2e8f0;margin-top:1px;height:1px;border-radius:0 0 20px 20px"></div>
        <?php endif; ?>

        <!-- Compare more -->
        <div style="text-align:center;margin-top:40px">
            <a href="<?= BASE_URL ?>/showroom/"
               style="background:#fff;border:1.5px solid #e2e8f0;color:#0f172a;padding:12px 28px;border-radius:12px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:9px">
                <i class="fa fa-scale-balanced"></i> Compare Different Cars
            </a>
        </div>

    </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>
