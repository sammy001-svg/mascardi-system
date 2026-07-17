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

        <a href="<?= BASE_URL ?>/modules/crm/test_drives.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/test_drives') ? 'active' : '' ?>"
           data-label="Test Drives"
           style="position:relative">
            <i class="fa fa-car-side"></i><span>Test Drives</span>
            <?php
            try {
                $__uid = (int)(authUser()['id'] ?? 0);
                $__tdToday = (int)getDB()->query("SELECT COUNT(*) FROM crm_test_drives td JOIN crm_leads l ON l.id=td.lead_id WHERE td.scheduled_date=CURDATE() AND td.status='scheduled' AND l.assigned_to={$__uid}")->fetchColumn();
                if ($__tdToday > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__tdToday ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/wa_templates.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/wa_templates') ? 'active' : '' ?>"
           data-label="WA Templates">
            <i class="fab fa-whatsapp"></i><span>WA Templates</span>
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

        <?php if (canWrite('crm')): ?>
        <a href="<?= BASE_URL ?>/modules/crm/import_leads.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/import_leads') ? 'active' : '' ?>"
           data-label="Import Leads">
            <i class="fa fa-file-import"></i><span>Import Leads</span>
        </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/modules/crm/my_tasks.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/my_tasks') ? 'active' : '' ?>"
           data-label="My Tasks"
           style="position:relative">
            <i class="fa fa-list-check"></i><span>My Tasks</span>
            <span id="tasksNavBadge" style="display:none;position:absolute;top:6px;right:8px;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;
                  min-width:16px;text-align:center;line-height:16px;color:#fff"></span>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/reports.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/reports') ? 'active' : '' ?>"
           data-label="My Reports">
            <i class="fa fa-chart-line"></i><span>My Reports</span>
        </a>
        <script>
        (function(){
            var badge = document.getElementById('tasksNavBadge');
            if (!badge) return;
            function poll(){
                fetch('<?= BASE_URL ?>/modules/crm/api/check_followups.php')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        var n = d.count || 0;
                        if (n > 0) {
                            badge.textContent = n > 99 ? '99+' : n;
                            badge.style.background = d.overdue > 0 ? '#dc2626' : '#f59e0b';
                            badge.style.display = '';
                        } else {
                            badge.style.display = 'none';
                        }
                    }).catch(function(){});
            }
            poll();
            setInterval(poll, 120000); // every 2 minutes
        }());
        </script>

        <!-- ══ FLEET ═════════════════════════════════════════════ -->
        <div class="nav-section">Fleet</div>

        <a href="<?= BASE_URL ?>/modules/cars/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/cars/') ? 'active' : '' ?>"
           data-label="All Cars">
            <i class="fa fa-car"></i><span>All Cars</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/reservations/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/reservations/') ? 'active' : '' ?>"
           data-label="Reservations"
           style="position:relative">
            <i class="fa fa-bookmark"></i><span>Reservations</span>
            <?php
            try {
                $__myUid = (int)(authUser()['id'] ?? 0);
                $__myResCount = (int)getDB()->query("SELECT COUNT(*) FROM crm_leads WHERE stage='reserved' AND assigned_to={$__myUid}")->fetchColumn();
                if ($__myResCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#7c3aed;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__myResCount > 99 ? '99+' : $__myResCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/delivered_cars/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/delivered_cars/') ? 'active' : '' ?>"
           data-label="Delivered Cars">
            <i class="fa fa-truck"></i><span>Delivered Cars</span>
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

        <a href="<?= BASE_URL ?>/modules/whatsapp/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/whatsapp/') ? 'active' : '' ?>"
           data-label="WA Inbox"
           style="position:relative">
            <i class="fab fa-whatsapp"></i><span>WA Inbox</span>
            <span id="waNavBadgeCrm" style="display:none;position:absolute;top:6px;right:8px;
                  background:#00a884;color:#fff;border-radius:10px;font-size:10px;
                  font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px"></span>
        </a>
        <script>
        (function(){
            var badge = document.getElementById('waNavBadgeCrm');
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
            poll();
            setInterval(poll, 20000);
        }());
        </script>

        <!-- ══ SETTINGS ═════════════════════════════════════════ -->
        <div class="nav-section">Account</div>

        <a href="<?= BASE_URL ?>/modules/crm/settings.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/settings') ? 'active' : '' ?>"
           data-label="My Settings">
            <i class="fa fa-user-gear"></i><span>My Settings</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        <small class="text-muted" style="font-size:10.5px">v<?= APP_VERSION ?></small>
    </div>
</div>

<!-- Walk-in Quick Capture FAB -->
<style>@media print{#walkInFab,#walkInLabel{display:none!important}}</style>
<?php if (canWrite('crm')): ?>
<button type="button" id="walkInFab"
        title="Walk-in Quick Capture"
        style="position:fixed;bottom:152px;right:24px;z-index:8800;
               width:52px;height:52px;border-radius:50%;border:none;
               background:#16a34a;color:#fff;font-size:20px;
               box-shadow:0 4px 16px rgba(0,0,0,.25);cursor:pointer;
               display:flex;align-items:center;justify-content:center;
               transition:transform .15s,box-shadow .15s"
        onmouseover="this.style.transform='scale(1.08)';this.style.boxShadow='0 6px 20px rgba(0,0,0,.3)'"
        onmouseout="this.style.transform='';this.style.boxShadow='0 4px 16px rgba(0,0,0,.25)'"
        data-bs-toggle="modal" data-bs-target="#walkInModal">
    <i class="fa fa-user-plus"></i>
</button>
<div style="position:fixed;bottom:162px;right:82px;z-index:8799;background:#1e293b;color:#fff;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;pointer-events:none;opacity:0;transition:opacity .2s;white-space:nowrap" id="walkInLabel">
    Walk-in
</div>
<script>
(function(){
    var fab = document.getElementById('walkInFab');
    var lbl = document.getElementById('walkInLabel');
    if (!fab) return;
    fab.addEventListener('mouseenter', function(){ lbl.style.opacity='1'; });
    fab.addEventListener('mouseleave', function(){ lbl.style.opacity='0'; });
}());
</script>

<!-- Walk-in Modal -->
<div class="modal fade" id="walkInModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h6 class="modal-title"><i class="fa fa-user-plus me-2"></i>Walk-in Quick Capture</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="walkInSuccess" style="display:none" class="alert alert-success">
            <i class="fa fa-check-circle me-2"></i>
            <span id="walkInSuccessMsg">Lead captured!</span>
            <div class="mt-2">
                <a id="walkInViewLink" href="#" class="btn btn-sm btn-success">
                    <i class="fa fa-eye me-1"></i>View Lead
                </a>
            </div>
        </div>
        <form id="walkInForm">
          <div class="mb-3">
            <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
            <input type="text" id="wiName" class="form-control" placeholder="Customer full name" required>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Phone</label>
            <input type="tel" id="wiPhone" class="form-control" placeholder="07XX XXX XXX">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Interested In</label>
            <input type="text" id="wiInterest" class="form-control" placeholder="e.g. Toyota Prado, SUV…">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Source</label>
            <select id="wiSource" class="form-select">
              <option value="walk_in" selected>Walk-in</option>
              <option value="referral">Referral</option>
              <option value="phone_call">Phone Call</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="facebook">Facebook</option>
              <option value="instagram">Instagram</option>
              <option value="other">Other</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" id="walkInSubmit" class="btn btn-success btn-sm">
            <i class="fa fa-user-plus me-1"></i>Capture Lead
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
    document.getElementById('walkInSubmit').addEventListener('click', function(){
        var name     = document.getElementById('wiName').value.trim();
        var phone    = document.getElementById('wiPhone').value.trim();
        var interest = document.getElementById('wiInterest').value.trim();
        var source   = document.getElementById('wiSource').value;
        if (!name) { document.getElementById('wiName').classList.add('is-invalid'); return; }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Saving…';

        var fd = new FormData();
        fd.append('name', name);
        fd.append('phone', phone);
        fd.append('interested_in', interest);
        fd.append('source', source);

        fetch('<?= BASE_URL ?>/modules/crm/api/quick_capture.php', { method:'POST', body:fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) {
                    document.getElementById('walkInForm').style.display = 'none';
                    document.getElementById('walkInSuccessMsg').textContent = d.name + ' captured as a new lead!';
                    document.getElementById('walkInViewLink').href = d.view_url;
                    document.getElementById('walkInSuccess').style.display = '';
                    btn.style.display = 'none';
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-user-plus me-1"></i>Capture Lead';
                    alert(d.error || 'Something went wrong.');
                }
            }).catch(function(){
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-user-plus me-1"></i>Capture Lead';
                alert('Network error. Please try again.');
            });
    });

    document.getElementById('walkInModal').addEventListener('hidden.bs.modal', function(){
        document.getElementById('walkInForm').style.display = '';
        document.getElementById('walkInSuccess').style.display = 'none';
        document.getElementById('walkInForm').reset();
        var btn = document.getElementById('walkInSubmit');
        btn.disabled = false;
        btn.style.display = '';
        btn.innerHTML = '<i class="fa fa-user-plus me-1"></i>Capture Lead';
        document.getElementById('wiName').classList.remove('is-invalid');
    });
}());
</script>
<?php endif; ?>
