<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Lead Conversion Report';
$db = getDB();

// ── Period ────────────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
switch ($period) {
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Last Month (' . date('M Y', strtotime('last month')) . ')';
        break;
    case 'last_3_months':
        $dateFrom = date('Y-m-01', strtotime('-2 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 3 Months';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
}

$sourceLabels = [
    'walk_in'    => 'Walk-in',  'referral'   => 'Referral',
    'facebook'   => 'Facebook', 'instagram'  => 'Instagram',
    'website'    => 'Website',  'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp', 'other'      => 'Other',
];

// ── Queries ───────────────────────────────────────────────────────────────────
$kpi = ['total' => 0, 'converted' => 0, 'lost' => 0, 'active' => 0, 'rate' => 0, 'avg_days' => 0];
$bySource    = [];
$byAgent     = [];
$byCampaign  = [];
$converted   = [];

try {
    // Overall KPIs — leads created in period
    $k = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(stage = 'closed_won') AS converted,
            SUM(stage = 'closed_lost') AS lost,
            SUM(stage NOT IN ('closed_won','closed_lost')) AS active,
            ROUND(AVG(CASE WHEN stage='closed_won' AND converted_at IS NOT NULL
                THEN DATEDIFF(converted_at, created_at) END), 1) AS avg_days
        FROM crm_leads
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $k->execute([$dateFrom, $dateTo]);
    $kpi = $k->fetch(PDO::FETCH_ASSOC);
    $closed = (int)$kpi['converted'] + (int)$kpi['lost'];
    $kpi['rate'] = $closed > 0 ? round((int)$kpi['converted'] / $closed * 100, 1) : 0;

    // By source
    $s = $db->prepare("
        SELECT source,
               COUNT(*) AS total,
               SUM(stage = 'closed_won') AS converted,
               ROUND(AVG(CASE WHEN stage='closed_won' AND converted_at IS NOT NULL
                   THEN DATEDIFF(converted_at, created_at) END), 1) AS avg_days
        FROM crm_leads
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY source ORDER BY converted DESC, total DESC
    ");
    $s->execute([$dateFrom, $dateTo]);
    $bySource = $s->fetchAll(PDO::FETCH_ASSOC);

    // By agent
    $a = $db->prepare("
        SELECT u.name AS agent,
               COUNT(l.id) AS total,
               SUM(l.stage = 'closed_won') AS converted,
               SUM(l.stage = 'closed_lost') AS lost,
               ROUND(AVG(CASE WHEN l.stage='closed_won' AND l.converted_at IS NOT NULL
                   THEN DATEDIFF(l.converted_at, l.created_at) END), 1) AS avg_days
        FROM crm_leads l JOIN users u ON u.id = l.assigned_to
        WHERE DATE(l.created_at) BETWEEN ? AND ?
        GROUP BY u.id, u.name ORDER BY converted DESC, total DESC
    ");
    $a->execute([$dateFrom, $dateTo]);
    $byAgent = $a->fetchAll(PDO::FETCH_ASSOC);

    // By campaign
    $c = $db->prepare("
        SELECT campaign,
               COUNT(*) AS total,
               SUM(stage = 'closed_won') AS converted
        FROM crm_leads
        WHERE DATE(created_at) BETWEEN ? AND ?
          AND campaign IS NOT NULL AND campaign != ''
        GROUP BY campaign ORDER BY converted DESC, total DESC
    ");
    $c->execute([$dateFrom, $dateTo]);
    $byCampaign = $c->fetchAll(PDO::FETCH_ASSOC);

    // Converted leads detail — for the table
    $cv = $db->prepare("
        SELECT l.id, l.name, l.phone, l.source, l.campaign,
               l.interested_in, l.budget, l.created_at, l.converted_at,
               DATEDIFF(l.converted_at, l.created_at) AS days_to_close,
               u.name AS agent_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.stage = 'closed_won'
          AND DATE(l.created_at) BETWEEN ? AND ?
        ORDER BY l.converted_at DESC
    ");
    $cv->execute([$dateFrom, $dateTo]);
    $converted = $cv->fetchAll(PDO::FETCH_ASSOC);

} catch (\Throwable $e) {
    // crm_leads may not have converted_at — silently degrade
}

$exportQs = 'type=lead_conversion&period=' . urlencode($period)
          . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- ── KPI Cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#eff6ff">
                    <i class="fa fa-user-group text-primary"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Leads Created</div>
                    <div class="fw-bold" style="font-size:24px;line-height:1.2"><?= number_format($kpi['total']) ?></div>
                    <div class="text-muted" style="font-size:11px"><?= (int)$kpi['active'] ?> still active</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#f0fdf4">
                    <i class="fa fa-circle-check text-success"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Converted</div>
                    <div class="fw-bold text-success" style="font-size:24px;line-height:1.2"><?= number_format($kpi['converted']) ?></div>
                    <div class="text-muted" style="font-size:11px">Closed Won</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#fefce8">
                    <i class="fa fa-percent text-warning"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Win Rate</div>
                    <div class="fw-bold" style="font-size:24px;line-height:1.2"><?= $kpi['rate'] ?>%</div>
                    <div class="text-muted" style="font-size:11px">of decided leads</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#faf5ff">
                    <i class="fa fa-hourglass-half" style="color:#9333ea"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Avg Days to Close</div>
                    <div class="fw-bold" style="font-size:24px;line-height:1.2"><?= $kpi['avg_days'] ?? '—' ?></div>
                    <div class="text-muted" style="font-size:11px">lead created → won</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">

    <!-- ── By source ──────────────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">By Lead Source</h6>
                <div class="text-muted small mb-3">Conversion rate per origin channel</div>
                <?php if (empty($bySource)): ?>
                <div class="text-muted small text-center py-4">No data</div>
                <?php else: ?>
                <table class="table table-sm mb-0" style="font-size:12.5px">
                    <thead style="color:#64748b;font-size:11px;text-transform:uppercase">
                        <tr>
                            <th>Source</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Won</th>
                            <th class="text-center">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bySource as $row):
                        $rate = (int)$row['total'] > 0
                            ? round((int)$row['converted'] / (int)$row['total'] * 100)
                            : 0;
                    ?>
                    <tr>
                        <td class="fw-medium"><?= e($sourceLabels[$row['source']] ?? ucfirst($row['source'])) ?></td>
                        <td class="text-center"><?= $row['total'] ?></td>
                        <td class="text-center"><span class="badge bg-success-subtle text-success border border-success-subtle"><?= $row['converted'] ?></span></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $rate >= 40 ? 'success' : ($rate >= 20 ? 'warning' : 'secondary') ?>">
                                <?= $rate ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── By agent ───────────────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">By Sales Agent</h6>
                <div class="text-muted small mb-3">Who's converting most effectively</div>
                <?php if (empty($byAgent)): ?>
                <div class="text-muted small text-center py-4">No assigned leads data</div>
                <?php else: ?>
                <table class="table table-sm mb-0" style="font-size:12.5px">
                    <thead style="color:#64748b;font-size:11px;text-transform:uppercase">
                        <tr>
                            <th>Agent</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Won</th>
                            <th class="text-end">Avg Days</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($byAgent as $i => $row):
                        $medal = match($i) { 0 => '🥇 ', 1 => '🥈 ', 2 => '🥉 ', default => '' };
                    ?>
                    <tr>
                        <td class="fw-medium"><?= $medal . e($row['agent']) ?></td>
                        <td class="text-center"><?= $row['total'] ?></td>
                        <td class="text-center"><span class="badge bg-success-subtle text-success border border-success-subtle"><?= $row['converted'] ?></span></td>
                        <td class="text-end text-muted"><?= $row['avg_days'] !== null ? $row['avg_days'] . 'd' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── By campaign ────────────────────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">By Campaign</h6>
                <div class="text-muted small mb-3">Which campaigns drove wins</div>
                <?php if (empty($byCampaign)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-bullhorn fa-2x mb-2 d-block opacity-25"></i>
                    No campaign leads in this period
                </div>
                <?php else: ?>
                <?php $maxCamp = max(array_column($byCampaign, 'total')) ?: 1; ?>
                <?php foreach ($byCampaign as $row):
                    $campRate = (int)$row['total'] > 0
                        ? round((int)$row['converted'] / (int)$row['total'] * 100)
                        : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:12.5px">
                        <span class="fw-medium text-truncate" style="max-width:160px" title="<?= e($row['campaign']) ?>"><?= e($row['campaign']) ?></span>
                        <span><span class="badge bg-success-subtle text-success border border-success-subtle"><?= $row['converted'] ?></span> / <?= $row['total'] ?>
                        <span class="badge bg-<?= $campRate >= 40 ? 'success' : 'secondary' ?> ms-1"><?= $campRate ?>%</span></span>
                    </div>
                    <div class="progress" style="height:5px;border-radius:3px">
                        <div class="progress-bar bg-info" style="width:<?= round($row['total']/$maxCamp*100) ?>%;border-radius:3px"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Converted leads table ──────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm" style="border-radius:12px">
    <div class="card-body p-0">
        <div class="p-4 pb-3 border-bottom d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0"><i class="fa fa-circle-check me-2 text-success"></i>Converted Leads — Detail</h6>
                <div class="text-muted small">Leads created in this period that reached Closed Won</div>
            </div>
            <a href="<?= BASE_URL ?>/modules/reports/export.php?<?= htmlspecialchars($exportQs) ?>"
               class="btn btn-xs btn-outline-secondary">
                <i class="fa fa-file-csv me-1"></i>Export CSV
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable" style="font-size:13px">
                <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                    <tr>
                        <th class="ps-4 py-3">Lead</th>
                        <th class="py-3">Source</th>
                        <th class="py-3">Campaign</th>
                        <th class="py-3">Agent</th>
                        <th class="py-3">Interest</th>
                        <th class="py-3">Created</th>
                        <th class="py-3">Converted</th>
                        <th class="py-3 text-center pe-4">Days</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($converted as $lead):
                    $days = (int)($lead['days_to_close'] ?? 0);
                    $daysCls = $days <= 7 ? 'success' : ($days <= 30 ? 'warning' : 'danger');
                ?>
                <tr>
                    <td class="ps-4 py-3">
                        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $lead['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($lead['name']) ?>
                        </a>
                        <?php if ($lead['phone']): ?>
                        <div class="text-muted" style="font-size:11px"><?= e($lead['phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3"><span class="badge bg-light text-dark border" style="font-size:11px"><?= e($sourceLabels[$lead['source']] ?? ucfirst($lead['source'])) ?></span></td>
                    <td class="py-3 text-muted small"><?= $lead['campaign'] ? e($lead['campaign']) : '—' ?></td>
                    <td class="py-3 text-muted small"><?= e($lead['agent_name'] ?? '—') ?></td>
                    <td class="py-3 text-muted small"><?= $lead['interested_in'] ? e($lead['interested_in']) : '—' ?></td>
                    <td class="py-3 text-muted small"><?= fmtDate($lead['created_at'], 'd M Y') ?></td>
                    <td class="py-3 text-muted small"><?= $lead['converted_at'] ? fmtDate($lead['converted_at'], 'd M Y') : '—' ?></td>
                    <td class="py-3 text-center pe-4">
                        <span class="badge bg-<?= $daysCls ?>-subtle text-<?= $daysCls ?> border border-<?= $daysCls ?>-subtle">
                            <?= $days ?>d
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$converted): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">
                    <i class="fa fa-circle-xmark fa-2x mb-2 d-block opacity-25"></i>No leads converted in this period.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
