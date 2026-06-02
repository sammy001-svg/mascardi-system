</div><!-- /container-lg -->
</div><!-- /showroom-body -->

<!-- Footer -->
<footer style="background:#0f172a;color:rgba(255,255,255,.5);padding:32px 0;font-size:13px;margin-top:auto">
    <div class="container-lg">
        <div class="row g-4 align-items-center">
            <div class="col-md-6">
                <div style="font-weight:800;font-size:16px;color:#fff;margin-bottom:6px">
                    <?= htmlspecialchars($__companyName) ?>
                </div>
                <div>Quality vehicles, trusted service.</div>
                <?php if ($__companyPhone): ?>
                <div class="mt-2">
                    <a href="tel:<?= htmlspecialchars($__companyPhone) ?>" style="color:rgba(255,255,255,.7)">
                        <i class="fa fa-phone me-1"></i><?= htmlspecialchars($__companyPhone) ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($__companyEmail): ?>
                <div class="mt-1">
                    <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>" style="color:rgba(255,255,255,.7)">
                        <i class="fa fa-envelope me-1"></i><?= htmlspecialchars($__companyEmail) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 text-md-end">
                <?php if ($__whatsapp): ?>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $__whatsapp) ?>" target="_blank" rel="noopener"
                   style="background:#25d366;color:#fff;padding:10px 20px;border-radius:8px;font-weight:700;display:inline-flex;align-items:center;gap:8px;text-decoration:none;margin-bottom:12px">
                    <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
                </a>
                <?php endif; ?>
                <div class="mt-2" style="font-size:11.5px">
                    &copy; <?= date('Y') ?> <?= htmlspecialchars($__companyName) ?>. All rights reserved.
                </div>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
