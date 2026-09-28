<?php
/**
 * The finance portal sidebar — for finance_manager, accountant and cashier.
 * Included from sidebar.php by the same early-exit pattern as the other portals.
 *
 * These three roles have had permissions defined in auth.php since long before
 * this file, but no menu of their own: they fell through to the general staff
 * sidebar, which runs to some thirty items across Imports, Dispatch, Showroom
 * Transfers, Key Handovers and the workshop floor. An accountant needs almost
 * none of it.
 *
 * So this is the money, in the order a finance day actually runs: the position
 * first, then what came in, what went out, who owes us, what we owe, the
 * vehicle cost build-up, and the books.
 *
 * The three roles are not the same and the menu says so — a cashier takes
 * payments and should not be looking at payroll — but the difference is carried
 * by canAccess() rather than by a list per role, because the permissions are
 * already the truth of it and a second copy would drift from the first.
 *
 * Every item is still wrapped in its canAccess() test even though the roles'
 * permissions already match. The menu is not the permission: someone will edit
 * one of these lists one day, and a link that appears but dies on the far side
 * is worse than no link at all.
 */
$__uri = $_SERVER['REQUEST_URI'];
$__is  = fn (string $p): string => str_contains($__uri, $p) ? 'active' : '';

/** A small count on a menu item, or nothing at all if the table is not there. */
$__badge = function (string $sql): int {
    try { return (int)getDB()->query($sql)->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
};

// Only the counts that mean somebody has to do something.
$__pendingPay = $__badge("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$__unpaidInv  = $__badge("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')");
$__overdue    = $__badge("SELECT COUNT(*) FROM invoices
                           WHERE status IN ('unpaid','partial')
                             AND COALESCE(due_date, date) < CURDATE()");
$__openQuote  = $__badge("SELECT COUNT(*) FROM quotations WHERE status IN ('draft','sent')");
$__lpoOut     = $__badge("SELECT COUNT(*) FROM lpo WHERE status IN ('sent','acknowledged','partial')");
// Credit accounts with something overdue. Loaded through the finance module so
// the sidebar and the book cannot disagree about what "overdue" means.
require_once __DIR__ . '/../modules/finance/_credit.php';
$__creditOverdue = $__badge("SELECT COUNT(DISTINCT a.id) FROM credit_agreements a
                               JOIN credit_installments ci ON ci.agreement_id = a.id
                              WHERE a.status IN ('active','defaulted')
                                AND ci.due_date < CURDATE()
                                AND ci.amount_paid < ci.amount");
$__arrears    = $__badge("SELECT COUNT(*) FROM sale_installments
                           WHERE due_date < CURDATE()
                             AND COALESCE(amount_paid,0) < amount_due
                             AND COALESCE(status,'') <> 'cancelled'");
?>
<div class="app-sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <?php $__logo = function_exists('companyLogo') ? companyLogo() : ['exists' => false, 'url' => '']; ?>
        <?php if (!empty($__logo['exists'])): ?>
            <img src="<?= e($__logo['url']) ?>" alt="<?= e(getSetting('company_name', 'Mascardi')) ?>">
        <?php else: ?>
            <span class="brand-mark"><i class="fa fa-scale-balanced"></i></span>
        <?php endif; ?>
        <span class="brand-text">
            <?= e(getSetting('company_name', 'Mascardi')) ?>
            <small>Finance</small>
        </span>
    </div>

    <nav class="sidebar-nav">

        <!-- ══ THE POSITION ═══════════════════════════════════════ -->
        <div class="nav-section">The position</div>

        <?php if (canAccess('reports')): // the dashboard is a management view; see finCanUse() ?>
        <a href="<?= BASE_URL ?>/modules/finance/index.php"
           class="nav-item <?= $__is('/modules/finance/') ?>" data-label="Dashboard"
           style="position:relative">
            <i class="fa fa-scale-balanced"></i><span>Dashboard</span>
            <?php if ($__pendingPay > 0): ?>
            <span class="nav-badge" style="position:absolute;top:6px;right:8px;background:#d97706;
                  color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;
                  min-width:16px;text-align:center;line-height:16px"><?= $__pendingPay > 99 ? '99+' : $__pendingPay ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('reports')): ?>
        <a href="<?= BASE_URL ?>/modules/reports/financial.php"
           class="nav-item <?= $__is('/reports/financial') ?>" data-label="Financial report">
            <i class="fa fa-chart-line"></i><span>Financial report</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/reports/index.php"
           class="nav-item <?= $__is('/modules/reports/index') ?>" data-label="All reports">
            <i class="fa fa-folder-tree"></i><span>All reports</span>
        </a>
        <?php endif; ?>

        <!-- ══ MONEY IN ═══════════════════════════════════════════ -->
        <div class="nav-section">Money in</div>

        <?php if (canAccess('payments')): ?>
        <a href="<?= BASE_URL ?>/modules/payments/index.php"
           class="nav-item <?= $__is('/modules/payments/') ?>" data-label="Payments"
           style="position:relative">
            <i class="fa fa-cash-register"></i><span>Payments</span>
            <?php if ($__pendingPay > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#d97706;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="Awaiting confirmation"><?= $__pendingPay ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php"
           class="nav-item <?= $__is('/modules/invoices/') ?>" data-label="Invoices"
           style="position:relative">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
            <?php if ($__overdue > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#dc2626;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="Overdue"><?= $__overdue ?></span>
            <?php elseif ($__unpaidInv > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#64748b;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="Unpaid"><?= $__unpaidInv ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quotations')): ?>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php"
           class="nav-item <?= $__is('/modules/quotations/') ?>" data-label="Quotations"
           style="position:relative">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
            <?php if ($__openQuote > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#0891b2;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px"><?= $__openQuote ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (creditCanView()): ?>
        <a href="<?= BASE_URL ?>/modules/finance/receivables.php"
           class="nav-item <?= $__is('/finance/receivables') . $__is('/finance/account') ?>"
           data-label="Receivables" style="position:relative">
            <i class="fa fa-file-invoice-dollar"></i><span>Receivables</span>
            <?php if ($__creditOverdue > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#dc2626;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="Accounts overdue"><?= $__creditOverdue ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/finance/month.php"
           class="nav-item <?= $__is('/finance/month') ?>" data-label="Monthly collection">
            <i class="fa fa-calendar-days"></i><span>Monthly collection</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('installments')): ?>
        <a href="<?= BASE_URL ?>/modules/installments/index.php"
           class="nav-item <?= $__is('/modules/installments/') ?>" data-label="Instalments"
           style="position:relative">
            <i class="fa fa-calendar-check"></i><span>Instalments</span>
            <?php if ($__arrears > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#dc2626;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="In arrears"><?= $__arrears ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('sales')): ?>
        <a href="<?= BASE_URL ?>/modules/sales/index.php"
           class="nav-item <?= $__is('/modules/sales/') ?>" data-label="Sales">
            <i class="fa fa-handshake"></i><span>Sales</span>
        </a>
        <?php endif; ?>

        <!-- ══ MONEY OUT ══════════════════════════════════════════ -->
        <div class="nav-section">Money out</div>

        <?php if (canAccess('expenses')): ?>
        <a href="<?= BASE_URL ?>/modules/expenses/index.php"
           class="nav-item <?= $__is('/modules/expenses/') ?>" data-label="Expenses">
            <i class="fa fa-receipt"></i><span>Expenses</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('lpo')): ?>
        <a href="<?= BASE_URL ?>/modules/lpo/index.php"
           class="nav-item <?= $__is('/modules/lpo/') ?>" data-label="Purchase orders"
           style="position:relative">
            <i class="fa fa-file-contract"></i><span>Purchase orders</span>
            <?php if ($__lpoOut > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#64748b;color:#fff;
                  border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;
                  text-align:center;line-height:16px" title="Out, not yet received"><?= $__lpoOut ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('suppliers')): ?>
        <a href="<?= BASE_URL ?>/modules/suppliers/index.php"
           class="nav-item <?= $__is('/modules/suppliers/') ?>" data-label="Suppliers">
            <i class="fa fa-truck-field"></i><span>Suppliers</span>
        </a>
        <?php endif; ?>

        <?php if (hasRole('finance_manager')): // policy, not daily work — see settings.php ?>
        <a href="<?= BASE_URL ?>/modules/finance/settings.php"
           class="nav-item <?= $__is('/finance/settings') ?>" data-label="Payment reminders">
            <i class="fa fa-bell"></i><span>Payment reminders</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('payroll')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php"
           class="nav-item <?= $__is('/modules/payroll/') ?>" data-label="Payroll">
            <i class="fa fa-money-check-dollar"></i><span>Payroll</span>
        </a>
        <?php endif; ?>

        <!-- ══ WHAT THE STOCK COST ════════════════════════════════ -->
        <div class="nav-section">Stock &amp; cost</div>

        <?php if (canAccess('car_costs')): ?>
        <a href="<?= BASE_URL ?>/modules/car_costs/index.php"
           class="nav-item <?= $__is('/modules/car_costs/') ?>" data-label="Vehicle costs">
            <i class="fa fa-coins"></i><span>Vehicle costs</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/index.php"
           class="nav-item <?= $__is('/modules/cars/') ?>" data-label="Vehicles">
            <i class="fa fa-car"></i><span>Vehicles</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('inventory')): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php"
           class="nav-item <?= $__is('/modules/inventory/') ?>" data-label="Parts stock">
            <i class="fa fa-boxes-stacked"></i><span>Parts stock</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('trade_in')): ?>
        <a href="<?= BASE_URL ?>/modules/trade_in/index.php"
           class="nav-item <?= $__is('/modules/trade_in/') ?>" data-label="Trade-ins">
            <i class="fa fa-right-left"></i><span>Trade-ins</span>
        </a>
        <?php endif; ?>

        <!-- ══ PEOPLE ═════════════════════════════════════════════ -->
        <div class="nav-section">People</div>

        <?php if (canAccess('clients')): ?>
        <a href="<?= BASE_URL ?>/modules/clients/index.php"
           class="nav-item <?= $__is('/modules/clients/') ?>" data-label="Clients">
            <i class="fa fa-users"></i><span>Clients</span>
        </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/modules/mail/index.php"
           class="nav-item <?= $__is('/modules/mail/') ?>" data-label="Mail"
           style="position:relative">
            <i class="fa fa-envelope"></i><span>Mail</span>
            <span class="mailNavBadge" style="display:none;position:absolute;top:6px;right:8px;
                  background:#0f6b5c;color:#fff;border-radius:10px;font-size:10px;font-weight:700;
                  padding:1px 5px;min-width:16px;text-align:center;line-height:16px"></span>
        </a>
        <script>
        (function(){
            var badges = document.querySelectorAll('.mailNavBadge');
            if (!badges.length) return;
            function poll(){
                fetch('<?= BASE_URL ?>/modules/mail/api/unread.php')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        var n = d.unread || 0;
                        badges.forEach(function(b){
                            if (n > 0) { b.textContent = n > 99 ? '99+' : n; b.style.display = ''; }
                            else { b.style.display = 'none'; }
                        });
                    }).catch(function(){});
            }
            poll();
            setInterval(poll, 120000);
        }());
        </script>

        <?php if (canAccess('chat')): ?>
        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= $__is('/modules/chat/') ?>" data-label="Team chat">
            <i class="fa fa-comments"></i><span>Team chat</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('meetings')): ?>
        <a href="<?= BASE_URL ?>/modules/meetings/index.php"
           class="nav-item <?= $__is('/modules/meetings/') ?>" data-label="Meetings">
            <i class="fa fa-calendar-days"></i><span>Meetings</span>
        </a>
        <?php endif; ?>

        <!-- ══ ACCOUNT ════════════════════════════════════════════ -->
        <div class="nav-section">Account</div>

        <?php // profile.php, not users/edit.php: the latter is requireRole('admin')
              // and 403s for every role this menu is for. ?>
        <a href="<?= BASE_URL ?>/profile.php"
           class="nav-item <?= $__is('/profile.php') ?>" data-label="My profile">
            <i class="fa fa-user"></i><span>My profile</span>
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="nav-item" data-label="Sign out">
            <i class="fa fa-arrow-right-from-bracket"></i><span>Sign out</span>
        </a>
    </nav>
</div>
