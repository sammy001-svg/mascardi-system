<?php
/**
 * Super Admin sidebar — loaded by sidebar.php via early-exit when authRole() === 'admin'.
 * isActive() and $__uri are already defined in sidebar.php before this file is included.
 */
$__isWorkshop = str_contains($__uri, '/modules/admin/workshop');
$__isSales    = str_contains($__uri, '/modules/admin/sales');
?>
<div class="app-sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo">
            <?php $__logo = companyLogo(); ?>
            <?php if ($__logo['exists']): ?>
            <img src="<?= e($__logo['url']) ?>" alt="Logo"
                 style="height:32px;width:32px;object-fit:contain;border-radius:4px">
            <?php else: ?>
            <i class="fa fa-car-side" style="font-size:16px"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub" style="color:#60a5fa;font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase">Admin Portal</span>
        </div>
    </div>

    <!-- Navigation -->
    <?php /* The collapsible grouping in header.php is for the long staff menu
             (Fleet, Operations, Finance, …). This portal is a short, deliberately
             focused list whose links all sit under a heading, so collapsing them
             left nothing on screen at all. Opted out — every link stays visible. */ ?>
    <nav class="sidebar-nav" data-accordion="off">

        <!-- ══ DASHBOARDS ═══════════════════════════════════════════════ -->
        <div class="nav-section">Dashboards</div>

        <a href="<?= BASE_URL ?>/modules/admin/workshop_dashboard.php"
           class="nav-item <?= $__isWorkshop ? 'active' : '' ?>"
           data-label="Workshop Dashboard">
            <i class="fa fa-screwdriver-wrench" style="<?= $__isWorkshop ? '' : 'color:#f59e0b' ?>"></i>
            <span>Workshop Dashboard</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/admin/sales_dashboard.php"
           class="nav-item <?= $__isSales ? 'active' : '' ?>"
           data-label="Sales Dashboard">
            <i class="fa fa-chart-line" style="<?= $__isSales ? '' : 'color:#10b981' ?>"></i>
            <span>Sales Dashboard</span>
        </a>

        <!-- ══ MANAGEMENT ════════════════════════════════════════════════ -->
        <div class="nav-section">Management</div>

        <a href="<?= BASE_URL ?>/modules/cars/index.php"
           class="nav-item <?= isActive('/modules/cars/') ?>"
           data-label="All Cars">
            <i class="fa fa-car"></i><span>All Cars</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/reservations/index.php"
           class="nav-item <?= isActive('/modules/reservations/') ?>"
           data-label="Reservations"
           style="position:relative">
            <i class="fa fa-bookmark"></i><span>Reservations</span>
            <?php
            try {
                $__resCount = (int)getDB()->query("SELECT COUNT(*) FROM crm_leads WHERE stage='reserved'")->fetchColumn();
                if ($__resCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#7c3aed;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__resCount > 99 ? '99+' : $__resCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/delivered_cars/index.php"
           class="nav-item <?= isActive('/modules/delivered_cars/') ?>"
           data-label="Delivered Cars">
            <i class="fa fa-truck"></i><span>Delivered Cars</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/trade_in/index.php"
           class="nav-item <?= isActive('/modules/trade_in/') ?>"
           data-label="Trade-In &amp; Sale on Behalf"
           style="position:relative">
            <i class="fa fa-handshake"></i><span>Trade-In &amp; Sale on Behalf</span>
            <?php
            try {
                $__consignCount = (int)getDB()->query("SELECT COUNT(*) FROM consignments WHERE status='active'")->fetchColumn();
                if ($__consignCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#0ea5e9;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__consignCount > 99 ? '99+' : $__consignCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/index.php"
           class="nav-item <?= isActive('/modules/crm/') ?>"
           data-label="Sales Pipeline">
            <i class="fa fa-filter-circle-dollar"></i><span>Sales Pipeline</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/quotations/index.php"
           class="nav-item <?= isActive('/modules/quotations/') ?>"
           data-label="Quotations">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/invoices/index.php"
           class="nav-item <?= isActive('/modules/invoices/') ?>"
           data-label="Invoices">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/payments/index.php"
           class="nav-item <?= isActive('/modules/payments/') ?>"
           data-label="Payments">
            <i class="fa fa-money-bill-wave"></i><span>Payments</span>
        </a>

        <!-- ══ COMMUNICATION ══════════════════════════════════════════════ -->
        <div class="nav-section">Communication</div>

        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= isActive('/modules/chat/') ?>"
           data-label="Team Chat">
            <i class="fa fa-comments"></i><span>Team Chat</span>
        </a>

        <?php // Meetings sits with the other ways people talk to each other.
              // Universal — see universalModules() in includes/auth.php. ?>
        <a href="<?= BASE_URL ?>/modules/meetings/index.php"
           class="nav-item <?= isActive('/modules/meetings/index') ?>"
           data-label="Meetings">
            <i class="fa fa-handshake-angle"></i><span>Meetings</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/meetings/actions.php"
           class="nav-item <?= isActive('/modules/meetings/actions') ?>"
           data-label="My Deliverables"
           style="position:relative">
            <i class="fa fa-list-check"></i><span>My Deliverables</span>
            <?php
            try {
                $__mtDue = getDB()->prepare("SELECT COUNT(*) FROM meeting_actions
                    WHERE assigned_to = ? AND status IN ('pending','in_progress','blocked')");
                $__mtDue->execute([(int)(authUser()['id'] ?? 0)]);
                $__mtDue = (int)$__mtDue->fetchColumn();
                if ($__mtDue > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__mtDue > 99 ? '99+' : $__mtDue ?>
            </span>
            <?php endif; } catch (\Throwable $_) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/whatsapp/index.php"
           class="nav-item <?= isActive('/modules/whatsapp/') ?>"
           data-label="WA Inbox" style="position:relative">
            <i class="fab fa-whatsapp"></i><span>WA Inbox</span>
            <span id="waNavBadge"
                  style="position:absolute;right:12px;top:50%;transform:translateY(-50%);
                         background:#25d366;color:#fff;border-radius:50%;font-size:10px;
                         font-weight:700;min-width:18px;height:18px;line-height:18px;
                         text-align:center;padding:0 3px;display:none"></span>
        </a>

        <!-- ══ ADMINISTRATION ═════════════════════════════════════════════ -->
        <div class="nav-section">Administration</div>

        <a href="<?= BASE_URL ?>/modules/users/index.php"
           class="nav-item <?= isActive('/modules/users/') ?>"
           data-label="Users">
            <i class="fa fa-users-gear"></i><span>Users</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/settings/index.php"
           class="nav-item <?= isActive('/modules/settings/') ?>"
           data-label="Settings">
            <i class="fa fa-gear"></i><span>Settings</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/audit/index.php"
           class="nav-item <?= isActive('/modules/audit/') ?>"
           data-label="Audit Logs">
            <i class="fa fa-clipboard-list"></i><span>Audit Logs</span>
        </a>

    </nav>

    <script>
    (function(){
        var badge = document.getElementById('waNavBadge');
        if (!badge) return;
        function poll(){
            fetch('<?= BASE_URL ?>/modules/whatsapp/api/unread.php')
                .then(function(r){ return r.json(); })
                .then(function(d){
                    var n = d.count || 0;
                    if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                    else { badge.style.display = 'none'; }
                }).catch(function(){});
        }
        poll(); setInterval(poll, 20000);
    }());
    </script>
</div>
