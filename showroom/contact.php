<?php
/**
 * Public contact page — no auth required.
 */
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$__companyName  = getSetting('company_name',    'Mascardi Car Yard');
$__companyPhone = getSetting('company_phone',   '');
$__companyEmail = getSetting('company_email',   '');
$__whatsapp     = getSetting('whatsapp_number', $__companyPhone);
$__address      = getSetting('company_address', '');
$__waClean      = preg_replace('/[^0-9]/', '', $__whatsapp);

$success = false;
$errors  = [];
$d = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) {
        $d[$k] = trim($_POST[$k] ?? '');
    }

    if (!$d['name'])    $errors[] = 'Your name is required.';
    if (!$d['phone'] && !$d['email']) $errors[] = 'Please provide a phone number or email address.';
    if (!$d['message']) $errors[] = 'Please write a message.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        // Try to save to DB (non-fatal if table doesn't exist)
        try {
            $db->prepare("
                INSERT INTO contact_messages (name, phone, email, subject, message, created_at)
                VALUES (?,?,?,?,?,NOW())
            ")->execute([$d['name'], $d['phone'] ?: null, $d['email'] ?: null, $d['subject'] ?: null, $d['message']]);
        } catch (\Throwable $e) {}

        // Notify admin via email
        try {
            require_once __DIR__ . '/../includes/mailer.php';
            $adminEmail = getSetting('admin_email', getSetting('company_email', ''));
            if ($adminEmail) {
                $subj = "New Contact Message" . ($d['subject'] ? ": {$d['subject']}" : '') . " — {$d['name']}";
                $body = "<p>A new contact message was received from <strong>" . e($d['name']) . "</strong>.</p>
                         <table style='font-family:sans-serif;font-size:14px;border-collapse:collapse'>
                             <tr><td style='padding:6px 20px 6px 0;color:#64748b;font-weight:600'>Name</td><td>" . e($d['name']) . "</td></tr>"
                       . ($d['phone']   ? "<tr><td style='padding:6px 20px 6px 0;color:#64748b;font-weight:600'>Phone</td><td><a href='tel:{$d['phone']}'>{$d['phone']}</a></td></tr>" : "")
                       . ($d['email']   ? "<tr><td style='padding:6px 20px 6px 0;color:#64748b;font-weight:600'>Email</td><td><a href='mailto:{$d['email']}'>{$d['email']}</a></td></tr>" : "")
                       . ($d['subject'] ? "<tr><td style='padding:6px 20px 6px 0;color:#64748b;font-weight:600'>Subject</td><td>" . e($d['subject']) . "</td></tr>" : "")
                       . "<tr><td style='padding:6px 20px 6px 0;color:#64748b;font-weight:600;vertical-align:top'>Message</td><td>" . nl2br(e($d['message'])) . "</td></tr>
                         </table>";
                sendMail($adminEmail, $__companyName, $subj, $body);
            }
        } catch (\Throwable $e) {}

        $success = true;
    }
}

$pageTitle = 'Contact Us';
$metaDesc  = "Get in touch with {$__companyName}. Call, email, or visit us in person. We're here to help with your vehicle needs.";
include __DIR__ . '/header.php';
?>

<!-- ══════════════════════════════════════════════════════
     PAGE HERO
══════════════════════════════════════════════════════════ -->
<section style="background:linear-gradient(135deg,#0f172a 0%,#1e293b 60%,#1e3a8a 100%);padding:64px 0 52px;position:relative;overflow:hidden">
    <div style="position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.022) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.022) 1px,transparent 1px);background-size:50px 50px;pointer-events:none"></div>
    <!-- Decorative blobs -->
    <div style="position:absolute;top:-80px;right:-60px;width:320px;height:320px;border-radius:50%;background:radial-gradient(rgba(37,99,235,.18),transparent 70%);pointer-events:none"></div>
    <div style="position:absolute;bottom:-60px;left:10%;width:220px;height:220px;border-radius:50%;background:radial-gradient(rgba(124,58,237,.12),transparent 70%);pointer-events:none"></div>

    <div class="container-xl" style="position:relative">
        <nav style="font-size:13px;color:rgba(255,255,255,.38);margin-bottom:18px">
            <a href="<?= BASE_URL ?>/showroom/" style="color:rgba(255,255,255,.38);text-decoration:none;transition:color .15s"
               onmouseover="this.style.color='rgba(255,255,255,.7)'" onmouseout="this.style.color='rgba(255,255,255,.38)'">Home</a>
            <span style="margin:0 8px">›</span>
            Contact Us
        </nav>

        <div class="row align-items-center g-4">
            <div class="col-lg-6">
                <h1 style="font-size:clamp(30px,5vw,52px);font-weight:900;color:#fff;letter-spacing:-2px;line-height:1.08;margin:0 0 16px">
                    Let's Talk.<br>
                    <span style="background:linear-gradient(135deg,#60a5fa,#818cf8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">We're Here for You.</span>
                </h1>
                <p style="font-size:16px;color:rgba(255,255,255,.52);line-height:1.7;margin:0 0 36px;max-width:460px">
                    Have questions about a vehicle, a service, or financing? Our team is ready to help you through every step.
                </p>
                <!-- Quick contact pills -->
                <div style="display:flex;flex-wrap:wrap;gap:10px">
                    <?php if ($__companyPhone): ?>
                    <a href="tel:<?= e($__companyPhone) ?>"
                       style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:50px;padding:9px 18px;color:#fff;text-decoration:none;font-size:13.5px;font-weight:600;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.14)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-phone" style="color:#60a5fa"></i><?= e($__companyPhone) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <a href="mailto:<?= e($__companyEmail) ?>"
                       style="display:inline-flex;align-items:center;gap:8px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14);border-radius:50px;padding:9px 18px;color:#fff;text-decoration:none;font-size:13.5px;font-weight:600;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.14)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-envelope" style="color:#60a5fa"></i><?= e($__companyEmail) ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($__waClean): ?>
                    <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:8px;background:#25d366;border-radius:50px;padding:9px 18px;color:#fff;text-decoration:none;font-size:13.5px;font-weight:700;transition:background .15s"
                       onmouseover="this.style.background='#128c7e'" onmouseout="this.style.background='#25d366'">
                        <i class="fa-brands fa-whatsapp" style="font-size:16px"></i>WhatsApp Us
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5 offset-lg-1 d-none d-lg-block">
                <!-- Stat cards -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <?php foreach ([
                        ['fa-phone',       '#2563eb', 'Rapid Response',  'Mon – Sat, 8 AM – 6 PM'],
                        ['fa-envelope',    '#7c3aed', 'Email Support',   'Reply within 24 hours'],
                        ['fa-location-dot','#d97706', 'Walk-In Welcome', 'Visit our showroom'],
                        ['fa-headset',     '#16a34a', 'Expert Advice',   'Our team is ready to help'],
                    ] as [$ico, $color, $title, $sub]): ?>
                    <div style="background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.09);border-radius:16px;padding:20px 18px">
                        <div style="width:38px;height:38px;border-radius:10px;background:<?= $color ?>22;display:flex;align-items:center;justify-content:center;margin-bottom:12px">
                            <i class="fa <?= $ico ?>" style="font-size:16px;color:<?= $color ?>"></i>
                        </div>
                        <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:3px"><?= $title ?></div>
                        <div style="font-size:12px;color:rgba(255,255,255,.38)"><?= $sub ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════════ -->
<section style="background:#f8fafc;padding:64px 0 80px">
    <div class="container-xl">
        <div class="row g-5">

            <!-- LEFT: Contact info cards -->
            <div class="col-lg-5">

                <!-- Contact details -->
                <div style="margin-bottom:24px">
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#2563eb;margin-bottom:10px">Get In Touch</div>
                    <h2 style="font-size:clamp(22px,3vw,32px);font-weight:900;color:#0f172a;letter-spacing:-.8px;margin:0 0 6px">Our Contact Information</h2>
                    <p style="font-size:15px;color:#64748b;margin:0 0 28px;line-height:1.6">Reach us through any of the channels below. We always respond promptly.</p>
                </div>

                <?php
                $contactCards = [];
                if ($__companyPhone) {
                    $contactCards[] = ['fa-phone', '#2563eb', '#eff6ff', 'Phone', $__companyPhone,
                        'tel:' . e($__companyPhone), 'Call us now', null];
                }
                if ($__companyEmail) {
                    $contactCards[] = ['fa-envelope', '#7c3aed', '#f3e8ff', 'Email', $__companyEmail,
                        'mailto:' . e($__companyEmail), 'Send an email', null];
                }
                if ($__waClean) {
                    $contactCards[] = ['fa-brands fa-whatsapp', '#16a34a', '#f0fdf4', 'WhatsApp',
                        preg_replace('/[^0-9]/', '', $__waClean),
                        'https://wa.me/' . $__waClean, 'Chat now', '_blank'];
                }
                if ($__address) {
                    $contactCards[] = ['fa-location-dot', '#d97706', '#fff7ed', 'Address', $__address, null, null, null];
                }
                ?>

                <?php foreach ($contactCards as [$ico, $color, $bg, $label, $value, $href, $cta, $target]): ?>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:22px 24px;margin-bottom:14px;display:flex;align-items:center;gap:18px;box-shadow:0 2px 10px rgba(0,0,0,.04);transition:box-shadow .2s,transform .2s"
                     onmouseover="this.style.boxShadow='0 8px 28px rgba(0,0,0,.08)';this.style.transform='translateY(-2px)'"
                     onmouseout="this.style.boxShadow='0 2px 10px rgba(0,0,0,.04)';this.style.transform=''">
                    <div style="width:50px;height:50px;border-radius:14px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa <?= $ico ?>" style="font-size:20px;color:<?= $color ?>"></i>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#94a3b8;margin-bottom:3px"><?= $label ?></div>
                        <div style="font-size:15px;font-weight:700;color:#0f172a;overflow-wrap:anywhere"><?= e($value) ?></div>
                    </div>
                    <?php if ($href && $cta): ?>
                    <a href="<?= $href ?>" <?= $target ? "target=\"{$target}\" rel=\"noopener\"" : '' ?>
                       style="flex-shrink:0;background:<?= $color ?>;color:#fff;border-radius:9px;padding:8px 14px;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;transition:opacity .15s"
                       onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                        <?= $cta ?>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <!-- Working hours -->
                <div style="background:#0f172a;border-radius:18px;padding:24px;margin-top:8px">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
                        <div style="width:38px;height:38px;border-radius:10px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center">
                            <i class="fa fa-clock" style="color:#60a5fa;font-size:16px"></i>
                        </div>
                        <div style="font-size:15px;font-weight:800;color:#fff">Working Hours</div>
                    </div>
                    <?php foreach ([
                        ['Monday – Friday', '8:00 AM – 6:00 PM', true],
                        ['Saturday',        '8:00 AM – 4:00 PM', true],
                        ['Sunday',          'Closed',             false],
                    ] as [$day, $hours, $open]): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)">
                        <span style="font-size:14px;color:rgba(255,255,255,.55);font-weight:500"><?= $day ?></span>
                        <span style="font-size:14px;font-weight:700;color:<?= $open ? '#22c55e' : '#ef4444' ?>"><?= $hours ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:16px;font-size:12.5px;color:rgba(255,255,255,.3);line-height:1.5">
                        <i class="fa fa-circle-info me-1"></i>Public holidays may affect opening times. WhatsApp us to confirm.
                    </div>
                </div>

            </div>

            <!-- RIGHT: Contact form -->
            <div class="col-lg-7">

                <?php if ($success): ?>
                <!-- Success message -->
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:52px 40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.06)">
                    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;box-shadow:0 6px 24px rgba(34,197,94,.28)">
                        <i class="fa fa-paper-plane" style="font-size:28px;color:#fff"></i>
                    </div>
                    <h3 style="font-size:24px;font-weight:900;color:#0f172a;letter-spacing:-.5px;margin:0 0 10px">Message Sent!</h3>
                    <p style="font-size:15px;color:#64748b;line-height:1.65;margin:0 0 28px;max-width:380px;margin-left:auto;margin-right:auto">
                        Thank you, <strong style="color:#0f172a"><?= e($d['name']) ?></strong>! We've received your message and will get back to you as soon as possible.
                    </p>
                    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                        <a href="<?= BASE_URL ?>/showroom/"
                           style="background:#0f172a;color:#fff;border-radius:12px;padding:13px 24px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:background .15s"
                           onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">
                            <i class="fa fa-arrow-left"></i>Back to Showroom
                        </a>
                        <?php if ($__waClean): ?>
                        <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                           style="background:#25d366;color:#fff;border-radius:12px;padding:13px 24px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
                            <i class="fa-brands fa-whatsapp fa-lg"></i>Chat on WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>
                <!-- Contact form -->
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 4px 24px rgba(0,0,0,.06);overflow:hidden">
                    <div style="padding:28px 32px;border-bottom:1px solid #f1f5f9">
                        <h2 style="font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.5px;margin:0 0 4px">
                            <i class="fa fa-paper-plane me-2" style="color:#2563eb;font-size:.85em"></i>Send Us a Message
                        </h2>
                        <p style="font-size:13.5px;color:#94a3b8;margin:0">We'll respond within one business day.</p>
                    </div>

                    <?php if ($errors): ?>
                    <div style="margin:20px 32px 0;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:14px 18px">
                        <?php foreach ($errors as $err): ?>
                        <div style="display:flex;align-items:center;gap:8px;font-size:13.5px;color:#dc2626;font-weight:600;margin-bottom:4px">
                            <i class="fa fa-circle-exclamation flex-shrink-0"></i><?= e($err) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" style="padding:28px 32px;display:grid;gap:20px">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
                            <div>
                                <label class="ct-label">Full Name <span style="color:#dc2626">*</span></label>
                                <input type="text" name="name" class="ct-input" required
                                       value="<?= e($d['name']) ?>" placeholder="Your full name">
                            </div>
                            <div>
                                <label class="ct-label">Phone Number</label>
                                <input type="tel" name="phone" class="ct-input"
                                       value="<?= e($d['phone']) ?>" placeholder="e.g. 0712 345 678">
                            </div>
                        </div>

                        <div>
                            <label class="ct-label">Email Address</label>
                            <input type="email" name="email" class="ct-input"
                                   value="<?= e($d['email']) ?>" placeholder="your@email.com">
                        </div>

                        <div>
                            <label class="ct-label">Subject</label>
                            <select name="subject" class="ct-input">
                                <option value="" <?= !$d['subject'] ? 'selected' : '' ?>>— Select a topic —</option>
                                <?php foreach ([
                                    'Vehicle Inquiry',
                                    'Service Booking',
                                    'Financing / Payment Plans',
                                    'Trade-In Valuation',
                                    'General Inquiry',
                                    'Complaint / Feedback',
                                ] as $opt): ?>
                                <option value="<?= $opt ?>" <?= $d['subject'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="ct-label">Message <span style="color:#dc2626">*</span></label>
                            <textarea name="message" rows="5" class="ct-input" required
                                      placeholder="Tell us how we can help you…"><?= e($d['message']) ?></textarea>
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
                            <p style="font-size:12px;color:#94a3b8;margin:0;line-height:1.55;max-width:280px">
                                <i class="fa fa-shield-halved me-1"></i>Your contact information is kept private and used only to respond to your inquiry.
                            </p>
                            <button type="submit"
                                    style="background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;border-radius:12px;padding:13px 32px;font-size:15px;font-weight:800;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:9px;transition:box-shadow .2s,transform .1s;letter-spacing:-.2px;white-space:nowrap"
                                    onmouseover="this.style.boxShadow='0 6px 24px rgba(37,99,235,.45)';this.style.transform='translateY(-1px)'"
                                    onmouseout="this.style.boxShadow='none';this.style.transform=''">
                                <i class="fa fa-paper-plane"></i>Send Message
                            </button>
                        </div>

                    </form>
                </div>
                <?php endif; ?>

                <!-- Book a service CTA below form -->
                <div style="background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:18px;padding:24px 28px;margin-top:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
                    <div>
                        <div style="font-size:16px;font-weight:800;color:#fff;letter-spacing:-.3px;margin-bottom:4px">Need a Workshop Service?</div>
                        <div style="font-size:13.5px;color:rgba(255,255,255,.65)">Book your vehicle service online in minutes.</div>
                    </div>
                    <a href="<?= BASE_URL ?>/showroom/book-service.php"
                       style="background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.3);border-radius:11px;padding:11px 22px;font-size:14px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.25)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
                        <i class="fa fa-calendar-check"></i>Book a Service
                    </a>
                </div>

            </div>
        </div>
    </div>
</section>

<style>
.ct-label { display:block; font-size:11.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:7px; }
.ct-input { width:100%; border:1.5px solid #e2e8f0; border-radius:10px; padding:11px 14px; font-size:14px; font-family:inherit; outline:none; color:#0f172a; background:#fff; transition:border-color .15s, box-shadow .15s; }
.ct-input:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
select.ct-input { cursor:pointer; }
textarea.ct-input { resize:vertical; min-height:120px; }
@media (max-width:576px) {
    form > div:first-child { grid-template-columns:1fr !important; }
}
</style>

<?php include __DIR__ . '/footer.php'; ?>
