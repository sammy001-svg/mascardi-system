<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(getSetting('company_name', APP_NAME)) ?></title>

<!-- PWA — Web App Manifest -->
<link rel="manifest" href="<?= BASE_URL ?>/manifest.php">

<!-- PWA — Theme & status bar -->
<meta name="theme-color" content="#2563eb" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1e3a8a" media="(prefers-color-scheme: dark)">
<meta name="color-scheme" content="light dark">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="<?= e(getSetting('company_name', APP_NAME)) ?>">

<!-- PWA — Apple / iOS -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(getSetting('company_name', APP_NAME)) ?>">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">

<!-- PWA — Microsoft (Edge/Windows) -->
<meta name="msapplication-TileColor" content="#2563eb">
<meta name="msapplication-tap-highlight" content="no">

<!-- SEO / sharing -->
<meta name="description" content="Car Yard Management — fleet, workshop, sales and finance.">
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<link rel="shortcut icon" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome 6 -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<!-- Custom — cache-busted so changes always load -->
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(BASE_PATH . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
<meta name="csrf-token" content="<?= csrfToken() ?>">
</head>
<body>
<?php requireLogin(); $__user = authUser(); ?>

<!-- ── App Shell ────────────────────────────────────────── -->
<div id="appShell" style="display:flex;min-height:100vh">

<?php include __DIR__ . '/sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main-wrap" style="flex:1;min-width:0;display:flex;flex-direction:column;background:#edf0f7">

<!-- ── Top Navbar ─────────────────────────────────────── -->
<header class="app-topbar">
    <div class="topbar-left">
        <button class="toggle-btn" id="sidebarToggle" title="Toggle menu">
            <i class="fa fa-bars"></i>
        </button>
        <span class="topbar-title"><?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?></span>
    </div>

    <div class="topbar-right">
        <?php
        require_once __DIR__ . '/notifications.php';
        $__notifCount = getUnreadNotificationCount((int)$__user['id']);
        try { $__ls = getDashboardStats()['low_stock']; } catch (Exception $e) { $__ls = 0; }
        if ($__ls > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock"
           class="topbar-alert d-none d-md-flex">
            <i class="fa fa-triangle-exclamation"></i> <?= $__ls ?> low stock
        </a>
        <?php endif; ?>

        <span class="topbar-date d-none d-lg-inline"><?= date('d M Y') ?></span>

        <!-- Notifications bell -->
        <div class="dropdown">
            <button type="button" class="topbar-icon-btn position-relative" id="notifBell"
                    data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                <i class="fa fa-bell"></i>
                <span class="notif-badge" id="notifBadge"
                      style="<?= $__notifCount > 0 ? '' : 'display:none' ?>">
                    <?= $__notifCount > 99 ? '99+' : $__notifCount ?>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-panel p-0"
                 aria-labelledby="notifBell" style="width:360px;max-height:500px;overflow-y:auto">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-white sticky-top">
                    <span class="fw-semibold" style="font-size:13px">Notifications</span>
                    <button class="btn btn-xs btn-outline-secondary" id="markAllRead">Mark all read</button>
                </div>
                <div id="notifList">
                    <div class="notif-empty"><i class="fa fa-bell-slash fa-lg mb-2 d-block"></i>No notifications yet</div>
                </div>
                <div class="sticky-bottom bg-white border-top text-center py-2">
                    <a href="<?= BASE_URL ?>/modules/notifications/index.php"
                       class="text-primary small fw-medium text-decoration-none">
                        View all notifications <i class="fa fa-arrow-right ms-1" style="font-size:10px"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Network status indicator -->
        <span id="networkStatusDot" class="network-dot network-online" title="Online"></span>

        <!-- Dark mode toggle -->
        <button class="topbar-icon-btn" id="themeToggle" title="Toggle dark mode">
            <i class="fa fa-moon" id="themeIcon"></i>
        </button>

        <!-- User dropdown -->
        <div class="dropdown">
            <button type="button" class="topbar-user dropdown-toggle"
                    id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="topbar-avatar"><?= strtoupper(substr($__user['name'], 0, 1)) ?></div>
                <div class="d-none d-md-block">
                    <div class="topbar-username"><?= e($__user['name']) ?></div>
                    <div class="topbar-userrole"><?= ucwords(str_replace('_',' ',e($__user['role']))) ?></div>
                </div>
                <i class="fa fa-chevron-down topbar-chevron d-none d-md-inline ms-1"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end topbar-dropdown" aria-labelledby="userDropdown">
                <li>
                    <div class="dd-user-info">
                        <div class="dd-user-name"><?= e($__user['name']) ?></div>
                        <div class="dd-user-role"><?= ucwords(str_replace('_',' ',e($__user['role']))) ?></div>
                    </div>
                </li>
                <?php if (hasRole('admin')): ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="fa fa-user-circle"></i>My Profile
                </a></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/settings/index.php">
                    <i class="fa fa-gear"></i>System Settings
                </a></li>
                <?php else: ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="fa fa-user-circle"></i>My Profile
                </a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item item-danger" href="<?= BASE_URL ?>/logout.php">
                    <i class="fa fa-right-from-bracket"></i>Sign Out
                </a></li>
            </ul>
        </div>
    </div>
</header>

<!-- ── Notification JS ──────────────────────────────── -->
<script>
(function () {
    var apiUrl   = '<?= BASE_URL ?>/modules/notifications/api.php';
    var pollInt  = null;

    var typeColors = {
        booking:   '#2563eb', payment: '#16a34a', low_stock: '#d97706',
        issue:     '#dc2626', lpo:     '#0284c7', job:       '#9333ea',
        sale:      '#0f172a', info:    '#64748b'
    };
    var typeIcons = {
        booking: 'fa-calendar-check', payment: 'fa-money-bill-wave',
        low_stock: 'fa-boxes-stacked', issue: 'fa-triangle-exclamation',
        lpo: 'fa-truck', job: 'fa-toolbox', sale: 'fa-tag', info: 'fa-info-circle'
    };

    function setBadge(n) {
        var badge = document.getElementById('notifBadge');
        if (!badge) return;
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : n;
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    function renderList(notifications, unread) {
        var list = document.getElementById('notifList');
        if (!list) return;
        if (!notifications || !notifications.length) {
            list.innerHTML = '<div class="notif-empty"><i class="fa fa-bell-slash fa-lg mb-2 d-block"></i>No notifications yet</div>';
            return;
        }
        var html = '';
        notifications.forEach(function (n) {
            var icon  = typeIcons[n.type]   || 'fa-info-circle';
            var color = typeColors[n.type]  || '#64748b';
            var href  = n.link ? n.link : '#';
            html += '<a class="notif-item' + (n.is_read == 0 ? ' unread' : '') + '"'
                  + ' href="' + href + '"'
                  + ' data-id="' + n.id + '">'
                  + '<div class="notif-icon" style="color:' + color + ';background:' + color + '18">'
                  + '<i class="fa ' + icon + '"></i></div>'
                  + '<div class="notif-body">'
                  + '<div class="notif-title">' + escHtml(n.title) + '</div>'
                  + (n.message ? '<div class="notif-msg">' + escHtml(n.message) + '</div>' : '')
                  + '</div>'
                  + '<div class="notif-ago">' + (n.ago || '') + '</div>'
                  + '</a>';
        });
        list.innerHTML = html;
        setBadge(unread);
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function fetchNotifs() {
        fetch(apiUrl + '?action=list')
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d.notifications) renderList(d.notifications, d.count); })
            .catch(function(){});
    }

    function updateCount() {
        fetch(apiUrl + '?action=count')
            .then(function(r){ return r.json(); })
            .then(function(d){ if (typeof d.count !== 'undefined') setBadge(d.count); })
            .catch(function(){});
    }

    // Load list when dropdown opens
    document.getElementById('notifBell') && document.getElementById('notifBell').addEventListener('show.bs.dropdown', fetchNotifs);

    // Mark individual read on click
    document.addEventListener('click', function(e) {
        var item = e.target.closest && e.target.closest('.notif-item');
        if (!item || !item.dataset.id) return;
        var id = item.dataset.id;
        fetch(apiUrl + '?action=mark_read', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+id })
            .then(function(r){ return r.json(); })
            .then(function(d){ item.classList.remove('unread'); setBadge(d.count); })
            .catch(function(){});
    });

    // Mark all read
    document.getElementById('markAllRead') && document.getElementById('markAllRead').addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        fetch(apiUrl + '?action=mark_all_read', { method:'POST' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                document.querySelectorAll('.notif-item.unread').forEach(function(el){ el.classList.remove('unread'); });
                setBadge(0);
            })
            .catch(function(){});
    });

    // Poll every 60 s for new notifications
    pollInt = setInterval(updateCount, 60000);
}());
</script>

<!-- ── Flash ─────────────────────────────────────────── -->
<?php $__flash = getFlash(); if ($__flash): ?>
<div class="alert alert-<?= $__flash['type'] === 'error' ? 'danger' : $__flash['type'] ?> alert-dismissible fade show mx-4 mt-3 mb-0" role="alert" style="border-radius:10px;border:none">
    <i class="fa fa-<?= $__flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
    <?= e($__flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Page Content ───────────────────────────────────── -->
<div class="page-body">
