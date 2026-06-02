<?php
/**
 * Public self-service booking page — no auth required.
 */
require_once __DIR__ . '/../includes/functions.php';

$db          = getDB();
$companyName = getSetting('company_name', 'Mascardi Car Yard');
$__waClean   = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));

$serviceTypes = [
    'Engine Service'  => 'fa-engine',
    'Major Service'   => 'fa-screwdriver-wrench',
    'Diagnostics'     => 'fa-stethoscope',
    'Paint Job'       => 'fa-brush',
    'Body Work'       => 'fa-car-burst',
    'Buffing'         => 'fa-circle-dot',
];
$timeSlots = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00'];

$success    = false;
$bookingNum = '';
$errors     = [];

// Pre-fill from GET params (when navigating from landing page mini-form)
$d = [
    'client_name'      => trim($_GET['name']    ?? ''),
    'client_email'     => trim($_GET['email']   ?? ''),
    'client_phone'     => trim($_GET['phone']   ?? ''),
    'car_make'         => trim($_GET['make']    ?? ''),
    'car_model'        => trim($_GET['model']   ?? ''),
    'car_registration' => strtoupper(trim($_GET['reg']  ?? '')),
    'service_type'     => isset($_GET['service']) && $_GET['service'] !== '' ? [$_GET['service']] : [],
    'description'      => trim($_GET['desc']    ?? ''),
    'preferred_date'   => trim($_GET['date']    ?? ''),
    'preferred_time'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'client_name'      => trim($_POST['client_name']      ?? ''),
        'client_email'     => trim($_POST['client_email']     ?? ''),
        'client_phone'     => trim($_POST['client_phone']     ?? ''),
        'car_make'         => trim($_POST['car_make']         ?? ''),
        'car_model'        => trim($_POST['car_model']        ?? ''),
        'car_registration' => strtoupper(trim($_POST['car_registration'] ?? '')),
        'service_type'     => array_values(array_filter(array_map('trim', (array)($_POST['service_type'] ?? [])))),
        'description'      => trim($_POST['description']      ?? ''),
        'preferred_date'   => trim($_POST['preferred_date']   ?? ''),
        'preferred_time'   => trim($_POST['preferred_time']   ?? ''),
    ];

    if (!$d['client_name'])        $errors[] = 'Your full name is required.';
    if (!$d['client_phone'])       $errors[] = 'Phone number is required.';
    if (empty($d['service_type'])) $errors[] = 'Please select at least one service type.';
    if ($d['client_email'] && !filter_var($d['client_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        try {
            $bNum       = nextNumber('service_bookings', 'booking_number', 'BK');
            $serviceStr = implode(', ', $d['service_type']);

            $db->prepare("
                INSERT INTO service_bookings
                    (booking_number, client_name, client_email, client_phone,
                     car_make, car_model, car_registration, car_description,
                     service_type, description,
                     booking_date, preferred_date, preferred_time, created_by)
                VALUES (?,?,?,?, ?,?,?,?, ?,?, ?,?,?,?)
            ")->execute([
                $bNum,
                $d['client_name'], $d['client_email'], $d['client_phone'],
                $d['car_make'], $d['car_model'], $d['car_registration'],
                trim("{$d['car_make']} {$d['car_model']} {$d['car_registration']}"),
                $serviceStr, $d['description'],
                date('Y-m-d'),
                $d['preferred_date'] ?: null,
                $d['preferred_time'] ?: null,
                'online-booking',
            ]);

            $newId      = (int)$db->lastInsertId();
            $bookingNum = $bNum;
            $success    = true;

            // Notify admins / workshop managers
            try {
                $admins = $db->query("SELECT id FROM users WHERE role IN ('admin','workshop_manager') AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
                $notif  = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)");
                foreach ($admins as $uid) {
                    $notif->execute([
                        $uid, 'booking',
                        "Online Booking: {$bNum}",
                        "{$d['client_name']} — {$serviceStr}",
                        BASE_URL . '/modules/service_bookings/view.php?id=' . $newId,
                    ]);
                }
            } catch (\Throwable $e) {}

            // Confirmation email to client
            if ($d['client_email']) {
                try {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $subj    = "Booking Confirmation — {$bNum}";
                    $dateStr = $d['preferred_date'] ? date('d M Y', strtotime($d['preferred_date'])) : 'To be confirmed';
                    $vehicle = trim("{$d['car_make']} {$d['car_model']} {$d['car_registration']}");
                    $body    = "<p>Dear " . e($d['client_name']) . ",</p>
                               <p>Thank you! Your service booking has been received. Here are your details:</p>
                               <table class='data'>
                                 <tr><th>Booking No.</th><td><strong>{$bNum}</strong></td></tr>
                                 <tr><th>Service</th><td>" . e($serviceStr) . "</td></tr>
                                 <tr><th>Vehicle</th><td>" . e($vehicle ?: 'Not specified') . "</td></tr>
                                 <tr><th>Preferred Date</th><td>{$dateStr}" . ($d['preferred_time'] ? " at {$d['preferred_time']}" : '') . "</td></tr>
                               </table>
                               <p>Our team will contact you shortly to confirm your appointment slot.</p>";
                    sendMail($d['client_email'], $d['client_name'], $subj,
                             mailTemplate($subj, $body), 'service_booking', $newId);
                } catch (\Throwable $e) {}
            }

        } catch (\Throwable $e) {
            $errors[] = 'Booking could not be saved. Please try again or contact us directly.';
        }
    }
}

$pageTitle = 'Book a Service';
$metaDesc  = "Schedule your vehicle for service at {$companyName}. Choose a date, select your service type, and we'll confirm your booking.";
include __DIR__ . '/header.php';
?>

<!-- Page header -->
<section style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);padding:56px 0 44px;position:relative;overflow:hidden">
    <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.022) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.022) 1px,transparent 1px);background-size:50px 50px;pointer-events:none"></div>
    <div class="container-xl" style="position:relative">
        <nav style="font-size:13px;color:rgba(255,255,255,.38);margin-bottom:16px">
            <a href="<?= BASE_URL ?>/showroom/" style="color:rgba(255,255,255,.38);text-decoration:none;transition:color .15s"
               onmouseover="this.style.color='rgba(255,255,255,.7)'" onmouseout="this.style.color='rgba(255,255,255,.38)'">Home</a>
            <span style="margin:0 8px">›</span>
            Book a Service
        </nav>
        <h1 style="font-size:clamp(26px,4vw,44px);font-weight:900;color:#fff;letter-spacing:-1.5px;margin:0 0 10px">
            <i class="fa fa-calendar-check me-3" style="color:#60a5fa;font-size:.82em"></i>Book a Service
        </h1>
        <p style="font-size:15.5px;color:rgba(255,255,255,.5);margin:0;max-width:520px;line-height:1.65">
            Schedule your vehicle for service with ease. Fill in the form and we'll confirm your slot within a few hours.
        </p>
    </div>
</section>

<?php if ($success): ?>
<!-- ── SUCCESS STATE ──────────────────────────────────────── -->
<section style="background:#f8fafc;padding:80px 0;min-height:55vh;display:flex;align-items:center">
    <div class="container-xl">
        <div style="max-width:540px;margin:0 auto;text-align:center">
            <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;margin:0 auto 24px;box-shadow:0 8px 32px rgba(34,197,94,.3)">
                <i class="fa fa-check" style="font-size:34px;color:#fff"></i>
            </div>
            <h2 style="font-size:28px;font-weight:900;color:#0f172a;letter-spacing:-.5px;margin:0 0 10px">Booking Received!</h2>
            <p style="font-size:15.5px;color:#64748b;line-height:1.65;margin:0 0 28px">
                Your reference is <strong style="color:#0f172a;font-size:17px"><?= e($bookingNum) ?></strong>.<br>
                We'll call or WhatsApp you to confirm your slot.
            </p>

            <!-- Summary card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px 28px;text-align:left;margin-bottom:28px;box-shadow:0 2px 12px rgba(0,0,0,.05)">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#94a3b8;margin-bottom:16px">Your Booking Details</div>
                <div style="display:grid;gap:0">
                    <?php
                    $rows = [
                        ['Reference',     $bookingNum],
                        ['Name',          $d['client_name']],
                        ['Phone',         $d['client_phone']],
                        ['Service',       implode(', ', $d['service_type'])],
                        ['Vehicle',       trim("{$d['car_make']} {$d['car_model']} {$d['car_registration']}") ?: null],
                        ['Preferred Date', $d['preferred_date'] ? date('d M Y', strtotime($d['preferred_date'])) . ($d['preferred_time'] ? ' at ' . $d['preferred_time'] : '') : null],
                    ];
                    $filtered = array_values(array_filter($rows, fn($r) => !empty($r[1])));
                    foreach ($filtered as $i => [$label, $value]): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 0;<?= $i < count($filtered)-1 ? 'border-bottom:1px solid #f1f5f9' : '' ?>">
                        <span style="font-size:13px;color:#64748b;font-weight:600"><?= $label ?></span>
                        <span style="font-size:13.5px;color:#0f172a;font-weight:700"><?= e($value) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>/showroom/"
                   style="background:#0f172a;color:#fff;border-radius:12px;padding:13px 24px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:background .15s"
                   onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">
                    <i class="fa fa-arrow-left"></i> Back to Showroom
                </a>
                <?php if ($__waClean): ?>
                <a href="https://wa.me/<?= $__waClean ?>?text=<?= urlencode("Hi, I just booked a service online. My reference is {$bookingNum}. Name: {$d['client_name']}.") ?>"
                   target="_blank" rel="noopener"
                   style="background:#25d366;color:#fff;border-radius:12px;padding:13px 24px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
                    <i class="fa-brands fa-whatsapp fa-lg"></i> Chat With Us
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<?php else: ?>
<!-- ── BOOKING FORM ───────────────────────────────────────── -->
<section style="background:#f8fafc;padding:56px 0 72px">
    <div class="container-xl">
        <div class="row g-5">

            <!-- Left: form -->
            <div class="col-lg-8">

                <?php if ($errors): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:14px;padding:16px 20px;margin-bottom:28px">
                    <?php foreach ($errors as $err): ?>
                    <div style="display:flex;align-items:center;gap:9px;font-size:14px;color:#dc2626;font-weight:600;margin-bottom:4px;last-child:margin-bottom:0">
                        <i class="fa fa-circle-exclamation flex-shrink-0"></i><?= e($err) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="bookingForm">

                    <!-- Your Details -->
                    <div class="bk-card mb-5">
                        <div class="bk-card-header">
                            <div class="bk-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fa fa-user"></i></div>
                            <div>
                                <div class="bk-card-title">Your Details</div>
                                <div class="bk-card-sub">How we'll reach you to confirm the booking</div>
                            </div>
                        </div>
                        <div class="bk-card-body">
                            <div class="bk-grid-2">
                                <div>
                                    <label class="bk-label">Full Name <span class="bk-req">*</span></label>
                                    <input type="text" name="client_name" class="bk-input"
                                           value="<?= e($d['client_name']) ?>" placeholder="Your full name" required>
                                </div>
                                <div>
                                    <label class="bk-label">Phone Number <span class="bk-req">*</span></label>
                                    <input type="tel" name="client_phone" class="bk-input"
                                           value="<?= e($d['client_phone']) ?>" placeholder="e.g. 0712 345 678" required>
                                </div>
                            </div>
                            <div style="margin-top:18px">
                                <label class="bk-label">
                                    Email Address
                                    <span style="font-weight:500;color:#94a3b8;font-size:11px;text-transform:none;letter-spacing:0;margin-left:4px">(optional — for confirmation email)</span>
                                </label>
                                <input type="email" name="client_email" class="bk-input"
                                       value="<?= e($d['client_email']) ?>" placeholder="your@email.com">
                            </div>
                        </div>
                    </div>

                    <!-- Vehicle Details -->
                    <div class="bk-card mb-5">
                        <div class="bk-card-header">
                            <div class="bk-card-icon" style="background:#fff7ed;color:#d97706"><i class="fa fa-car"></i></div>
                            <div>
                                <div class="bk-card-title">Vehicle Details</div>
                                <div class="bk-card-sub">Tell us about the vehicle being serviced</div>
                            </div>
                        </div>
                        <div class="bk-card-body">
                            <div class="bk-grid-3">
                                <div>
                                    <label class="bk-label">Car Make</label>
                                    <input type="text" name="car_make" class="bk-input"
                                           value="<?= e($d['car_make']) ?>" placeholder="e.g. Toyota, BMW">
                                </div>
                                <div>
                                    <label class="bk-label">Car Model</label>
                                    <input type="text" name="car_model" class="bk-input"
                                           value="<?= e($d['car_model']) ?>" placeholder="e.g. Prado, X5">
                                </div>
                                <div>
                                    <label class="bk-label">Registration No.</label>
                                    <input type="text" name="car_registration" class="bk-input"
                                           value="<?= e($d['car_registration']) ?>" placeholder="e.g. KDA 000Q"
                                           style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Service Type -->
                    <div class="bk-card mb-5">
                        <div class="bk-card-header">
                            <div class="bk-card-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-wrench"></i></div>
                            <div>
                                <div class="bk-card-title">Service Type <span class="bk-req">*</span></div>
                                <div class="bk-card-sub">Select one or more services needed</div>
                            </div>
                        </div>
                        <div class="bk-card-body">
                            <div class="svc-grid">
                                <?php foreach ($serviceTypes as $st => $ico): ?>
                                <div class="svc-card">
                                    <input type="checkbox" name="service_type[]"
                                           id="svc_<?= md5($st) ?>" value="<?= e($st) ?>"
                                           <?= in_array($st, $d['service_type']) ? 'checked' : '' ?>>
                                    <label for="svc_<?= md5($st) ?>">
                                        <i class="fa <?= $ico ?>"></i>
                                        <?= e($st) ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule & Description -->
                    <div class="bk-card mb-6">
                        <div class="bk-card-header">
                            <div class="bk-card-icon" style="background:#f0f9ff;color:#0891b2"><i class="fa fa-calendar-days"></i></div>
                            <div>
                                <div class="bk-card-title">Schedule & Description</div>
                                <div class="bk-card-sub">When do you want to bring the vehicle in?</div>
                            </div>
                        </div>
                        <div class="bk-card-body">
                            <div class="bk-grid-2" style="margin-bottom:18px">
                                <div>
                                    <label class="bk-label">Preferred Date</label>
                                    <input type="date" name="preferred_date" class="bk-input"
                                           value="<?= e($d['preferred_date']) ?>"
                                           min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                                    <div style="font-size:11.5px;color:#94a3b8;margin-top:5px">
                                        <i class="fa fa-circle-info me-1 text-primary"></i>Mon – Sat, 8:00 AM – 6:00 PM
                                    </div>
                                </div>
                                <div>
                                    <label class="bk-label">Preferred Start Time</label>
                                    <select name="preferred_time" class="bk-input">
                                        <option value="">— Any time —</option>
                                        <?php foreach ($timeSlots as $ts): ?>
                                        <option value="<?= $ts ?>" <?= $d['preferred_time'] === $ts ? 'selected' : '' ?>><?= $ts ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="bk-label">Issues / Description</label>
                                <textarea name="description" rows="4" class="bk-input"
                                          placeholder="Describe any issues, warning lights, noises, or symptoms…"><?= e($d['description']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                            style="width:100%;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:14px;padding:16px;font-size:16px;font-weight:800;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:10px;transition:box-shadow .2s,transform .1s;letter-spacing:-.2px"
                            onmouseover="this.style.boxShadow='0 8px 32px rgba(37,99,235,.45)';this.style.transform='translateY(-1px)'"
                            onmouseout="this.style.boxShadow='none';this.style.transform=''">
                        <i class="fa fa-calendar-check fa-lg"></i>
                        Confirm Booking
                    </button>
                    <p style="text-align:center;font-size:12.5px;color:#94a3b8;margin-top:14px">
                        <i class="fa fa-shield-halved me-1"></i>Your details are used only to process this booking.
                    </p>

                </form>
            </div>

            <!-- Right: sidebar -->
            <div class="col-lg-4">
                <div style="background:#0f172a;border-radius:20px;padding:28px;color:rgba(255,255,255,.6);position:sticky;top:90px">
                    <div style="font-size:15px;font-weight:800;color:#fff;letter-spacing:-.2px;margin-bottom:24px">
                        <i class="fa fa-circle-info me-2" style="color:#60a5fa"></i>How It Works
                    </div>

                    <?php foreach ([
                        ['1', '#2563eb', 'Submit Your Booking',  'Fill in the form with your details, service needed, and preferred date.'],
                        ['2', '#7c3aed', 'We Confirm Your Slot', 'Our team will call or WhatsApp you within a few hours to confirm availability.'],
                        ['3', '#0891b2', 'Bring In Your Vehicle', 'Drop off your car on the confirmed date. Our workshop team takes it from there.'],
                        ['4', '#22c55e', 'Pick Up & Go',         'We notify you when it\'s ready. Clear invoice, quality work, every time.'],
                    ] as [$num, $color, $title, $desc]): ?>
                    <div style="display:flex;gap:14px;margin-bottom:20px">
                        <div style="width:30px;height:30px;border-radius:50%;background:<?= $color ?>;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff;flex-shrink:0;margin-top:1px"><?= $num ?></div>
                        <div>
                            <div style="font-weight:700;color:#fff;font-size:14px;margin-bottom:3px"><?= $title ?></div>
                            <div style="font-size:12.5px;line-height:1.55;color:rgba(255,255,255,.45)"><?= $desc ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php
                    $__phone   = getSetting('company_phone', '');
                    $__email   = getSetting('company_email', '');
                    if ($__phone || $__email || $__waClean):
                    ?>
                    <div style="border-top:1px solid rgba(255,255,255,.08);padding-top:20px;margin-top:8px">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.28);margin-bottom:14px">Need Help?</div>
                        <?php if ($__phone): ?>
                        <a href="tel:<?= e($__phone) ?>"
                           style="display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.65);text-decoration:none;font-size:13.5px;font-weight:600;margin-bottom:10px;transition:color .15s"
                           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.65)'">
                            <i class="fa fa-phone" style="color:#60a5fa;width:16px;text-align:center"></i><?= e($__phone) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($__email): ?>
                        <a href="mailto:<?= e($__email) ?>"
                           style="display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.65);text-decoration:none;font-size:13.5px;font-weight:600;margin-bottom:10px;transition:color .15s"
                           onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.65)'">
                            <i class="fa fa-envelope" style="color:#60a5fa;width:16px;text-align:center"></i><?= e($__email) ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($__waClean): ?>
                        <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                           style="display:flex;align-items:center;gap:10px;background:#25d366;color:#fff;text-decoration:none;font-size:13.5px;font-weight:700;padding:11px 16px;border-radius:11px;margin-top:14px;transition:background .15s"
                           onmouseover="this.style.background='#128c7e'" onmouseout="this.style.background='#25d366'">
                            <i class="fa-brands fa-whatsapp" style="font-size:18px"></i>Chat on WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</section>
<?php endif; ?>

<style>
/* Card layout */
.bk-card { background:#fff; border-radius:20px; border:1px solid #e2e8f0; box-shadow:0 2px 14px rgba(0,0,0,.04); overflow:hidden; }
.bk-card-header { display:flex; align-items:center; gap:14px; padding:20px 24px; border-bottom:1px solid #f1f5f9; }
.bk-card-icon { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
.bk-card-title { font-size:15px; font-weight:800; color:#0f172a; letter-spacing:-.25px; }
.bk-card-sub { font-size:12px; color:#94a3b8; margin-top:1px; }
.bk-card-body { padding:24px; }
.bk-label { display:block; font-size:11.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:7px; }
.bk-req { color:#dc2626; }
.bk-input { width:100%; border:1.5px solid #e2e8f0; border-radius:10px; padding:11px 14px; font-size:14px; font-family:inherit; outline:none; color:#0f172a; background:#fff; transition:border-color .15s, box-shadow .15s; }
.bk-input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
select.bk-input { cursor:pointer; }
textarea.bk-input { resize:vertical; min-height:90px; }
.bk-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.bk-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:18px; }
.mb-5 { margin-bottom:24px !important; }
.mb-6 { margin-bottom:32px !important; }

/* Service checkboxes */
.svc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
.svc-card input[type=checkbox] { display:none; }
.svc-card label {
    display:flex; flex-direction:column; align-items:center; justify-content:center; gap:9px;
    border:2px solid #e2e8f0; border-radius:14px; padding:18px 14px;
    cursor:pointer; transition:all .15s; background:#f8fafc; text-align:center;
    font-size:13px; font-weight:700; color:#64748b; height:100%; position:relative; user-select:none;
}
.svc-card label i.fa { font-size:22px; color:#94a3b8; transition:color .15s; }
.svc-card input[type=checkbox]:checked + label { border-color:#2563eb; background:#eff6ff; color:#1d4ed8; }
.svc-card input[type=checkbox]:checked + label i.fa { color:#2563eb; }
.svc-card input[type=checkbox]:checked + label::after {
    content:'\f058'; font-family:'Font Awesome 6 Free'; font-weight:900;
    position:absolute; top:7px; right:8px; color:#2563eb; font-size:14px;
}
.svc-card label:hover { border-color:#93c5fd; background:#f0f9ff; }
.svc-card label:hover i.fa { color:#3b82f6; }

/* Responsive */
@media (max-width:768px) {
    .bk-grid-2 { grid-template-columns:1fr; }
    .bk-grid-3 { grid-template-columns:1fr 1fr; }
}
@media (max-width:480px) {
    .bk-grid-3 { grid-template-columns:1fr; }
    .svc-grid { grid-template-columns:1fr 1fr; }
}
</style>

<?php include __DIR__ . '/footer.php'; ?>
