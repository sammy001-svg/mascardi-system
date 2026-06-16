<?php
/**
 * Focused sidebar rendered for customer_relations role.
 * Included from sidebar.php via early-exit pattern.
 */
$__uri    = $_SERVER['REQUEST_URI'];
$__isDash = str_contains($__uri, '/modules/crm/my_dashboard');
?>
<div class="app-sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo">
            <?php $__logo = getSetting('company_logo', ''); ?>
            <?php if ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo)): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>" alt="Logo"
                 style="height:32px;width:32px;object-fit:contain;border-radius:4px">
            <?php else: ?>
            <i class="fa fa-car-side" style="font-size:16px"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub">Customer Relations</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- ══ MY WORKSPACE ══════════════════════════════════════ -->
        <div class="nav-section">My Workspace</div>

        <a href="<?= BASE_URL ?>/modules/crm/my_dashboard.php"
           class="nav-item <?= $__isDash ? 'active' : '' ?>"
           data-label="My Dashboard">
            <i class="fa fa-gauge-high"></i><span>My Dashboard</span>
        </a>

        <!-- ══ CLIENT RELATIONS ═══════════════════════════════════ -->
        <div class="nav-section">Client Relations</div>

        <a href="<?= BASE_URL ?>/modules/clients/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/clients/') ? 'active' : '' ?>"
           data-label="Clients">
            <i class="fa fa-users"></i><span>Clients</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/leads.php"
           class="nav-item <?= (str_contains($__uri,'/modules/crm/leads') || str_contains($__uri,'/modules/crm/view_lead') || str_contains($__uri,'/modules/crm/add_lead')) ? 'active' : '' ?>"
           data-label="My Leads"
           style="position:relative">
            <i class="fa fa-user-plus"></i><span>My Leads</span>
            <?php
            try {
                $__uid = (int)(authUser()['id'] ?? 0);
                $__myLeadCount = (int)getDB()->query("SELECT COUNT(*) FROM crm_leads WHERE assigned_to={$__uid} AND stage NOT IN ('lost','delivered')")->fetchColumn();
                if ($__myLeadCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__myLeadCount > 99 ? '99+' : $__myLeadCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/index') ? 'active' : '' ?>"
           data-label="Sales Pipeline"
           style="position:relative">
            <i class="fa fa-filter"></i><span>Sales Pipeline</span>
            <?php
            try {
                $__uid = (int)(authUser()['id'] ?? 0);
                $__overdueCount = (int)getDB()->query("SELECT COUNT(*) FROM crm_leads WHERE assigned_to={$__uid} AND follow_up_date < CURDATE() AND stage NOT IN ('lost','delivered')")->fetchColumn();
                if ($__overdueCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#dc2626;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px"
                  title="Overdue follow-ups">
                <?= $__overdueCount > 99 ? '99+' : $__overdueCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <!-- ══ COMMUNICATION ══════════════════════════════════════ -->
        <div class="nav-section">Communication</div>

        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/chat/') ? 'active' : '' ?>"
           data-label="Team Chat"
           style="position:relative">
            <i class="fa fa-comments"></i><span>Team Chat</span>
            <span id="chatNavBadge" style="display:none;position:absolute;top:6px;right:8px;
                  background:#25d366;color:#fff;border-radius:10px;font-size:10px;
                  font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px"></span>
        </a>
        <script>
        (function(){
            var badge = document.getElementById('chatNavBadge');
            if (!badge) return;
            function poll(){
                fetch('<?= BASE_URL ?>/modules/chat/api/unread.php')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        var n = d.count || 0;
                        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                        else { badge.style.display = 'none'; }
                    }).catch(function(){});
            }
            poll();
            setInterval(poll, 15000);
        }());
        </script>

    </nav>

    <div class="sidebar-footer">
        <small class="text-muted" style="font-size:10.5px">v<?= APP_VERSION ?></small>
    </div>
</div>
