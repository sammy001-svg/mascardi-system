<?php
/**
 * Shared tab navigation for all report sub-pages.
 * Included after header.php. Requires $period, $dateFrom, $dateTo, $label
 * to already be defined in the calling page.
 */
$__reportPage = basename($_SERVER['PHP_SELF']);
$__qs = http_build_query(array_filter([
    'period'    => $period ?? 'this_month',
    'date_from' => ($period ?? '') === 'custom' ? ($dateFrom ?? '') : '',
    'date_to'   => ($period ?? '') === 'custom' ? ($dateTo   ?? '') : '',
]));
function reportUrl(string $page, string $qs): string {
    return BASE_URL . '/modules/reports/' . $page . ($qs ? '?' . $qs : '');
}
?>
<!-- ── Period filter ──────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-chart-bar me-2 text-primary"></i>Reports &amp; Analytics</h5>
        <div class="text-muted small">Period: <strong><?= e($label ?? '') ?></strong></div>
    </div>
    <form class="d-flex align-items-center gap-2 flex-wrap" method="GET">
        <select name="period" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="this_month"    <?= ($period??'')==='this_month'    ?'selected':'' ?>>This Month</option>
            <option value="last_month"    <?= ($period??'')==='last_month'    ?'selected':'' ?>>Last Month</option>
            <option value="last_3_months" <?= ($period??'')==='last_3_months' ?'selected':'' ?>>Last 3 Months</option>
            <option value="this_year"     <?= ($period??'')==='this_year'     ?'selected':'' ?>>This Year</option>
            <option value="custom"        <?= ($period??'')==='custom'        ?'selected':'' ?>>Custom Range</option>
        </select>
        <?php if (($period??'') === 'custom'): ?>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom??'') ?>">
        <span class="text-muted small">to</span>
        <input type="date" name="date_to"   class="form-control form-control-sm" value="<?= e($dateTo??'') ?>">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        <?php endif; ?>
    </form>
</div>

<!-- ── Tab nav ────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e2e8f0;gap:2px">
    <?php
    $tabs = [
        'index.php'            => ['fa-gauge-high',        'Overview'],
        'financial.php'        => ['fa-scale-balanced',    'Financial'],
        'workshop.php'         => ['fa-screwdriver-wrench','Workshop'],
        'sales_performance.php'=> ['fa-trophy',            'Sales'],
        'vehicle_profit.php'   => ['fa-car-side',          'Vehicle P&L'],
        'crm.php'              => ['fa-funnel-dollar',     'CRM'],
    ];
    foreach ($tabs as $file => [$icon, $label2]):
        $active = $__reportPage === $file ? 'active' : '';
    ?>
    <li class="nav-item">
        <a href="<?= reportUrl($file, $__qs) ?>"
           class="nav-link <?= $active ?>"
           style="font-size:13px;font-weight:600;padding:8px 16px">
            <i class="fa <?= $icon ?> me-1"></i><?= $label2 ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
