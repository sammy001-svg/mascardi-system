<?php
/**
 * The finance portal sidebar — for finance_manager, accountant and cashier.
 * Included from sidebar.php by the same early-exit pattern as the other portals.
 *
 * It used to run to twenty-six items across six headings: the position, money
 * in, money out, stock and cost, people, account. That was the whole of finance
 * rather than the whole of a finance day, and a menu that long is one nobody
 * reads to the bottom of.
 *
 * It is now seven: the dashboard, and the six things this portal is for.
 * Receivables, the month's collection, the payment plans, payments, invoices,
 * quotations. Nothing else.
 *
 * What left the menu did not leave the system. Expenses, suppliers, purchase
 * orders, payroll, vehicle costs, the full reports, company accounts and
 * statements are all still there on their own addresses, and the permissions
 * that let these roles reach them are untouched — a finance manager who opens
 * one by link or bookmark gets it exactly as before. Only the menu is shorter.
 *
 * Every item is still wrapped in its canAccess() test even though these roles'
 * permissions already match. The menu is not the permission: someone will edit
 * one of these lists one day, and a link that appears and then dies on the far
 * side is worse than no link at all.
 */
$__uri = $_SERVER['REQUEST_URI'];
$__is  = fn (string $p): string => str_contains($__uri, $p) ? 'active' : '';

/** A small count on a menu item, or nothing at all if the table is not there. */
$__badge = function (string $sql): int {
    try { return (int)getDB()->query($sql)->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
};

// Only the counts that mean somebody has to do something today.
$__pendingPay = $__badge("SELECT COUNT(*) FROM payments WHERE status = 'pending'");
$__unpaidInv  = $__badge("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')");
$__openQuote  = $__badge("SELECT COUNT(*) FROM quotations WHERE status IN ('draft','sent')");

// Credit accounts with something overdue. Counted through the same definition
// the book uses, so the badge and the page cannot disagree about "overdue".
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
    <?php // The same markup as the other portals' brand blocks. The classes that
          // carry the styling are sidebar-brand / brand-logo / brand-text /
          // brand-name / brand-sub; brand-mark and brand-link, which an earlier
          // version of this file used, are not styled anywhere. ?>
    <div class="sidebar-brand">
        <div class="brand-logo">
            <?php $__logo = function_exists('companyLogo') ? companyLogo() : ['exists' => false, 'url' => '']; ?>
            <?php if (!empty($__logo['exists'])): ?>
            <img src="<?= e($__logo['url']) ?>" alt="Logo"
                 style="height:32px;width:32px;object-fit:contain;border-radius:4px">
            <?php else: ?>
            <i class="fa fa-coins" style="font-size:16px"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub">Finance</span>
        </div>
    </div>

    <?php // Seven items and one heading. The accordion is switched off: with a
          // menu this short, collapsing it would hide more than it tidies. ?>
    <nav class="sidebar-nav" data-accordion="off">

        <a href="<?= BASE_URL ?>/modules/finance/index.php"
           class="nav-item <?= $__is('/finance/index.php') ?>" data-label="Dashboard">
            <i class="fa fa-chart-line"></i><span>Dashboard</span>
        </a>

        <div class="nav-section">The money</div>

        <?php if (canAccess('installments')): ?>
        <a href="<?= BASE_URL ?>/modules/finance/receivables.php"
           class="nav-item <?= $__is('/finance/receivables.php') ?>" data-label="Receivables"
           style="position:relative">
            <i class="fa fa-hand-holding-dollar"></i><span>Receivables</span>
            <?php if ($__creditOverdue): ?>
            <span class="nav-badge nav-badge-bad"><?= $__creditOverdue ?></span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/finance/month.php"
           class="nav-item <?= $__is('/finance/month.php') ?>" data-label="Monthly collection">
            <i class="fa fa-calendar-check"></i><span>Monthly collection</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/installments/index.php"
           class="nav-item <?= $__is('/installments/') ?>" data-label="Payment plans"
           style="position:relative">
            <i class="fa fa-list-check"></i><span>Payment plans</span>
            <?php if ($__arrears): ?>
            <span class="nav-badge nav-badge-bad"><?= $__arrears ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('payments')): ?>
        <a href="<?= BASE_URL ?>/modules/payments/index.php"
           class="nav-item <?= $__is('/payments/') ?>" data-label="Payments"
           style="position:relative">
            <i class="fa fa-cash-register"></i><span>Payments</span>
            <?php if ($__pendingPay): ?>
            <span class="nav-badge"><?= $__pendingPay ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php"
           class="nav-item <?= $__is('/invoices/') ?>" data-label="Invoices"
           style="position:relative">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
            <?php if ($__unpaidInv): ?>
            <span class="nav-badge"><?= $__unpaidInv ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quotations')): ?>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php"
           class="nav-item <?= $__is('/quotations/') ?>" data-label="Quotations"
           style="position:relative">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
            <?php if ($__openQuote): ?>
            <span class="nav-badge"><?= $__openQuote ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php // Profile and sign-out are in the user menu at the top right of
              // every page, so they are not repeated here. ?>
    </nav>
</div>
