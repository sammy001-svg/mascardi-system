</div><!-- /cp-main -->
<div style="text-align:center;padding:24px 0 40px;font-size:12px;color:#94a3b8">
    <?= e(getSetting('company_name','Mascardi System')) ?> &nbsp;·&nbsp;
    <?= e(getSetting('company_phone','')) ?> &nbsp;·&nbsp;
    <?= e(getSetting('company_email','')) ?> &nbsp;·&nbsp;
    Client Portal v<?= APP_VERSION ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
</body>
</html>
