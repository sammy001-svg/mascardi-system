<!-- ── Footer ────────────────────────────────────────────── -->
<footer id="contact" style="background:var(--navy);color:rgba(255,255,255,.6);padding:72px 0 0">
    <div class="container-xl">
        <div class="row g-5 mb-5">

            <!-- Brand column -->
            <div class="col-lg-4">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
                    <?php if ($__logoSrc): ?>
                    <img src="<?= htmlspecialchars($__logoSrc) ?>" width="44" height="44"
                         style="border-radius:10px;object-fit:contain" alt="">
                    <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px">
                        <i class="fa fa-car-side"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:18px;font-weight:800;color:#fff;letter-spacing:-.3px"><?= htmlspecialchars($__companyName) ?></div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);font-weight:500">Official Car Showroom</div>
                    </div>
                </div>
                <p style="line-height:1.75;font-size:14px;margin:0 0 24px">
                    Your trusted destination for quality imported vehicles. We offer transparent pricing, flexible financing, and an unmatched selection of cars for every lifestyle.
                </p>
                <!-- Social / contact icons -->
                <div style="display:flex;gap:10px">
                    <?php if ($__waClean): ?>
                    <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                       style="width:40px;height:40px;border-radius:10px;background:#25d366;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;text-decoration:none;transition:transform .15s"
                       onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyPhone): ?>
                    <a href="tel:<?= htmlspecialchars($__companyPhone) ?>"
                       style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);font-size:16px;text-decoration:none;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-phone"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>"
                       style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);font-size:16px;text-decoration:none;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-envelope"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick links -->
            <div class="col-sm-6 col-lg-2">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Quick Links</div>
                <?php foreach ([
                    ['Home',        BASE_URL . '/showroom/'],
                    ['All Cars',    BASE_URL . '/showroom/#inventory'],
                    ['Categories',  BASE_URL . '/showroom/#categories'],
                    ['About Us',    BASE_URL . '/showroom/#why-us'],
                    ['Contact',     BASE_URL . '/showroom/#contact'],
                    ['Staff Login', BASE_URL . '/login.php'],
                ] as [$lbl, $url]): ?>
                <div style="margin-bottom:10px">
                    <a href="<?= $url ?>" style="color:rgba(255,255,255,.55);font-size:14px;font-weight:500;text-decoration:none;transition:color .15s"
                       onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.55)'">
                        <?= $lbl ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Contact info -->
            <div class="col-sm-6 col-lg-3">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Contact Us</div>
                <div style="display:flex;flex-direction:column;gap:14px;font-size:14px">
                    <?php if ($__companyPhone): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-phone" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Phone</div>
                            <a href="tel:<?= htmlspecialchars($__companyPhone) ?>" style="color:rgba(255,255,255,.8);font-weight:600;text-decoration:none"><?= htmlspecialchars($__companyPhone) ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-envelope" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Email</div>
                            <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>" style="color:rgba(255,255,255,.8);font-weight:600;text-decoration:none"><?= htmlspecialchars($__companyEmail) ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($__address): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0;margin-top:2px">
                            <i class="fa fa-location-dot" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Location</div>
                            <div style="color:rgba(255,255,255,.8);font-weight:500;line-height:1.5"><?= htmlspecialchars($__address) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-clock" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Working Hours</div>
                            <div style="color:rgba(255,255,255,.8);font-weight:500">Mon – Sat: 8:00 AM – 6:00 PM</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- WhatsApp CTA column -->
            <div class="col-lg-3">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Get In Touch</div>
                <?php if ($__waClean): ?>
                <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                   style="display:flex;align-items:center;gap:12px;background:#25d366;border-radius:14px;padding:18px 20px;text-decoration:none;margin-bottom:12px;transition:transform .15s,box-shadow .15s;box-shadow:0 4px 20px rgba(37,211,102,.25)"
                   onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 28px rgba(37,211,102,.35)'"
                   onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 20px rgba(37,211,102,.25)'">
                    <i class="fa-brands fa-whatsapp" style="font-size:28px;color:#fff"></i>
                    <div>
                        <div style="font-weight:800;color:#fff;font-size:14px">Chat on WhatsApp</div>
                        <div style="color:rgba(255,255,255,.75);font-size:12px">Instant response</div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if ($__companyPhone): ?>
                <a href="tel:<?= htmlspecialchars($__companyPhone) ?>"
                   style="display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px 20px;text-decoration:none;transition:background .15s"
                   onmouseover="this.style.background='rgba(255,255,255,.1)'" onmouseout="this.style.background='rgba(255,255,255,.06)'">
                    <i class="fa fa-phone" style="font-size:20px;color:#60a5fa"></i>
                    <div>
                        <div style="font-weight:700;color:#fff;font-size:14px"><?= htmlspecialchars($__companyPhone) ?></div>
                        <div style="color:rgba(255,255,255,.45);font-size:12px">Call us anytime</div>
                    </div>
                </a>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Bottom bar -->
    <div style="border-top:1px solid rgba(255,255,255,.06);padding:20px 0;margin-top:0">
        <div class="container-xl d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div style="font-size:13px;color:rgba(255,255,255,.3)">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($__companyName) ?>. All rights reserved.
            </div>
            <div style="font-size:12px;color:rgba(255,255,255,.2)">
                Powered by Mascardi System
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
